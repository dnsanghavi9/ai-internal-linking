<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIIL_Hooks {

	public static function register() {
		// cron_schedules is registered at file-load time in the main plugin file.
		add_action( 'aiil_process_queue', array( 'AIIL_Queue', 'process_tick' ) );
		add_action( 'aiil_link_health_check', array( 'AIIL_Health', 'run_daily' ) );

		// Self-heal: make sure the recurring events are scheduled on every load. This
		// recovers installs where activation could not register them.
		add_action( 'init', array( 'AIIL_Activator', 'ensure_scheduled_events' ) );

		add_action( 'save_post', array( __CLASS__, 'on_save_post' ), 20, 3 );
		add_action( 'before_delete_post', array( __CLASS__, 'on_delete_post' ) );
		add_action( 'wp_trash_post', array( __CLASS__, 'on_trash_post' ) );
	}

	public static function register_cron_schedules( $schedules ) {
		if ( ! isset( $schedules['aiil_minute'] ) ) {
			$schedules['aiil_minute'] = array(
				'interval' => 60,
				'display'  => __( 'Every minute (AIIL)', 'ai-internal-linking' ),
			);
		}
		return $schedules;
	}

	public static function on_save_post( $post_id, $post, $update ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( 'post' !== $post->post_type ) {
			return;
		}
		if ( 'publish' !== $post->post_status ) {
			return;
		}
		// Skip re-analysis when the update was triggered by our own link insertion.
		if ( AIIL_Inserter::should_skip_save_hook( $post_id ) ) {
			return;
		}

		// The existing index is kept until the queued index_post job runs and overwrites it
		// (and it is skipped entirely when the content hash is unchanged), so the matcher can
		// still see this post in the meantime. 'auto' marks this as a real publish/edit so the
		// worker can auto-link it (the bulk "Index Existing Posts" scan omits the flag, leaving
		// that initial run under the user's control on the Dashboard).
		AIIL_Queue::enqueue( AIIL_Queue::JOB_INDEX_POST, (int) $post_id, array( 'auto' => 1 ) );
	}

	/**
	 * Soft delete (trash). The post still exists and may be restored, so we keep its
	 * metadata and inserted-link history — we only cancel not-yet-acted-on work so the
	 * queue and reviewer don't operate on a now-unpublished post. Links pointing to it
	 * are invalidated by the daily health check.
	 */
	public static function on_trash_post( $post_id ) {
		global $wpdb;
		$post_id = (int) $post_id;

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM " . AIIL_DB::opportunities_table() . "
				 WHERE ( source_post_id = %d OR target_post_id = %d )
				   AND status IN (%s, %s, %s)",
				$post_id,
				$post_id,
				'pending',
				'ready',
				'inserting'
			)
		);

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM " . AIIL_DB::queue_table() . " WHERE post_id = %d AND status IN (%s, %s)",
				$post_id,
				AIIL_Queue::STATUS_PENDING,
				AIIL_Queue::STATUS_PROCESSING
			)
		);
	}

	/**
	 * Permanent delete. The post row is about to disappear, so remove every reference
	 * to it and fix the link counters on the surviving endpoints.
	 */
	public static function on_delete_post( $post_id ) {
		global $wpdb;
		$post_id = (int) $post_id;

		// Decrement counters on the surviving endpoint of each active link first.
		$links = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT source_post_id, target_post_id FROM " . AIIL_DB::links_table() . "
				 WHERE ( source_post_id = %d OR target_post_id = %d ) AND status = %s",
				$post_id,
				$post_id,
				'active'
			)
		);
		foreach ( $links as $link ) {
			AIIL_Inserter::bump_link_counts( (int) $link->source_post_id, (int) $link->target_post_id, -1 );
		}

		$wpdb->query( $wpdb->prepare( "DELETE FROM " . AIIL_DB::links_table() . " WHERE source_post_id = %d OR target_post_id = %d", $post_id, $post_id ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM " . AIIL_DB::site_links_table() . " WHERE source_post_id = %d OR target_post_id = %d", $post_id, $post_id ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM " . AIIL_DB::opportunities_table() . " WHERE source_post_id = %d OR target_post_id = %d", $post_id, $post_id ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM " . AIIL_DB::queue_table() . " WHERE post_id = %d", $post_id ) );

		AIIL_Indexer::delete( $post_id );
	}
}
