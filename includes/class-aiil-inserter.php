<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AIIL_Inserter {

	/** Short connector words allowed INSIDE an anchor phrase (e.g. "car rental in Singapore"). */
	protected static $bridge = array(
		'in'=>1,'of'=>1,'for'=>1,'and'=>1,'the'=>1,'a'=>1,'an'=>1,'to'=>1,'on'=>1,'with'=>1,'at'=>1,'by'=>1,'or'=>1,
	);

	/** Common / filler words that must NOT count as a target "topic" word when refining anchors. */
	protected static $keystop = array(
		'you'=>1,'your'=>1,'our'=>1,'are'=>1,'is'=>1,'was'=>1,'were'=>1,'be'=>1,'been'=>1,'has'=>1,'have'=>1,'had'=>1,
		'how'=>1,'why'=>1,'what'=>1,'when'=>1,'where'=>1,'who'=>1,'this'=>1,'that'=>1,'these'=>1,'those'=>1,'it'=>1,'its'=>1,
		'as'=>1,'about'=>1,'more'=>1,'most'=>1,'best'=>1,'top'=>1,'will'=>1,'can'=>1,'could'=>1,'would'=>1,'should'=>1,'may'=>1,
		'new'=>1,'guide'=>1,'tips'=>1,'into'=>1,'from'=>1,'after'=>1,'before'=>1,'here'=>1,'now'=>1,'truth'=>1,'forever'=>1,
		// Pronouns / verbs / generic nouns that are frequent in ANY article body. Without these
		// the "appears twice in the target" rule promoted words like "they", "also" and "get"
		// to topic words, producing anchors such as "they also get health transport billing".
		'they'=>1,'them'=>1,'their'=>1,'there'=>1,'then'=>1,'than'=>1,'also'=>1,'just'=>1,'only'=>1,'even'=>1,'such'=>1,
		'get'=>1,'gets'=>1,'got'=>1,'make'=>1,'makes'=>1,'made'=>1,'take'=>1,'takes'=>1,'give'=>1,'gives'=>1,'keep'=>1,
		'use'=>1,'uses'=>1,'used'=>1,'using'=>1,'need'=>1,'needs'=>1,'want'=>1,'wants'=>1,'help'=>1,'helps'=>1,
		'one'=>1,'two'=>1,'all'=>1,'any'=>1,'some'=>1,'many'=>1,'much'=>1,'other'=>1,'others'=>1,'same'=>1,'own'=>1,
		'way'=>1,'ways'=>1,'thing'=>1,'things'=>1,'time'=>1,'times'=>1,'day'=>1,'days'=>1,'year'=>1,'years'=>1,
		'good'=>1,'great'=>1,'well'=>1,'very'=>1,'like'=>1,'while'=>1,'because'=>1,'through'=>1,'over'=>1,'under'=>1,
	);

	/**
	 * Words that read as filler when they are the ENTIRE anchor. They may be perfectly good
	 * inside a phrase ("brake pads wear out"), so this list is only consulted for one-word
	 * anchors — verbs and abstract nouns that tell a reader nothing about the destination.
	 */
	protected static $weak_lone = array(
		'buy'=>1,'buys'=>1,'buying'=>1,'sell'=>1,'sells'=>1,'selling'=>1,'bid'=>1,'bids'=>1,'rent'=>1,'rents'=>1,
		'help'=>1,'helps'=>1,'prevent'=>1,'prevents'=>1,'protect'=>1,'protects'=>1,'perform'=>1,'performs'=>1,
		'develop'=>1,'develops'=>1,'provide'=>1,'provides'=>1,'ensure'=>1,'ensures'=>1,'offer'=>1,'offers'=>1,
		'include'=>1,'includes'=>1,'improve'=>1,'improves'=>1,'reduce'=>1,'reduces'=>1,'increase'=>1,'increases'=>1,
		'choose'=>1,'choosing'=>1,'select'=>1,'selecting'=>1,'find'=>1,'finding'=>1,'know'=>1,'knowing'=>1,
		'begin'=>1,'begins'=>1,'change'=>1,'changing'=>1,'getting'=>1,'approved'=>1,'trust'=>1,'expert'=>1,
		'key'=>1,'list'=>1,'lists'=>1,'variety'=>1,'factor'=>1,'factors'=>1,'future'=>1,'hours'=>1,'demand'=>1,
		'source'=>1,'sources'=>1,'quality'=>1,'value'=>1,'values'=>1,'price'=>1,'prices'=>1,'cost'=>1,'costs'=>1,
		'option'=>1,'options'=>1,'process'=>1,'result'=>1,'results'=>1,'level'=>1,'levels'=>1,'type'=>1,'types'=>1,
		'part'=>1,'parts'=>1,'item'=>1,'items'=>1,'place'=>1,'area'=>1,'areas'=>1,'point'=>1,'points'=>1,
		'step'=>1,'steps'=>1,'case'=>1,'cases'=>1,'issue'=>1,'issues'=>1,'problem'=>1,'problems'=>1,
		'reason'=>1,'reasons'=>1,'method'=>1,'methods'=>1,'idea'=>1,'ideas'=>1,'detail'=>1,'details'=>1,
		'information'=>1,'condition'=>1,'conditions'=>1,'standard'=>1,'standards'=>1,'range'=>1,'amount'=>1,
		'number'=>1,'numbers'=>1,'size'=>1,'sizes'=>1,'work'=>1,'works'=>1,'job'=>1,'jobs'=>1,'task'=>1,'tasks'=>1,
		'plan'=>1,'plans'=>1,'goal'=>1,'goals'=>1,'benefit'=>1,'benefits'=>1,'feature'=>1,'features'=>1,
		'experience'=>1,'support'=>1,'solution'=>1,'solutions'=>1,'service'=>1,'services'=>1,'system'=>1,'systems'=>1,
	);

	/**
	 * Would this anchor read as meaningless link text on its own?
	 *
	 * Only single words are ever rejected, and only when they are filler OR are not part of the
	 * destination's title. That keeps genuine one-word topics ("brakes", "windshield", "PPF",
	 * "dealership") while dropping verbs and vague nouns ("helps", "buy", "trust", "variety").
	 * Acronyms are always allowed — they are the most precise anchors a page can have.
	 */
	public static function is_weak_lone_anchor( $anchor, $target_title = '' ) {
		$anchor = trim( (string) $anchor );
		if ( '' === $anchor || preg_match( '/\s/u', $anchor ) ) {
			return false; // multi-word anchors are judged elsewhere
		}
		if ( preg_match( '/^[\p{Lu}\p{N}][\p{Lu}\p{N}\-]{1,7}$/u', $anchor ) ) {
			return false; // acronym / model code (PPF, DIY, SIP, 4WD)
		}
		$lc = mb_strtolower( preg_replace( '/[^\p{L}\p{N}\-]+/u', '', $anchor ) );
		if ( '' === $lc || isset( self::$weak_lone[ $lc ] ) ) {
			return true;
		}
		// Otherwise it must actually name the destination.
		foreach ( preg_split( '/[^\p{L}\p{N}]+/u', mb_strtolower( wp_strip_all_tags( (string) $target_title ) ), -1, PREG_SPLIT_NO_EMPTY ) as $w ) {
			if ( self::stem( $w ) === self::stem( $lc ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Is this word specific enough to anchor on? A word only counts as a target "topic" word if
	 * it is not a common filler AND the corpus says it is distinctive (IDF). The IDF check is
	 * what stops site-wide vocabulary ("transport" on a transport blog) from being treated as a
	 * topic marker — it is corpus-derived, so it stays niche-agnostic.
	 */
	protected static function is_topical( $word ) {
		$word = mb_strtolower( (string) $word );
		if ( mb_strlen( $word ) < 3 || isset( self::$bridge[ $word ] ) || isset( self::$keystop[ $word ] ) ) {
			return false;
		}
		if ( class_exists( 'AIIL_Idf' ) ) {
			return AIIL_Idf::idf( $word ) >= AIIL_Idf::distinctive_floor();
		}
		return true;
	}

	/**
	 * Crude stemmer so "coating"/"coatings" and "detail"/"detailing" match.
	 *
	 * Plurals are handled before the generic "-es" rule: stripping "es" from "brakes" gave
	 * "brak", which no longer matched "brake" in the target title — so a perfectly good one-word
	 * anchor looked like it had nothing to do with its destination.
	 */
	protected static function stem( $w ) {
		$w   = mb_strtolower( (string) $w );
		$len = strlen( $w );
		if ( $len <= 3 ) {
			return $w;
		}
		if ( 'ies' === substr( $w, -3 ) ) {
			return substr( $w, 0, -3 ) . 'y';           // companies -> company
		}
		if ( 'ings' === substr( $w, -4 ) ) {
			return substr( $w, 0, -4 );                  // coatings -> coat
		}
		if ( 'ing' === substr( $w, -3 ) && $len > 5 ) {
			return substr( $w, 0, -3 );                  // coating -> coat
		}
		if ( preg_match( '/(?:s|x|z|ch|sh)es$/', $w ) ) {
			return substr( $w, 0, -2 );                  // boxes -> box
		}
		if ( 's' === substr( $w, -1 ) && 'ss' !== substr( $w, -2 ) ) {
			return substr( $w, 0, -1 );                  // brakes -> brake, tyres -> tyre
		}
		return $w;
	}

	/**
	 * Improve an anchor deterministically (no AI call): replace/expand it with the longest
	 * phrase that (a) actually exists in the source passage and (b) overlaps the TARGET title.
	 *
	 * The AI often grabs a single word ("Ceramic") when the passage contains the fuller phrase
	 * ("Ceramic coatings"), or an off-topic word ("quietly") when the on-topic phrase ("car
	 * rental in Singapore") is right there. This picks the richest target-overlapping phrase.
	 *
	 * @return string
	 */
	public static function refine_anchor( $anchor, $passage, $target_title, $target_content = '' ) {
		$anchor  = trim( (string) $anchor );
		$passage = (string) $passage;
		if ( '' === $anchor || '' === $passage ) {
			return $anchor;
		}

		// "Topic" stems that mark the target: every content word in its TITLE, plus the words its
		// BODY repeats (freq >= 2). The body vocabulary lets us expand a lone verb like "Document"
		// into "Document the scene" — a real phrase in the source that describes the target — even
		// when the exact title phrase isn't present in the source passage.
		// Title words define the target's identity, so they qualify on the filler check alone.
		$ttok = array();
		foreach ( preg_split( '/[^\p{L}\p{N}]+/u', mb_strtolower( wp_strip_all_tags( (string) $target_title ) ), -1, PREG_SPLIT_NO_EMPTY ) as $w ) {
			if ( mb_strlen( $w ) >= 3 && ! isset( self::$bridge[ $w ] ) && ! isset( self::$keystop[ $w ] ) ) {
				$ttok[ self::stem( $w ) ] = true;
			}
		}
		// Body words must ALSO be corpus-distinctive: "appears twice in the target" alone let
		// ordinary words through and produced nonsense anchors.
		if ( '' !== $target_content ) {
			$freq = array();
			foreach ( preg_split( '/[^\p{L}\p{N}]+/u', mb_strtolower( wp_strip_all_tags( (string) $target_content ) ), -1, PREG_SPLIT_NO_EMPTY ) as $w ) {
				if ( self::is_topical( $w ) ) {
					$freq[ $w ] = ( $freq[ $w ] ?? 0 ) + 1;
				}
			}
			foreach ( $freq as $w => $c ) {
				if ( $c >= 2 ) {
					$ttok[ self::stem( $w ) ] = true;
				}
			}
		}
		if ( empty( $ttok ) ) {
			return $anchor;
		}

		if ( ! preg_match_all( '/[\p{L}\p{N}]+/u', $passage, $tm, PREG_OFFSET_CAPTURE ) ) {
			return $anchor;
		}
		// Track what sits BETWEEN tokens. A phrase must never span a sentence or clause boundary:
		// the stored passage is flattened plain text, but the inserter can only place a link
		// inside ONE HTML block, so a span crossing a full stop (often a paragraph break too) can
		// never be found in the live post — it fails to insert forever. This is what produced
		// anchors like "DDC Wheels. Their dually wheels".
		$toks     = array();
		$prev_end = null;
		foreach ( $tm[0] as $t ) {
			$start = $t[1];
			$brk   = false;
			if ( null !== $prev_end ) {
				$gap = substr( $passage, $prev_end, $start - $prev_end );
				$brk = (bool) preg_match( '/[.!?;:,\r\n|()\[\]"]|—|–|--/u', $gap );
			}
			$lc     = mb_strtolower( $t[0] );
			$type   = isset( $ttok[ self::stem( $lc ) ] ) ? 'key' : ( isset( self::$bridge[ $lc ] ) ? 'bridge' : 'other' );
			$toks[] = array( 'start' => $start, 'end' => $start + strlen( $t[0] ), 'type' => $type, 'brk' => $brk );
			$prev_end = $start + strlen( $t[0] );
		}

		// Longest run bounded by key tokens (interior key/bridge), up to 6 words, richest in keys.
		$best       = null;
		$best_multi = null;
		$n          = count( $toks );
		for ( $i = 0; $i < $n; $i++ ) {
			if ( 'key' !== $toks[ $i ]['type'] ) {
				continue;
			}
			$keys = 0;
			$last = $i;
			for ( $j = $i; $j < $n && ( $j - $i ) < 6; $j++ ) {
				if ( $j > $i && $toks[ $j ]['brk'] ) {
					break; // punctuation boundary — stop the phrase here
				}
				if ( 'key' === $toks[ $j ]['type'] ) {
					$keys++;
					$last = $j;
				} elseif ( 'bridge' !== $toks[ $j ]['type'] ) {
					break;
				}
			}
			$words = $last - $i + 1;
			$cand  = array( 'start' => $toks[ $i ]['start'], 'end' => $toks[ $last ]['end'], 'keys' => $keys, 'words' => $words );
			if ( null === $best || $keys > $best['keys'] || ( $keys === $best['keys'] && $words > $best['words'] ) ) {
				$best = $cand;
			}
			// Track the best MULTI-word phrase separately, so a one-word winner that reads as
			// filler can be traded for a real phrase instead.
			if ( $words > 1 && ( null === $best_multi || $keys > $best_multi['keys'] || ( $keys === $best_multi['keys'] && $words > $best_multi['words'] ) ) ) {
				$best_multi = $cand;
			}
		}
		if ( null === $best ) {
			return $anchor;
		}

		$grab = function ( $pick ) use ( $passage ) {
			return trim( substr( $passage, $pick['start'], $pick['end'] - $pick['start'] ) );
		};

		$phrase = $grab( $best );

		// A lone filler word is worse link text than a shorter-scoring real phrase.
		if ( null !== $best_multi && self::is_weak_lone_anchor( $phrase, $target_title ) ) {
			$phrase = $grab( $best_multi );
			$best   = $best_multi;
		}

		$anchor_words  = count( preg_split( '/\s+/u', $anchor, -1, PREG_SPLIT_NO_EMPTY ) );
		$anchor_is_key = isset( $ttok[ self::stem( preg_replace( '/[^\p{L}\p{N}]+/u', '', mb_strtolower( $anchor ) ) ) ] );

		// Prefer the richer phrase: more words than the current anchor, the current anchor is not
		// even a target term (e.g. "quietly"), or the current anchor is filler on its own.
		if ( '' !== $phrase
			&& ( $best['words'] > $anchor_words || ! $anchor_is_key || self::is_weak_lone_anchor( $anchor, $target_title ) ) ) {
			return $phrase;
		}
		return $anchor;
	}

	/**
	 * When true, the save_post hook should ignore the next update — it was
	 * triggered by us inserting a link, not by a real edit. See AIIL_Hooks::on_save_post.
	 *
	 * @var int[]  Post IDs to skip on the next save_post tick.
	 */
	protected static $skip_post_ids = array();

	public static function should_skip_save_hook( $post_id ) {
		return in_array( (int) $post_id, self::$skip_post_ids, true );
	}

	public static function insert_for_opportunity( $opportunity_id, $override_anchor = null ) {
		global $wpdb;

		$opportunity = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM " . AIIL_DB::opportunities_table() . " WHERE id = %d", (int) $opportunity_id )
		);
		if ( ! $opportunity ) {
			throw new Exception( 'Opportunity not found: ' . $opportunity_id );
		}

		$source = get_post( (int) $opportunity->source_post_id );
		$target = get_post( (int) $opportunity->target_post_id );
		if ( ! $source || ! $target ) {
			throw new Exception( 'Missing source/target post.' );
		}

		// Reciprocal guard: if the reverse direction (target -> source) already has an
		// active link, don't also link this direction. Evaluate each direction on its own.
		if ( (int) AIIL_Settings::get( 'avoid_reciprocal', 1 ) === 1 ) {
			$reverse = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM " . AIIL_DB::links_table() . "
					 WHERE source_post_id = %d AND target_post_id = %d AND status = %s",
					(int) $opportunity->target_post_id,
					(int) $opportunity->source_post_id,
					'active'
				)
			);
			if ( $reverse > 0 ) {
				$wpdb->update( AIIL_DB::opportunities_table(), array( 'status' => 'reciprocal' ), array( 'id' => (int) $opportunity_id ) );
				throw new Exception( 'Skipped: the reverse link already exists (reciprocal).' );
			}
		}

		$stashed  = AIIL_Placement::get_stash( (int) $opportunity_id );
		$sentence = $stashed && ! empty( $stashed['sentence'] ) ? $stashed['sentence'] : '';
		$stored   = (string) $opportunity->anchor_text;

		// Candidate anchors, best first. The refined phrase is preferred, but if it cannot be
		// placed we fall back to the stored anchor rather than failing the whole insertion —
		// a refinement should never be able to lose us a good link.
		$candidates = array();
		if ( null !== $override_anchor ) {
			$candidates[] = (string) $override_anchor;
		} else {
			if ( '' !== $sentence ) {
				$candidates[] = self::refine_anchor( $stored, $sentence, $target->post_title, $target->post_content );
			}
			$candidates[] = $stored;
		}
		$candidates = array_values( array_unique( array_filter( array_map( 'trim', $candidates ) ) ) );

		if ( empty( $candidates ) ) {
			throw new Exception( 'No anchor text on opportunity ' . $opportunity_id );
		}

		// Atomically claim the opportunity so two concurrent approvals (double-click /
		// auto-insert racing a manual approve) can't both insert the link. Only one
		// UPDATE can move it out of pending/ready into 'inserting'.
		$claimed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE " . AIIL_DB::opportunities_table() . "
				 SET status = %s WHERE id = %d AND status IN (%s, %s, %s, %s, %s)",
				'inserting',
				(int) $opportunity_id,
				'pending',
				'ready',
				'rewrite_suggested',
				'verified',
				'insert_failed'
			)
		);
		if ( 1 !== (int) $claimed ) {
			throw new Exception( 'Opportunity is already being inserted or is no longer pending: ' . $opportunity_id );
		}

		try {
			$target_url  = get_permalink( $target );
			$new_content = null;
			$anchor      = '';

			foreach ( $candidates as $candidate ) {
				$attempt = self::replace_anchor( $source->post_content, $sentence, $candidate, $target_url );
				if ( $attempt !== $source->post_content ) {
					$new_content = $attempt;
					$anchor      = $candidate;
					break;
				}
			}

			if ( null === $new_content ) {
				throw new Exception( 'Could not locate anchor "' . $candidates[0] . '" in source content.' );
			}

			self::$skip_post_ids[] = (int) $source->ID;
			$result = wp_update_post(
				array(
					'ID'           => (int) $source->ID,
					'post_content' => $new_content,
				),
				true
			);
			self::$skip_post_ids = array_diff( self::$skip_post_ids, array( (int) $source->ID ) );

			if ( is_wp_error( $result ) || 0 === (int) $result ) {
				$msg = is_wp_error( $result ) ? $result->get_error_message() : 'wp_update_post returned 0';
				throw new Exception( 'wp_update_post failed: ' . $msg );
			}

			self::record_insertion( (int) $opportunity->source_post_id, (int) $opportunity->target_post_id, $anchor );

			$wpdb->update(
				AIIL_DB::opportunities_table(),
				array(
					'status'      => 'inserted',
					'anchor_text' => mb_substr( $anchor, 0, 255 ),
				),
				array( 'id' => (int) $opportunity_id )
			);

			AIIL_Placement::clear_stash( (int) $opportunity_id );

			self::bump_link_counts( (int) $opportunity->source_post_id, (int) $opportunity->target_post_id, 1 );
		} catch ( Exception $e ) {
			self::$skip_post_ids = array_diff( self::$skip_post_ids, array( (int) $source->ID ) );

			// Park the failure in a TERMINAL state instead of reverting to 'ready'.
			//
			// Reverting made the row eligible for AI verification again, so a permanently
			// unplaceable anchor was re-verified (a paid API call) and re-failed on every pass,
			// forever — burning money with no possible progress. 'insert_failed' is excluded from
			// the verify query, so the cycle stops. Nothing is lost: the reason is recorded, the
			// row stays claimable, and "Re-prepare" or a manual insert with your own anchor
			// brings it straight back.
			$signals = json_decode( (string) $opportunity->signals, true );
			$signals = is_array( $signals ) ? $signals : array();
			$signals['insert_error']    = mb_substr( $e->getMessage(), 0, 300 );
			$signals['insert_attempts'] = (int) ( $signals['insert_attempts'] ?? 0 ) + 1;
			$signals['insert_failed_at'] = current_time( 'mysql' );

			$wpdb->update(
				AIIL_DB::opportunities_table(),
				array( 'status' => 'insert_failed', 'signals' => wp_json_encode( $signals ) ),
				array( 'id' => (int) $opportunity_id )
			);
			throw $e;
		}

		AIIL_Logger::info(
			'Internal link inserted',
			array(
				'opportunity_id' => (int) $opportunity_id,
				'source'         => (int) $opportunity->source_post_id,
				'target'         => (int) $opportunity->target_post_id,
				'anchor'         => $anchor,
			)
		);

		return true;
	}

	/**
	 * Inject an <a> tag for the given anchor text into the post HTML.
	 *
	 * Strategy:
	 * 1. "Shield" headings (and other non-body regions) so a link can NEVER be placed inside
	 *    them — an internal link belongs in body copy, not an H2/H3, nav, caption, etc.
	 * 2. Walk block-level chunks (paragraphs, list items, quotes, table/def cells), locate the
	 *    AI-supplied sentence, and word-boundary replace the anchor there (never inside an <a>).
	 * 3. If sentence-aware location fails, fall back to the first valid occurrence in the
	 *    (still heading-shielded) content.
	 */
	public static function replace_anchor( $content, $sentence, $anchor, $url ) {
		$anchor = trim( $anchor );
		if ( '' === $anchor ) {
			return $content;
		}

		// Replace headings / non-linkable regions with opaque placeholders so nothing inside
		// them can match. They are restored verbatim at the end.
		$shields   = array();
		$protected = preg_replace_callback(
			'/<h[1-6]\b[^>]*>.*?<\/h[1-6]>|<figcaption\b[^>]*>.*?<\/figcaption>|<(?:nav|thead)\b[^>]*>.*?<\/(?:nav|thead)>/is',
			function ( $m ) use ( &$shields ) {
				$key             = '<!--AIILSHIELD' . count( $shields ) . '-->';
				$shields[ $key ] = $m[0];
				return $key;
			},
			(string) $content
		);

		// Match the anchor even when the author split it with inline markup — the stored passage
		// is flattened text, so "rental cars in Bozeman" can appear in the post as
		// "rental cars in <strong>Bozeman</strong>" and a literal match would never find it.
		// Only INLINE tags may sit between words; block tags would mean a different paragraph.
		$words = preg_split( '/\s+/u', $anchor, -1, PREG_SPLIT_NO_EMPTY );
		$parts = array();
		foreach ( $words as $w ) {
			$parts[] = preg_quote( $w, '/' );
		}
		$inline = 'strong|b|em|i|span|u|mark|small|sub|sup|code|abbr';
		$sep    = '(?:\s|&nbsp;|<\/?(?:' . $inline . ')\b[^>]*>)+';
		// Optional leading opens / trailing closes so a partially-marked-up phrase is captured
		// WHOLE ("<em>interior</em> cleaning", "rental cars in <strong>Bozeman</strong>").
		// Without these the match would stop mid-tag and produce broken HTML when wrapped.
		$open    = '(?:<(?:' . $inline . ')\b[^>]*>)*';
		$close   = '(?:<\/(?:' . $inline . ')\s*>)*';
		$pattern = '/' . $open . '(?<![\p{L}\p{N}])' . implode( $sep, $parts ) . '(?![\p{L}\p{N}])' . $close . '/iu';
		$min_gap = (int) AIIL_Settings::get( 'link_word_gap', 3 );

		$sentence = trim( (string) $sentence );
		$result   = null;

		// Preferred: the block that contains the AI-chosen sentence, and only if we can honour
		// the word-gap there. Otherwise fall through to a best-effort placement.
		if ( '' !== $sentence ) {
			$needle = self::normalize_for_match( $sentence );

			if ( preg_match_all( '/<(p|li|blockquote|dd|td)\b[^>]*>(.*?)<\/\1>/is', $protected, $m, PREG_OFFSET_CAPTURE ) ) {
				foreach ( $m[0] as $match ) {
					$block_html  = $match[0];
					$byte_offset = $match[1];

					$block_text = self::normalize_for_match( wp_strip_all_tags( $block_html ) );
					if ( '' === $block_text || false === mb_stripos( $block_text, $needle ) ) {
						continue;
					}

					$spot = self::best_offset( $block_html, $pattern, $min_gap );
					if ( $spot && $spot['gap'] >= $min_gap ) {
						$matched   = substr( $block_html, $spot['offset'], $spot['length'] );
						$new_block = substr( $block_html, 0, $spot['offset'] ) . self::build_link( $matched, $url ) . substr( $block_html, $spot['offset'] + $spot['length'] );
						$result    = substr( $protected, 0, $byte_offset ) . $new_block . substr( $protected, $byte_offset + strlen( $block_html ) );
						break;
					}
				}
			}
		}

		if ( null === $result ) {
			// Best-effort fallback: the spot in the whole (heading-shielded) content that sits
			// furthest from any existing link, so a new link never crowds an old one — even when
			// no spot fully meets the gap, we pick the roomiest available.
			$spot = self::best_offset( $protected, $pattern, $min_gap );
			if ( $spot ) {
				$matched = substr( $protected, $spot['offset'], $spot['length'] );
				$result  = substr( $protected, 0, $spot['offset'] ) . self::build_link( $matched, $url ) . substr( $protected, $spot['offset'] + $spot['length'] );
			}
		}

		// Restore the shielded headings/regions verbatim.
		return strtr( null === $result ? $protected : $result, $shields );
	}

	/**
	 * Wrap the matched source text in our link, keeping whatever inline markup it already had —
	 * so linking "rental cars in <strong>Bozeman</strong>" preserves the author's formatting
	 * instead of flattening it. data-aiil marks the link as ours so "Remove inserted links" can
	 * find and unwrap exactly our own links later.
	 */
	/**
	 * True when every inline tag opened in this fragment is also closed in it (and never closed
	 * before it is opened). Guards against wrapping a span that would produce invalid markup.
	 */
	protected static function tags_balanced( $html ) {
		if ( ! preg_match_all( '/<(\/?)([a-z0-9]+)\b[^>]*>/i', (string) $html, $m, PREG_SET_ORDER ) ) {
			return true; // no tags at all
		}
		$stack = array();
		foreach ( $m as $tag ) {
			$name = strtolower( $tag[2] );
			if ( '' === $tag[1] ) {
				$stack[] = $name;
			} else {
				if ( empty( $stack ) || array_pop( $stack ) !== $name ) {
					return false; // closed something that was not open here
				}
			}
		}
		return empty( $stack );
	}

	protected static function build_link( $matched, $url ) {
		$inner = (string) $matched;
		if ( (int) AIIL_Settings::get( 'bold_links', 1 ) === 1 && ! preg_match( '/<(?:strong|b)\b/i', $inner ) ) {
			$inner = '<strong>' . $inner . '</strong>';
		}
		return '<a href="' . esc_url( $url ) . '" data-aiil="1">' . $inner . '</a>';
	}

	/**
	 * Choose where to place the anchor: the occurrence that maximises the word distance to the
	 * nearest existing <a> link. Occurrences inside an existing link are skipped entirely.
	 *
	 * @return array{offset:int,length:int,gap:int}|null  Byte offset + length of the chosen
	 *         occurrence and the word gap to the nearest existing link (PHP_INT_MAX when there
	 *         are no existing links). Null when the anchor does not occur in $html.
	 */
	protected static function best_offset( $html, $pattern, $min_gap ) {
		if ( ! preg_match_all( $pattern, $html, $mm, PREG_OFFSET_CAPTURE ) ) {
			return null;
		}

		$links = array();
		if ( preg_match_all( '/<a\b[^>]*>.*?<\/a>/is', $html, $lm, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $lm[0] as $l ) {
				$links[] = array( $l[1], $l[1] + strlen( $l[0] ) );
			}
		}

		$best = null;
		foreach ( $mm[0] as $m ) {
			$cs  = $m[1];
			$ce  = $cs + strlen( $m[0] );
			$gap = PHP_INT_MAX;
			$bad = false;

			// Tag-tolerant matching could span an existing <a>, or stop mid-tag. Never nest links,
			// and never wrap a span whose inline tags are unbalanced — that would emit broken
			// HTML like "<a ...>text <strong>word</a></strong>".
			if ( false !== stripos( $m[0], '<a' ) || ! self::tags_balanced( $m[0] ) ) {
				continue;
			}

			foreach ( $links as $L ) {
				if ( $cs >= $L[0] && $ce <= $L[1] ) {
					$bad = true; // occurrence sits inside an existing link
					break;
				}
				if ( $L[1] <= $cs ) {
					$between = substr( $html, $L[1], $cs - $L[1] );
				} elseif ( $L[0] >= $ce ) {
					$between = substr( $html, $ce, $L[0] - $ce );
				} else {
					$between = '';
				}
				$words = count( preg_split( '/\s+/u', trim( wp_strip_all_tags( $between ) ), -1, PREG_SPLIT_NO_EMPTY ) );
				$gap   = min( $gap, $words );
			}
			if ( $bad ) {
				continue;
			}

			if ( null === $best || $gap > $best['gap'] ) {
				$best = array( 'offset' => $cs, 'length' => strlen( $m[0] ), 'gap' => $gap );
				// Once we clear the gap next to a real link, stop — the earliest qualifying spot
				// reads most naturally.
				if ( $gap >= $min_gap && ! empty( $links ) ) {
					break;
				}
			}
		}

		return $best;
	}

	/**
	 * Remove every internal link this plugin inserted from post content, restoring the plain
	 * anchor text. Editorial links are left untouched. Marks the link rows removed and fixes
	 * the counters; opportunities that were 'inserted' revert to 'verified' so state stays sane.
	 *
	 * @return array{removed:int,posts:int,missing:int}
	 */
	public static function remove_all_inserted_links() {
		global $wpdb;

		$links = $wpdb->get_results(
			$wpdb->prepare( "SELECT id, source_post_id, target_post_id, anchor_text FROM " . AIIL_DB::links_table() . " WHERE status = %s", 'active' )
		);

		// Group by source post so each post is edited once.
		$by_source = array();
		foreach ( $links as $l ) {
			$by_source[ (int) $l->source_post_id ][] = $l;
		}

		$removed = 0;
		$posts   = 0;
		$missing = 0;

		foreach ( $by_source as $source_id => $rows ) {
			$post = get_post( (int) $source_id );
			if ( ! $post ) {
				$missing += count( $rows );
				continue;
			}
			$content = $post->post_content;
			$changed = false;

			foreach ( $rows as $row ) {
				$new = self::unwrap_link( $content, (int) $row->target_post_id, (string) $row->anchor_text );
				if ( null !== $new ) {
					$content = $new;
					$changed = true;
					$removed++;
					$wpdb->update( AIIL_DB::links_table(), array( 'status' => 'removed' ), array( 'id' => (int) $row->id ) );
					self::bump_link_counts( (int) $source_id, (int) $row->target_post_id, -1 );
					$wpdb->update(
						AIIL_DB::opportunities_table(),
						array( 'status' => 'verified' ),
						array( 'source_post_id' => (int) $source_id, 'target_post_id' => (int) $row->target_post_id, 'status' => 'inserted' )
					);
				} else {
					$missing++;
				}
			}

			if ( $changed ) {
				self::$skip_post_ids[] = (int) $source_id;
				wp_update_post( array( 'ID' => (int) $source_id, 'post_content' => $content ) );
				self::$skip_post_ids = array_diff( self::$skip_post_ids, array( (int) $source_id ) );
				$posts++;
			}
		}

		AIIL_Logger::info( 'Removed inserted internal links', array( 'removed' => $removed, 'posts' => $posts, 'not_found' => $missing ) );
		return array( 'removed' => $removed, 'posts' => $posts, 'missing' => $missing );
	}

	/**
	 * Unwrap ONE plugin link to $target_id in $content, returning the modified content (or null
	 * if not found). Prefers a data-aiil="1" marked link; falls back to matching an <a> whose
	 * href resolves to the target and whose text equals the recorded anchor (for links inserted
	 * before the marker existed). The wrapping <strong> we may have added is removed too.
	 */
	protected static function unwrap_link( $content, $target_id, $anchor ) {
		$permalink = get_permalink( (int) $target_id );
		$path      = $permalink ? trim( (string) wp_parse_url( $permalink, PHP_URL_PATH ), '/' ) : '';
		$anchor_lc = self::normalize_for_match( wp_strip_all_tags( (string) $anchor ) );

		if ( ! preg_match_all( '/<a\b[^>]*>.*?<\/a>/is', (string) $content, $m, PREG_OFFSET_CAPTURE ) ) {
			return null;
		}

		$fallback = null; // href+anchor match, used if no marked link is found
		foreach ( $m[0] as $match ) {
			$tag    = $match[0];
			$offset = $match[1];

			// Does this <a> point at the target?
			$href = '';
			if ( preg_match( '/href\s*=\s*("|\')(.*?)\1/i', $tag, $hm ) ) {
				$href = html_entity_decode( $hm[2], ENT_QUOTES, 'UTF-8' );
			}
			$points_to_target = ( '' !== $path && false !== strpos( $href, $path ) )
				|| (bool) preg_match( '/[?&](?:p|page_id)=' . (int) $target_id . '\b/', $href );
			if ( ! $points_to_target ) {
				continue;
			}

			$inner = preg_replace( '/^<a\b[^>]*>|<\/a>$/i', '', $tag );
			$plain = self::normalize_for_match( wp_strip_all_tags( $inner ) );

			if ( false !== strpos( $tag, 'data-aiil' ) ) {
				return substr( $content, 0, $offset ) . self::unwrap_inner( $inner ) . substr( $content, $offset + strlen( $tag ) );
			}
			if ( null === $fallback && '' !== $anchor_lc && $plain === $anchor_lc ) {
				$fallback = array( $offset, strlen( $tag ), self::unwrap_inner( $inner ) );
			}
		}

		if ( $fallback ) {
			return substr( $content, 0, $fallback[0] ) . $fallback[2] . substr( $content, $fallback[0] + $fallback[1] );
		}
		return null;
	}

	/** Inner HTML of an unwrapped link, with a wrapping <strong> (added by bold_links) stripped. */
	protected static function unwrap_inner( $inner ) {
		$inner = trim( (string) $inner );
		if ( preg_match( '/^<strong>(.*)<\/strong>$/is', $inner, $sm ) ) {
			return $sm[1];
		}
		return $inner;
	}

	/**
	 * Normalize whitespace + lowercase for fuzzy sentence matching.
	 */
	protected static function normalize_for_match( $text ) {
		$text = preg_replace( '/\s+/u', ' ', (string) $text );
		return mb_strtolower( trim( $text ) );
	}

	protected static function record_insertion( $source_id, $target_id, $anchor ) {
		global $wpdb;
		$wpdb->insert(
			AIIL_DB::links_table(),
			array(
				'source_post_id' => (int) $source_id,
				'target_post_id' => (int) $target_id,
				'anchor_text'    => mb_substr( $anchor, 0, 255 ),
				'inserted_at'    => current_time( 'mysql' ),
				'status'         => 'active',
			)
		);
	}

	public static function bump_link_counts( $source_id, $target_id, $delta = 1 ) {
		global $wpdb;
		$delta = (int) $delta;
		$table = AIIL_DB::posts_table();

		self::ensure_post_row( (int) $source_id );
		self::ensure_post_row( (int) $target_id );

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET outgoing_links = GREATEST(0, outgoing_links + %d) WHERE post_id = %d AND blog_id = %d",
				$delta,
				(int) $source_id,
				get_current_blog_id()
			)
		);
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET incoming_links = GREATEST(0, incoming_links + %d) WHERE post_id = %d AND blog_id = %d",
				$delta,
				(int) $target_id,
				get_current_blog_id()
			)
		);
	}

	/**
	 * Create a stub wp_aiil_posts row so link counters have somewhere to land
	 * even if the post hasn't been analyzed yet.
	 */
	protected static function ensure_post_row( $post_id ) {
		global $wpdb;
		$table = AIIL_DB::posts_table();

		// INSERT IGNORE is atomic against the (post_id, blog_id) unique key: it creates
		// the stub only if no row exists, with no SELECT-then-INSERT race. If a real
		// metadata row already exists, this is a harmless no-op that leaves it untouched.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (post_id, blog_id, max_outgoing_links, updated_at)
				 VALUES (%d, %d, %d, %s)",
				(int) $post_id,
				get_current_blog_id(),
				(int) AIIL_Settings::get( 'max_outgoing_links', 3 ),
				current_time( 'mysql' )
			)
		);
	}

	public static function reject_opportunity( $opportunity_id ) {
		global $wpdb;
		$wpdb->update(
			AIIL_DB::opportunities_table(),
			array( 'status' => 'rejected' ),
			array( 'id' => (int) $opportunity_id )
		);
		AIIL_Placement::clear_stash( (int) $opportunity_id );
	}
}
