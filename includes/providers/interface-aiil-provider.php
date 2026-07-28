<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface AIIL_Provider_Interface {

	/**
	 * Embed a single text into a vector.
	 *
	 * @param string $text
	 * @return float[]
	 */
	public function embed( $text );

	/**
	 * Embed several texts in one request. Returns vectors in the same order.
	 *
	 * @param string[] $texts
	 * @return float[][]
	 */
	public function embed_batch( array $texts );

	/**
	 * AI fallback: given ONE source sentence and the target's title, return a natural
	 * anchor (a span that exists in the sentence) or a minimal rewrite. Grounded to the
	 * sentence, so it cannot hallucinate an anchor that isn't there.
	 *
	 * @param string $sentence
	 * @param string $target_title
	 * @return array { @type string $anchor, @type string $sentence, @type bool $rewrite, @type int $confidence }
	 */
	public function pick_anchor( $sentence, $target_title );

	/**
	 * Judge whether a prepared internal link genuinely helps the reader — the RAG "verify"
	 * step. Embeddings can tell that two articles are topically similar but not whether the
	 * target actually expands the source passage, nor whether they are jurisdiction/product
	 * compatible. This is where a single scoped AI call earns its cost.
	 *
	 * @param array $ctx { @type string $source_title, @type string $passage, @type string $anchor,
	 *                     @type string $target_title, @type string $target_excerpt }
	 * @return array { @type bool $keep, @type int $score (0-100 usefulness), @type string $reason }
	 */
	public function rerank( array $ctx );
}
