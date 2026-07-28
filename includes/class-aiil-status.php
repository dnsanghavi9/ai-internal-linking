<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Human-facing vocabulary for opportunity statuses.
 *
 * The pipeline uses ~12 internal statuses; users should only ever think in a handful of
 * buckets. This class is the single source of truth mapping raw statuses to buckets, short
 * labels and plain-English explanations, so the dashboard, review page and JS all agree.
 */
class AIIL_Status {

	/**
	 * Ordered buckets: key => [ label, description ]. These are the tabs a user sees.
	 */
	public static function buckets() {
		return array(
			'review'   => array(
				'label' => __( 'Ready to insert', 'ai-internal-linking' ),
				'desc'  => __( 'Approved suggestions with a grounded anchor. Review and insert these.', 'ai-internal-linking' ),
			),
			'live'     => array(
				'label' => __( 'Live', 'ai-internal-linking' ),
				'desc'  => __( 'Links already inserted into your posts.', 'ai-internal-linking' ),
			),
			'excluded' => array(
				'label' => __( 'Not linked', 'ai-internal-linking' ),
				'desc'  => __( 'Candidates that were dropped, with the reason why.', 'ai-internal-linking' ),
			),
			'working'  => array(
				'label' => __( 'In progress', 'ai-internal-linking' ),
				'desc'  => __( 'Not finished processing yet — run the pipeline to advance these.', 'ai-internal-linking' ),
			),
		);
	}

	/**
	 * Raw status => [ bucket, label, description ].
	 * 'ready' is context-sensitive (see status_meta) because with AI verification on it is a
	 * processing state, not an actionable one.
	 */
	protected static function map() {
		return array(
			'pending'            => array( 'working',   __( 'Pending', 'ai-internal-linking' ),          __( 'Waiting to be prepared.', 'ai-internal-linking' ) ),
			'ready'              => array( 'review',    __( 'Ready', 'ai-internal-linking' ),            __( 'Anchor found; ready to insert.', 'ai-internal-linking' ) ),
			'verified'           => array( 'review',    __( 'Verified', 'ai-internal-linking' ),         __( 'AI confirmed the target genuinely helps the reader.', 'ai-internal-linking' ) ),
			'rewrite_suggested'  => array( 'excluded',  __( 'No usable anchor', 'ai-internal-linking' ),  __( 'A relevant pair, but the AI found no phrase in the passage it could turn into a link.', 'ai-internal-linking' ) ),
			'no_anchor'          => array( 'excluded',  __( 'No anchor', 'ai-internal-linking' ),        __( 'A relevant passage exists but no distinctive anchor was found.', 'ai-internal-linking' ) ),
			'inserted'           => array( 'live',      __( 'Inserted', 'ai-internal-linking' ),         __( 'Link is live in the post.', 'ai-internal-linking' ) ),
			'rejected_relevance' => array( 'excluded',  __( 'AI rejected', 'ai-internal-linking' ),      __( 'AI judged the target a poor fit (product / jurisdiction / not useful).', 'ai-internal-linking' ) ),
			'rejected'           => array( 'excluded',  __( 'Rejected', 'ai-internal-linking' ),         __( 'You rejected this suggestion.', 'ai-internal-linking' ) ),
			'capped'             => array( 'excluded',  __( 'Over cap', 'ai-internal-linking' ),         __( 'The source already has its maximum number of links.', 'ai-internal-linking' ) ),
			'reciprocal'         => array( 'excluded',  __( 'Reverse kept', 'ai-internal-linking' ),     __( 'The opposite direction of this pair was linked instead.', 'ai-internal-linking' ) ),
			'low_relevance'      => array( 'excluded',  __( 'Low relevance', 'ai-internal-linking' ),    __( 'No source passage was relevant enough to host the link.', 'ai-internal-linking' ) ),
			'invalid'            => array( 'excluded',  __( 'Invalid', 'ai-internal-linking' ),          __( 'A post in this pair is missing or unpublished.', 'ai-internal-linking' ) ),
		);
	}

	/**
	 * @return array{bucket:string,label:string,desc:string}
	 */
	public static function status_meta( $status, $rerank_on = null ) {
		$map = self::map();
		$m   = $map[ $status ] ?? array( 'working', ucfirst( str_replace( '_', ' ', (string) $status ) ), '' );

		// With AI verification ON, a raw 'ready' link is still awaiting verification — it is a
		// processing state, not "ready to insert". Reflect that so the buckets read correctly.
		if ( 'ready' === $status && null === $rerank_on ) {
			$rerank_on = (int) AIIL_Settings::get( 'use_ai_rerank', 0 ) === 1;
		}
		if ( 'ready' === $status && $rerank_on ) {
			$m = array( 'working', __( 'Awaiting verification', 'ai-internal-linking' ), __( 'Prepared; run AI verification to confirm.', 'ai-internal-linking' ) );
		}

		return array( 'bucket' => $m[0], 'label' => $m[1], 'desc' => $m[2] );
	}

	/**
	 * Raw statuses that belong to a bucket, honouring the rerank-sensitive 'ready' mapping.
	 *
	 * @return string[]
	 */
	public static function statuses_in( $bucket, $rerank_on = null ) {
		$out = array();
		foreach ( array_keys( self::map() ) as $status ) {
			if ( self::status_meta( $status, $rerank_on )['bucket'] === $bucket ) {
				$out[] = $status;
			}
		}
		return $out;
	}

	public static function bucket_label( $status, $rerank_on = null ) {
		return self::status_meta( $status, $rerank_on )['label'];
	}
}
