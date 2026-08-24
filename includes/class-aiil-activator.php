<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIIL_Activator {

	public static function activate() {
		self::drop_legacy_schema();
		self::install_schema();
		AIIL_Settings::seed_defaults();
		self::ensure_scheduled_events();
		update_option( 'aiil_db_version', AIIL_VERSION );
	}

	/**
	 * Run on every admin load. Re-applies the schema and re-asserts cron events when
	 * the stored DB version is behind the plugin version (e.g. after an update where
	 * the activation hook does not fire).
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'aiil_db_version' ) === AIIL_VERSION ) {
			return;
		}

		self::drop_legacy_schema();
		self::install_schema();
		self::ensure_scheduled_events();
		self::migrate_price_defaults();
		update_option( 'aiil_db_version', AIIL_VERSION );

		AIIL_Logger::info( 'Database schema upgraded', array( 'version' => AIIL_VERSION ) );
	}

	/**
	 * Move sites still on the original (placeholder) Cost-tab rates onto the gemini-3.1-flash-lite
	 * rates. A stored value is only replaced when it still exactly equals the old default — if you
	 * have edited a rate for your own account/region it is left alone. Rates are display-only, so
	 * this re-prices the Cost tab and changes nothing about recorded usage.
	 */
	protected static function migrate_price_defaults() {
		$settings = get_option( AIIL_Settings::OPTION_KEY, array() );
		if ( ! is_array( $settings ) ) {
			return;
		}

		$moves = array(
			// key => [ old default, new default ]
			'price_gen_in_per_m'       => array( 0.10, 0.25 ),
			'price_gen_out_per_m'      => array( 0.40, 1.5 ),
			'price_gen_in_flex_per_m'  => array( 0.05, 0.125 ),
			'price_gen_out_flex_per_m' => array( 0.20, 0.75 ),
		);

		$changed = false;
		foreach ( $moves as $key => $pair ) {
			if ( isset( $settings[ $key ] ) && abs( (float) $settings[ $key ] - $pair[0] ) < 0.0000001 ) {
				$settings[ $key ] = $pair[1];
				$changed          = true;
			}
		}

		if ( $changed ) {
			update_option( AIIL_Settings::OPTION_KEY, $settings );
			AIIL_Logger::info( 'Cost tab rates updated to gemini-3.1-flash-lite pricing (customised rates kept)' );
		}
	}

	/**
	 * V2 replaced the lexical schema (topics/importance/similarity_score) with an embedding
	 * schema. The table names are shared, and dbDelta never drops columns, so a V1 install
	 * would keep incompatible NOT NULL columns that break V2 inserts. When we detect the old
	 * schema we drop the plugin tables so install_schema() can rebuild them cleanly. Settings
	 * and inserted links in the post content are untouched.
	 */
	protected static function drop_legacy_schema() {
		global $wpdb;

		$posts   = AIIL_DB::posts_table();
		$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$posts}" ); // empty if the table doesn't exist
		$legacy  = is_array( $columns ) && (
			in_array( 'topics_json', $columns, true ) ||
			in_array( 'primary_topic', $columns, true ) ||
			in_array( 'analyzed_at', $columns, true )
		);
		if ( ! $legacy ) {
			return;
		}

		foreach ( array(
			AIIL_DB::posts_table(),
			AIIL_DB::passages_table(),
			AIIL_DB::opportunities_table(),
			AIIL_DB::links_table(),
			AIIL_DB::queue_table(),
			AIIL_DB::logs_table(),
		) as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		}
		// No logging here: the logs table has just been dropped and will be recreated next.
	}

	protected static function install_schema() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( AIIL_DB::schema() as $sql ) {
			dbDelta( $sql );
		}
	}

	/**
	 * Make sure our cron events exist. Safe to call on every request — the
	 * wp_next_scheduled() guards make it a no-op once the events are registered.
	 * This self-heals installs where the events were never scheduled (e.g. the
	 * schedule was unavailable at activation time).
	 */
	public static function ensure_scheduled_events() {
		if ( ! wp_next_scheduled( 'aiil_process_queue' ) ) {
			wp_schedule_event( time() + 60, 'aiil_minute', 'aiil_process_queue' );
		}
		if ( ! wp_next_scheduled( 'aiil_link_health_check' ) ) {
			wp_schedule_event( time() + 3600, 'daily', 'aiil_link_health_check' );
		}
	}
}
