<?php
/**
 * Drops AIIL tables and options when the plugin is deleted.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$tables = array(
	$wpdb->prefix . 'aiil_posts',
	$wpdb->prefix . 'aiil_passages',
	$wpdb->prefix . 'aiil_link_opportunities',
	$wpdb->prefix . 'aiil_links',
	$wpdb->prefix . 'aiil_site_links',
	$wpdb->prefix . 'aiil_queue',
	$wpdb->prefix . 'aiil_logs',
	$wpdb->prefix . 'aiil_usage',
);

foreach ( $tables as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
}

delete_option( 'aiil_settings' );
delete_option( 'aiil_db_version' );

// Placement stashes are stored as transients; remove any that survived.
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '_transient_aiil_placement_%'
	    OR option_name LIKE '_transient_timeout_aiil_placement_%'"
);

foreach ( array( 'aiil_process_queue', 'aiil_link_health_check' ) as $hook ) {
	$timestamp = wp_next_scheduled( $hook );
	while ( $timestamp ) {
		wp_unschedule_event( $timestamp, $hook );
		$timestamp = wp_next_scheduled( $hook );
	}
}
