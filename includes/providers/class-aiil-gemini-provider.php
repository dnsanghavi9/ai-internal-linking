<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIIL_Gemini_Provider implements AIIL_Provider_Interface {

	const GEN_ENDPOINT       = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';
	const EMBED_ENDPOINT     = 'https://generativelanguage.googleapis.com/v1beta/models/%s:embedContent';
	const BATCH_ENDPOINT     = 'https://generativelanguage.googleapis.com/v1beta/models/%s:batchEmbedContents';
	const EMBED_MODEL        = 'gemini-embedding-001';
	const EMBED_DIMENSIONS   = 768;

	protected $api_key;
	protected $model;

	public function __construct( $api_key = null, $model = null ) {
		$this->api_key = $api_key ?: AIIL_Settings::get( 'api_key' );
		$this->model   = $model ?: AIIL_Settings::get( 'model', 'gemini-2.5-flash-lite' );
	}

	protected function embed_model() {
		return (string) apply_filters( 'aiil_embedding_model', self::EMBED_MODEL );
	}

	protected function dimensions() {
		return (int) apply_filters( 'aiil_embedding_dimensions', self::EMBED_DIMENSIONS );
	}

	public function embed( $text ) {
		$out = $this->embed_batch( array( (string) $text ) );
		if ( empty( $out[0] ) ) {
			throw new Exception( 'Embedding response contained no vector.' );
		}
		return $out[0];
	}

	public function embed_batch( array $texts ) {
		if ( empty( $this->api_key ) ) {
			throw new Exception( 'Gemini API key is not configured.' );
		}
		if ( empty( $texts ) ) {
			return array();
		}

		$model = $this->embed_model();
		$dims  = $this->dimensions();
		$url   = add_query_arg( 'key', $this->api_key, sprintf( self::BATCH_ENDPOINT, rawurlencode( $model ) ) );

		$requests = array();
		foreach ( $texts as $t ) {
			$requests[] = array(
				'model'                => 'models/' . $model,
				'content'              => array( 'parts' => array( array( 'text' => mb_substr( (string) $t, 0, 8000 ) ) ) ),
				'taskType'             => 'SEMANTIC_SIMILARITY',
				'outputDimensionality' => $dims,
			);
		}

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 45,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( array( 'requests' => $requests ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new Exception( 'Embedding request failed: ' . $response->get_error_message() );
		}
		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		if ( $code < 200 || $code >= 300 ) {
			throw new Exception( 'Embedding HTTP ' . $code . ': ' . mb_substr( $raw, 0, 300 ) );
		}

		$decoded = json_decode( $raw, true );
		$rows    = $decoded['embeddings'] ?? null;
		if ( ! is_array( $rows ) ) {
			throw new Exception( 'Embedding response had no embeddings array.' );
		}

		$out = array();
		foreach ( $rows as $row ) {
			$values = $row['values'] ?? null;
			$out[]  = is_array( $values ) ? array_map( 'floatval', $values ) : null;
		}
		return $out;
	}

	public function pick_anchor( $sentence, $target_title ) {
		if ( empty( $this->api_key ) ) {
			throw new Exception( 'Gemini API key is not configured.' );
		}

		$prompt = "You are choosing internal-link anchor text.\n"
			. "SENTENCE (from the source article):\n\"" . $sentence . "\"\n\n"
			. "TARGET article title: \"" . $target_title . "\"\n\n"
			. "Return STRICT JSON:\n"
			. "- anchor: 2-6 words to hyperlink. If a natural anchor already exists VERBATIM in SENTENCE, copy it exactly and set rewrite=false.\n"
			. "- sentence: the sentence to use. If no natural anchor exists, lightly rewrite SENTENCE to introduce one and set rewrite=true; otherwise return SENTENCE unchanged.\n"
			. "- rewrite: boolean.\n"
			. "- confidence: integer 0-100 for how natural and relevant the link is. If the target is a poor fit for this sentence, use a low value.\n"
			. "anchor MUST appear verbatim inside the returned sentence. Return ONLY the JSON.";

		$json = $this->generate_json( $prompt );

		return array(
			'anchor'     => isset( $json['anchor'] ) ? sanitize_text_field( $json['anchor'] ) : '',
			'sentence'   => isset( $json['sentence'] ) ? (string) $json['sentence'] : (string) $sentence,
			'rewrite'    => ! empty( $json['rewrite'] ),
			'confidence' => isset( $json['confidence'] ) ? max( 0, min( 100, (int) $json['confidence'] ) ) : 0,
		);
	}

	public function rerank( array $ctx ) {
		if ( empty( $this->api_key ) ) {
			throw new Exception( 'Gemini API key is not configured.' );
		}

		$source_title   = (string) ( $ctx['source_title'] ?? '' );
		$passage        = (string) ( $ctx['passage'] ?? '' );
		$anchor         = (string) ( $ctx['anchor'] ?? '' );
		$target_title   = (string) ( $ctx['target_title'] ?? '' );
		$target_excerpt = (string) ( $ctx['target_excerpt'] ?? '' );

		$prompt = "You are auditing a proposed internal link between two articles on the SAME blog, and CHOOSING the anchor text.\n\n"
			. "SOURCE article: \"" . $source_title . "\"\n"
			. "SOURCE passage (the link must go somewhere inside THIS text):\n\"\"\"\n" . mb_substr( $passage, 0, 800 ) . "\n\"\"\"\n\n"
			. "TARGET article: \"" . $target_title . "\"\n"
			. "TARGET opening:\n\"" . mb_substr( $target_excerpt, 0, 600 ) . "\"\n\n"
			. ( '' !== $anchor ? "A mechanical guess for the anchor was: \"" . $anchor . "\" (use it only if it is genuinely good).\n\n" : '' )
			. "STEP 1 — PAIR relevance: does the TARGET genuinely expand the exact point the passage makes?\n"
			. "   - topic_match: same subject area.\n"
			. "   - product_match: same product/service/instrument (a personal loan is NOT a payday loan; ULIP is NOT a mutual fund). true if neither article is about a specific product.\n"
			. "   - jurisdiction_match: compatible country/legal context (India vs Singapore vs US). true if neither is jurisdiction-specific, or the link is an explicit comparison.\n"
			. "   - pair_score: integer 0-100 for how much the target helps this reader.\n\n"
			. "STEP 2 — CHOOSE THE ANCHOR: pick the best span of text to hyperlink to the target.\n"
			. "   RULES:\n"
			. "   - It MUST be copied VERBATIM from the SOURCE passage above (exact characters, an unbroken span that really appears there).\n"
			. "   - Prefer a 2 to 5 word NOUN PHRASE that names the target's specific topic (e.g. 'business loan collateral', 'tax settlement services').\n"
			. "   - A single word is allowed ONLY if it is a specific term or named entity (e.g. SIP, ULIP, IPO, bookkeeping). NEVER choose a generic or vague word (the, this, obtain, existence, things, ideas, proper, trading, taxes) as a lone anchor.\n"
			. "   - The chosen words, in this sentence, must clearly refer to the TARGET's topic.\n"
			. "   - If the passage contains no span that both appears verbatim AND specifically denotes the target, return anchor as an empty string \"\".\n"
			. "   - anchor_score: integer 0-100 for how specific and natural the chosen anchor is (empty anchor = 0).\n\n"
			. "Return STRICT JSON with EXACTLY these keys: {\"topic_match\":bool,\"product_match\":bool,\"jurisdiction_match\":bool,\"pair_score\":int,\"anchor\":\"verbatim span or empty\",\"anchor_score\":int,\"reason\":\"short phrase\"}.\n"
			. "Return ONLY the JSON.";

		$json = $this->generate_json( $prompt );

		$clamp = function ( $v ) {
			return max( 0, min( 100, (int) $v ) );
		};

		return array(
			'topic_match'        => ! empty( $json['topic_match'] ),
			'product_match'      => ! isset( $json['product_match'] ) || ! empty( $json['product_match'] ),
			'jurisdiction_match' => ! isset( $json['jurisdiction_match'] ) || ! empty( $json['jurisdiction_match'] ),
			'pair_score'         => isset( $json['pair_score'] ) ? $clamp( $json['pair_score'] ) : 0,
			'anchor'             => isset( $json['anchor'] ) ? trim( (string) $json['anchor'] ) : '',
			'anchor_score'       => isset( $json['anchor_score'] ) ? $clamp( $json['anchor_score'] ) : 0,
			'reason'             => isset( $json['reason'] ) ? sanitize_text_field( (string) $json['reason'] ) : '',
		);
	}

	protected function generate_json( $prompt ) {
		$url  = add_query_arg( 'key', $this->api_key, sprintf( self::GEN_ENDPOINT, rawurlencode( $this->model ) ) );
		$body = array(
			'contents'         => array( array( 'role' => 'user', 'parts' => array( array( 'text' => $prompt ) ) ) ),
			'generationConfig' => array( 'temperature' => 0.2, 'responseMimeType' => 'application/json' ),
		);

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 45,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new Exception( 'Gemini request failed: ' . $response->get_error_message() );
		}
		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		if ( $code < 200 || $code >= 300 ) {
			throw new Exception( 'Gemini HTTP ' . $code . ': ' . mb_substr( $raw, 0, 400 ) );
		}

		$decoded = json_decode( $raw, true );
		$text    = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? '';
		if ( '' === $text ) {
			throw new Exception( 'Gemini returned an empty response.' );
		}
		$json = json_decode( $text, true );
		if ( null === $json && JSON_ERROR_NONE !== json_last_error() && preg_match( '/\{[\s\S]*\}/', $text, $m ) ) {
			$json = json_decode( $m[0], true );
		}
		if ( ! is_array( $json ) ) {
			throw new Exception( 'Gemini response was not valid JSON.' );
		}
		return $json;
	}
}
