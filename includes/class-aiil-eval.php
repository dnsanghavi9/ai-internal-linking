<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lightweight evaluation harness.
 *
 * Encodes the "should link" (positive) and "should NOT be a ready link" (negative)
 * pairs surfaced by the manual audit, resolved by post-title fragment so it survives
 * unknown post IDs. Running it reports candidate-retrieval recall, ready-link recall and
 * a precision proxy — so every change to the matcher can be scored instead of guessed.
 *
 * This is a regression fixture, not ground truth: fragments are matched case-insensitively
 * against published post titles; the first match wins.
 */
class AIIL_Eval {

	/**
	 * Pairs that SHOULD be discovered (ideally reach a ready/inserted direction).
	 * @return array<int,array{0:string,1:string}>
	 */
	public static function positives() {
		return array(
			array( 'How do you set a UPI PIN', 'Unlock the Power of UPI Payments on Your Credit Card' ),
			array( 'Your ADU In San Jose', 'Things to Consider When Building an ADU' ),
			array( 'Building Granny Suites', 'Your ADU In San Jose' ),
			array( 'Essential Options Trading Strategies For Beginners', 'Naked Options Trading Explained' ),
			array( 'Options Overload', 'Essential Options Trading Strategies For Beginners' ),
			array( 'Forex CFD Trading Tips', 'Keplero Reviews' ),
			array( 'Benefits of Early Investing in ULIP', 'All You Need To Know About ULIP Premiums' ),
			array( 'Benefits of Early Investing in ULIP', "Investing in ULIP for your child's higher education" ),
			array( 'Term Insurance Riders', 'Why Life Insurance is a Must-Have' ),
			array( 'Health Insurance in Singapore', 'Difference Between Network' ),
			array( 'Demystifying Demat Accounts', 'Mutual Fund Investment Using SIP' ),
			array( 'What is a Hybrid Mutual Fund', 'How to Save tax by investing in Mutual Fund' ),
			array( 'All You Need to Know to Open a Current Account', 'trust a bank account opening procedure' ),
			array( 'safe online bank account operation', 'trust a bank account opening procedure' ),
			array( 'Best Time to Send Money from the USA to India', 'What Is Mobile Money Transfer' ),
			array( 'How Brand Awareness Is Increased by SEO', 'Tampa Content Marketing' ),
			array( 'Bookkeeping for Entrepreneurs', 'Common Bookkeeping Errors' ),
			array( '3 Types of Taxes', 'Choosing the Right Business Path for Tax' ),
			array( 'Trying To Find Government Business Grants', 'Loans for Entrepreneurs with Big Ideas' ),
			array( 'payroll cycle', 'PEO Senegal' ),
		);
	}

	/**
	 * Pairs that should NOT surface as a ready link (weak / jurisdiction / product mismatch).
	 * @return array<int,array{0:string,1:string}>
	 */
	public static function negatives() {
		return array(
			array( 'How to Claim Insurance for Bike Damage', 'Difference Between Network' ),
			array( 'Federal Consolidation School Loans', 'Comprehend The Operation Behind Pay day' ),
			array( 'Benefits of Private Mortgage Loans', 'Understanding the Different Types of Personal Loans in India' ),
			array( '5 Benefits of Commercial Liability Insurance', 'Why Personal Accident Insurance' ),
			array( 'Gold trading made easy', 'SIP vs Lump Sum' ),
		);
	}

	public static function report() {
		$positives = array();
		$present   = 0;
		$ready     = 0;
		foreach ( self::positives() as $pair ) {
			$row = self::evaluate_pair( $pair[0], $pair[1] );
			if ( $row['resolved'] ) {
				if ( 'absent' !== $row['status'] ) {
					$present++;
				}
				if ( in_array( $row['status'], array( 'ready', 'verified', 'rewrite_suggested', 'inserted' ), true ) ) {
					$ready++;
				}
			}
			$positives[] = $row;
		}

		$negatives   = array();
		$suppressed  = 0;
		$leaked      = 0;
		foreach ( self::negatives() as $pair ) {
			$row = self::evaluate_pair( $pair[0], $pair[1] );
			if ( $row['resolved'] ) {
				if ( in_array( $row['status'], array( 'ready', 'verified', 'rewrite_suggested', 'inserted' ), true ) ) {
					$leaked++;
				} else {
					$suppressed++;
				}
			}
			$negatives[] = $row;
		}

		$resolved_pos = count( array_filter( $positives, function ( $r ) { return $r['resolved']; } ) );
		$resolved_neg = count( array_filter( $negatives, function ( $r ) { return $r['resolved']; } ) );

		return array(
			'generated_at' => current_time( 'mysql' ),
			'summary'      => array(
				'positives_resolved'    => $resolved_pos,
				'candidate_recall'      => $resolved_pos ? round( $present / $resolved_pos * 100, 1 ) : null,
				'ready_recall'          => $resolved_pos ? round( $ready / $resolved_pos * 100, 1 ) : null,
				'negatives_resolved'    => $resolved_neg,
				'precision_suppression' => $resolved_neg ? round( $suppressed / $resolved_neg * 100, 1 ) : null,
			),
			'positives'    => $positives,
			'negatives'    => $negatives,
		);
	}

	protected static function evaluate_pair( $frag_a, $frag_b ) {
		$a = self::resolve( $frag_a );
		$b = self::resolve( $frag_b );

		if ( ! $a || ! $b ) {
			return array(
				'resolved' => false,
				'a'        => $frag_a,
				'b'        => $frag_b,
				'status'   => 'unresolved',
			);
		}

		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT status, doc_similarity, passage_similarity, confidence FROM " . AIIL_DB::opportunities_table() . "
				 WHERE ( source_post_id = %d AND target_post_id = %d )
				    OR ( source_post_id = %d AND target_post_id = %d )",
				$a,
				$b,
				$b,
				$a
			)
		);

		$rank_order = array(
			'inserted'           => 11,
			'verified'           => 10,
			'ready'              => 9,
			'rewrite_suggested'  => 8,
			'reciprocal'         => 7,
			'rejected_relevance' => 6,
			'no_anchor'          => 5,
			'low_relevance'      => 4,
			'capped'             => 3,
			'pending'            => 2,
			'invalid'            => 1,
		);

		$best         = 'absent';
		$best_rank    = 0;
		$best_doc     = null;
		$best_passage = null;
		foreach ( $rows as $r ) {
			$rank = isset( $rank_order[ $r->status ] ) ? $rank_order[ $r->status ] : 0;
			if ( $rank >= $best_rank ) {
				$best_rank    = $rank;
				$best         = $r->status;
				$best_doc     = (float) $r->doc_similarity;
				$best_passage = null === $r->passage_similarity ? null : (float) $r->passage_similarity;
			}
		}

		return array(
			'resolved'   => true,
			'a'          => $frag_a,
			'b'          => $frag_b,
			'status'     => $best,
			'doc_sim'    => $best_doc,
			'passage_sim' => $best_passage,
		);
	}

	protected static function resolve( $fragment ) {
		global $wpdb;
		$like = '%' . $wpdb->esc_like( $fragment ) . '%';
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish' AND post_title LIKE %s ORDER BY ID ASC LIMIT 1",
				$like
			)
		);
	}
}
