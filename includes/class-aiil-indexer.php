<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns a post into passages + embeddings. This is the whole "understanding" step in V2 —
 * there is no topic extraction. A post is represented by its passage vectors (for placement)
 * and their mean (the document vector, for matching).
 */
class AIIL_Indexer {

	const MAX_PASSAGES = 60;

	public static function provider() {
		return new AIIL_Gemini_Provider();
	}

	/**
	 * Split post content into passages (paragraph/sentence sized chunks of plain text).
	 *
	 * @return string[]
	 */
	public static function split_passages( $content ) {
		// Drop headings and captions before extracting passages, so a link is never anchored to
		// heading text (the inserter also refuses to write into headings, but keeping them out of
		// the passage set means they can't even be chosen as the place to link from).
		$content = preg_replace( '/<h[1-6]\b[^>]*>.*?<\/h[1-6]>|<figcaption\b[^>]*>.*?<\/figcaption>/is', "\n\n", (string) $content );

		$text = wp_strip_all_tags( (string) $content );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$text = trim( preg_replace( '/[ \t]+/u', ' ', $text ) );
		if ( '' === $text ) {
			return array();
		}

		// Split on blank lines first (paragraphs), then break very long paragraphs at
		// sentence boundaries so each passage is a focused, embeddable unit.
		$paragraphs = preg_split( '/\n\s*\n/u', $text );
		$passages   = array();
		foreach ( $paragraphs as $p ) {
			$p = trim( preg_replace( '/\s+/u', ' ', $p ) );
			if ( mb_strlen( $p ) < 25 ) {
				continue; // skip tiny fragments (headings, captions)
			}
			if ( mb_strlen( $p ) <= 500 ) {
				$passages[] = $p;
				continue;
			}
			$sentences = preg_split( '/(?<=[.!?])\s+/u', $p );
			$buffer    = '';
			foreach ( $sentences as $s ) {
				if ( mb_strlen( $buffer ) + mb_strlen( $s ) > 500 && '' !== $buffer ) {
					$passages[] = trim( $buffer );
					$buffer     = '';
				}
				$buffer .= ' ' . $s;
			}
			if ( '' !== trim( $buffer ) ) {
				$passages[] = trim( $buffer );
			}
		}

		return array_slice( array_values( array_filter( $passages ) ), 0, self::MAX_PASSAGES );
	}

	/**
	 * Index (embed) a post. Skips work when the content is unchanged.
	 *
	 * @return array|null { passage_count, dims } or null if not indexable.
	 * @throws Exception on API/transport error.
	 */
	public static function index_post( $post_id ) {
		$post_id = (int) $post_id;
		$post    = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status || 'post' !== $post->post_type ) {
			self::delete( $post_id );
			return null;
		}

		// Body passages are the only anchorable units. The title is embedded too (it is a
		// strong topical signal for retrieval) but is NEVER stored as a passage, so a link can
		// never be placed inside the post title / H1.
		$passages = self::split_passages( $post->post_content );
		if ( empty( $passages ) ) {
			return null;
		}

		$title = trim( wp_strip_all_tags( (string) $post->post_title ) );

		$content_hash = md5( $title . '|' . implode( '|', $passages ) );
		$existing     = self::meta( $post_id );
		if ( $existing && (string) $existing->content_hash === $content_hash && (int) $existing->passage_count > 0 ) {
			// Trust the recorded count only if the passage rows are really there. A post indexed
			// by an older build could carry a count with no rows behind it; re-indexing would
			// then skip it forever and it could never produce a usable link. Verifying here means
			// a plain re-index repairs such a site — no need to clear plugin data.
			global $wpdb;
			$actual = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM " . AIIL_DB::passages_table() . " WHERE post_id = %d AND blog_id = %d",
					$post_id,
					get_current_blog_id()
				)
			);
			if ( $actual > 0 ) {
				return array( 'passage_count' => $actual, 'skipped' => true );
			}
			AIIL_Logger::warning(
				'Stored passage count had no passages behind it — re-indexing',
				array( 'post_id' => $post_id, 'recorded' => (int) $existing->passage_count )
			);
		}

		// Embed the title first, then the body passages, in one batch.
		$embed_input = array_merge( array( $title ), $passages );
		$vectors     = self::provider()->embed_batch( $embed_input );
		$doc_vector  = AIIL_Vector::mean( $vectors ); // includes the title vector
		if ( null === $doc_vector ) {
			throw new Exception( 'No embeddings returned for post ' . $post_id );
		}

		// Drop the title vector so $vectors aligns 1:1 with the body $passages below.
		array_shift( $vectors );

		global $wpdb;
		$now      = current_time( 'mysql' );
		$blog_id  = get_current_blog_id();
		$max_out  = (int) AIIL_Settings::get( 'max_outgoing_links', 3 );

		// Write the passages FIRST, then record how many actually landed. Doing it the other way
		// round let a post be marked indexed with a passage_count that never matched reality —
		// the post then looked healthy while every link it produced failed the passage gate.
		$wpdb->delete( AIIL_DB::passages_table(), array( 'post_id' => $post_id, 'blog_id' => $blog_id ) );
		$stored = 0;
		foreach ( $passages as $i => $text ) {
			if ( empty( $vectors[ $i ] ) ) {
				continue;
			}
			$ok = $wpdb->insert(
				AIIL_DB::passages_table(),
				array(
					'post_id' => $post_id,
					'blog_id' => $blog_id,
					'idx'     => (int) $i,
					'passage_text' => $text,
					'embedding'    => wp_json_encode( $vectors[ $i ] ),
				)
			);
			if ( $ok ) {
				$stored++;
			}
		}

		// A post with no stored passages can never host a link, so refuse to record it as indexed.
		// Throwing keeps the job in the queue to retry instead of leaving a post that looks fine
		// but silently poisons every opportunity it takes part in.
		if ( $stored < 1 ) {
			// Surface the database's own reason — without it this looks like an AI problem when it
			// is usually schema-level (a missing table, or a column the server rejects).
			$db_error = ! empty( $wpdb->last_error ) ? ' DB said: ' . $wpdb->last_error : '';
			throw new Exception( 'No passages could be stored for post ' . $post_id . ' — not marking it indexed.' . $db_error );
		}

		// Upsert the post row, preserving link counters on update.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO " . AIIL_DB::posts_table() . "
					(post_id, blog_id, doc_vector, content_hash, passage_count, max_outgoing_links, indexed_at, updated_at)
				 VALUES (%d, %d, %s, %s, %d, %d, %s, %s)
				 ON DUPLICATE KEY UPDATE
					doc_vector = VALUES(doc_vector),
					content_hash = VALUES(content_hash),
					passage_count = VALUES(passage_count),
					indexed_at = VALUES(indexed_at),
					updated_at = VALUES(updated_at)",
				$post_id,
				$blog_id,
				wp_json_encode( $doc_vector ),
				$content_hash,
				$stored,
				$max_out,
				$now,
				$now
			)
		);

		AIIL_Logger::info( 'Indexed post', array( 'post_id' => $post_id, 'passages_stored' => $stored, 'chunks' => count( $passages ) ) );
		return array( 'passage_count' => $stored );
	}

	public static function delete( $post_id ) {
		global $wpdb;
		$blog_id = get_current_blog_id();
		$wpdb->delete( AIIL_DB::posts_table(), array( 'post_id' => (int) $post_id, 'blog_id' => $blog_id ) );
		$wpdb->delete( AIIL_DB::passages_table(), array( 'post_id' => (int) $post_id, 'blog_id' => $blog_id ) );
	}

	public static function meta( $post_id ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM " . AIIL_DB::posts_table() . " WHERE post_id = %d AND blog_id = %d",
				(int) $post_id,
				get_current_blog_id()
			)
		);
	}

	/** Passage rows (with decoded vectors) for a post. */
	public static function passages( $post_id ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, idx, passage_text AS text, embedding AS vector FROM " . AIIL_DB::passages_table() . " WHERE post_id = %d AND blog_id = %d ORDER BY idx ASC",
				(int) $post_id,
				get_current_blog_id()
			)
		);
	}

	/**
	 * Queue every published post for indexing.
	 *
	 * @return int
	 */
	public static function enqueue_all() {
		global $wpdb;
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_status = %s AND post_type = %s ORDER BY ID ASC",
				'publish',
				'post'
			)
		);
		foreach ( $ids as $id ) {
			AIIL_Queue::enqueue( AIIL_Queue::JOB_INDEX_POST, (int) $id );
		}
		AIIL_Logger::info( 'Enqueued indexing', array( 'count' => count( $ids ) ) );
		return count( $ids );
	}
}
