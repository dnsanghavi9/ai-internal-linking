<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIIL_Deactivator {

	public static function deactivate() {
		$timestamp = wp_next_scheduled( 'aiil_process_queue' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'aiil_process_queue' );
		}
		$timestamp = wp_next_scheduled( 'aiil_link_health_check' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'aiil_link_health_check' );
		}
	}
}
