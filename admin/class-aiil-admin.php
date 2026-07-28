<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIIL_Admin {

	const CAPABILITY = 'manage_options';
	const MENU_SLUG  = 'aiil';
	const NONCE      = 'aiil_action';

	protected static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	protected function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_aiil_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_post_aiil_initial_scan', array( $this, 'handle_initial_scan' ) );
		add_action( 'admin_post_aiil_index_post', array( $this, 'handle_index_post' ) );
		add_action( 'admin_post_aiil_opportunity_action', array( $this, 'handle_opportunity_action' ) );
		add_action( 'admin_post_aiil_orphan_find', array( $this, 'handle_orphan_find' ) );
		add_action( 'admin_post_aiil_orphan_find_all', array( $this, 'handle_orphan_find_all' ) );
		add_action( 'admin_post_aiil_scan_links', array( $this, 'handle_scan_links' ) );
		add_action( 'admin_post_aiil_run_queue', array( $this, 'handle_run_queue' ) );
		add_action( 'admin_post_aiil_export', array( $this, 'handle_export' ) );
		add_action( 'admin_post_aiil_reset_data', array( $this, 'handle_reset_data' ) );
		add_action( 'admin_post_aiil_reevaluate', array( $this, 'handle_reevaluate' ) );
		add_action( 'admin_post_aiil_rebuild_matches', array( $this, 'handle_rebuild_matches' ) );
		add_action( 'admin_post_aiil_reapply_thresholds', array( $this, 'handle_reapply_thresholds' ) );
		add_action( 'wp_ajax_aiil_process_batch', array( $this, 'ajax_process_batch' ) );
		add_action( 'wp_ajax_aiil_opportunity_ajax', array( $this, 'ajax_opportunity_action' ) );
		add_action( 'wp_ajax_aiil_prepare_all', array( $this, 'ajax_prepare_all' ) );
		add_action( 'wp_ajax_aiil_verify_all', array( $this, 'ajax_verify_all' ) );
		add_action( 'wp_ajax_aiil_graph_data', array( $this, 'ajax_graph_data' ) );
		add_action( 'wp_ajax_aiil_insert_all', array( $this, 'ajax_insert_all' ) );
		add_action( 'wp_ajax_aiil_index_enqueue', array( $this, 'ajax_index_enqueue' ) );
	}

	public function register_menu() {
		add_menu_page(
			__( 'AI Internal Linking', 'ai-internal-linking' ),
			__( 'AI Linking', 'ai-internal-linking' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render_dashboard' ),
			'dashicons-admin-links',
			58
		);

		$pages = array(
			array( 'dashboard',          __( 'Dashboard', 'ai-internal-linking' ),      'render_dashboard' ),
			array( 'link-opportunities', __( 'Review Links', 'ai-internal-linking' ),   'render_link_opportunities' ),
			array( 'inserted-links',     __( 'Live Links', 'ai-internal-linking' ),     'render_inserted_links' ),
			array( 'knowledge-graph',    __( 'Knowledge Graph', 'ai-internal-linking' ), 'render_knowledge_graph' ),
			array( 'orphan-pages',       __( 'Orphans', 'ai-internal-linking' ),        'render_orphan_pages' ),
			array( 'posts-analysis',     __( 'Indexed Posts', 'ai-internal-linking' ),  'render_posts_analysis' ),
			array( 'settings',           __( 'Settings', 'ai-internal-linking' ),       'render_settings' ),
			array( 'logs',               __( 'Logs', 'ai-internal-linking' ),           'render_logs' ),
		);

		foreach ( $pages as $page ) {
			list( $slug, $title, $cb ) = $page;
			add_submenu_page(
				self::MENU_SLUG,
				$title,
				$title,
				self::CAPABILITY,
				'dashboard' === $slug ? self::MENU_SLUG : self::MENU_SLUG . '-' . $slug,
				array( $this, $cb )
			);
		}
	}

	public function enqueue_assets( $hook ) {
		if ( false === strpos( (string) $hook, self::MENU_SLUG ) ) {
			return;
		}
		$css_path = AIIL_PLUGIN_DIR . 'admin/css/admin.css';
		$js_path  = AIIL_PLUGIN_DIR . 'admin/js/admin.js';
		// Version by file mtime so browsers pick up JS/CSS changes immediately instead of
		// serving a stale cached copy under a fixed ?ver=.
		$css_ver = file_exists( $css_path ) ? filemtime( $css_path ) : AIIL_VERSION;
		$js_ver  = file_exists( $js_path ) ? filemtime( $js_path ) : AIIL_VERSION;

		wp_enqueue_style( 'aiil-admin', AIIL_PLUGIN_URL . 'admin/css/admin.css', array(), $css_ver );
		wp_enqueue_script( 'aiil-admin', AIIL_PLUGIN_URL . 'admin/js/admin.js', array( 'jquery' ), $js_ver, true );

		// The graph renderer is heavy and only needed on its own page.
		if ( false !== strpos( (string) $hook, 'knowledge-graph' ) ) {
			$graph_path = AIIL_PLUGIN_DIR . 'admin/js/graph.js';
			$graph_ver  = file_exists( $graph_path ) ? filemtime( $graph_path ) : AIIL_VERSION;
			wp_enqueue_script( 'aiil-graph', AIIL_PLUGIN_URL . 'admin/js/graph.js', array( 'aiil-admin' ), $graph_ver, true );
		}
		wp_localize_script(
			'aiil-admin',
			'AIIL',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
				'i18n'    => array(
					'working' => __( 'Processing…', 'ai-internal-linking' ),
					'done'    => __( 'All jobs processed.', 'ai-internal-linking' ),
					'error'   => __( 'Something went wrong. Check the Logs tab.', 'ai-internal-linking' ),
					'resume'  => __( 'Resume Processing', 'ai-internal-linking' ),
					'remaining' => __( 'remaining', 'ai-internal-linking' ),
				),
			)
		);
	}

	protected function check_cap() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'ai-internal-linking' ) );
		}
	}

	public function render_dashboard() {
		$this->check_cap();
		include AIIL_PLUGIN_DIR . 'admin/views/dashboard.php';
	}

	public function render_posts_analysis() {
		$this->check_cap();
		include AIIL_PLUGIN_DIR . 'admin/views/posts-analysis.php';
	}

	public function render_link_opportunities() {
		$this->check_cap();
		include AIIL_PLUGIN_DIR . 'admin/views/link-opportunities.php';
	}

	public function render_knowledge_graph() {
		$this->check_cap();
		include AIIL_PLUGIN_DIR . 'admin/views/knowledge-graph.php';
	}

	public function render_inserted_links() {
		$this->check_cap();
		include AIIL_PLUGIN_DIR . 'admin/views/inserted-links.php';
	}

	public function render_orphan_pages() {
		$this->check_cap();
		include AIIL_PLUGIN_DIR . 'admin/views/orphan-pages.php';
	}

	public function render_settings() {
		$this->check_cap();
		include AIIL_PLUGIN_DIR . 'admin/views/settings.php';
	}

	public function render_logs() {
		$this->check_cap();
		include AIIL_PLUGIN_DIR . 'admin/views/logs.php';
	}

	public function handle_save_settings() {
		$this->check_cap();
		check_admin_referer( self::NONCE );

		$input = wp_unslash( $_POST['aiil'] ?? array() );
		AIIL_Settings::update( is_array( $input ) ? $input : array() );

		$this->redirect( 'settings', array( 'updated' => 1 ) );
	}

	public function handle_initial_scan() {
		$this->check_cap();
		check_admin_referer( self::NONCE );

		// Indexing already skips posts whose content hash is unchanged, so there is no
		// separate "force" path — enqueue every published post and let the worker decide.
		$count = AIIL_Indexer::enqueue_all();
		$this->redirect( '', array( 'queued' => $count ) );
	}

	public function handle_index_post() {
		$this->check_cap();
		check_admin_referer( self::NONCE );

		$post_id = (int) ( $_POST['post_id'] ?? 0 );
		if ( $post_id ) {
			AIIL_Queue::enqueue( AIIL_Queue::JOB_INDEX_POST, $post_id );
		}
		$this->redirect( 'posts-analysis', array( 'queued' => 1 ) );
	}

	public function handle_opportunity_action() {
		$this->check_cap();
		check_admin_referer( self::NONCE );

		$opportunity_id = (int) ( $_POST['opportunity_id'] ?? 0 );
		$action         = sanitize_text_field( wp_unslash( $_POST['op'] ?? '' ) );
		$anchor         = isset( $_POST['anchor_text'] ) ? sanitize_text_field( wp_unslash( $_POST['anchor_text'] ) ) : null;
		$status_key     = 'msg';

		try {
			switch ( $action ) {
				case 'prepare':
					// Placement is deterministic (no API call unless AI refinement is on),
					// so run it synchronously for instant feedback.
					if ( $opportunity_id ) {
						$res          = AIIL_Placement::process_opportunity( $opportunity_id );
						$status_value = $res ? 'anchor ready' : 'no natural anchor found';
					} else {
						$status_value = 'unknown';
					}
					break;
				case 'approve':
					AIIL_Inserter::insert_for_opportunity( $opportunity_id, $anchor );
					$status_value = 'inserted';
					break;
				case 'reject':
					AIIL_Inserter::reject_opportunity( $opportunity_id );
					$status_value = 'rejected';
					break;
				default:
					$status_value = 'unknown';
			}
		} catch ( Exception $e ) {
			AIIL_Logger::error( 'Opportunity action failed', array( 'error' => $e->getMessage(), 'opportunity_id' => $opportunity_id ) );
			$status_value = 'error';
		}

		$this->redirect( 'link-opportunities', array( $status_key => $status_value ) );
	}

	public function handle_orphan_find() {
		$this->check_cap();
		check_admin_referer( self::NONCE );

		$post_id = (int) ( $_POST['post_id'] ?? 0 );
		$found   = $post_id ? AIIL_Orphans::find_opportunities_for( $post_id ) : 0;
		$this->redirect( 'orphan-pages', array( 'found' => $found ) );
	}

	public function handle_scan_links() {
		$this->check_cap();
		check_admin_referer( 'aiil_scan_links' );

		$res = AIIL_Link_Scanner::scan_all();
		$this->redirect(
			'orphan-pages',
			array(
				'scanned_posts' => (int) $res['posts'],
				'scanned_links' => (int) $res['links'],
				'scan_orphans'  => (int) $res['orphans'],
			)
		);
	}

	public function handle_orphan_find_all() {
		$this->check_cap();
		check_admin_referer( self::NONCE );

		$res = AIIL_Orphans::find_opportunities_all();
		$this->redirect(
			'orphan-pages',
			array(
				'found_all' => (int) $res['created'],
				'scanned'   => (int) $res['processed'],
			)
		);
	}

	public function handle_reevaluate() {
		$this->check_cap();
		check_admin_referer( 'aiil_reevaluate' );

		global $wpdb;
		// Reset non-terminal opportunities back to pending so they are re-prepared under the
		// current relevance rules. Inserted and user-rejected rows are left untouched.
		$count = $wpdb->query(
			$wpdb->prepare(
				"UPDATE " . AIIL_DB::opportunities_table() . "
				 SET status = %s, anchor_text = NULL, confidence = NULL, passage_similarity = NULL, best_passage_id = NULL
				 WHERE status IN ( 'ready', 'verified', 'rewrite_suggested', 'no_anchor', 'low_relevance', 'reciprocal', 'capped', 'rejected_relevance', 'error' )",
				'pending'
			)
		);

		AIIL_Logger::info( 'Re-evaluated opportunities', array( 'reset_to_pending' => (int) $count ) );
		$this->redirect( 'link-opportunities', array( 'status' => 'pending', 'reevaluated' => (int) $count ) );
	}

	public function handle_reapply_thresholds() {
		$this->check_cap();
		check_admin_referer( 'aiil_reapply_thresholds' );

		$res = AIIL_Reranker::reapply_thresholds();
		$this->redirect(
			'link-opportunities',
			array( 'status' => 'verified', 'reapplied' => (int) $res['verified'] )
		);
	}

	public function handle_rebuild_matches() {
		$this->check_cap();
		check_admin_referer( 'aiil_rebuild_matches' );

		global $wpdb;
		// Drop undecided opportunities so stale pairs from the previous retrieval disappear.
		// User decisions (inserted / rejected) are preserved.
		$wpdb->query(
			"DELETE FROM " . AIIL_DB::opportunities_table() . "
			 WHERE status IN ( 'pending', 'ready', 'verified', 'rewrite_suggested', 'low_relevance', 'no_anchor', 'reciprocal', 'capped', 'rejected_relevance', 'invalid' )"
		);

		AIIL_Matcher::flush_cache();

		// Re-match every indexed post from stored embeddings — no AI calls involved.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM " . AIIL_DB::posts_table() . " WHERE blog_id = %d AND indexed_at IS NOT NULL",
				get_current_blog_id()
			)
		);
		foreach ( $ids as $pid ) {
			AIIL_Queue::enqueue( AIIL_Queue::JOB_GENERATE_MATCHES, (int) $pid );
		}

		AIIL_Logger::info( 'Rebuild matching enqueued', array( 'posts' => count( $ids ) ) );
		$this->redirect( 'link-opportunities', array( 'rebuilt' => count( $ids ) ) );
	}

	public function handle_reset_data() {
		$this->check_cap();
		check_admin_referer( 'aiil_reset_data' );

		AIIL_DB::reset_data();
		AIIL_Logger::info( 'Plugin data reset (testing tool)' );

		$this->redirect( 'settings', array( 'reset' => 1 ) );
	}

	public function handle_export() {
		$this->check_cap();
		check_admin_referer( 'aiil_export' );

		$type = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : 'all';
		if ( ! in_array( $type, AIIL_Export::types(), true ) ) {
			$type = 'all';
		}

		$data     = AIIL_Export::dataset( $type );
		$filename = 'aiil-' . $type . '-' . gmdate( 'Ymd-His' ) . '.json';

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		echo wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		exit;
	}

	/**
	 * Returns a nonce-protected "Export JSON" button linking to the export handler.
	 */
	public static function export_button( $type, $label = null ) {
		$label = $label ? $label : __( 'Export JSON', 'ai-internal-linking' );
		$url   = wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'aiil_export',
					'type'   => $type,
				),
				admin_url( 'admin-post.php' )
			),
			'aiil_export'
		);
		return '<a href="' . esc_url( $url ) . '" class="button">' . esc_html( $label ) . '</a>';
	}

	public function handle_run_queue() {
		$this->check_cap();
		check_admin_referer( self::NONCE );

		// No-JS fallback: drain a bounded batch synchronously.
		$processed = AIIL_Queue::process_tick( 20 );

		$this->redirect( '', array( 'processed' => $processed ) );
	}

	/**
	 * AJAX: run a single opportunity action (prepare/approve/reject) and return the row's
	 * fresh state so the browser can update it in place — no full-page reload.
	 */
	public function ajax_opportunity_action() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}
		check_ajax_referer( self::NONCE, 'nonce' );

		$opportunity_id = (int) ( $_POST['opportunity_id'] ?? 0 );
		$op             = sanitize_text_field( wp_unslash( $_POST['op'] ?? '' ) );
		$anchor         = isset( $_POST['anchor_text'] ) ? sanitize_text_field( wp_unslash( $_POST['anchor_text'] ) ) : null;

		if ( ! $opportunity_id ) {
			wp_send_json_error( array( 'message' => 'missing opportunity id' ) );
		}

		try {
			switch ( $op ) {
				case 'prepare':
					AIIL_Placement::process_opportunity( $opportunity_id );
					break;
				case 'approve':
					AIIL_Inserter::insert_for_opportunity( $opportunity_id, $anchor );
					break;
				case 'reject':
					AIIL_Inserter::reject_opportunity( $opportunity_id );
					break;
				default:
					wp_send_json_error( array( 'message' => 'unknown action' ) );
			}
		} catch ( Exception $e ) {
			AIIL_Logger::error( 'Opportunity action failed', array( 'error' => $e->getMessage(), 'opportunity_id' => $opportunity_id ) );
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}

		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT status, anchor_text, confidence FROM " . AIIL_DB::opportunities_table() . " WHERE id = %d", $opportunity_id )
		);

		wp_send_json_success(
			array(
				'id'          => $opportunity_id,
				'status'      => $row ? $row->status : 'deleted',
				'anchor_text' => $row ? (string) $row->anchor_text : '',
				'confidence'  => ( $row && null !== $row->confidence ) ? (float) $row->confidence : null,
			)
		);
	}

	/**
	 * AJAX: prepare anchors for a batch of pending opportunities. The browser calls this
	 * repeatedly until none remain. Batch size is small when AI refinement is on (each
	 * call hits the API) and larger when placement is purely deterministic.
	 */
	public function ajax_prepare_all() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}
		check_ajax_referer( self::NONCE, 'nonce' );

		global $wpdb;
		$table = AIIL_DB::opportunities_table();
		$limit = ( (int) AIIL_Settings::get( 'use_ai_anchor', 0 ) === 1 ) ? 5 : 25;

		// process_opportunity() moves each row out of 'pending', so re-selecting the top
		// pending rows each call walks the whole set without an OFFSET.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE status = %s ORDER BY doc_similarity DESC, id ASC LIMIT %d",
				'pending',
				$limit
			)
		);

		$prepared  = 0;
		$no_anchor = 0;
		foreach ( $ids as $id ) {
			$res = AIIL_Placement::process_opportunity( (int) $id );
			if ( $res ) {
				$prepared++;
			} else {
				$no_anchor++;
			}
		}

		$remaining = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", 'pending' )
		);

		// When the whole pending set is prepared, finalize — but ONLY when AI verification is off.
		// With rerank on, reciprocal blocking and the per-source cap are applied during the verify
		// pass (so a direction is never blocked/capped before it has been judged).
		$capped   = 0;
		$rerank   = (int) AIIL_Settings::get( 'use_ai_rerank', 0 ) === 1;
		if ( 0 === $remaining && ! $rerank ) {
			$fin    = AIIL_Placement::finalize_ready();
			$capped = (int) $fin['capped'];
		}

		wp_send_json_success(
			array(
				'processed'   => count( $ids ),
				'prepared'    => $prepared,
				'no_anchor'   => $no_anchor,
				'remaining'   => $remaining,
				'capped'      => $capped,
				'needsVerify' => ( 0 === $remaining && $rerank ),
			)
		);
	}

	/**
	 * AJAX: verify a batch of ready links with the AI reranker. The browser calls this
	 * repeatedly until no ready links remain. Only meaningful when use_ai_rerank is on.
	 */
	public function ajax_verify_all() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( (int) AIIL_Settings::get( 'use_ai_rerank', 0 ) !== 1 ) {
			wp_send_json_error( array( 'message' => 'AI verification is disabled in Settings.' ) );
		}
		if ( ! AIIL_Settings::has_api_key() ) {
			wp_send_json_error( array( 'message' => 'No API key configured.' ) );
		}

		$res = AIIL_Reranker::verify_batch( 5 );

		// When no ready links remain, run a safety pass: resolve any pair that ended up verified
		// in both directions, and enforce the cap on the verified set. Both are normally handled
		// inline during verification; this just guarantees the final state is consistent.
		if ( 0 === (int) $res['remaining'] ) {
			$res['reciprocal_final'] = AIIL_Placement::resolve_reciprocal( 'verified' );
			$res['capped_final']     = AIIL_Placement::enforce_source_caps( 'verified' );
		}

		wp_send_json_success( $res );
	}

	/**
	 * AJAX: enqueue every published post for indexing. Used by the one-click dashboard
	 * pipeline to start phase 1 without a page navigation. Idempotent — indexing skips posts
	 * whose content is unchanged.
	 */
	public function ajax_index_enqueue() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! AIIL_Settings::has_api_key() ) {
			wp_send_json_error( array( 'message' => 'Add your Gemini API key in Settings first.' ) );
		}

		$queued = AIIL_Indexer::enqueue_all();
		$counts = AIIL_Queue::counts();
		wp_send_json_success(
			array(
				'queued'    => (int) $queued,
				'remaining' => (int) $counts[ AIIL_Queue::STATUS_PENDING ] + (int) $counts[ AIIL_Queue::STATUS_PROCESSING ],
			)
		);
	}

	/**
	 * AJAX: insert a batch of ready/verified links. The browser calls this repeatedly until
	 * none remain, so the whole "Ready to insert" set can be applied from one button.
	 */
	public function ajax_insert_all() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}
		check_ajax_referer( self::NONCE, 'nonce' );

		global $wpdb;
		$t   = AIIL_DB::opportunities_table();
		$ids = $wpdb->get_col(
			"SELECT id FROM {$t} WHERE status IN ('verified','ready') ORDER BY confidence DESC, id ASC LIMIT 10"
		);

		$inserted = 0;
		$failed   = 0;
		foreach ( $ids as $id ) {
			try {
				AIIL_Inserter::insert_for_opportunity( (int) $id );
				$inserted++;
			} catch ( Exception $e ) {
				$failed++;
				AIIL_Logger::warning( 'Bulk insert skipped one link', array( 'opportunity_id' => (int) $id, 'error' => $e->getMessage() ) );
			}
		}

		$remaining = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE status IN ('verified','ready')" );

		// Stop when nothing was inserted this round (all that remain are failing) to avoid a loop.
		wp_send_json_success(
			array(
				'processed' => count( $ids ),
				'inserted'  => $inserted,
				'failed'    => $failed,
				'remaining' => ( 0 === $inserted ) ? 0 : $remaining,
				'stuck'     => ( 0 === $inserted && $remaining > 0 ) ? $remaining : 0,
			)
		);
	}

	/**
	 * AJAX: knowledge-graph node/edge data for the current filters.
	 */
	public function ajax_graph_data() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}
		check_ajax_referer( self::NONCE, 'nonce' );

		$data = AIIL_Graph::data(
			array(
				'min_sim'   => isset( $_GET['min_sim'] ) ? (float) $_GET['min_sim'] : null,
				'edge_type' => isset( $_GET['edge_type'] ) ? sanitize_key( wp_unslash( $_GET['edge_type'] ) ) : 'semantic',
				'orphans'   => ! isset( $_GET['orphans'] ) || '0' !== (string) $_GET['orphans'],
			)
		);
		wp_send_json_success( $data );
	}

	/**
	 * AJAX: process one small batch and report what's left. The browser calls this
	 * repeatedly to drain the queue with a progress bar — no cron required.
	 */
	public function ajax_process_batch() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}
		check_ajax_referer( self::NONCE, 'nonce' );

		$processed = AIIL_Queue::process_tick( 5 );

		$counts    = AIIL_Queue::counts();
		$remaining = (int) $counts[ AIIL_Queue::STATUS_PENDING ] + (int) $counts[ AIIL_Queue::STATUS_PROCESSING ];

		wp_send_json_success(
			array(
				'processed' => (int) $processed,
				'remaining' => $remaining,
				'counts'    => $counts,
			)
		);
	}

	protected function redirect( $sub, $args = array() ) {
		$slug   = $sub ? self::MENU_SLUG . '-' . $sub : self::MENU_SLUG;
		$target = add_query_arg( array_merge( array( 'page' => $slug ), $args ), admin_url( 'admin.php' ) );
		wp_safe_redirect( $target );
		exit;
	}

	public static function url( $sub = '', $args = array() ) {
		$slug = $sub ? self::MENU_SLUG . '-' . $sub : self::MENU_SLUG;
		return add_query_arg( array_merge( array( 'page' => $slug ), $args ), admin_url( 'admin.php' ) );
	}
}
