<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIIL_Settings {

	const OPTION_KEY = 'aiil_settings';

	public static function defaults() {
		return array(
			'provider'            => 'gemini',
			'model'               => 'gemini-2.5-flash-lite', // used only for the AI anchor fallback
			'api_key'             => '',
			'max_outgoing_links'  => 2,
			'match_top_k'         => 8,    // semantic neighbours retrieved per post
			'min_doc_similarity'  => 55,   // 0-100; below this a pair isn't even an opportunity
			'min_passage_score'   => 55,   // 0-100; a link needs a source passage at least this relevant
			'both_direction_min'  => 60,   // 0-100 doc similarity at/above which BOTH directions are generated
			'avoid_reciprocal'    => 1,
			'use_ai_anchor'       => 0,    // AI fallback to pick anchor / suggest rewrite (opt-in, small cost)
			'use_ai_rerank'       => 0,    // AI verify step over READY links (opt-in; judges usefulness/jurisdiction/product)
			'rerank_budget'       => 8,    // max AI verify calls per source (backfills until max_outgoing_links kept)
			'rerank_pair_min'     => 75,   // 0-100; pair usefulness below this -> reject
			'rerank_anchor_min'   => 70,   // 0-100; safety floor only — the AI picks the anchor, so
			                               // this should stay low. Below it -> needs a human.
			'bold_links'          => 1,    // wrap inserted anchor text in <strong>
			'link_word_gap'       => 3,    // keep at least this many words between a new link and an existing one
			'auto_link_new'       => 1,    // when a post is published/edited, auto-prepare (+verify) its links in the background
			'auto_insert'         => 1,    // insert kept links automatically, no manual review
			'auto_min_confidence' => 75,   // aligns with rerank_pair_min so AI-verified links actually auto-insert
			'batch_size'          => 20,
		);
	}

	public static function seed_defaults() {
		$existing = get_option( self::OPTION_KEY, array() );
		update_option( self::OPTION_KEY, wp_parse_args( $existing, self::defaults() ) );
	}

	public static function all() {
		return wp_parse_args( get_option( self::OPTION_KEY, array() ), self::defaults() );
	}

	public static function get( $key, $default = null ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : $default;
	}

	public static function update( array $values ) {
		$current = self::all();
		if ( isset( $values['api_key'] ) && '' === trim( (string) $values['api_key'] ) ) {
			unset( $values['api_key'] ); // empty submit keeps existing key (masked UI)
		}
		$sanitized = self::sanitize( array_merge( $current, $values ) );
		update_option( self::OPTION_KEY, $sanitized );
		return $sanitized;
	}

	public static function has_api_key() {
		return '' !== trim( (string) self::get( 'api_key', '' ) );
	}

	public static function sanitize( $input ) {
		$out                        = array();
		$out['provider']            = sanitize_text_field( $input['provider'] ?? 'gemini' );
		$out['model']               = sanitize_text_field( $input['model'] ?? 'gemini-2.5-flash-lite' );
		$out['api_key']             = trim( (string) ( $input['api_key'] ?? '' ) );
		$out['max_outgoing_links']  = max( 1, (int) ( $input['max_outgoing_links'] ?? 2 ) );
		$out['match_top_k']         = min( 50, max( 1, (int) ( $input['match_top_k'] ?? 8 ) ) );
		$out['min_doc_similarity']  = min( 100, max( 0, (int) ( $input['min_doc_similarity'] ?? 55 ) ) );
		$out['min_passage_score']   = min( 100, max( 0, (int) ( $input['min_passage_score'] ?? 55 ) ) );
		$out['both_direction_min']  = min( 100, max( 0, (int) ( $input['both_direction_min'] ?? 60 ) ) );
		$out['avoid_reciprocal']    = empty( $input['avoid_reciprocal'] ) ? 0 : 1;
		$out['use_ai_anchor']       = empty( $input['use_ai_anchor'] ) ? 0 : 1;
		$out['use_ai_rerank']       = empty( $input['use_ai_rerank'] ) ? 0 : 1;
		$out['rerank_budget']       = min( 20, max( 1, (int) ( $input['rerank_budget'] ?? 8 ) ) );
		$out['rerank_pair_min']     = min( 100, max( 0, (int) ( $input['rerank_pair_min'] ?? 75 ) ) );
		$out['rerank_anchor_min']   = min( 100, max( 0, (int) ( $input['rerank_anchor_min'] ?? 70 ) ) );
		$out['bold_links']          = empty( $input['bold_links'] ) ? 0 : 1;
		$out['link_word_gap']       = min( 20, max( 0, (int) ( $input['link_word_gap'] ?? 3 ) ) );
		$out['auto_link_new']       = empty( $input['auto_link_new'] ) ? 0 : 1;
		$out['auto_insert']         = empty( $input['auto_insert'] ) ? 0 : 1;
		$out['auto_min_confidence'] = min( 100, max( 0, (int) ( $input['auto_min_confidence'] ?? 75 ) ) );
		$out['batch_size']          = max( 1, min( 100, (int) ( $input['batch_size'] ?? 20 ) ) );
		return $out;
	}
}
