<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Orphans = published posts with no inbound internal links.
 *
 * Once you have run "Scan existing links", orphan status comes from the REAL link graph
 * discovered in your post HTML (see AIIL_Link_Scanner) — so it reflects editorial links, not
 * just links this plugin created. Before any scan it falls back to the plugin's own counters.
 * Either way, we give orphans inbound links by asking the matcher for their nearest semantic
 * neighbours, which become eligible sources.
 */
class AIIL_Orphans {

	public static function fetch( $limit = 50, $offset = 0 ) {
		global $wpdb;
		$limit  = max( 1, (int) $limit );
		$offset = max( 0, (int) $offset );
		$blog   = get_current_blog_id();

		if ( AIIL_Link_Scanner::has_scan() ) {
			// Real graph: published posts that nothing links to.
			return $wpdb->get_results(
				$wpdb->prepare(
					"SELECT p.ID AS post_id, COALESCE(m.passage_count, 0) AS passage_count, m.indexed_at, 0 AS real_incoming
					 FROM {$wpdb->posts} p
					 LEFT JOIN " . AIIL_DB::posts_table() . " m ON m.post_id = p.ID AND m.blog_id = %d
					 WHERE p.post_status = 'publish' AND p.post_type = 'post'
					   AND NOT EXISTS (
						 SELECT 1 FROM " . AIIL_DB::site_links_table() . " sl
						 WHERE sl.target_post_id = p.ID AND sl.blog_id = %d
					   )
					 ORDER BY p.ID DESC LIMIT %d OFFSET %d",
					$blog,
					$blog,
					$limit,
					$offset
				)
			);
		}

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM " . AIIL_DB::posts_table() . " WHERE incoming_links = 0 AND blog_id = %d ORDER BY indexed_at DESC LIMIT %d OFFSET %d",
				$blog,
				$limit,
				$offset
			)
		);
	}

	public static function count() {
		if ( AIIL_Link_Scanner::has_scan() ) {
			return AIIL_Link_Scanner::orphan_count();
		}
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM " . AIIL_DB::posts_table() . " WHERE incoming_links = 0 AND blog_id = %d",
				get_current_blog_id()
			)
		);
	}

	/**
	 * Generate inbound opportunities for every orphan page.
	 *
	 * Matching is embedding cosine only — no AI calls — so it is safe to run synchronously.
	 * Orphan status does not change while we run (opportunities are not links yet), so
	 * paginating by offset is stable.
	 *
	 * @return array{processed:int,created:int}
	 */
	public static function find_opportunities_all() {
		$page_size = 100;
		$offset    = 0;
		$processed = 0;
		$created   = 0;

		while ( true ) {
			$orphans = self::fetch( $page_size, $offset );
			if ( empty( $orphans ) ) {
				break;
			}
			foreach ( $orphans as $orphan ) {
				$created += self::find_opportunities_for( (int) $orphan->post_id );
				$processed++;
			}
			$offset += $page_size;
		}

		AIIL_Logger::info(
			'Bulk orphan opportunity scan complete',
			array( 'orphans_scanned' => $processed, 'opportunities_created' => $created )
		);

		return array( 'processed' => $processed, 'created' => $created );
	}

	public static function find_opportunities_for( $orphan_post_id ) {
		return AIIL_Matcher::generate_incoming_for( (int) $orphan_post_id );
	}

	/**
	 * Live (still actionable) link suggestions for a set of posts, in both directions.
	 * "Live" = anything not yet decided or discarded: waiting to be processed, ready,
	 * AI-verified, or needing a human. Inserted/rejected/capped/reciprocal are excluded.
	 *
	 * @param int[] $post_ids
	 * @return array<int,array{in:int,out:int}>
	 */
	public static function suggestion_counts( array $post_ids ) {
		$out = array();
		$ids = array_values( array_unique( array_map( 'intval', $post_ids ) ) );
		if ( empty( $ids ) ) {
			return $out;
		}
		foreach ( $ids as $id ) {
			$out[ $id ] = array( 'in' => 0, 'out' => 0 );
		}

		global $wpdb;
		$table        = AIIL_DB::opportunities_table();
		$id_ph        = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$live         = array( 'pending', 'ready', 'verified', 'rewrite_suggested' );
		$status_ph    = implode( ',', array_fill( 0, count( $live ), '%s' ) );

		foreach ( array( 'in' => 'target_post_id', 'out' => 'source_post_id' ) as $dir => $col ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT {$col} AS pid, COUNT(*) AS c FROM {$table}
					 WHERE {$col} IN ({$id_ph}) AND status IN ({$status_ph})
					 GROUP BY {$col}",
					array_merge( $ids, $live )
				),
				ARRAY_A
			);
			foreach ( $rows as $r ) {
				$pid = (int) $r['pid'];
				if ( isset( $out[ $pid ] ) ) {
					$out[ $pid ][ $dir ] = (int) $r['c'];
				}
			}
		}

		return $out;
	}
}
