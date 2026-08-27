<?php
/**
 * Plugin Name:       AI Internal Linking
 * Description:       Retrieval-based internal linking. Embeds every post at the passage level, matches posts by semantic similarity, and grounds each link anchor in a real source sentence. No topic lists, no per-niche configuration.
 * Version:           2.10.2
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Sanghavi Devarsh
 * License:           GPL-2.0-or-later
 * Text Domain:       ai-internal-linking
 * Update URI:        https://github.com/dnsanghavi9/ai-internal-linking
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AIIL_VERSION', '2.10.2' );
define( 'AIIL_PLUGIN_FILE', __FILE__ );
define( 'AIIL_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AIIL_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'AIIL_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Where self-updates come from. Set this to your repo ("owner/repo") to enable updating.
 * To move hosting later, ship one release from GitHub that swaps this for
 * AIIL_UPDATE_JSON_URL (or use the `aiil_update_source` filter) — see class-aiil-updater.php.
 */
if ( ! defined( 'AIIL_GITHUB_REPO' ) ) {
	define( 'AIIL_GITHUB_REPO', 'dnsanghavi9/ai-internal-linking' );
}

require_once AIIL_PLUGIN_DIR . 'includes/class-aiil-updater.php';
require_once AIIL_PLUGIN_DIR . 'includes/class-aiil-db.php';
require_once AIIL_PLUGIN_DIR . 'includes/class-aiil-activator.php';
require_once AIIL_PLUGIN_DIR . 'includes/class-aiil-deactivator.php';
require_once AIIL_PLUGIN_DIR . 'includes/class-aiil-settings.php';
require_once AIIL_PLUGIN_DIR . 'includes/class-aiil-status.php';
require_once AIIL_PLUGIN_DIR . 'includes/class-aiil-logger.php';
require_once AIIL_PLUGIN_DIR . 'includes/class-aiil-vector.php';
require_once AIIL_PLUGIN_DIR . 'includes/class-aiil-queue.php';
require_once AIIL_PLUGIN_DIR . 'includes/class-aiil-usage.php';
require_once AIIL_PLUGIN_DIR . 'includes/providers/interface-aiil-provider.php';
require_once AIIL_PLUGIN_DIR . 'includes/providers/class-aiil-gemini-provider.php';
require_once AIIL_PLUGIN_DIR . 'includes/class-aiil-indexer.php';
require_once AIIL_PLUGIN_DIR . 'includes/class-aiil-idf.php';
require_once AIIL_PLUGIN_DIR . 'includes/class-aiil-matcher.php';
require_once AIIL_PLUGIN_DIR . 'includes/class-aiil-placement.php';
require_once AIIL_PLUGIN_DIR . 'includes/class-aiil-inserter.php';
require_once AIIL_PLUGIN_DIR . 'includes/class-aiil-reranker.php';
require_once AIIL_PLUGIN_DIR . 'includes/class-aiil-link-scanner.php';
require_once AIIL_PLUGIN_DIR . 'includes/class-aiil-orphans.php';
require_once AIIL_PLUGIN_DIR . 'includes/class-aiil-graph.php';
require_once AIIL_PLUGIN_DIR . 'includes/class-aiil-export.php';
require_once AIIL_PLUGIN_DIR . 'includes/class-aiil-eval.php';
require_once AIIL_PLUGIN_DIR . 'includes/class-aiil-health.php';
require_once AIIL_PLUGIN_DIR . 'includes/class-aiil-hooks.php';
require_once AIIL_PLUGIN_DIR . 'includes/class-aiil-plugin.php';
require_once AIIL_PLUGIN_DIR . 'admin/class-aiil-admin.php';

// Register the custom cron schedule at file-load so it exists during activation.
add_filter( 'cron_schedules', array( 'AIIL_Hooks', 'register_cron_schedules' ) );

register_activation_hook( __FILE__, array( 'AIIL_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'AIIL_Deactivator', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'AIIL_Plugin', 'instance' ) );

// Self-updating (no-op until AIIL_GITHUB_REPO / AIIL_UPDATE_JSON_URL is set).
AIIL_Updater::init();
