<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scans existing post HTML for real internal links.
 *
 * Orphan detection is only trustworthy if it reflects the links actually on the site — not
 * just the ones this plugin inserted. This scanner walks every published post, extracts each
 * <a href> that points to another post on this site, resolves it to a local post id, and
 * stores the real source -> target graph. Orphans are then posts nothing links to.
 */
class AIIL_Link_Scanner {

	const LAST_SCAN_OPTION = 'aiil_last_link_scan';

	/** @var array<string,int>|null path (no trailing slash) => post_id */
	protected static $path_map = null;
	protected static $site_host = null;

	/**
	 * Scan every published post and rebuild the real internal-link graph.
	 *
	 * @return array{posts:int,links:int,orphans:int}
	 */
	public static function scan_all() {
		global $wpdb;
		$blog_id = get_current_blog_id();
		self::build_url_map();

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_status = %s AND post_type = %s",
				'publish',
				'post'
			)
		);

		$table = AIIL_DB::site_links_table();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE blog_id = %d", $blog_id ) );

		$now         = current_time( 'mysql' );
		$links_found = 0;

		foreach ( $ids as $source_id ) {
			$source_id = (int) $source_id;
			$post      = get_post( $source_id );
			if ( ! $post ) {
				continue;
			}
			$targets = self::links_in( $post->post_content, $source_id );
			foreach ( $targets as $target_id => $anchor ) {
				$inserted = $wpdb->query(
					$wpdb->prepare(
						"INSERT IGNORE INTO {$table} (source_post_id, target_post_id, blog_id, anchor_text, scanned_at)
						 VALUES (%d, %d, %d, %s, %s)",
						$source_id,
						(int) $target_id,
						$blog_id,
						mb_substr( (string) $anchor, 0, 255 ),
						$now
					)
				);
				$links_found += (int) $inserted;
			}
		}

		update_option( self::LAST_SCAN_OPTION, $now, false );

		$orphans = self::orphan_count();
		AIIL_Logger::info( 'Scanned existing internal links', array( 'posts' => count( $ids ), 'links' => $links_found, 'orphans' => $orphans ) );

		return array( 'posts' => count( $ids ), 'links' => $links_found, 'orphans' => $orphans );
	}

	/**
	 * Resolve the internal links inside one post's HTML to local post ids.
	 *
	 * @return array<int,string> target_post_id => anchor text (deduped, self-links removed)
	 */
	public static function links_in( $html, $source_id ) {
		if ( null === self::$path_map ) {
			self::build_url_map();
		}
		$out = array();
		if ( ! preg_match_all( '/<a\b[^>]*href\s*=\s*("|\')(.*?)\1[^>]*>(.*?)<\/a>/is', (string) $html, $m, PREG_SET_ORDER ) ) {
			return $out;
		}
		foreach ( $m as $match ) {
			$target = self::resolve( $match[2] );
			if ( ! $target || (int) $target === (int) $source_id ) {
				continue;
			}
			if ( ! isset( $out[ $target ] ) ) {
				$out[ $target ] = trim( wp_strip_all_tags( $match[3] ) );
			}
		}
		return $out;
	}

	/**
	 * Resolve a single href to a local published-post id, or 0.
	 */
	protected static function resolve( $href ) {
		$href = trim( html_entity_decode( (string) $href, ENT_QUOTES, 'UTF-8' ) );
		if ( '' === $href || preg_match( '~^(mailto:|tel:|javascript:|#)~i', $href ) ) {
			return 0;
		}

		// Make protocol-relative and root-relative URLs absolute against the site host.
		if ( 0 === strpos( $href, '//' ) ) {
			$href = 'https:' . $href;
		} elseif ( 0 === strpos( $href, '/' ) ) {
			$href = home_url( $href );
		} elseif ( ! preg_match( '~^[a-z][a-z0-9+.-]*://~i', $href ) && '?' !== $href[0] ) {
			// A document-relative link ("some-slug/") resolves against the CURRENT post's URL,
			// not the site root, so we cannot map it to a post id from the href alone. Skipping
			// is deliberate: guessing here would invent an inbound link and mask a real orphan.
			return 0;
		}

		$parts = wp_parse_url( $href );
		if ( ! empty( $parts['host'] ) && self::norm_host( $parts['host'] ) !== self::$site_host ) {
			return 0; // external link
		}

		// ?p=ID / ?page_id=ID forms.
		if ( ! empty( $parts['query'] ) ) {
			parse_str( $parts['query'], $q );
			foreach ( array( 'p', 'page_id' ) as $k ) {
				if ( ! empty( $q[ $k ] ) && ctype_digit( (string) $q[ $k ] ) ) {
					return self::valid_post( (int) $q[ $k ] );
				}
			}
		}

		$path = isset( $parts['path'] ) ? self::norm_path( $parts['path'] ) : '';
		if ( '' !== $path && isset( self::$path_map[ $path ] ) ) {
			return self::$path_map[ $path ];
		}

		// Fallback: let WordPress resolve unusual permalink shapes (slower; only when needed).
		$id = url_to_postid( $href );
		return $id ? self::valid_post( $id ) : 0;
	}

	protected static function valid_post( $id ) {
		$post = get_post( (int) $id );
		return ( $post && 'publish' === $post->post_status && 'post' === $post->post_type ) ? (int) $id : 0;
	}

	/**
	 * Compare hosts ignoring a leading "www." — content frequently links to the www variant of
	 * a site configured without it (or the reverse), and treating those as external would make
	 * the scan find no internal links at all.
	 */
	protected static function norm_host( $host ) {
		return preg_replace( '~^www\.~i', '', strtolower( (string) $host ) );
	}

	protected static function norm_path( $path ) {
		$path = rawurldecode( (string) $path );
		$path = '/' . trim( $path, '/' );
		return strtolower( $path );
	}

	/**
	 * Build a fast permalink-path => post_id map for all published posts, so href resolution
	 * needs no per-link database query.
	 */
	protected static function build_url_map() {
		global $wpdb;
		self::$site_host = self::norm_host( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		self::$path_map  = array();

		$ids = $wpdb->get_col(
			$wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_status = %s AND post_type = %s", 'publish', 'post' )
		);
		foreach ( $ids as $id ) {
			$permalink = get_permalink( (int) $id );
			if ( ! $permalink ) {
				continue;
			}
			$path = self::norm_path( (string) wp_parse_url( $permalink, PHP_URL_PATH ) );
			if ( '' !== $path ) {
				self::$path_map[ $path ] = (int) $id;
			}
		}
	}

	public static function has_scan() {
		return (bool) get_option( self::LAST_SCAN_OPTION, false );
	}

	public static function last_scan() {
		return get_option( self::LAST_SCAN_OPTION, '' );
	}

	/** post_id => real incoming link count (targets of discovered links). */
	public static function incoming_counts() {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT target_post_id AS pid, COUNT(*) c FROM " . AIIL_DB::site_links_table() . " WHERE blog_id = %d GROUP BY target_post_id",
				get_current_blog_id()
			),
			ARRAY_A
		);
		$out = array();
		foreach ( $rows as $r ) {
			$out[ (int) $r['pid'] ] = (int) $r['c'];
		}
		return $out;
	}

	/** Published post ids that no discovered internal link points to. */
	public static function orphan_ids() {
		global $wpdb;
		return array_map(
			'intval',
			$wpdb->get_col(
				$wpdb->prepare(
					"SELECT p.ID FROM {$wpdb->posts} p
					 LEFT JOIN " . AIIL_DB::site_links_table() . " sl
					   ON sl.target_post_id = p.ID AND sl.blog_id = %d
					 WHERE p.post_status = 'publish' AND p.post_type = 'post' AND sl.id IS NULL
					 ORDER BY p.ID ASC",
					get_current_blog_id()
				)
			)
		);
	}

	public static function orphan_count() {
		return count( self::orphan_ids() );
	}
}
