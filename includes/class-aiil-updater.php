<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Self-updating support for a plugin distributed outside the wordpress.org directory.
 *
 * WordPress discovers updates through the `update_plugins` site transient, so all we do is
 * inject our own release info into it. Once that is there, everything native works: the
 * update badge, "Update now", and the per-plugin "Enable auto-updates" toggle.
 *
 * The release SOURCE is a swappable adapter so you can move hosting later without a rewrite:
 *
 *   - GitHub releases (default):  define( 'AIIL_GITHUB_REPO', 'owner/repo' );
 *   - Your own endpoint:          define( 'AIIL_UPDATE_JSON_URL', 'https://example.com/aiil.json' );
 *
 * or override entirely with the `aiil_update_source` filter. A JSON endpoint must return:
 * { "version": "2.6.0", "download_url": "https://…/aiil.zip", "changelog": "…",
 *   "homepage": "…", "requires": "5.8", "tested": "6.5", "requires_php": "7.4" }
 *
 * NOTE: whoever can publish a release can run code on every site using this plugin, so the
 * source must always be a repo/server you control, over HTTPS.
 */
class AIIL_Updater {

	const CACHE_KEY = 'aiil_update_check';
	const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	public static function init() {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject_update' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_details' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'fix_source_dir' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'flush' ), 10, 0 );
	}

	/**
	 * Where releases come from. Swap hosting by defining a constant or filtering this.
	 *
	 * @return array{type:string,repo?:string,url?:string}
	 */
	public static function source() {
		$source = array( 'type' => 'none' );

		if ( defined( 'AIIL_UPDATE_JSON_URL' ) && AIIL_UPDATE_JSON_URL ) {
			$source = array( 'type' => 'json', 'url' => AIIL_UPDATE_JSON_URL );
		} elseif ( defined( 'AIIL_GITHUB_REPO' ) && AIIL_GITHUB_REPO ) {
			$source = array( 'type' => 'github', 'repo' => AIIL_GITHUB_REPO );
		}

		return (array) apply_filters( 'aiil_update_source', $source );
	}

	protected static function basename() {
		return defined( 'AIIL_PLUGIN_BASENAME' ) ? AIIL_PLUGIN_BASENAME : plugin_basename( AIIL_PLUGIN_FILE );
	}

	/** Folder slug, e.g. "ai-internal-linking". */
	protected static function slug() {
		return dirname( self::basename() );
	}

	/**
	 * Fetch the latest release, cached so we never hammer the API (GitHub allows only 60
	 * unauthenticated requests/hour per IP — without caching, update checks fail intermittently).
	 *
	 * @return array|null { version, package, url, changelog, requires, tested, requires_php }
	 */
	public static function latest( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::CACHE_KEY );
			if ( is_array( $cached ) ) {
				return empty( $cached['version'] ) ? null : $cached;
			}
		}

		$source = self::source();
		$info   = null;

		if ( 'github' === ( $source['type'] ?? '' ) && ! empty( $source['repo'] ) ) {
			$info = self::from_github( $source['repo'] );
		} elseif ( 'json' === ( $source['type'] ?? '' ) && ! empty( $source['url'] ) ) {
			$info = self::from_json( $source['url'] );
		}

		// Cache negatives too (as an empty marker) so a broken source doesn't retry every load.
		set_transient( self::CACHE_KEY, is_array( $info ) ? $info : array(), self::CACHE_TTL );
		return $info;
	}

	protected static function from_github( $repo ) {
		$url      = 'https://api.github.com/repos/' . trim( $repo, '/' ) . '/releases/latest';
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 15,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'AI-Internal-Linking-Updater',
				),
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		$r = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $r ) || empty( $r['tag_name'] ) ) {
			return null;
		}

		// Prefer a zip attached to the release (already has the right folder structure);
		// fall back to GitHub's generated source zip, which fix_source_dir() renames.
		$package = '';
		foreach ( (array) ( $r['assets'] ?? array() ) as $asset ) {
			if ( ! empty( $asset['browser_download_url'] ) && '.zip' === strtolower( substr( $asset['browser_download_url'], -4 ) ) ) {
				$package = $asset['browser_download_url'];
				break;
			}
		}
		if ( '' === $package ) {
			$package = (string) ( $r['zipball_url'] ?? '' );
		}
		if ( '' === $package ) {
			return null;
		}

		return array(
			'version'      => ltrim( (string) $r['tag_name'], 'vV' ),
			'package'      => $package,
			'url'          => (string) ( $r['html_url'] ?? '' ),
			'changelog'    => (string) ( $r['body'] ?? '' ),
			'requires'     => '5.8',
			'tested'       => '',
			'requires_php' => '7.4',
		);
	}

	protected static function from_json( $url ) {
		$response = wp_remote_get( $url, array( 'timeout' => 15 ) );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		$r = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $r ) || empty( $r['version'] ) || empty( $r['download_url'] ) ) {
			return null;
		}
		return array(
			'version'      => (string) $r['version'],
			'package'      => (string) $r['download_url'],
			'url'          => (string) ( $r['homepage'] ?? '' ),
			'changelog'    => (string) ( $r['changelog'] ?? '' ),
			'requires'     => (string) ( $r['requires'] ?? '5.8' ),
			'tested'       => (string) ( $r['tested'] ?? '' ),
			'requires_php' => (string) ( $r['requires_php'] ?? '7.4' ),
		);
	}

	/**
	 * Add our release to the update transient when it is newer than what is installed.
	 */
	public static function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		// Honour the "Check again" button on the Plugins screen.
		$force  = ! empty( $_GET['force-check'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$latest = self::latest( $force );
		if ( ! $latest ) {
			return $transient;
		}

		$file    = self::basename();
		$current = defined( 'AIIL_VERSION' ) ? AIIL_VERSION : '0';

		if ( version_compare( $latest['version'], $current, '<=' ) ) {
			// Up to date — make sure a stale entry doesn't linger.
			unset( $transient->response[ $file ] );
			$transient->no_update[ $file ] = self::entry( $latest, $current );
			return $transient;
		}

		$transient->response[ $file ] = self::entry( $latest, $latest['version'] );
		return $transient;
	}

	protected static function entry( $latest, $version ) {
		return (object) array(
			'id'           => self::slug(),
			'slug'         => self::slug(),
			'plugin'       => self::basename(),
			'new_version'  => $version,
			'url'          => $latest['url'],
			'package'      => $latest['package'],
			'requires'     => $latest['requires'],
			'requires_php' => $latest['requires_php'],
			'tested'       => $latest['tested'],
			'icons'        => array(),
			'banners'      => array(),
		);
	}

	/**
	 * Populate the "View details" modal so it doesn't 404 against wordpress.org.
	 */
	public static function plugin_details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || self::slug() !== $args->slug ) {
			return $result;
		}
		$latest = self::latest();
		if ( ! $latest ) {
			return $result;
		}

		return (object) array(
			'name'          => 'AI Internal Linking',
			'slug'          => self::slug(),
			'version'       => $latest['version'],
			'requires'      => $latest['requires'],
			'requires_php'  => $latest['requires_php'],
			'homepage'      => $latest['url'],
			'download_link' => $latest['package'],
			'sections'      => array(
				'changelog' => wpautop( esc_html( $latest['changelog'] ) ),
			),
		);
	}

	/**
	 * GitHub's generated source zip extracts to "repo-2.5.0/", which WordPress would install as
	 * a DIFFERENT plugin folder — leaving a duplicate and deactivating the original. Rename the
	 * extracted directory back to the real plugin slug before install.
	 */
	public static function fix_source_dir( $source, $remote_source, $upgrader, $args = array() ) {
		if ( empty( $args['plugin'] ) || self::basename() !== $args['plugin'] ) {
			return $source;
		}
		$desired = trailingslashit( $remote_source ) . self::slug();
		if ( untrailingslashit( $source ) === $desired ) {
			return $source;
		}

		global $wp_filesystem;
		if ( $wp_filesystem && $wp_filesystem->move( $source, $desired, true ) ) {
			return trailingslashit( $desired );
		}
		return $source;
	}

	public static function flush() {
		delete_transient( self::CACHE_KEY );
	}
}
