<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * V2 matching: purely semantic. Each post is matched to its nearest neighbours by document
 * embedding cosine. No topics, no lexical scoring, no per-niche configuration.
 */
class AIIL_Matcher {

	/** Per-request cache of all MEAN-CENTERED doc vectors: post_id => vector. */
	protected static $vectors_memo = null;

	/** Per-request cache of the corpus mean doc vector. */
	protected static $mean_memo = null;

	protected static function all_vectors() {
		if ( null !== self::$vectors_memo ) {
			return self::$vectors_memo;
		}
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, doc_vector FROM " . AIIL_DB::posts_table() . "
				 WHERE blog_id = %d AND doc_vector IS NOT NULL AND doc_vector != ''",
				get_current_blog_id()
			)
		);
		$raw = array();
		foreach ( $rows as $r ) {
			$vec = AIIL_Vector::decode( $r->doc_vector );
			if ( $vec ) {
				$raw[ (int) $r->post_id ] = $vec;
			}
		}

		// Mean-center every vector. Embedding cosines are heavily anisotropic (everything looks
		// ~0.9 similar); subtracting the corpus mean removes that common component and spreads
		// the similarities into a discriminative range, so thresholds regain meaning.
		$mean = AIIL_Vector::mean( array_values( $raw ) );
		$out  = array();
		if ( $mean ) {
			foreach ( $raw as $pid => $vec ) {
				$out[ $pid ] = AIIL_Vector::subtract( $vec, $mean );
			}
		} else {
			$out = $raw;
		}

		self::$mean_memo    = $mean;
		self::$vectors_memo = $out;
		return $out;
	}

	/** The corpus mean doc vector (for callers that must center their own vectors, e.g. placement). */
	public static function corpus_mean() {
		if ( null === self::$mean_memo ) {
			self::all_vectors();
		}
		return self::$mean_memo;
	}

	public static function flush_cache() {
		self::$vectors_memo = null;
		self::$mean_memo    = null;
	}

	/**
	 * Generate opportunities for a source post: its top-K nearest neighbours by cosine.
	 * For strong pairs (>= both_direction_min) the REVERSE direction is also generated, so a
	 * newly indexed post immediately becomes linkable *from* existing posts too.
	 *
	 * @return int Number of opportunity rows created.
	 */
	public static function generate_for_post( $source_post_id ) {
		$source_post_id = (int) $source_post_id;
		$vectors        = self::all_vectors();
		if ( empty( $vectors[ $source_post_id ] ) ) {
			return 0;
		}
		if ( ! self::eligible( $source_post_id ) ) {
			return 0;
		}

		$source_vec = $vectors[ $source_post_id ];
		$top_k      = (int) AIIL_Settings::get( 'match_top_k', 8 );
		$min_doc    = (float) AIIL_Settings::get( 'min_doc_similarity', 55 );
		$both_min   = (float) AIIL_Settings::get( 'both_direction_min', 60 );
		$now        = current_time( 'mysql' );

		$scored = array();
		foreach ( $vectors as $pid => $vec ) {
			if ( $pid === $source_post_id ) {
				continue;
			}
			$sim = AIIL_Vector::score( AIIL_Vector::cosine( $source_vec, $vec ) );
			if ( $sim < $min_doc ) {
				continue;
			}
			$scored[ $pid ] = $sim;
		}
		arsort( $scored );
		$neighbours = array_slice( $scored, 0, $top_k, true );

		$created = 0;
		foreach ( $neighbours as $target => $sim ) {
			if ( ! self::eligible_target( (int) $target ) ) {
				continue;
			}
			if ( self::upsert( $source_post_id, (int) $target, $sim, $now ) ) {
				$created++;
			}
			// Both-direction: for a strong pair, make sure the reverse opportunity exists too.
			if ( $sim >= $both_min && self::eligible( (int) $target ) && self::eligible_target( $source_post_id ) ) {
				self::upsert( (int) $target, $source_post_id, $sim, $now );
			}
		}

		AIIL_Logger::info(
			'Generated opportunities',
			array( 'source_post_id' => $source_post_id, 'neighbours' => count( $neighbours ), 'created' => $created )
		);
		return $created;
	}

	/**
	 * Generate INCOMING opportunities for a target post: its top-K nearest neighbours become
	 * eligible sources that link *to* it. Used to give orphan posts inbound links.
	 *
	 * @return int Number of opportunity rows created.
	 */
	public static function generate_incoming_for( $target_post_id ) {
		$target_post_id = (int) $target_post_id;
		$vectors        = self::all_vectors();
		if ( empty( $vectors[ $target_post_id ] ) ) {
			return 0;
		}
		if ( ! self::eligible_target( $target_post_id ) ) {
			return 0;
		}

		$target_vec = $vectors[ $target_post_id ];
		$top_k      = (int) AIIL_Settings::get( 'match_top_k', 8 );
		$min_doc    = (float) AIIL_Settings::get( 'min_doc_similarity', 55 );
		$now        = current_time( 'mysql' );

		$scored = array();
		foreach ( $vectors as $pid => $vec ) {
			if ( $pid === $target_post_id || ! self::eligible( $pid ) ) {
				continue;
			}
			$sim = AIIL_Vector::score( AIIL_Vector::cosine( $target_vec, $vec ) );
			if ( $sim < $min_doc ) {
				continue;
			}
			$scored[ $pid ] = $sim;
		}
		arsort( $scored );

		$created = 0;
		foreach ( array_slice( $scored, 0, $top_k, true ) as $source => $sim ) {
			if ( self::upsert( (int) $source, $target_post_id, $sim, $now ) ) {
				$created++;
			}
		}
		return $created;
	}

	protected static function upsert( $source, $target, $sim, $now ) {
		global $wpdb;
		$affected = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO " . AIIL_DB::opportunities_table() . "
					(source_post_id, target_post_id, doc_similarity, status, created_at)
				 VALUES (%d, %d, %f, %s, %s)
				 ON DUPLICATE KEY UPDATE doc_similarity = VALUES(doc_similarity)",
				(int) $source,
				(int) $target,
				(float) $sim,
				'pending',
				$now
			)
		);
		return 1 === (int) $affected;
	}

	protected static function eligible( $post_id ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT outgoing_links, max_outgoing_links FROM " . AIIL_DB::posts_table() . " WHERE post_id = %d AND blog_id = %d",
				(int) $post_id,
				get_current_blog_id()
			)
		);
		if ( ! $row ) {
			return false;
		}
		return (int) $row->outgoing_links < (int) $row->max_outgoing_links;
	}

	protected static function eligible_target( $post_id ) {
		$post = get_post( (int) $post_id );
		return $post && 'publish' === $post->post_status;
	}
}
