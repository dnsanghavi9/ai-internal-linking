<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIIL_Health {

	public static function run_daily() {
		global $wpdb;

		$links = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM " . AIIL_DB::links_table() . " WHERE status = %s",
				'active'
			)
		);

		$invalidated = 0;
		foreach ( $links as $link ) {
			$target = get_post( (int) $link->target_post_id );
			$valid  = $target && in_array( $target->post_status, array( 'publish' ), true );
			if ( ! $valid ) {
				$wpdb->update(
					AIIL_DB::links_table(),
					array( 'status' => 'broken' ),
					array( 'id' => (int) $link->id )
				);
				// Reflect the broken link in counters so the dashboard and orphan
				// detection stop counting it.
				AIIL_Inserter::bump_link_counts(
					(int) $link->source_post_id,
					(int) $link->target_post_id,
					-1
				);
				$invalidated++;
			}
		}

		AIIL_Logger::purge( 60 );
		AIIL_Queue::purge_completed( 7 );

		AIIL_Logger::info( 'Daily link health check complete', array( 'broken' => $invalidated ) );
	}

	public static function broken_links( $limit = 100 ) {
		global $wpdb;
		$limit = max( 1, (int) $limit );
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM " . AIIL_DB::links_table() . " WHERE status = %s ORDER BY inserted_at DESC LIMIT %d",
				'broken',
				$limit
			)
		);
	}
}
