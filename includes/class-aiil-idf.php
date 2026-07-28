<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Corpus inverse-document-frequency (IDF).
 *
 * IDF is the niche-agnostic way to know a word's *specificity*: "ULIP", "IPO" or "Demat"
 * appear in a handful of posts (high IDF → distinctive), while "interest", "financial" or
 * "insurance" appear everywhere (low IDF → generic). We use it only to rank/gate anchor
 * candidates — never for matching — so there are no per-niche word lists anywhere.
 *
 * The map is derived from the stored passage text (which excludes titles/nav) and cached in
 * a transient, rebuilt whenever the index changes.
 */
class AIIL_Idf {

	const CACHE_KEY = 'aiil_idf_map';

	/** @var array{n:int,df:array<string,int>}|null */
	protected static $memo = null;

	/**
	 * @return array{n:int,df:array<string,int>}
	 */
	public static function map() {
		if ( null !== self::$memo ) {
			return self::$memo;
		}
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) && isset( $cached['n'], $cached['df'] ) ) {
			self::$memo = $cached;
			return self::$memo;
		}
		return self::build();
	}

	/**
	 * @return array{n:int,df:array<string,int>}
	 */
	public static function build() {
		global $wpdb;
		$table   = AIIL_DB::passages_table();
		$blog_id = get_current_blog_id();

		$post_ids = $wpdb->get_col(
			$wpdb->prepare( "SELECT DISTINCT post_id FROM {$table} WHERE blog_id = %d", $blog_id )
		);

		$df = array();
		$n  = 0;
		foreach ( $post_ids as $pid ) {
			$texts = $wpdb->get_col(
				$wpdb->prepare( "SELECT text FROM {$table} WHERE post_id = %d AND blog_id = %d", (int) $pid, $blog_id )
			);
			if ( empty( $texts ) ) {
				continue;
			}
			$n++;
			$seen = array();
			foreach ( $texts as $t ) {
				foreach ( self::tokens( $t ) as $tok ) {
					$seen[ $tok ] = 1;
				}
			}
			foreach ( array_keys( $seen ) as $tok ) {
				$df[ $tok ] = isset( $df[ $tok ] ) ? $df[ $tok ] + 1 : 1;
			}
		}

		$map = array( 'n' => $n, 'df' => $df );
		set_transient( self::CACHE_KEY, $map, WEEK_IN_SECONDS );
		self::$memo = $map;
		return $map;
	}

	public static function flush() {
		self::$memo = null;
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * Smoothed IDF of a single (lowercased) token. Unknown tokens are treated as rare.
	 */
	public static function idf( $token ) {
		$map = self::map();
		$n   = max( 1, (int) $map['n'] );
		$df  = isset( $map['df'][ $token ] ) ? (int) $map['df'][ $token ] : 0;
		return log( ( $n + 1 ) / ( $df + 1 ) ) + 1.0; // >= ~ -? ; +1 keeps it positive-ish
	}

	/**
	 * The IDF floor above which a token counts as "distinctive". Relative to the corpus size:
	 * a token in more than ~15% of posts is considered generic.
	 */
	public static function distinctive_floor() {
		$map = self::map();
		$n   = max( 1, (int) $map['n'] );
		// idf of a token that appears in 15% of documents.
		return log( ( $n + 1 ) / ( 0.15 * $n + 1 ) ) + 1.0;
	}

	public static function tokens( $text ) {
		$words = preg_split( '/[^\p{L}\p{N}]+/u', mb_strtolower( (string) $text ), -1, PREG_SPLIT_NO_EMPTY );
		$out   = array();
		foreach ( $words as $w ) {
			if ( mb_strlen( $w ) >= 3 ) {
				$out[] = $w;
			}
		}
		return $out;
	}
}
