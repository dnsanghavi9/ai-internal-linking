<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * V2 placement — retrieval-first, grounded.
 *
 * For an opportunity (source -> target) we find the source PASSAGE whose embedding is most
 * similar to the target document. That passage is where the link belongs. The anchor is a
 * phrase that already exists in that passage and refers to the target (derived from the
 * target's own words — title + frequent phrases — so it needs no topic list). An optional
 * AI fallback picks the anchor / suggests a light rewrite only when nothing natural is found.
 */
class AIIL_Placement {

	/** Universal function words for cheap key-phrase extraction (niche-agnostic). */
	protected static $stop = array(
		'the'=>1,'and'=>1,'for'=>1,'with'=>1,'you'=>1,'your'=>1,'are'=>1,'from'=>1,'that'=>1,
		'this'=>1,'how'=>1,'what'=>1,'why'=>1,'when'=>1,'about'=>1,'into'=>1,'out'=>1,'get'=>1,
		'can'=>1,'will'=>1,'have'=>1,'has'=>1,'was'=>1,'were'=>1,'been'=>1,'more'=>1,'all'=>1,
		'use'=>1,'using'=>1,'via'=>1,'per'=>1,'a'=>1,'an'=>1,'of'=>1,'to'=>1,'in'=>1,'on'=>1,
		'at'=>1,'by'=>1,'is'=>1,'it'=>1,'as'=>1,'or'=>1,'be'=>1,'but'=>1,'not'=>1,'they'=>1,
		'their'=>1,'them'=>1,'these'=>1,'those'=>1,'which'=>1,'who'=>1,'whom'=>1,'than'=>1,
		'then'=>1,'also'=>1,'may'=>1,'should'=>1,'could'=>1,'would'=>1,'do'=>1,'does'=>1,
		'best'=>1,'top'=>1,'guide'=>1,'tips'=>1,'need'=>1,'know'=>1,'new'=>1,'ways'=>1,
	);

	public static function process_opportunity( $opportunity_id ) {
		global $wpdb;
		$opportunity_id = (int) $opportunity_id;

		$opp = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM " . AIIL_DB::opportunities_table() . " WHERE id = %d", $opportunity_id )
		);
		if ( ! $opp ) {
			return null;
		}
		if ( ! in_array( $opp->status, array( 'pending', 'ready', 'rewrite_suggested', 'no_anchor', 'low_relevance', 'insert_failed' ), true ) ) {
			return null; // decided (inserted/rejected/reciprocal)
		}

		$source = get_post( (int) $opp->source_post_id );
		$target = get_post( (int) $opp->target_post_id );
		if ( ! $source || ! $target ) {
			$wpdb->update( AIIL_DB::opportunities_table(), array( 'status' => 'invalid' ), array( 'id' => $opportunity_id ) );
			return null;
		}

		$target_meta = AIIL_Indexer::meta( (int) $target->ID );
		$target_vec  = $target_meta ? AIIL_Vector::decode( $target_meta->doc_vector ) : null;
		if ( ! $target_vec ) {
			$wpdb->update( AIIL_DB::opportunities_table(), array( 'status' => 'invalid' ), array( 'id' => $opportunity_id ) );
			return null;
		}

		// Center both the target document and the source passages by the same corpus mean, so
		// passage retrieval uses the same de-anisotropised, discriminative space as matching.
		$mean = AIIL_Matcher::corpus_mean();
		if ( $mean ) {
			$target_vec = AIIL_Vector::subtract( $target_vec, $mean );
		}

		// --- Retrieve the best source passage for this target --------------------------
		$best      = null;
		$best_score = -1.0;
		foreach ( AIIL_Indexer::passages( (int) $source->ID ) as $p ) {
			$vec = AIIL_Vector::decode( $p->vector );
			if ( ! $vec ) {
				continue;
			}
			if ( $mean ) {
				$vec = AIIL_Vector::subtract( $vec, $mean );
			}
			$score = AIIL_Vector::cosine( $vec, $target_vec );
			if ( $score > $best_score ) {
				$best_score = $score;
				$best       = $p;
			}
		}

		$passage_score = AIIL_Vector::score( $best_score );
		$min_passage   = (float) AIIL_Settings::get( 'min_passage_score', 55 );

		// Precision gate: no source passage is relevant enough to host this link.
		if ( ! $best || $passage_score < $min_passage ) {
			self::save( $opportunity_id, array(
				'status'             => 'low_relevance',
				'passage_similarity' => $passage_score,
				'signals'            => array( 'reason' => 'no_relevant_passage', 'passage_score' => $passage_score ),
			) );
			return null;
		}

		// NOTE: reciprocal de-dup and the per-source cap are intentionally NOT done here.
		// Both directions are prepared independently; a reverse direction is only blocked once
		// its counterpart is actually kept (verified, or ready when AI rerank is off), and the
		// cap is applied to the kept set. See AIIL_Placement::finalize_ready() and the reranker.

		// --- Anchor selection ----------------------------------------------------------
		$picked   = self::best_anchor( $best->text, $target );
		$anchor   = $picked ? $picked['anchor'] : '';
		$spec     = $picked ? $picked['spec'] : 0.0;
		$rewrite  = false;
		$sentence = $best->text;
		$ai_conf  = null;

		if ( '' === $anchor && (int) AIIL_Settings::get( 'use_ai_anchor', 0 ) === 1 ) {
			try {
				$ai = AIIL_Indexer::provider()->pick_anchor( $best->text, $target->post_title );
				$candidate = trim( (string) ( $ai['anchor'] ?? '' ) );
				$sent      = (string) ( $ai['sentence'] ?? $best->text );
				if ( '' !== $candidate && false !== mb_stripos( $sent, $candidate ) ) {
					$anchor   = $candidate;
					$spec     = self::phrase_spec( $candidate, $target );
					$sentence = $sent;
					$rewrite  = ! empty( $ai['rewrite'] );
					$ai_conf  = isset( $ai['confidence'] ) ? (int) $ai['confidence'] : null;
				}
			} catch ( Exception $e ) {
				AIIL_Logger::warning( 'AI anchor failed', array( 'opportunity_id' => $opportunity_id, 'error' => $e->getMessage() ) );
			}
		}

		$rerank_on = (int) AIIL_Settings::get( 'use_ai_rerank', 0 ) === 1;

		// Upgrade the mechanical single-word pick to the richest target-overlapping phrase in the
		// passage (e.g. "loan" -> "loan terms", "remove" -> "remove visible dirt"). This is what
		// makes anchors good on the deterministic (AI-verification-off) path too, not just at
		// insert time. No API call.
		if ( '' !== $anchor ) {
			$anchor = AIIL_Inserter::refine_anchor( $anchor, $sentence, $target->post_title, $target->post_content );
		}

		if ( '' === $anchor && ! $rerank_on ) {
			// AI verification is OFF, so the mechanical anchor is final — and there is no
			// distinctive one here (only generic words). Surface it rather than ship a weak link.
			self::save( $opportunity_id, array(
				'status'             => 'no_anchor',
				'passage_similarity' => $passage_score,
				'best_passage_id'    => (int) $best->id,
				'signals'            => array( 'passage_score' => $passage_score, 'reason' => 'no_distinctive_anchor', 'best_passage' => $best->text ),
			) );
			return null;
		}

		// With AI verification ON, the mechanical anchor (if any) is only a hint — the verify
		// step chooses the real anchor from this passage. So a relevant passage is kept as
		// 'ready' even when no distinctive word was found mechanically.
		$confidence = self::confidence( $passage_score, $anchor, $spec, $ai_conf );

		// No "needs a human" state — a prepared candidate is simply 'ready'. If the optional AI
		// anchor fallback lightly reworded the sentence, the inserter still handles it via the stash.
		$status = 'ready';
		self::save( $opportunity_id, array(
			'status'             => $status,
			'passage_similarity' => $passage_score,
			'best_passage_id'    => (int) $best->id,
			'anchor_text'        => mb_substr( $anchor, 0, 255 ),
			'confidence'         => $confidence,
			'signals'            => array(
				'passage_score' => $passage_score,
				'anchor_spec'   => round( $spec, 2 ),
				'retrieval'     => 'passage',
				'ai_anchor'     => ( null !== $ai_conf ),
				'best_passage'  => $best->text,
			),
		) );

		set_transient(
			'aiil_placement_' . $opportunity_id,
			array( 'sentence' => $sentence, 'anchor' => $anchor, 'passage_id' => (int) $best->id, 'rewrite' => $rewrite ),
			DAY_IN_SECONDS
		);

		// Auto-insert is deferred to finalize_ready() (rerank off) or the verify pass (rerank on),
		// so it acts only on the reciprocal-resolved, capped, kept set — never on a raw candidate.
		return array( 'status' => $status, 'anchor' => $anchor, 'confidence' => $confidence );
	}

	/**
	 * Choose the most *distinctive* anchor that already exists in the passage and refers to the
	 * target. Candidates come from the target's own words (title phrases up to 6 words, frequent
	 * content phrases, acronyms, and rare single terms). Each is scored by specificity (corpus
	 * IDF + title membership + acronym), and only a candidate above the corpus distinctiveness
	 * floor is accepted — so generic phrases shared across the whole site ("interest rates",
	 * "financial goals") are rejected instead of becoming weak links. No niche word lists.
	 *
	 * @return array{anchor:string,spec:float}|null
	 */
	protected static function best_anchor( $passage, $target ) {
		$candidates = self::target_candidates( $target );
		if ( empty( $candidates ) ) {
			return null;
		}
		$plain = ' ' . preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $passage ) ) . ' ';
		$floor = AIIL_Idf::distinctive_floor();

		$best      = null;
		$best_spec = -1.0;
		$best_len  = -1;
		foreach ( $candidates as $phrase => $spec ) {
			if ( $spec < $floor ) {
				continue;
			}
			$pattern = '/(?<![\p{L}\p{N}])' . preg_quote( $phrase, '/' ) . '(?![\p{L}\p{N}])/iu';
			if ( ! preg_match( $pattern, $plain, $m ) ) {
				continue;
			}
			$len = mb_strlen( $phrase );
			// Prefer higher specificity; break ties toward the longer (more descriptive) span.
			if ( $spec > $best_spec || ( $spec === $best_spec && $len > $best_len ) ) {
				$best      = trim( $m[0] );
				$best_spec = $spec;
				$best_len  = $len;
			}
		}

		return null === $best ? null : array( 'anchor' => $best, 'spec' => $best_spec );
	}

	/**
	 * Candidate anchor phrases for a target mapped to a specificity score.
	 *
	 * @return array<string,float> phrase => specificity
	 */
	public static function target_candidates( $target ) {
		$title_raw   = wp_strip_all_tags( (string) $target->post_title );
		$content_raw = wp_strip_all_tags( (string) $target->post_content );

		$title_lc = mb_strtolower( $title_raw );
		$out      = array();

		// 1) Title phrases, 2..6 words. Title membership is a strong "refers to the target" cue.
		//    Single title words are intentionally excluded here (a lone generic word like "loans"
		//    is a weak anchor); distinctive single terms are added via steps 3 and 4 instead.
		foreach ( self::ngrams( self::tokens( $title_raw ), 2, 6 ) as $g ) {
			$out[ $g ] = max( $out[ $g ] ?? 0, self::spec_for( $g, $title_lc, true ) );
		}

		// 2) Frequent content phrases (bi/tri-grams).
		$content_tokens = self::tokens( $content_raw );
		$freq           = array();
		foreach ( array( 2, 3 ) as $n ) {
			foreach ( self::ngrams( $content_tokens, $n, $n ) as $g ) {
				$freq[ $g ] = isset( $freq[ $g ] ) ? $freq[ $g ] + 1 : 1;
			}
		}
		foreach ( array_slice( array_keys( $freq ), 0, 40 ) as $g ) {
			$out[ $g ] = max( $out[ $g ] ?? 0, self::spec_for( $g, $title_lc, false ) );
		}

		// 3) Acronyms (all-caps tokens, 2-6 chars) from the ORIGINAL text — SIP, ULIP, IPO, PEO…
		if ( preg_match_all( '/\b([\p{Lu}][\p{Lu}\p{Nd}]{1,5})\b/u', $title_raw . ' ' . $content_raw, $am ) ) {
			foreach ( array_unique( $am[1] ) as $ac ) {
				$key         = mb_strtolower( $ac );
				$out[ $key ] = max( $out[ $key ] ?? 0, self::spec_for( $key, $title_lc, false ) + 3.0 );
			}
		}

		// 4) Rare single terms (high IDF) from the target — demat, forex, bookkeeping, payroll…
		$floor = AIIL_Idf::distinctive_floor();
		foreach ( array_unique( array_merge( self::real_tokens( $title_raw ), self::real_tokens( $content_raw ) ) ) as $tok ) {
			$idf = AIIL_Idf::idf( $tok );
			if ( $idf >= $floor ) {
				$out[ $tok ] = max( $out[ $tok ] ?? 0, self::spec_for( $tok, $title_lc, false ) );
			}
		}

		return $out;
	}

	/** Specificity of a phrase = most-distinctive token's IDF, plus a title-membership bonus. */
	protected static function spec_for( $phrase, $title_lc, $from_title ) {
		$spec = 0.0;
		foreach ( self::real_tokens( $phrase ) as $tok ) {
			$spec = max( $spec, AIIL_Idf::idf( $tok ) );
		}
		if ( $from_title || false !== mb_strpos( ' ' . $title_lc . ' ', ' ' . $phrase . ' ' ) ) {
			$spec += 1.5; // the phrase literally names the target
		}
		return $spec;
	}

	/** Specificity of an arbitrary phrase (used for the AI fallback anchor). */
	protected static function phrase_spec( $phrase, $target ) {
		return self::spec_for( mb_strtolower( trim( $phrase ) ), mb_strtolower( wp_strip_all_tags( (string) $target->post_title ) ), false );
	}

	/** Content tokens (no stopwords, len>=3) with no positional gaps — for IDF lookups. */
	protected static function real_tokens( $text ) {
		$out = array();
		foreach ( self::tokens( $text ) as $t ) {
			if ( '' !== $t ) {
				$out[] = $t;
			}
		}
		return $out;
	}

	protected static function tokens( $text ) {
		$words = preg_split( '/[^\p{L}\p{N}]+/u', mb_strtolower( (string) $text ), -1, PREG_SPLIT_NO_EMPTY );
		$out   = array();
		foreach ( $words as $w ) {
			$out[] = ( mb_strlen( $w ) < 3 || isset( self::$stop[ $w ] ) ) ? '' : $w;
		}
		return $out; // keep positions (empty = stopword boundary) so n-grams don't cross them
	}

	protected static function ngrams( array $tokens, $min, $max ) {
		$out   = array();
		$count = count( $tokens );
		for ( $i = 0; $i < $count; $i++ ) {
			$run = array();
			for ( $j = $i; $j < $count && count( $run ) < $max; $j++ ) {
				if ( '' === $tokens[ $j ] ) {
					break; // don't span a stopword boundary
				}
				$run[] = $tokens[ $j ];
				if ( count( $run ) >= $min ) {
					$out[] = implode( ' ', $run );
				}
			}
		}
		return $out;
	}

	protected static function confidence( $passage_score, $anchor, $spec, $ai_conf ) {
		$wc    = count( preg_split( '/\s+/u', trim( (string) $anchor ) ) );
		$floor = AIIL_Idf::distinctive_floor();
		// How far above the distinctiveness floor the anchor sits (0..~15 points).
		$spec_bonus = max( 0.0, min( 15.0, ( (float) $spec - $floor ) * 4.0 ) );
		$val        = ( 0.6 * (float) $passage_score ) + ( 3 * $wc ) + $spec_bonus + 10;
		if ( null !== $ai_conf ) {
			$val = ( $val + (float) $ai_conf ) / 2; // blend grounded score with AI's judgement
		}
		return (int) max( 20, min( 96, round( $val ) ) );
	}

	protected static function save( $opportunity_id, array $fields ) {
		global $wpdb;
		if ( isset( $fields['signals'] ) && is_array( $fields['signals'] ) ) {
			$fields['signals'] = wp_json_encode( $fields['signals'] );
		}
		$wpdb->update( AIIL_DB::opportunities_table(), $fields, array( 'id' => (int) $opportunity_id ) );
	}

	public static function get_stash( $opportunity_id ) {
		return get_transient( 'aiil_placement_' . (int) $opportunity_id );
	}

	public static function clear_stash( $opportunity_id ) {
		delete_transient( 'aiil_placement_' . (int) $opportunity_id );
	}

	/**
	 * Enforce the per-source outgoing-link budget on prepared results: each source keeps only
	 * its best `max_outgoing_links` ready opportunities (by confidence), counting links it has
	 * already inserted against the budget. The rest are demoted to 'capped' so a single source
	 * post can never present more than its allowance as final Ready. Re-evaluate restores them.
	 *
	 * @return int Number of opportunities demoted to 'capped'.
	 */
	public static function enforce_source_caps( $status = 'ready' ) {
		global $wpdb;
		$t       = AIIL_DB::opportunities_table();
		$posts   = AIIL_DB::posts_table();
		$blog_id = get_current_blog_id();
		$max     = (int) AIIL_Settings::get( 'max_outgoing_links', 3 );

		$sources = $wpdb->get_col(
			$wpdb->prepare( "SELECT DISTINCT source_post_id FROM {$t} WHERE status = %s", $status )
		);
		$capped = 0;

		foreach ( $sources as $sid ) {
			$sid     = (int) $sid;
			$already = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT outgoing_links FROM {$posts} WHERE post_id = %d AND blog_id = %d", $sid, $blog_id )
			);
			$budget = max( 0, $max - $already );
			$ids    = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT id FROM {$t} WHERE source_post_id = %d AND status = %s
					 ORDER BY confidence DESC, doc_similarity DESC, id ASC",
					$sid,
					$status
				)
			);
			$drop = array_slice( $ids, min( count( $ids ), $budget ) );
			foreach ( $drop as $id ) {
				$wpdb->update( $t, array( 'status' => 'capped' ), array( 'id' => (int) $id ) );
				self::clear_stash( (int) $id );
				$capped++;
			}
		}

		if ( $capped ) {
			AIIL_Logger::info( 'Enforced per-source link cap', array( 'from_status' => $status, 'demoted_to_capped' => $capped ) );
		}
		return $capped;
	}

	/**
	 * Resolve reciprocal pairs among links that are in the given kept status. For each unordered
	 * pair where BOTH directions are kept, the higher-confidence direction stays and the other is
	 * demoted to 'reciprocal'. Crucially this only ever runs on links that have already earned a
	 * kept state, so a rejected / capped / no-anchor direction never blocks its reverse.
	 *
	 * @return int Number of directions demoted to 'reciprocal'.
	 */
	public static function resolve_reciprocal( $status = 'ready' ) {
		if ( (int) AIIL_Settings::get( 'avoid_reciprocal', 1 ) !== 1 ) {
			return 0;
		}
		global $wpdb;
		$t = AIIL_DB::opportunities_table();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, source_post_id AS s, target_post_id AS t, confidence FROM {$t} WHERE status = %s",
				$status
			)
		);

		$by_dir = array();
		foreach ( $rows as $r ) {
			$by_dir[ (int) $r->s . '-' . (int) $r->t ] = $r;
		}

		$demoted = 0;
		$seen    = array();
		foreach ( $rows as $r ) {
			$a = (int) $r->s;
			$b = (int) $r->t;
			$key = $a < $b ? $a . '|' . $b : $b . '|' . $a;
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$rev = $by_dir[ $b . '-' . $a ] ?? null;
			if ( ! $rev ) {
				continue; // reverse isn't kept — nothing to resolve
			}
			$seen[ $key ] = true;
			// Keep the stronger direction; ties keep the lower id for determinism.
			$loser = ( (float) $r->confidence >= (float) $rev->confidence ) ? $rev : $r;
			$wpdb->update( $t, array( 'status' => 'reciprocal', 'anchor_text' => null, 'confidence' => null ), array( 'id' => (int) $loser->id ) );
			self::clear_stash( (int) $loser->id );
			$demoted++;
		}

		if ( $demoted ) {
			AIIL_Logger::info( 'Resolved reciprocal pairs', array( 'status' => $status, 'demoted' => $demoted ) );
		}
		return $demoted;
	}

	/**
	 * Auto-process one post's opportunities end-to-end (used when auto_link_new is on, after a
	 * post is published/edited). Prepares every pending opportunity that touches this post in
	 * either direction, then verifies (if AI rerank is on) and finalizes. Idempotent: rows that
	 * are already decided are skipped, so a retry after a timeout safely resumes.
	 */
	public static function finalize_post( $post_id ) {
		global $wpdb;
		$t       = AIIL_DB::opportunities_table();
		$post_id = (int) $post_id;

		$pending = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$t} WHERE ( source_post_id = %d OR target_post_id = %d ) AND status = 'pending'",
				$post_id,
				$post_id
			)
		);
		foreach ( $pending as $id ) {
			self::process_opportunity( (int) $id );
		}

		if ( (int) AIIL_Settings::get( 'use_ai_rerank', 0 ) === 1 && AIIL_Settings::has_api_key() ) {
			$ready = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT id FROM {$t} WHERE ( source_post_id = %d OR target_post_id = %d ) AND status = 'ready'
					 ORDER BY confidence DESC, id ASC",
					$post_id,
					$post_id
				)
			);
			foreach ( $ready as $id ) {
				AIIL_Reranker::verify_opportunity( (int) $id );
			}
			self::resolve_reciprocal( 'verified' );
			self::enforce_source_caps( 'verified' );
		} else {
			self::finalize_ready();
		}

		AIIL_Logger::info( 'Auto-linked post', array( 'post_id' => $post_id, 'prepared' => count( $pending ) ) );
	}

	/**
	 * Finalize the deterministic (AI-rerank OFF) pipeline once every candidate is prepared:
	 * resolve reciprocal pairs, enforce the per-source cap, then auto-insert the survivors that
	 * clear the confidence gate. Safe to call repeatedly.
	 *
	 * @return array{reciprocal:int,capped:int,inserted:int}
	 */
	public static function finalize_ready() {
		$reciprocal = self::resolve_reciprocal( 'ready' );
		$capped     = self::enforce_source_caps( 'ready' );
		$inserted   = 0;

		if ( (int) AIIL_Settings::get( 'auto_insert', 0 ) === 1 ) {
			global $wpdb;
			$t   = AIIL_DB::opportunities_table();
			$min = (int) AIIL_Settings::get( 'auto_min_confidence', 90 );
			$ids = $wpdb->get_col(
				$wpdb->prepare( "SELECT id FROM {$t} WHERE status = 'ready' AND confidence >= %d ORDER BY confidence DESC, id ASC", $min )
			);
			foreach ( $ids as $id ) {
				try {
					AIIL_Inserter::insert_for_opportunity( (int) $id );
					$inserted++;
				} catch ( Exception $e ) {
					AIIL_Logger::warning( 'Auto-insert failed', array( 'opportunity_id' => (int) $id, 'error' => $e->getMessage() ) );
				}
			}
		}

		return array( 'reciprocal' => $reciprocal, 'capped' => $capped, 'inserted' => $inserted );
	}
}
