<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tracks real Gemini API usage so the Cost tab can show what the pipeline has actually spent.
 *
 * Token counts come straight from the API where Gemini provides them: every generateContent
 * response (AI verify, AI anchor fallback) includes `usageMetadata` with exact prompt/output
 * token counts — those rows are stored `measured = 1`. Embedding calls (embedContent /
 * batchEmbedContents) do NOT return token usage, so those rows are an estimate (~4 chars/token)
 * and stored `measured = 0`. The Cost tab shows both figures and is explicit about which is which
 * — this class never blurs a real number with a guess.
 *
 * Dollar cost is computed at DISPLAY time from the settings rates (not baked in at record time),
 * so changing a rate re-prices the whole history instantly with no data migration.
 */
class AIIL_Usage {

	/**
	 * Record one API call. Never throws — a bookkeeping failure must not break the pipeline.
	 */
	public static function record( $call_type, $model, $service_tier, $input_tokens, $output_tokens, $measured, $requests = 1 ) {
		try {
			global $wpdb;
			$wpdb->insert(
				AIIL_DB::usage_table(),
				array(
					'blog_id'       => get_current_blog_id(),
					'call_type'     => sanitize_key( $call_type ),
					'model'         => mb_substr( (string) $model, 0, 100 ),
					'service_tier'  => mb_substr( (string) $service_tier, 0, 20 ),
					'requests'      => max( 1, (int) $requests ),
					'input_tokens'  => max( 0, (int) $input_tokens ),
					'output_tokens' => max( 0, (int) $output_tokens ),
					'measured'      => $measured ? 1 : 0,
					'created_at'    => current_time( 'mysql' ),
				)
			);
		} catch ( Exception $e ) {
			// Cost tracking is best-effort; never let it surface to the caller.
		}
	}

	/** Rough token estimate for text Gemini doesn't report usage for (embeddings). */
	public static function estimate_tokens( $text ) {
		return (int) ceil( mb_strlen( (string) $text ) / 4 );
	}

	/**
	 * Aggregate usage grouped by call_type + model + tier + measured, with per-row cost applied
	 * from the configured rates, plus a grand total.
	 *
	 * @return array{rows:array,total_cost:float,has_estimated:bool,since:?string}
	 */
	public static function summary() {
		global $wpdb;
		$table = AIIL_DB::usage_table();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT call_type, model, service_tier, measured,
				        SUM(requests) AS requests, SUM(input_tokens) AS input_tokens, SUM(output_tokens) AS output_tokens,
				        MIN(created_at) AS first_at, MAX(created_at) AS last_at
				 FROM {$table}
				 WHERE blog_id = %d
				 GROUP BY call_type, model, service_tier, measured
				 ORDER BY call_type, model",
				get_current_blog_id()
			),
			ARRAY_A
		);

		$total         = 0.0;
		$has_estimated = false;
		$since         = null;

		foreach ( $rows as &$r ) {
			$r['requests']      = (int) $r['requests'];
			$r['input_tokens']  = (int) $r['input_tokens'];
			$r['output_tokens'] = (int) $r['output_tokens'];
			$r['measured']      = (bool) $r['measured'];
			$r['cost']          = self::cost_for( $r['call_type'], $r['service_tier'], $r['input_tokens'], $r['output_tokens'] );
			$total             += $r['cost'];
			if ( ! $r['measured'] ) {
				$has_estimated = true;
			}
			if ( null === $since || ( $r['first_at'] && $r['first_at'] < $since ) ) {
				$since = $r['first_at'];
			}
		}
		unset( $r );

		return array(
			'rows'          => $rows,
			'total_cost'    => $total,
			'has_estimated' => $has_estimated,
			'since'         => $since,
		);
	}

	/** Cost in USD for one usage bucket, from the configured $/1M rates. */
	public static function cost_for( $call_type, $service_tier, $input_tokens, $output_tokens ) {
		if ( 'embed' === $call_type ) {
			$rate_in  = (float) AIIL_Settings::get( 'price_embed_per_m', 0 );
			$rate_out = 0.0;
		} elseif ( 'flex' === $service_tier ) {
			$rate_in  = (float) AIIL_Settings::get( 'price_gen_in_flex_per_m', 0 );
			$rate_out = (float) AIIL_Settings::get( 'price_gen_out_flex_per_m', 0 );
		} else {
			$rate_in  = (float) AIIL_Settings::get( 'price_gen_in_per_m', 0 );
			$rate_out = (float) AIIL_Settings::get( 'price_gen_out_per_m', 0 );
		}
		return ( $input_tokens / 1000000 * $rate_in ) + ( $output_tokens / 1000000 * $rate_out );
	}

	public static function clear() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM " . AIIL_DB::usage_table() . " WHERE blog_id = %d", get_current_blog_id() ) );
	}
}
