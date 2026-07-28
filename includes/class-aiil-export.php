<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIIL_Export {

	const ROW_CAP = 10000;
	const LOG_CAP = 1000;

	public static function types() {
		return array( 'all', 'posts', 'opportunities', 'links', 'site_links', 'orphans', 'graph', 'logs', 'queue', 'eval' );
	}

	public static function dataset( $type ) {
		switch ( $type ) {
			case 'eval':
				return array( 'meta' => self::meta(), 'eval' => AIIL_Eval::report() );
			case 'graph':
				return array( 'meta' => self::meta(), 'graph' => AIIL_Graph::data() );
			case 'posts':
				return array( 'meta' => self::meta(), 'posts' => self::posts() );
			case 'opportunities':
				return array( 'meta' => self::meta(), 'opportunities' => self::opportunities() );
			case 'links':
				return array( 'meta' => self::meta(), 'links' => self::links() );
			case 'site_links':
				return array( 'meta' => self::meta(), 'site_links' => self::site_links() );
			case 'orphans':
				return array( 'meta' => self::meta(), 'orphans' => self::orphans() );
			case 'logs':
				return array( 'meta' => self::meta(), 'logs' => self::logs() );
			case 'queue':
				return array( 'meta' => self::meta(), 'queue' => self::queue() );
			case 'all':
			default:
				return array(
					'meta'          => self::meta(),
					'posts'         => self::posts(),
					'opportunities' => self::opportunities(),
					'links'         => self::links(),
					'site_links'    => self::site_links(),
					'orphans'       => self::orphans(),
					'queue'         => self::queue(),
					'logs'          => self::logs(),
				);
		}
	}

	public static function meta() {
		global $wpdb;

		$settings = AIIL_Settings::all();
		// Never export the secret; just report whether one is configured.
		unset( $settings['api_key'] );
		$settings['api_key_set'] = AIIL_Settings::has_api_key();

		return array(
			'plugin_version'  => AIIL_VERSION,
			'db_version'      => get_option( 'aiil_db_version' ),
			'exported_at'     => current_time( 'mysql' ),
			'site_url'        => home_url(),
			'blog_id'         => get_current_blog_id(),
			'settings'        => $settings,
			'queue_counts'    => AIIL_Queue::counts(),
			'cron_next_run'   => wp_next_scheduled( 'aiil_process_queue' ),
			'cron_disabled'   => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'totals'          => array(
				'published_posts'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type = 'post'" ),
				'indexed'              => (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . AIIL_DB::posts_table() . " WHERE indexed_at IS NOT NULL" ),
				'plugin_active_links'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . AIIL_DB::links_table() . " WHERE status = 'active'" ),
				'scanned_site_links'   => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM " . AIIL_DB::site_links_table() . " WHERE blog_id = %d", get_current_blog_id() ) ),
				'orphans'              => AIIL_Orphans::count(),
			),
			'orphan_source'   => AIIL_Link_Scanner::has_scan() ? 'scanned_site_links' : 'plugin_links_only',
			'last_link_scan'  => AIIL_Link_Scanner::last_scan(),
			'row_cap'         => self::ROW_CAP,
		);
	}

	protected static function posts() {
		global $wpdb;
		// Raw vectors are huge and not useful in an export; omit doc_vector.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.post_id, m.blog_id, m.content_hash, m.passage_count, m.incoming_links,
				        m.outgoing_links, m.max_outgoing_links, m.indexed_at, m.updated_at,
				        p.post_title, p.post_status, p.post_date
				 FROM " . AIIL_DB::posts_table() . " m
				 LEFT JOIN {$wpdb->posts} p ON p.ID = m.post_id
				 WHERE m.blog_id = %d
				 ORDER BY m.post_id ASC
				 LIMIT %d",
				get_current_blog_id(),
				self::ROW_CAP
			),
			ARRAY_A
		);
		foreach ( $rows as &$r ) {
			$r['post_id']        = (int) $r['post_id'];
			$r['passage_count']  = (int) $r['passage_count'];
			$r['incoming_links'] = (int) $r['incoming_links'];
			$r['outgoing_links'] = (int) $r['outgoing_links'];
		}
		unset( $r );
		return $rows;
	}

	protected static function opportunities() {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT o.*, s.post_title AS source_title, t.post_title AS target_title
				 FROM " . AIIL_DB::opportunities_table() . " o
				 LEFT JOIN {$wpdb->posts} s ON s.ID = o.source_post_id
				 LEFT JOIN {$wpdb->posts} t ON t.ID = o.target_post_id
				 ORDER BY o.id ASC
				 LIMIT %d",
				self::ROW_CAP
			),
			ARRAY_A
		);
		// Cast ids to int (wpdb returns all columns as strings) and round FLOAT columns so the
		// JSON is clean and joins/compares correctly against other tools.
		foreach ( $rows as &$r ) {
			$r['id']                 = (int) $r['id'];
			$r['source_post_id']     = (int) $r['source_post_id'];
			$r['target_post_id']     = (int) $r['target_post_id'];
			$r['best_passage_id']    = null === $r['best_passage_id'] ? null : (int) $r['best_passage_id'];
			$r['doc_similarity']     = null === $r['doc_similarity'] ? null : round( (float) $r['doc_similarity'], 2 );
			$r['passage_similarity'] = null === $r['passage_similarity'] ? null : round( (float) $r['passage_similarity'], 2 );
			$r['confidence']         = null === $r['confidence'] ? null : round( (float) $r['confidence'], 2 );
			$r['signals']            = ! empty( $r['signals'] ) ? json_decode( (string) $r['signals'], true ) : null;
		}
		unset( $r );
		return $rows;
	}

	protected static function links() {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.*, s.post_title AS source_title, t.post_title AS target_title
				 FROM " . AIIL_DB::links_table() . " l
				 LEFT JOIN {$wpdb->posts} s ON s.ID = l.source_post_id
				 LEFT JOIN {$wpdb->posts} t ON t.ID = l.target_post_id
				 ORDER BY l.id ASC
				 LIMIT %d",
				self::ROW_CAP
			),
			ARRAY_A
		);
		foreach ( $rows as &$r ) {
			$r['id']             = (int) $r['id'];
			$r['source_post_id'] = (int) $r['source_post_id'];
			$r['target_post_id'] = (int) $r['target_post_id'];
		}
		unset( $r );
		return $rows;
	}

	protected static function site_links() {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT sl.id, sl.source_post_id, sl.target_post_id, sl.anchor_text, sl.scanned_at,
				        s.post_title AS source_title, t.post_title AS target_title
				 FROM " . AIIL_DB::site_links_table() . " sl
				 LEFT JOIN {$wpdb->posts} s ON s.ID = sl.source_post_id
				 LEFT JOIN {$wpdb->posts} t ON t.ID = sl.target_post_id
				 WHERE sl.blog_id = %d
				 ORDER BY sl.source_post_id ASC, sl.target_post_id ASC
				 LIMIT %d",
				get_current_blog_id(),
				self::ROW_CAP
			),
			ARRAY_A
		);
		foreach ( $rows as &$r ) {
			$r['id']             = (int) $r['id'];
			$r['source_post_id'] = (int) $r['source_post_id'];
			$r['target_post_id'] = (int) $r['target_post_id'];
		}
		unset( $r );
		return $rows;
	}

	protected static function orphans() {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.post_id, m.passage_count, m.outgoing_links, m.indexed_at, p.post_title
				 FROM " . AIIL_DB::posts_table() . " m
				 LEFT JOIN {$wpdb->posts} p ON p.ID = m.post_id
				 WHERE m.incoming_links = 0 AND m.blog_id = %d
				 ORDER BY m.post_id ASC
				 LIMIT %d",
				get_current_blog_id(),
				self::ROW_CAP
			),
			ARRAY_A
		);
	}

	protected static function queue() {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM " . AIIL_DB::queue_table() . " ORDER BY id DESC LIMIT %d",
				self::ROW_CAP
			),
			ARRAY_A
		);
	}

	protected static function logs() {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM " . AIIL_DB::logs_table() . " ORDER BY id DESC LIMIT %d",
				self::LOG_CAP
			),
			ARRAY_A
		);
	}
}
