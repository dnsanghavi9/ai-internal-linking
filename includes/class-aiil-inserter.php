<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIIL_Inserter {

	/**
	 * When true, the save_post hook should ignore the next update — it was
	 * triggered by us inserting a link, not by a real edit. See AIIL_Hooks::on_save_post.
	 *
	 * @var int[]  Post IDs to skip on the next save_post tick.
	 */
	protected static $skip_post_ids = array();

	public static function should_skip_save_hook( $post_id ) {
		return in_array( (int) $post_id, self::$skip_post_ids, true );
	}

	public static function insert_for_opportunity( $opportunity_id, $override_anchor = null ) {
		global $wpdb;

		$opportunity = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM " . AIIL_DB::opportunities_table() . " WHERE id = %d", (int) $opportunity_id )
		);
		if ( ! $opportunity ) {
			throw new Exception( 'Opportunity not found: ' . $opportunity_id );
		}

		$source = get_post( (int) $opportunity->source_post_id );
		$target = get_post( (int) $opportunity->target_post_id );
		if ( ! $source || ! $target ) {
			throw new Exception( 'Missing source/target post.' );
		}

		// Reciprocal guard: if the reverse direction (target -> source) already has an
		// active link, don't also link this direction. Evaluate each direction on its own.
		if ( (int) AIIL_Settings::get( 'avoid_reciprocal', 1 ) === 1 ) {
			$reverse = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM " . AIIL_DB::links_table() . "
					 WHERE source_post_id = %d AND target_post_id = %d AND status = %s",
					(int) $opportunity->target_post_id,
					(int) $opportunity->source_post_id,
					'active'
				)
			);
			if ( $reverse > 0 ) {
				$wpdb->update( AIIL_DB::opportunities_table(), array( 'status' => 'reciprocal' ), array( 'id' => (int) $opportunity_id ) );
				throw new Exception( 'Skipped: the reverse link already exists (reciprocal).' );
			}
		}

		$stashed  = AIIL_Placement::get_stash( (int) $opportunity_id );
		$sentence = $stashed && ! empty( $stashed['sentence'] ) ? $stashed['sentence'] : '';
		$anchor   = $override_anchor !== null ? (string) $override_anchor : (string) $opportunity->anchor_text;

		if ( '' === trim( $anchor ) ) {
			throw new Exception( 'No anchor text on opportunity ' . $opportunity_id );
		}

		// Atomically claim the opportunity so two concurrent approvals (double-click /
		// auto-insert racing a manual approve) can't both insert the link. Only one
		// UPDATE can move it out of pending/ready into 'inserting'.
		$claimed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE " . AIIL_DB::opportunities_table() . "
				 SET status = %s WHERE id = %d AND status IN (%s, %s, %s, %s)",
				'inserting',
				(int) $opportunity_id,
				'pending',
				'ready',
				'rewrite_suggested',
				'verified'
			)
		);
		if ( 1 !== (int) $claimed ) {
			throw new Exception( 'Opportunity is already being inserted or is no longer pending: ' . $opportunity_id );
		}

		try {
			$target_url  = get_permalink( $target );
			$new_content = self::replace_anchor( $source->post_content, $sentence, $anchor, $target_url );

			if ( $new_content === $source->post_content ) {
				throw new Exception( 'Could not locate anchor "' . $anchor . '" in source content.' );
			}

			self::$skip_post_ids[] = (int) $source->ID;
			$result = wp_update_post(
				array(
					'ID'           => (int) $source->ID,
					'post_content' => $new_content,
				),
				true
			);
			self::$skip_post_ids = array_diff( self::$skip_post_ids, array( (int) $source->ID ) );

			if ( is_wp_error( $result ) || 0 === (int) $result ) {
				$msg = is_wp_error( $result ) ? $result->get_error_message() : 'wp_update_post returned 0';
				throw new Exception( 'wp_update_post failed: ' . $msg );
			}

			self::record_insertion( (int) $opportunity->source_post_id, (int) $opportunity->target_post_id, $anchor );

			$wpdb->update(
				AIIL_DB::opportunities_table(),
				array(
					'status'      => 'inserted',
					'anchor_text' => mb_substr( $anchor, 0, 255 ),
				),
				array( 'id' => (int) $opportunity_id )
			);

			AIIL_Placement::clear_stash( (int) $opportunity_id );

			self::bump_link_counts( (int) $opportunity->source_post_id, (int) $opportunity->target_post_id, 1 );
		} catch ( Exception $e ) {
			// Release the claim so the user can fix the anchor and retry.
			self::$skip_post_ids = array_diff( self::$skip_post_ids, array( (int) $source->ID ) );
			$wpdb->update(
				AIIL_DB::opportunities_table(),
				array( 'status' => 'ready' ),
				array( 'id' => (int) $opportunity_id )
			);
			throw $e;
		}

		AIIL_Logger::info(
			'Internal link inserted',
			array(
				'opportunity_id' => (int) $opportunity_id,
				'source'         => (int) $opportunity->source_post_id,
				'target'         => (int) $opportunity->target_post_id,
				'anchor'         => $anchor,
			)
		);

		return true;
	}

	/**
	 * Inject an <a> tag for the given anchor text into the post HTML.
	 *
	 * Strategy:
	 * 1. "Shield" headings (and other non-body regions) so a link can NEVER be placed inside
	 *    them — an internal link belongs in body copy, not an H2/H3, nav, caption, etc.
	 * 2. Walk block-level chunks (paragraphs, list items, quotes, table/def cells), locate the
	 *    AI-supplied sentence, and word-boundary replace the anchor there (never inside an <a>).
	 * 3. If sentence-aware location fails, fall back to the first valid occurrence in the
	 *    (still heading-shielded) content.
	 */
	public static function replace_anchor( $content, $sentence, $anchor, $url ) {
		$anchor = trim( $anchor );
		if ( '' === $anchor ) {
			return $content;
		}

		// Replace headings / non-linkable regions with opaque placeholders so nothing inside
		// them can match. They are restored verbatim at the end.
		$shields   = array();
		$protected = preg_replace_callback(
			'/<h[1-6]\b[^>]*>.*?<\/h[1-6]>|<figcaption\b[^>]*>.*?<\/figcaption>|<(?:nav|thead)\b[^>]*>.*?<\/(?:nav|thead)>/is',
			function ( $m ) use ( &$shields ) {
				$key             = '<!--AIILSHIELD' . count( $shields ) . '-->';
				$shields[ $key ] = $m[0];
				return $key;
			},
			(string) $content
		);

		$link    = '<a href="' . esc_url( $url ) . '">' . esc_html( $anchor ) . '</a>';
		$pattern = '/(?<![\p{L}\p{N}])' . preg_quote( $anchor, '/' ) . '(?![\p{L}\p{N}])(?![^<]*<\/a>)/iu';

		$sentence = trim( (string) $sentence );
		$result   = null;

		if ( '' !== $sentence ) {
			$needle = self::normalize_for_match( $sentence );

			if ( preg_match_all( '/<(p|li|blockquote|dd|td)\b[^>]*>(.*?)<\/\1>/is', $protected, $m, PREG_OFFSET_CAPTURE ) ) {
				foreach ( $m[0] as $idx => $match ) {
					$block_html  = $match[0];
					$byte_offset = $match[1];

					$block_text = self::normalize_for_match( wp_strip_all_tags( $block_html ) );
					if ( '' === $block_text || false === mb_stripos( $block_text, $needle ) ) {
						continue;
					}

					$replaced = preg_replace( $pattern, $link, $block_html, 1 );
					if ( is_string( $replaced ) && $replaced !== $block_html ) {
						$result = substr( $protected, 0, $byte_offset ) . $replaced . substr( $protected, $byte_offset + strlen( $block_html ) );
						break;
					}
				}
			}
		}

		if ( null === $result ) {
			// Fallback: first valid match anywhere in the heading-shielded content.
			$new    = preg_replace( $pattern, $link, $protected, 1 );
			$result = is_string( $new ) ? $new : $protected;
		}

		// Restore the shielded headings/regions verbatim.
		return strtr( $result, $shields );
	}

	/**
	 * Normalize whitespace + lowercase for fuzzy sentence matching.
	 */
	protected static function normalize_for_match( $text ) {
		$text = preg_replace( '/\s+/u', ' ', (string) $text );
		return mb_strtolower( trim( $text ) );
	}

	protected static function record_insertion( $source_id, $target_id, $anchor ) {
		global $wpdb;
		$wpdb->insert(
			AIIL_DB::links_table(),
			array(
				'source_post_id' => (int) $source_id,
				'target_post_id' => (int) $target_id,
				'anchor_text'    => mb_substr( $anchor, 0, 255 ),
				'inserted_at'    => current_time( 'mysql' ),
				'status'         => 'active',
			)
		);
	}

	public static function bump_link_counts( $source_id, $target_id, $delta = 1 ) {
		global $wpdb;
		$delta = (int) $delta;
		$table = AIIL_DB::posts_table();

		self::ensure_post_row( (int) $source_id );
		self::ensure_post_row( (int) $target_id );

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET outgoing_links = GREATEST(0, outgoing_links + %d) WHERE post_id = %d AND blog_id = %d",
				$delta,
				(int) $source_id,
				get_current_blog_id()
			)
		);
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET incoming_links = GREATEST(0, incoming_links + %d) WHERE post_id = %d AND blog_id = %d",
				$delta,
				(int) $target_id,
				get_current_blog_id()
			)
		);
	}

	/**
	 * Create a stub wp_aiil_posts row so link counters have somewhere to land
	 * even if the post hasn't been analyzed yet.
	 */
	protected static function ensure_post_row( $post_id ) {
		global $wpdb;
		$table = AIIL_DB::posts_table();

		// INSERT IGNORE is atomic against the (post_id, blog_id) unique key: it creates
		// the stub only if no row exists, with no SELECT-then-INSERT race. If a real
		// metadata row already exists, this is a harmless no-op that leaves it untouched.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (post_id, blog_id, max_outgoing_links, updated_at)
				 VALUES (%d, %d, %d, %s)",
				(int) $post_id,
				get_current_blog_id(),
				(int) AIIL_Settings::get( 'max_outgoing_links', 3 ),
				current_time( 'mysql' )
			)
		);
	}

	public static function reject_opportunity( $opportunity_id ) {
		global $wpdb;
		$wpdb->update(
			AIIL_DB::opportunities_table(),
			array( 'status' => 'rejected' ),
			array( 'id' => (int) $opportunity_id )
		);
		AIIL_Placement::clear_stash( (int) $opportunity_id );
	}
}
