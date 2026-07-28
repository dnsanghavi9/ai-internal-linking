<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIIL_Logger {

	const LEVEL_INFO    = 'info';
	const LEVEL_WARNING = 'warning';
	const LEVEL_ERROR   = 'error';
	const LEVEL_DEBUG   = 'debug';

	public static function info( $message, $context = array() ) {
		self::write( self::LEVEL_INFO, $message, $context );
	}

	public static function warning( $message, $context = array() ) {
		self::write( self::LEVEL_WARNING, $message, $context );
	}

	public static function error( $message, $context = array() ) {
		self::write( self::LEVEL_ERROR, $message, $context );
	}

	public static function debug( $message, $context = array() ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			self::write( self::LEVEL_DEBUG, $message, $context );
		}
	}

	protected static function write( $level, $message, $context ) {
		global $wpdb;

		$row = array(
			'level'      => $level,
			'message'    => is_scalar( $message ) ? (string) $message : wp_json_encode( $message ),
			'context'    => $context ? wp_json_encode( $context ) : null,
			'created_at' => current_time( 'mysql' ),
		);

		$inserted = $wpdb->insert( AIIL_DB::logs_table(), $row );

		if ( false === $inserted ) {
			// fall back to PHP error log if the table is missing
			error_log( '[AIIL] ' . $level . ': ' . $row['message'] );
		}
	}

	public static function fetch( $limit = 100, $level = null ) {
		global $wpdb;
		$table = AIIL_DB::logs_table();
		$limit = max( 1, (int) $limit );

		if ( $level ) {
			$sql = $wpdb->prepare(
				"SELECT * FROM {$table} WHERE level = %s ORDER BY id DESC LIMIT %d",
				$level,
				$limit
			);
		} else {
			$sql = $wpdb->prepare(
				"SELECT * FROM {$table} ORDER BY id DESC LIMIT %d",
				$limit
			);
		}

		return $wpdb->get_results( $sql );
	}

	public static function purge( $older_than_days = 30 ) {
		global $wpdb;
		$table = AIIL_DB::logs_table();
		$days  = max( 1, (int) $older_than_days );
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE created_at < (NOW() - INTERVAL %d DAY)",
				$days
			)
		);
	}
}
