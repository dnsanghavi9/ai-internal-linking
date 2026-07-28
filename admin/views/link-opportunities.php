<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;
$t          = AIIL_DB::opportunities_table();
$use_rerank = (int) AIIL_Settings::get( 'use_ai_rerank', 0 ) === 1;
$buckets    = AIIL_Status::buckets();

$bucket = sanitize_key( wp_unslash( $_GET['bucket'] ?? 'review' ) );
if ( ! isset( $buckets[ $bucket ] ) ) {
	$bucket = 'review';
}

// Count opportunities per bucket for the tab badges.
$rowcounts = $wpdb->get_results( "SELECT status, COUNT(*) c FROM {$t} GROUP BY status", ARRAY_A );
$bucket_counts = array_fill_keys( array_keys( $buckets ), 0 );
foreach ( $rowcounts as $r ) {
	$b = AIIL_Status::status_meta( $r['status'], $use_rerank )['bucket'];
	if ( isset( $bucket_counts[ $b ] ) ) {
		$bucket_counts[ $b ] += (int) $r['c'];
	}
}

// Focus mode: the Orphans page links here with ?post=ID&dir=in|out to show EVERY suggestion
// for one post in one direction (any status), so you can see what linked, what was rejected and why.
$focus_post = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;
$focus_dir  = ( isset( $_GET['dir'] ) && 'out' === $_GET['dir'] ) ? 'out' : 'in';
$focus_obj  = $focus_post ? get_post( $focus_post ) : null;

$rows = array();
if ( $focus_obj ) {
	$col  = 'out' === $focus_dir ? 'source_post_id' : 'target_post_id';
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT o.*, s.post_title AS source_title, t2.post_title AS target_title
			 FROM {$t} o
			 LEFT JOIN {$wpdb->posts} s ON s.ID = o.source_post_id
			 LEFT JOIN {$wpdb->posts} t2 ON t2.ID = o.target_post_id
			 WHERE o.{$col} = %d
			 ORDER BY FIELD(o.status,'verified','ready','inserted','rewrite_suggested','no_anchor','rejected_relevance','capped','reciprocal','low_relevance','rejected','pending') ASC, o.confidence DESC, o.id DESC
			 LIMIT 200",
			$focus_post
		)
	);
} else {
	$in_statuses = AIIL_Status::statuses_in( $bucket, $use_rerank );
	if ( ! empty( $in_statuses ) ) {
		$ph   = implode( ',', array_fill( 0, count( $in_statuses ), '%s' ) );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT o.*, s.post_title AS source_title, t2.post_title AS target_title
				 FROM {$t} o
				 LEFT JOIN {$wpdb->posts} s ON s.ID = o.source_post_id
				 LEFT JOIN {$wpdb->posts} t2 ON t2.ID = o.target_post_id
				 WHERE o.status IN ($ph)
				 ORDER BY o.confidence DESC, o.doc_similarity DESC, o.id DESC
				 LIMIT 200",
				$in_statuses
			)
		);
	}
}

$pending_total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE status = %s", 'pending' ) );
$ready_total   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE status = %s", 'ready' ) );
?>
<div class="wrap aiil-wrap">
	<h1><?php esc_html_e( 'Review Links', 'ai-internal-linking' ); ?></h1>
	<p class="aiil-lead"><?php esc_html_e( 'Approve or reject each proposed link. Nothing is written to a post until you insert it.', 'ai-internal-linking' ); ?></p>

	<?php if ( isset( $_GET['reevaluated'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p>
			<?php printf( esc_html__( 'Reset %d suggestions. Run the pipeline from the Dashboard to re-process them.', 'ai-internal-linking' ), (int) $_GET['reevaluated'] ); ?>
		</p></div>
	<?php endif; ?>
	<?php if ( isset( $_GET['reapplied'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p>
			<?php printf( esc_html__( '%d links now verified under the current thresholds (no new AI calls were made).', 'ai-internal-linking' ), (int) $_GET['reapplied'] ); ?>
		</p></div>
	<?php endif; ?>

	<?php if ( isset( $_GET['rebuilt'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p>
			<?php printf( esc_html__( 'Re-matching queued for %d posts. Run the pipeline from the Dashboard.', 'ai-internal-linking' ), (int) $_GET['rebuilt'] ); ?>
		</p></div>
	<?php endif; ?>
	<?php if ( isset( $_GET['msg'] ) ) : ?>
		<div class="notice notice-info is-dismissible"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['msg'] ) ) ); ?></p></div>
	<?php endif; ?>

	<?php if ( $pending_total > 0 || ( $use_rerank && $ready_total > 0 ) ) : ?>
		<div class="aiil-panel aiil-advance">
			<p><strong><?php esc_html_e( 'Unfinished work', 'ai-internal-linking' ); ?></strong> — <?php
				$bits = array();
				if ( $pending_total ) { $bits[] = sprintf( esc_html__( '%d to prepare', 'ai-internal-linking' ), $pending_total ); }
				if ( $use_rerank && $ready_total ) { $bits[] = sprintf( esc_html__( '%d to verify', 'ai-internal-linking' ), $ready_total ); }
				echo esc_html( implode( ', ', $bits ) );
			?>.
			<?php esc_html_e( 'Run everything from the', 'ai-internal-linking' ); ?>
			<a href="<?php echo esc_url( AIIL_Admin::url() ); ?>"><?php esc_html_e( 'Dashboard', 'ai-internal-linking' ); ?></a><?php esc_html_e( ', or advance just this step:', 'ai-internal-linking' ); ?>
			</p>

			<?php if ( $pending_total ) : ?>
				<span id="aiil-prepare-all" data-remaining="<?php echo esc_attr( $pending_total ); ?>" data-ready-url="<?php echo esc_url( AIIL_Admin::url( 'link-opportunities', array( 'bucket' => 'review' ) ) ); ?>">
					<button type="button" class="button" id="aiil-prepare-all-start"><?php printf( esc_html__( 'Prepare anchors (%d)', 'ai-internal-linking' ), (int) $pending_total ); ?></button>
					<span class="aiil-prepare-all-label description"></span>
				</span>
			<?php endif; ?>

			<?php if ( $use_rerank && $ready_total ) : ?>
				<span id="aiil-verify-all" data-remaining="<?php echo esc_attr( $ready_total ); ?>" data-verified-url="<?php echo esc_url( AIIL_Admin::url( 'link-opportunities', array( 'bucket' => 'review' ) ) ); ?>" style="margin-left:10px">
					<button type="button" class="button" id="aiil-verify-all-start"><?php printf( esc_html__( 'Verify with AI (%d)', 'ai-internal-linking' ), (int) $ready_total ); ?></button>
					<span class="aiil-verify-all-label description"></span>
				</span>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<?php if ( $focus_obj ) : ?>
		<div class="aiil-panel">
			<p>
				<?php
				printf(
					'out' === $focus_dir
						? esc_html__( 'All link suggestions FROM: %s', 'ai-internal-linking' )
						: esc_html__( 'All link suggestions pointing AT: %s', 'ai-internal-linking' ),
					'<strong>' . esc_html( $focus_obj->post_title ) . '</strong>'
				);
				?>
				· <a href="<?php echo esc_url( AIIL_Admin::url( 'orphan-pages' ) ); ?>"><?php esc_html_e( '← Back to Orphans', 'ai-internal-linking' ); ?></a>
				· <a href="<?php echo esc_url( AIIL_Admin::url( 'link-opportunities' ) ); ?>"><?php esc_html_e( 'All links', 'ai-internal-linking' ); ?></a>
			</p>
		</div>
	<?php else : ?>
		<!-- Bucket tabs -->
		<h2 class="nav-tab-wrapper aiil-buckets">
			<?php foreach ( $buckets as $key => $b ) : ?>
				<a href="<?php echo esc_url( AIIL_Admin::url( 'link-opportunities', array( 'bucket' => $key ) ) ); ?>"
					class="nav-tab <?php echo $bucket === $key ? 'nav-tab-active' : ''; ?>">
					<?php echo esc_html( $b['label'] ); ?>
					<span class="aiil-badge"><?php echo esc_html( (int) $bucket_counts[ $key ] ); ?></span>
				</a>
			<?php endforeach; ?>
		</h2>
		<p class="description aiil-bucket-desc"><?php echo esc_html( $buckets[ $bucket ]['desc'] ); ?></p>

		<?php
		// Bulk insert: only on the "Ready to insert" tab, and only when there is something to insert.
		$insertable = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$t} WHERE status IN ('verified','ready')"
		);
		if ( 'review' === $bucket && $insertable > 0 ) :
			?>
			<div id="aiil-insert-all" data-remaining="<?php echo esc_attr( $insertable ); ?>" style="margin:10px 0">
				<button type="button" class="button button-primary" id="aiil-insert-all-start">
					<?php printf( esc_html__( 'Insert all %d links', 'ai-internal-linking' ), $insertable ); ?>
				</button>
				<span class="aiil-insert-all-label description"></span>
				<p class="description"><?php esc_html_e( 'Writes each link into its source post. Keep this tab open until it finishes. You can still reject individual ones first.', 'ai-internal-linking' ); ?></p>
			</div>
		<?php endif; ?>
	<?php endif; ?>

	<table class="widefat striped aiil-review-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Status', 'ai-internal-linking' ); ?></th>
				<th><?php esc_html_e( 'Link (source → target)', 'ai-internal-linking' ); ?></th>
				<th><?php esc_html_e( 'Confidence', 'ai-internal-linking' ); ?></th>
				<th><?php esc_html_e( 'Anchor & actions', 'ai-internal-linking' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $rows ) ) : ?>
				<tr><td colspan="4"><?php esc_html_e( 'Nothing here.', 'ai-internal-linking' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $rows as $row ) :
					$st           = $row->status;
					$meta         = AIIL_Status::status_meta( $st, $use_rerank );
					$show_prepare = in_array( $st, array( 'pending', 'ready', 'rewrite_suggested', 'no_anchor', 'low_relevance' ), true );
					$show_approve = in_array( $st, array( 'ready', 'verified', 'rewrite_suggested' ), true );
					$show_reject  = in_array( $st, array( 'pending', 'ready', 'verified', 'rewrite_suggested' ), true );
					$sig          = $row->signals ? json_decode( (string) $row->signals, true ) : array();
					$reason       = '';
					$rr           = array();
					if ( is_array( $sig ) ) {
						$reason = $sig['rerank']['reason'] ?? ( $sig['rerank_reason'] ?? '' );
						$rr     = ( ! empty( $sig['rerank'] ) && is_array( $sig['rerank'] ) ) ? $sig['rerank'] : array();
					}
					// The anchor column keeps the mechanical guess until a link is verified, so
					// show the anchor the AI actually chose (plus its scores) on every judged row.
					$ai_anchor = (string) ( $rr['grounded'] ?? '' );
					$ai_scores = isset( $rr['pair_score'] )
						? sprintf( __( 'pair %1$d · anchor %2$d', 'ai-internal-linking' ), (int) $rr['pair_score'], (int) ( $rr['anchor_score'] ?? 0 ) )
						: '';
					?>
					<tr>
						<td><span class="aiil-status-badge aiil-b-<?php echo esc_attr( $meta['bucket'] ); ?>" title="<?php echo esc_attr( $meta['desc'] ); ?>"><?php echo esc_html( $meta['label'] ); ?></span></td>
						<td>
							<a href="<?php echo esc_url( get_edit_post_link( (int) $row->source_post_id ) ); ?>"><?php echo esc_html( $row->source_title ); ?></a>
							<span class="aiil-arrow">&rarr;</span>
							<a href="<?php echo esc_url( get_edit_post_link( (int) $row->target_post_id ) ); ?>"><?php echo esc_html( $row->target_title ); ?></a>
							<br />
							<span class="aiil-simnote">
								<?php echo esc_html( sprintf( __( 'similarity %s%%', 'ai-internal-linking' ), number_format_i18n( (float) $row->doc_similarity, 0 ) ) ); ?>
								<?php if ( null !== $row->passage_similarity ) : ?>
									· <?php echo esc_html( sprintf( __( 'passage %s%%', 'ai-internal-linking' ), number_format_i18n( (float) $row->passage_similarity, 0 ) ) ); ?>
								<?php endif; ?>
							</span>
							<?php if ( '' !== $reason ) : ?>
								<br /><em class="description">“<?php echo esc_html( $reason ); ?>”</em>
							<?php endif; ?>
							<?php if ( '' !== $ai_scores || '' !== $ai_anchor ) : ?>
								<br /><span class="aiil-simnote">
									<?php if ( '' !== $ai_scores ) : ?><?php echo esc_html( $ai_scores ); ?><?php endif; ?>
									<?php if ( '' !== $ai_anchor && 'verified' !== $st ) : ?>
										· <?php echo esc_html( sprintf( __( 'AI would anchor: “%s”', 'ai-internal-linking' ), $ai_anchor ) ); ?>
									<?php endif; ?>
								</span>
							<?php endif; ?>
						</td>
						<td class="aiil-confidence"><?php echo esc_html( $row->confidence ? number_format_i18n( (float) $row->confidence, 0 ) : '—' ); ?></td>
						<td>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="aiil-inline-form" data-id="<?php echo (int) $row->id; ?>" data-status="<?php echo esc_attr( $st ); ?>">
								<input type="hidden" name="action" value="aiil_opportunity_action" />
								<input type="hidden" name="opportunity_id" value="<?php echo (int) $row->id; ?>" />
								<?php wp_nonce_field( AIIL_Admin::NONCE ); ?>
								<input type="text" name="anchor_text" value="<?php echo esc_attr( $row->anchor_text ); ?>" placeholder="<?php esc_attr_e( 'anchor text', 'ai-internal-linking' ); ?>" />
								<button type="submit" name="op" value="prepare" class="button aiil-op-prepare"<?php echo $show_prepare ? '' : ' style="display:none"'; ?>>
									<?php echo in_array( $st, array( 'ready', 'rewrite_suggested' ), true ) ? esc_html__( 'Re-prepare', 'ai-internal-linking' ) : esc_html__( 'Prepare anchor', 'ai-internal-linking' ); ?>
								</button>
								<button type="submit" name="op" value="approve" class="button button-primary aiil-op-approve"<?php echo $show_approve ? '' : ' style="display:none"'; ?>><?php esc_html_e( 'Insert', 'ai-internal-linking' ); ?></button>
								<button type="submit" name="op" value="reject" class="button aiil-op-reject"<?php echo $show_reject ? '' : ' style="display:none"'; ?>><?php esc_html_e( 'Reject', 'ai-internal-linking' ); ?></button>
								<span class="aiil-op-msg"></span>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>

	<!-- Maintenance / advanced -->
	<details class="aiil-panel aiil-advanced" style="margin-top:20px">
		<summary><?php esc_html_e( 'Maintenance & export', 'ai-internal-linking' ); ?></summary>
		<p><?php echo AIIL_Admin::export_button( 'opportunities' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
		<p class="description"><?php esc_html_e( 'Re-apply thresholds = re-sort existing AI verdicts under the current Min Pair/Anchor scores, with NO new API calls (use this after changing those settings). Rebuild = regenerate which post pairs exist (after changing matching settings). Re-evaluate = re-run anchoring/verification from scratch (spends AI credits again). All keep your inserted and rejected decisions.', 'ai-internal-linking' ); ?></p>
		<p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block">
				<input type="hidden" name="action" value="aiil_reapply_thresholds" />
				<?php wp_nonce_field( 'aiil_reapply_thresholds' ); ?>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Re-apply thresholds (no new AI calls)', 'ai-internal-linking' ); ?></button>
			</form>
		</p>
		<p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="aiil-danger-form" style="display:inline-block"
				data-confirm="<?php esc_attr_e( 'Rebuild the candidate set and re-match every post? Deletes undecided suggestions (inserted/rejected are kept). No AI calls.', 'ai-internal-linking' ); ?>">
				<input type="hidden" name="action" value="aiil_rebuild_matches" />
				<?php wp_nonce_field( 'aiil_rebuild_matches' ); ?>
				<button type="submit" class="button"><?php esc_html_e( 'Rebuild matches', 'ai-internal-linking' ); ?></button>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="aiil-danger-form" style="display:inline-block;margin-left:8px"
				data-confirm="<?php esc_attr_e( 'Reset all non-inserted suggestions to pending and re-process under current settings?', 'ai-internal-linking' ); ?>">
				<input type="hidden" name="action" value="aiil_reevaluate" />
				<?php wp_nonce_field( 'aiil_reevaluate' ); ?>
				<button type="submit" class="button"><?php esc_html_e( 'Re-evaluate', 'ai-internal-linking' ); ?></button>
			</form>
		</p>
	</details>
</div>
