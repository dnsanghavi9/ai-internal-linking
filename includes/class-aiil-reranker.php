<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AI verification pass over READY links — the RAG "verify" step.
 *
 * Runs only when opted in (use_ai_rerank). One scoped Gemini call per ready link judges pair
 * relevance (topic / product / jurisdiction / reader usefulness) and anchor quality (does the
 * anchor refer to the target in-sentence) SEPARATELY — the judgements embeddings cannot make.
 *
 * Outcomes: a poor pair -> 'rejected_relevance'; a good pair with a weak anchor ->
 * 'rewrite_suggested'; both good -> 'verified'. Verification is backfill-aware: it keeps
 * reviewing a source's candidates (past rejected ones) until the source has its full outgoing
 * allowance of verified links or the per-source rerank budget is spent, after which remaining
 * candidates are 'capped' without an AI call. A reverse direction is blocked ('reciprocal')
 * only at the moment its counterpart is actually verified.
 */
class AIIL_Reranker {

	/**
	 * Verify a batch of not-yet-verified ready links.
	 *
	 * @return array{processed:int,kept:int,rejected:int,inserted:int,remaining:int}
	 */
	public static function verify_batch( $limit = 5 ) {
		global $wpdb;
		$t     = AIIL_DB::opportunities_table();
		$limit = max( 1, (int) $limit );

		$per_call = (int) AIIL_Settings::get( 'rerank_candidates_per_call', 1 );
		$out      = array( 'processed' => 0, 'kept' => 0, 'rejected' => 0, 'rewrite' => 0, 'capped' => 0, 'inserted' => 0 );

		if ( $per_call > 1 ) {
			$out = self::verify_grouped( $limit * $per_call, $per_call, $out );
			$out['remaining'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE status = 'ready'" );
			return $out;
		}

		// Highest-confidence candidates first so each source's strongest links are reviewed
		// before its weaker ones (which matters for the per-source cap + reciprocal blocking).
		$ids = $wpdb->get_col(
			$wpdb->prepare( "SELECT id FROM {$t} WHERE status = 'ready' ORDER BY confidence DESC, id ASC LIMIT %d", $limit )
		);

		foreach ( $ids as $id ) {
			$res = self::verify_opportunity( (int) $id );
			if ( null === $res ) {
				continue;
			}
			$out['processed']++;
			$out[ $res['result'] ] = ( $out[ $res['result'] ] ?? 0 ) + 1;
			if ( ! empty( $res['inserted'] ) ) {
				$out['inserted']++;
			}
		}

		$out['remaining'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE status = 'ready'" );
		return $out;
	}

	/**
	 * Judge several candidates per API call — far fewer round trips, and fewer requests against a
	 * rate-limited key. Correctness is preserved by construction:
	 *
	 *  - a batch holds at most ONE candidate per source post, so the per-source allowance is
	 *    still checked before every judgement and we never buy verdicts a source cannot use;
	 *  - the cheap cap/budget guards still run per candidate BEFORE the call;
	 *  - verdicts are matched by id, never by position;
	 *  - any candidate the model omits falls back to its own single call.
	 */
	protected static function verify_grouped( $pool_size, $per_call, array $out ) {
		global $wpdb;
		$t = AIIL_DB::opportunities_table();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, source_post_id FROM {$t} WHERE status = 'ready' ORDER BY confidence DESC, id ASC LIMIT %d",
				max( 1, (int) $pool_size ) * 4 // oversample: most rows share a source
			)
		);
		if ( empty( $rows ) ) {
			return $out;
		}

		// One candidate per source, strongest first.
		$picked = array();
		$seen   = array();
		foreach ( $rows as $r ) {
			$src = (int) $r->source_post_id;
			if ( isset( $seen[ $src ] ) ) {
				continue;
			}
			$seen[ $src ] = true;
			$picked[]     = (int) $r->id;
			if ( count( $picked ) >= $per_call ) {
				break;
			}
		}

		// Prepare each candidate; the free cap/budget guards may settle some without any AI call.
		$items = array();
		$ctxs  = array();
		foreach ( $picked as $id ) {
			$prepared = self::prepare_candidate( $id );
			if ( 'capped' === $prepared ) {
				$out['processed']++;
				$out['capped']++;
				continue;
			}
			if ( ! is_array( $prepared ) ) {
				continue; // invalid / no longer ready
			}
			$items[ $id ] = $prepared['ctx'];
			$ctxs[ $id ]  = $prepared;
		}
		if ( empty( $items ) ) {
			return $out;
		}

		try {
			$verdicts = AIIL_Indexer::provider()->rerank_batch( $items );
		} catch ( Exception $e ) {
			AIIL_Logger::warning( 'Batched AI rerank failed — falling back to single calls', array( 'error' => $e->getMessage(), 'candidates' => count( $items ) ) );
			$verdicts = array();
		}

		foreach ( $ctxs as $id => $prepared ) {
			if ( isset( $verdicts[ $id ] ) ) {
				$res = self::apply_verdict( $prepared['opp'], $prepared['target'], $prepared['signals'], $prepared['passage'], $verdicts[ $id ] );
			} else {
				// Model skipped it (or the whole batch failed) — judge it on its own.
				$res = self::verify_opportunity( $id );
			}
			if ( null === $res ) {
				continue;
			}
			$out['processed']++;
			$out[ $res['result'] ] = ( $out[ $res['result'] ] ?? 0 ) + 1;
			if ( ! empty( $res['inserted'] ) ) {
				$out['inserted']++;
			}
		}

		return $out;
	}

	/**
	 * Load a ready candidate and run the free pre-checks.
	 *
	 * @return array{opp:object,target:WP_Post,signals:array,passage:string,ctx:array}|'capped'|null
	 */
	protected static function prepare_candidate( $opportunity_id ) {
		global $wpdb;
		$t   = AIIL_DB::opportunities_table();
		$opp = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", (int) $opportunity_id ) );
		if ( ! $opp || 'ready' !== $opp->status ) {
			return null;
		}

		$source = get_post( (int) $opp->source_post_id );
		$target = get_post( (int) $opp->target_post_id );
		if ( ! $source || ! $target ) {
			$wpdb->update( $t, array( 'status' => 'invalid' ), array( 'id' => (int) $opportunity_id ) );
			return null;
		}

		if ( self::should_cap( (int) $opp->source_post_id ) ) {
			$wpdb->update( $t, array( 'status' => 'capped' ), array( 'id' => (int) $opportunity_id ) );
			AIIL_Placement::clear_stash( (int) $opportunity_id );
			return 'capped';
		}

		$signals = json_decode( (string) $opp->signals, true );
		$signals = is_array( $signals ) ? $signals : array();
		$passage = isset( $signals['best_passage'] ) ? (string) $signals['best_passage'] : '';

		return array(
			'opp'     => $opp,
			'target'  => $target,
			'signals' => $signals,
			'passage' => $passage,
			'ctx'     => array(
				'source_title'   => $source->post_title,
				'passage'        => $passage,
				'anchor'         => (string) $opp->anchor_text,
				'target_title'   => $target->post_title,
				'target_excerpt' => self::excerpt( (int) $target->ID, $target ),
			),
		);
	}

	/**
	 * Has this source already earned its full allowance of links, or spent its AI budget?
	 * Both are answered from the database, so a capped candidate never costs an API call.
	 */
	protected static function should_cap( $source_post_id ) {
		global $wpdb;
		$t   = AIIL_DB::opportunities_table();
		$sid = (int) $source_post_id;

		$already = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT outgoing_links FROM " . AIIL_DB::posts_table() . " WHERE post_id = %d AND blog_id = %d", $sid, get_current_blog_id() )
		);
		$slots = max( 0, (int) AIIL_Settings::get( 'max_outgoing_links', 3 ) - $already );

		$verified_ct = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE source_post_id = %d AND status = 'verified'", $sid )
		);
		if ( $verified_ct >= $slots ) {
			return true;
		}

		$reranked_ct = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$t} WHERE source_post_id = %d AND status IN ('verified','rejected_relevance','rewrite_suggested')",
				$sid
			)
		);
		return $reranked_ct >= (int) AIIL_Settings::get( 'rerank_budget', 8 );
	}

	/**
	 * Verify one ready link. Backfill-aware: if the source already has its full allowance of
	 * verified links, or has already spent its rerank budget, the candidate is capped WITHOUT an
	 * AI call. Otherwise the structured verdict decides: reject / rewrite_suggested / verify.
	 *
	 * @return array{result:string,inserted:bool}|null  result in kept|rejected|rewrite|capped
	 */
	public static function verify_opportunity( $opportunity_id ) {
		global $wpdb;
		$t   = AIIL_DB::opportunities_table();
		$opp = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", (int) $opportunity_id ) );
		if ( ! $opp || 'ready' !== $opp->status ) {
			return null;
		}

		$source = get_post( (int) $opp->source_post_id );
		$target = get_post( (int) $opp->target_post_id );
		if ( ! $source || ! $target ) {
			$wpdb->update( $t, array( 'status' => 'invalid' ), array( 'id' => (int) $opportunity_id ) );
			return null;
		}

		$sid = (int) $opp->source_post_id;

		// Cap without spending an AI call: allowance already met, or budget exhausted.
		if ( self::should_cap( $sid ) ) {
			$wpdb->update( $t, array( 'status' => 'capped' ), array( 'id' => (int) $opportunity_id ) );
			AIIL_Placement::clear_stash( (int) $opportunity_id );
			return array( 'result' => 'capped', 'inserted' => false );
		}

		$signals = json_decode( (string) $opp->signals, true );
		$signals = is_array( $signals ) ? $signals : array();
		$passage = isset( $signals['best_passage'] ) ? (string) $signals['best_passage'] : '';

		$ctx = array(
			'source_title'   => $source->post_title,
			'passage'        => $passage,
			'anchor'         => (string) $opp->anchor_text,
			'target_title'   => $target->post_title,
			'target_excerpt' => self::excerpt( (int) $target->ID, $target ),
		);

		try {
			$v = AIIL_Indexer::provider()->rerank( $ctx );
		} catch ( Exception $e ) {
			AIIL_Logger::warning( 'AI rerank failed', array( 'opportunity_id' => $opportunity_id, 'error' => $e->getMessage() ) );
			return null; // leave as ready; a later pass can retry
		}

		return self::apply_verdict( $opp, $target, $signals, $passage, $v );
	}

	/**
	 * Turn one AI verdict into a decision. Shared by the single-call and batched paths so both
	 * apply exactly the same grounding, refining, weak-anchor and threshold rules.
	 *
	 * @return array{result:string,inserted:bool}
	 */
	protected static function apply_verdict( $opp, $target, array $signals, $passage, array $v ) {
		global $wpdb;
		$t              = AIIL_DB::opportunities_table();
		$opportunity_id = (int) $opp->id;
		$sid            = (int) $opp->source_post_id;

		$pair_min = (int) AIIL_Settings::get( 'rerank_pair_min', 75 );

		// Ground the AI-chosen anchor: it must appear VERBATIM (word-bounded) in the passage.
		// This both prevents hallucinated anchors and guarantees the inserter can place it.
		$grounded = self::ground_anchor( (string) $v['anchor'], $passage );
		// Then deterministically upgrade a lazy single-word / off-topic pick to the richest
		// target-overlapping phrase that exists in the passage (no extra AI call).
		if ( '' !== $grounded ) {
			$grounded = AIIL_Inserter::refine_anchor( $grounded, $passage, $target->post_title, $target->post_content );
		}

		$signals['rerank'] = array(
			'topic'        => (bool) $v['topic_match'],
			'product'      => (bool) $v['product_match'],
			'jurisdiction' => (bool) $v['jurisdiction_match'],
			'pair_score'   => (int) $v['pair_score'],
			'anchor'       => (string) $v['anchor'],
			'anchor_score' => (int) $v['anchor_score'],
			'grounded'     => $grounded,
			'reason'       => (string) $v['reason'],
		);

		// Binary decision — the AI is trusted to choose the anchor, so there is no "needs a
		// human" middle state. A link needs a good pair AND an anchor that actually exists in
		// the passage; anything else is simply not linked.
		$pair_ok = $v['topic_match'] && $v['product_match'] && $v['jurisdiction_match'] && (int) $v['pair_score'] >= $pair_min;

		// A lone filler word ("helps", "buy", "trust") is not usable link text, however good the
		// pair is — a reader cannot tell where it goes.
		$weak_anchor = ( '' !== $grounded ) && AIIL_Inserter::is_weak_lone_anchor( $grounded, $target->post_title );

		if ( ! $pair_ok || '' === $grounded || $weak_anchor ) {
			$signals['verified']      = false;
			$signals['reject_reason'] = ! $pair_ok ? 'pair' : ( $weak_anchor ? 'weak_anchor' : 'no_anchor_in_passage' );
			$wpdb->update( $t, array( 'status' => 'rejected_relevance', 'anchor_text' => null, 'confidence' => null, 'signals' => wp_json_encode( $signals ) ), array( 'id' => (int) $opportunity_id ) );
			AIIL_Placement::clear_stash( (int) $opportunity_id );
			return array( 'result' => 'rejected', 'inserted' => false );
		}

		// Verified: pair is good AND we have a grounded, specific anchor chosen by the AI.
		// Adopt that anchor (it supersedes the mechanical placement guess) and re-point the
		// insertion stash at the same passage so the link lands there.
		$signals['verified'] = true;
		$wpdb->update(
			$t,
			array(
				'status'      => 'verified',
				'anchor_text' => mb_substr( $grounded, 0, 255 ),
				'confidence'  => (int) $v['pair_score'],
				'signals'     => wp_json_encode( $signals ),
			),
			array( 'id' => (int) $opportunity_id )
		);
		$stash = AIIL_Placement::get_stash( (int) $opportunity_id );
		set_transient(
			'aiil_placement_' . (int) $opportunity_id,
			array(
				'sentence'   => $passage,
				'anchor'     => $grounded,
				'passage_id' => is_array( $stash ) && isset( $stash['passage_id'] ) ? (int) $stash['passage_id'] : 0,
				'rewrite'    => false,
			),
			DAY_IN_SECONDS
		);

		// Only NOW block the reverse direction — a real verified link exists to justify it.
		self::block_reverse( $sid, (int) $opp->target_post_id );

		$did_insert = false;
		if ( (int) AIIL_Settings::get( 'auto_insert', 0 ) === 1
			&& (int) $v['pair_score'] >= (int) AIIL_Settings::get( 'auto_min_confidence', 90 ) ) {
			try {
				AIIL_Inserter::insert_for_opportunity( (int) $opportunity_id );
				$did_insert = true;
			} catch ( Exception $e ) {
				AIIL_Logger::warning( 'Auto-insert after verify failed', array( 'opportunity_id' => $opportunity_id, 'error' => $e->getMessage() ) );
			}
		}

		return array( 'result' => 'kept', 'inserted' => $did_insert );
	}

	/**
	 * Re-bucket every opportunity that ALREADY has a stored AI verdict against the CURRENT
	 * thresholds — with no new API calls. This makes tuning pair/anchor cut-offs instant and
	 * free: the AI's judgement (pair/product/jurisdiction/scores and its chosen anchor) was
	 * cached in signals, so we only re-run the deterministic decision rules over it, then
	 * re-resolve reciprocal pairs and the per-source cap.
	 *
	 * Rows that were never AI-judged (e.g. capped before their turn) are left untouched.
	 *
	 * @return array{rejudged:int,verified:int,rejected:int}
	 */
	public static function reapply_thresholds() {
		global $wpdb;
		$t = AIIL_DB::opportunities_table();

		$pair_min = (int) AIIL_Settings::get( 'rerank_pair_min', 75 );
		$like     = '%' . $wpdb->esc_like( '"rerank"' ) . '%';
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT id, signals FROM {$t} WHERE signals LIKE %s", $like )
		);

		$counts = array( 'rejudged' => 0, 'verified' => 0, 'rejected' => 0 );

		foreach ( $rows as $row ) {
			$signals = json_decode( (string) $row->signals, true );
			if ( ! is_array( $signals ) || empty( $signals['rerank'] ) || ! is_array( $signals['rerank'] ) ) {
				continue;
			}
			$v = $signals['rerank'];
			// Older verdicts (before AI anchor selection) may lack a grounded anchor; skip them
			// so we don't wrongly reject links that predate this scoring model.
			if ( ! array_key_exists( 'grounded', $v ) ) {
				continue;
			}
			$counts['rejudged']++;

			$pair_ok  = ! empty( $v['topic'] ) && ! empty( $v['product'] ) && ! empty( $v['jurisdiction'] ) && (int) ( $v['pair_score'] ?? 0 ) >= $pair_min;
			$grounded = (string) ( $v['grounded'] ?? '' );

			if ( ! $pair_ok || '' === $grounded ) {
				$signals['verified']      = false;
				$signals['reject_reason'] = ! $pair_ok ? 'pair' : 'no_anchor_in_passage';
				$wpdb->update( $t, array( 'status' => 'rejected_relevance', 'anchor_text' => null, 'confidence' => null, 'signals' => wp_json_encode( $signals ) ), array( 'id' => (int) $row->id ) );
				AIIL_Placement::clear_stash( (int) $row->id );
				$counts['rejected']++;
			} else {
				$signals['verified'] = true;
				$wpdb->update(
					$t,
					array( 'status' => 'verified', 'anchor_text' => mb_substr( $grounded, 0, 255 ), 'confidence' => (int) ( $v['pair_score'] ?? 0 ), 'signals' => wp_json_encode( $signals ) ),
					array( 'id' => (int) $row->id )
				);
				$passage = isset( $signals['best_passage'] ) ? (string) $signals['best_passage'] : '';
				set_transient(
					'aiil_placement_' . (int) $row->id,
					array( 'sentence' => $passage, 'anchor' => $grounded, 'passage_id' => 0, 'rewrite' => false ),
					DAY_IN_SECONDS
				);
				$counts['verified']++;
			}
		}

		// Re-resolve now that the verified set changed.
		AIIL_Placement::resolve_reciprocal( 'verified' );
		AIIL_Placement::enforce_source_caps( 'verified' );

		AIIL_Logger::info( 'Re-applied AI thresholds (no new calls)', $counts + array( 'pair_min' => $pair_min ) );
		return $counts;
	}

	/**
	 * Confirm an AI-chosen anchor really exists in the passage and return the exact span from
	 * the passage (original casing), tolerating whitespace differences. Returns '' if it is not
	 * a genuine word-bounded span of the passage — so a hallucinated or paraphrased anchor is
	 * never used.
	 */
	protected static function ground_anchor( $anchor, $passage ) {
		$anchor = trim( preg_replace( '/\s+/u', ' ', (string) $anchor ) );
		if ( '' === $anchor || mb_strlen( $anchor ) < 2 ) {
			return '';
		}
		$tokens = preg_split( '/\s+/u', $anchor );
		$parts  = array_map(
			function ( $tok ) {
				return preg_quote( $tok, '/' );
			},
			$tokens
		);
		$pattern = '/(?<![\p{L}\p{N}])' . implode( '\s+', $parts ) . '(?![\p{L}\p{N}])/iu';
		if ( preg_match( $pattern, (string) $passage, $m ) ) {
			return trim( $m[0] );
		}
		return '';
	}

	/**
	 * Block the reverse direction of a just-verified link (target -> source) if it is still an
	 * undecided candidate. Never touches a direction that already earned its own kept state.
	 */
	protected static function block_reverse( $source_id, $target_id ) {
		if ( (int) AIIL_Settings::get( 'avoid_reciprocal', 1 ) !== 1 ) {
			return;
		}
		global $wpdb;
		$t   = AIIL_DB::opportunities_table();
		$rev = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id FROM {$t} WHERE source_post_id = %d AND target_post_id = %d AND status IN ('pending','ready','rewrite_suggested') LIMIT 1",
				(int) $target_id,
				(int) $source_id
			)
		);
		if ( $rev ) {
			$wpdb->update( $t, array( 'status' => 'reciprocal', 'anchor_text' => null, 'confidence' => null ), array( 'id' => (int) $rev->id ) );
			AIIL_Placement::clear_stash( (int) $rev->id );
		}
	}

	/** A short excerpt of the target for the reranker: its first couple of passages. */
	protected static function excerpt( $post_id, $post ) {
		$passages = AIIL_Indexer::passages( (int) $post_id );
		if ( ! empty( $passages ) ) {
			$parts = array();
			foreach ( array_slice( $passages, 0, 2 ) as $p ) {
				$parts[] = $p->passage_text;
			}
			return implode( ' ', $parts );
		}
		return wp_trim_words( wp_strip_all_tags( (string) $post->post_content ), 80 );
	}
}
