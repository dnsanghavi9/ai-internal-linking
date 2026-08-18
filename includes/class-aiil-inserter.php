<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIIL_Inserter {

	/** Short connector words allowed INSIDE an anchor phrase (e.g. "car rental in Singapore"). */
	protected static $bridge = array(
		'in'=>1,'of'=>1,'for'=>1,'and'=>1,'the'=>1,'a'=>1,'an'=>1,'to'=>1,'on'=>1,'with'=>1,'at'=>1,'by'=>1,'or'=>1,
	);

	/** Common / filler words that must NOT count as a target "topic" word when refining anchors. */
	protected static $keystop = array(
		'you'=>1,'your'=>1,'our'=>1,'are'=>1,'is'=>1,'was'=>1,'were'=>1,'be'=>1,'been'=>1,'has'=>1,'have'=>1,'had'=>1,
		'how'=>1,'why'=>1,'what'=>1,'when'=>1,'where'=>1,'who'=>1,'this'=>1,'that'=>1,'these'=>1,'those'=>1,'it'=>1,'its'=>1,
		'as'=>1,'about'=>1,'more'=>1,'most'=>1,'best'=>1,'top'=>1,'will'=>1,'can'=>1,'could'=>1,'would'=>1,'should'=>1,'may'=>1,
		'new'=>1,'guide'=>1,'tips'=>1,'into'=>1,'from'=>1,'after'=>1,'before'=>1,'here'=>1,'now'=>1,'truth'=>1,'forever'=>1,
	);

	/** Crude stemmer so "coating"/"coatings" and "detail"/"detailing" match. */
	protected static function stem( $w ) {
		$w = mb_strtolower( (string) $w );
		foreach ( array( 'ings', 'ing', 'ies', 'es', 's' ) as $suf ) {
			if ( mb_strlen( $w ) > strlen( $suf ) + 2 && substr( $w, -strlen( $suf ) ) === $suf ) {
				return substr( $w, 0, -strlen( $suf ) );
			}
		}
		return $w;
	}

	/**
	 * Improve an anchor deterministically (no AI call): replace/expand it with the longest
	 * phrase that (a) actually exists in the source passage and (b) overlaps the TARGET title.
	 *
	 * The AI often grabs a single word ("Ceramic") when the passage contains the fuller phrase
	 * ("Ceramic coatings"), or an off-topic word ("quietly") when the on-topic phrase ("car
	 * rental in Singapore") is right there. This picks the richest target-overlapping phrase.
	 *
	 * @return string
	 */
	public static function refine_anchor( $anchor, $passage, $target_title, $target_content = '' ) {
		$anchor  = trim( (string) $anchor );
		$passage = (string) $passage;
		if ( '' === $anchor || '' === $passage ) {
			return $anchor;
		}

		// "Topic" stems that mark the target: every content word in its TITLE, plus the words its
		// BODY repeats (freq >= 2). The body vocabulary lets us expand a lone verb like "Document"
		// into "Document the scene" — a real phrase in the source that describes the target — even
		// when the exact title phrase isn't present in the source passage.
		$ttok = array();
		foreach ( preg_split( '/[^\p{L}\p{N}]+/u', mb_strtolower( wp_strip_all_tags( (string) $target_title ) ), -1, PREG_SPLIT_NO_EMPTY ) as $w ) {
			if ( mb_strlen( $w ) >= 3 && ! isset( self::$bridge[ $w ] ) && ! isset( self::$keystop[ $w ] ) ) {
				$ttok[ self::stem( $w ) ] = true;
			}
		}
		if ( '' !== $target_content ) {
			$freq = array();
			foreach ( preg_split( '/[^\p{L}\p{N}]+/u', mb_strtolower( wp_strip_all_tags( (string) $target_content ) ), -1, PREG_SPLIT_NO_EMPTY ) as $w ) {
				if ( mb_strlen( $w ) >= 3 && ! isset( self::$bridge[ $w ] ) && ! isset( self::$keystop[ $w ] ) ) {
					$s          = self::stem( $w );
					$freq[ $s ] = ( $freq[ $s ] ?? 0 ) + 1;
				}
			}
			foreach ( $freq as $s => $c ) {
				if ( $c >= 2 ) {
					$ttok[ $s ] = true;
				}
			}
		}
		if ( empty( $ttok ) ) {
			return $anchor;
		}

		if ( ! preg_match_all( '/[\p{L}\p{N}]+/u', $passage, $tm, PREG_OFFSET_CAPTURE ) ) {
			return $anchor;
		}
		$toks = array();
		foreach ( $tm[0] as $t ) {
			$lc   = mb_strtolower( $t[0] );
			$type = isset( $ttok[ self::stem( $lc ) ] ) ? 'key' : ( isset( self::$bridge[ $lc ] ) ? 'bridge' : 'other' );
			$toks[] = array( 'start' => $t[1], 'end' => $t[1] + strlen( $t[0] ), 'type' => $type );
		}

		// Longest run bounded by key tokens (interior key/bridge), up to 6 words, richest in keys.
		$best = null;
		$n    = count( $toks );
		for ( $i = 0; $i < $n; $i++ ) {
			if ( 'key' !== $toks[ $i ]['type'] ) {
				continue;
			}
			$keys = 0;
			$last = $i;
			for ( $j = $i; $j < $n && ( $j - $i ) < 6; $j++ ) {
				if ( 'key' === $toks[ $j ]['type'] ) {
					$keys++;
					$last = $j;
				} elseif ( 'bridge' !== $toks[ $j ]['type'] ) {
					break;
				}
			}
			$words = $last - $i + 1;
			if ( null === $best || $keys > $best['keys'] || ( $keys === $best['keys'] && $words > $best['words'] ) ) {
				$best = array( 'start' => $toks[ $i ]['start'], 'end' => $toks[ $last ]['end'], 'keys' => $keys, 'words' => $words );
			}
		}
		if ( null === $best ) {
			return $anchor;
		}

		$phrase        = trim( substr( $passage, $best['start'], $best['end'] - $best['start'] ) );
		$anchor_words  = count( preg_split( '/\s+/u', $anchor, -1, PREG_SPLIT_NO_EMPTY ) );
		$anchor_is_key = isset( $ttok[ self::stem( preg_replace( '/[^\p{L}\p{N}]+/u', '', mb_strtolower( $anchor ) ) ) ] );

		// Prefer the richer phrase: it has more words than the current anchor, or the current
		// anchor is not even a target term (e.g. "quietly").
		if ( '' !== $phrase && ( $best['words'] > $anchor_words || ! $anchor_is_key ) ) {
			return $phrase;
		}
		return $anchor;
	}

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

		// Upgrade a lazy single-word anchor to the richest target-overlapping phrase present in
		// the passage (helps links verified before this improvement, without re-running the AI).
		if ( null === $override_anchor && '' !== $sentence ) {
			$anchor = self::refine_anchor( $anchor, $sentence, $target->post_title, $target->post_content );
		}

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

		$anchor_html = (int) AIIL_Settings::get( 'bold_links', 1 ) === 1
			? '<strong>' . esc_html( $anchor ) . '</strong>'
			: esc_html( $anchor );
		$link    = '<a href="' . esc_url( $url ) . '">' . $anchor_html . '</a>';
		// Bare word-boundary matcher (inside-<a> is handled by span checks in best_offset()).
		$pattern = '/(?<![\p{L}\p{N}])' . preg_quote( $anchor, '/' ) . '(?![\p{L}\p{N}])/iu';
		$min_gap = (int) AIIL_Settings::get( 'link_word_gap', 3 );

		$sentence = trim( (string) $sentence );
		$result   = null;

		// Preferred: the block that contains the AI-chosen sentence, and only if we can honour
		// the word-gap there. Otherwise fall through to a best-effort placement.
		if ( '' !== $sentence ) {
			$needle = self::normalize_for_match( $sentence );

			if ( preg_match_all( '/<(p|li|blockquote|dd|td)\b[^>]*>(.*?)<\/\1>/is', $protected, $m, PREG_OFFSET_CAPTURE ) ) {
				foreach ( $m[0] as $match ) {
					$block_html  = $match[0];
					$byte_offset = $match[1];

					$block_text = self::normalize_for_match( wp_strip_all_tags( $block_html ) );
					if ( '' === $block_text || false === mb_stripos( $block_text, $needle ) ) {
						continue;
					}

					$spot = self::best_offset( $block_html, $pattern, $min_gap );
					if ( $spot && $spot['gap'] >= $min_gap ) {
						$new_block = substr( $block_html, 0, $spot['offset'] ) . $link . substr( $block_html, $spot['offset'] + $spot['length'] );
						$result    = substr( $protected, 0, $byte_offset ) . $new_block . substr( $protected, $byte_offset + strlen( $block_html ) );
						break;
					}
				}
			}
		}

		if ( null === $result ) {
			// Best-effort fallback: the spot in the whole (heading-shielded) content that sits
			// furthest from any existing link, so a new link never crowds an old one — even when
			// no spot fully meets the gap, we pick the roomiest available.
			$spot = self::best_offset( $protected, $pattern, $min_gap );
			if ( $spot ) {
				$result = substr( $protected, 0, $spot['offset'] ) . $link . substr( $protected, $spot['offset'] + $spot['length'] );
			}
		}

		// Restore the shielded headings/regions verbatim.
		return strtr( null === $result ? $protected : $result, $shields );
	}

	/**
	 * Choose where to place the anchor: the occurrence that maximises the word distance to the
	 * nearest existing <a> link. Occurrences inside an existing link are skipped entirely.
	 *
	 * @return array{offset:int,length:int,gap:int}|null  Byte offset + length of the chosen
	 *         occurrence and the word gap to the nearest existing link (PHP_INT_MAX when there
	 *         are no existing links). Null when the anchor does not occur in $html.
	 */
	protected static function best_offset( $html, $pattern, $min_gap ) {
		if ( ! preg_match_all( $pattern, $html, $mm, PREG_OFFSET_CAPTURE ) ) {
			return null;
		}

		$links = array();
		if ( preg_match_all( '/<a\b[^>]*>.*?<\/a>/is', $html, $lm, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $lm[0] as $l ) {
				$links[] = array( $l[1], $l[1] + strlen( $l[0] ) );
			}
		}

		$best = null;
		foreach ( $mm[0] as $m ) {
			$cs  = $m[1];
			$ce  = $cs + strlen( $m[0] );
			$gap = PHP_INT_MAX;
			$bad = false;

			foreach ( $links as $L ) {
				if ( $cs >= $L[0] && $ce <= $L[1] ) {
					$bad = true; // occurrence sits inside an existing link
					break;
				}
				if ( $L[1] <= $cs ) {
					$between = substr( $html, $L[1], $cs - $L[1] );
				} elseif ( $L[0] >= $ce ) {
					$between = substr( $html, $ce, $L[0] - $ce );
				} else {
					$between = '';
				}
				$words = count( preg_split( '/\s+/u', trim( wp_strip_all_tags( $between ) ), -1, PREG_SPLIT_NO_EMPTY ) );
				$gap   = min( $gap, $words );
			}
			if ( $bad ) {
				continue;
			}

			if ( null === $best || $gap > $best['gap'] ) {
				$best = array( 'offset' => $cs, 'length' => strlen( $m[0] ), 'gap' => $gap );
				// Once we clear the gap next to a real link, stop — the earliest qualifying spot
				// reads most naturally.
				if ( $gap >= $min_gap && ! empty( $links ) ) {
					break;
				}
			}
		}

		return $best;
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
