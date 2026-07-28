<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Small numeric-vector utilities shared by the indexer, matcher and placement engine.
 */
class AIIL_Vector {

	/** Decode a JSON-encoded vector column, or null. */
	public static function decode( $json ) {
		if ( empty( $json ) ) {
			return null;
		}
		$vec = json_decode( (string) $json, true );
		return ( is_array( $vec ) && ! empty( $vec ) ) ? $vec : null;
	}

	/** Cosine similarity of two numeric vectors (0..1 for similar content). */
	public static function cosine( array $a, array $b ) {
		$dot = 0.0;
		$na  = 0.0;
		$nb  = 0.0;
		$len = min( count( $a ), count( $b ) );
		for ( $i = 0; $i < $len; $i++ ) {
			$x    = (float) $a[ $i ];
			$y    = (float) $b[ $i ];
			$dot += $x * $y;
			$na  += $x * $x;
			$nb  += $y * $y;
		}
		if ( $na <= 0 || $nb <= 0 ) {
			return 0.0;
		}
		return $dot / ( sqrt( $na ) * sqrt( $nb ) );
	}

	/** Element-wise a - b (used to mean-center a vector against the corpus mean). */
	public static function subtract( array $a, array $b ) {
		$len = min( count( $a ), count( $b ) );
		$out = array();
		for ( $i = 0; $i < $len; $i++ ) {
			$out[ $i ] = (float) $a[ $i ] - (float) $b[ $i ];
		}
		return $out;
	}

	/**
	 * Map a cosine value (which after mean-centering ranges roughly -1..1) onto a 0..100 score.
	 * (cos+1)/2*100, so 0 similarity → 50, and the plugin's thresholds keep a stable meaning.
	 */
	public static function score( $cosine ) {
		$v = ( ( (float) $cosine ) + 1.0 ) / 2.0 * 100.0;
		return round( max( 0.0, min( 100.0, $v ) ), 2 );
	}

	/** Element-wise mean of a list of equal-length vectors (the document vector). */
	public static function mean( array $vectors ) {
		$vectors = array_values( array_filter( $vectors, 'is_array' ) );
		$count   = count( $vectors );
		if ( 0 === $count ) {
			return null;
		}
		$dims = count( $vectors[0] );
		$sum  = array_fill( 0, $dims, 0.0 );
		foreach ( $vectors as $v ) {
			$n = min( $dims, count( $v ) );
			for ( $i = 0; $i < $n; $i++ ) {
				$sum[ $i ] += (float) $v[ $i ];
			}
		}
		for ( $i = 0; $i < $dims; $i++ ) {
			$sum[ $i ] /= $count;
		}
		return $sum;
	}
}
