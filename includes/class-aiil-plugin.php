<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIIL_Plugin {

	protected static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	protected function __construct() {
		load_plugin_textdomain( 'ai-internal-linking', false, dirname( AIIL_PLUGIN_BASENAME ) . '/languages' );

		AIIL_Hooks::register();

		if ( is_admin() ) {
			add_action( 'admin_init', array( 'AIIL_Activator', 'maybe_upgrade' ) );
			AIIL_Admin::instance();
		}
	}
}
