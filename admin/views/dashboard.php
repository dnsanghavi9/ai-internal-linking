<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$total_posts   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type = 'post'" );
$indexed_posts = (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . AIIL_DB::posts_table() . " WHERE indexed_at IS NOT NULL" );
$queue         = AIIL_Queue::counts();
$queue_busy    = (int) $queue['pending'] + (int) $queue['processing'];

// Opportunity counts by status.
$rows = $wpdb->get_results( "SELECT status, COUNT(*) c FROM " . AIIL_DB::opportunities_table() . " GROUP BY status", ARRAY_A );
$sc   = array();
foreach ( $rows as $r ) {
	$sc[ $r['status'] ] = (int) $r['c'];
}
$rerank_on = (int) AIIL_Settings::get( 'use_ai_rerank', 0 ) === 1;

$c = function ( ...$keys ) use ( $sc ) {
	$n = 0;
	foreach ( $keys as $k ) {
		$n += isset( $sc[ $k ] ) ? $sc[ $k ] : 0;
	}
	return $n;
};

$pending_opps = $c( 'pending' );
$awaiting     = $rerank_on ? $c( 'ready' ) : 0;                 // prepared, waiting for AI verify
$ready_insert = $rerank_on ? $c( 'verified' ) : $c( 'ready' );  // actionable "insert these"
$not_linked   = $c( 'rejected_relevance', 'capped', 'reciprocal', 'low_relevance', 'rewrite_suggested', 'no_anchor' );
$live_links   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . AIIL_DB::links_table() . " WHERE status = 'active'" );
$orphans      = AIIL_Orphans::count();

$has_api_key = AIIL_Settings::has_api_key();
$review_url  = AIIL_Admin::url( 'link-opportunities' );

// Step completion flags for the checklist.
$step1_done = $total_posts > 0 && $indexed_posts >= $total_posts && 0 === $queue_busy;
$step2_done = 0 === $pending_opps && ( $ready_insert + $not_linked ) > 0;
$step3_done = $rerank_on ? ( 0 === $awaiting && $ready_insert > 0 ) : $step2_done;
?>
<div class="wrap aiil-wrap">
	<h1><?php esc_html_e( 'AI Internal Linking', 'ai-internal-linking' ); ?></h1>
	<p class="aiil-lead"><?php esc_html_e( 'Reads every post, finds the posts that genuinely belong together, and proposes a natural internal link between them for you to review.', 'ai-internal-linking' ); ?></p>

	<p class="aiil-toolbar">
		<?php echo AIIL_Admin::export_button( 'all', __( 'Export everything (JSON)', 'ai-internal-linking' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<?php echo AIIL_Admin::export_button( 'eval', __( 'Run eval (JSON)', 'ai-internal-linking' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	</p>

	<?php if ( isset( $_GET['index_only'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p>
			<?php printf( esc_html__( 'Queued %d posts for indexing only — no link suggestions will be created for them. Run the pipeline (or let cron work through it), then publish a post and it will link itself automatically.', 'ai-internal-linking' ), (int) ( $_GET['queued'] ?? 0 ) ); ?>
		</p></div>
	<?php endif; ?>

	<?php if ( isset( $_GET['requeued'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p>
			<?php printf( esc_html__( 'Requeued %d failed job(s). Run the pipeline to process them.', 'ai-internal-linking' ), (int) $_GET['requeued'] ); ?>
		</p></div>
	<?php endif; ?>

	<?php if ( AIIL_Queue::is_backing_off() ) : ?>
		<div class="notice notice-warning"><p>
			<?php esc_html_e( 'Paused: the API is rate limiting this key (quota or requests per minute). Processing resumes automatically in a couple of minutes — nothing is lost, queued posts are retried. If this keeps happening on a large site, check your Gemini quota or billing.', 'ai-internal-linking' ); ?>
		</p></div>
	<?php endif; ?>

	<?php if ( (int) $queue['failed'] > 0 ) : ?>
		<div class="notice notice-warning"><p>
			<?php printf( esc_html__( '%d queued job(s) failed and were given up on — those posts are not indexed, so they can neither give nor receive links. See the Logs tab for the reason.', 'ai-internal-linking' ), (int) $queue['failed'] ); ?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0 0 10px">
				<input type="hidden" name="action" value="aiil_retry_failed" />
				<?php wp_nonce_field( 'aiil_retry_failed' ); ?>
				<button type="submit" class="button"><?php esc_html_e( 'Retry failed jobs', 'ai-internal-linking' ); ?></button>
			</form>
		</div>
	<?php endif; ?>

	<?php if ( ! $has_api_key ) : ?>
		<div class="notice notice-error"><p>
			<?php
			printf(
				wp_kses_post( __( 'Add your Gemini API key in <a href="%s">Settings</a> to begin.', 'ai-internal-linking' ) ),
				esc_url( AIIL_Admin::url( 'settings' ) )
			);
			?>
		</p></div>
	<?php endif; ?>

	<!-- ============================ PIPELINE ============================ -->
	<div class="aiil-panel aiil-pipeline"
		data-rerank="<?php echo $rerank_on ? 1 : 0; ?>"
		data-review-url="<?php echo esc_url( $review_url ); ?>"
		data-queue-busy="<?php echo esc_attr( $queue_busy ); ?>"
		data-pending="<?php echo esc_attr( $pending_opps ); ?>"
		data-awaiting="<?php echo esc_attr( $awaiting ); ?>">

		<div class="aiil-pipeline-head">
			<div>
				<h2><?php esc_html_e( 'Run the pipeline', 'ai-internal-linking' ); ?></h2>
				<p class="description"><?php esc_html_e( 'One button runs every step below in order. Keep this tab open until it finishes — no cron needed.', 'ai-internal-linking' ); ?></p>
			</div>
			<div class="aiil-pipeline-actions">
				<button type="button" class="button button-primary button-hero" id="aiil-pipeline-run" <?php disabled( ! $has_api_key ); ?>>
					<?php esc_html_e( 'Run pipeline', 'ai-internal-linking' ); ?>
				</button>
				<button type="button" class="button" id="aiil-pipeline-stop" style="display:none"><?php esc_html_e( 'Stop', 'ai-internal-linking' ); ?></button>
			</div>
		</div>

		<div class="aiil-progress" style="display:none">
			<div class="aiil-progress-bar"><span></span></div>
			<p class="aiil-progress-label"></p>
		</div>

		<ol class="aiil-steps">
			<li class="<?php echo $step1_done ? 'done' : ''; ?>" data-phase="index">
				<span class="aiil-step-n">1</span>
				<span class="aiil-step-body">
					<strong><?php esc_html_e( 'Index posts', 'ai-internal-linking' ); ?></strong>
					<span class="aiil-step-note"><?php printf( esc_html__( '%1$d of %2$d posts embedded', 'ai-internal-linking' ), (int) $indexed_posts, (int) $total_posts ); ?></span>
				</span>
			</li>
			<li data-phase="match">
				<span class="aiil-step-n">2</span>
				<span class="aiil-step-body">
					<strong><?php esc_html_e( 'Find related posts', 'ai-internal-linking' ); ?></strong>
					<span class="aiil-step-note"><?php esc_html_e( 'matches posts by meaning', 'ai-internal-linking' ); ?></span>
				</span>
			</li>
			<li class="<?php echo ( 0 === $pending_opps && $step2_done ) ? 'done' : ''; ?>" data-phase="prepare">
				<span class="aiil-step-n">3</span>
				<span class="aiil-step-body">
					<strong><?php esc_html_e( 'Prepare anchors', 'ai-internal-linking' ); ?></strong>
					<span class="aiil-step-note"><?php echo $pending_opps ? esc_html( sprintf( __( '%d waiting', 'ai-internal-linking' ), $pending_opps ) ) : esc_html__( 'grounds each link in a real sentence', 'ai-internal-linking' ); ?></span>
				</span>
			</li>
			<?php if ( $rerank_on ) : ?>
				<li class="<?php echo ( 0 === $awaiting && $ready_insert > 0 ) ? 'done' : ''; ?>" data-phase="verify">
					<span class="aiil-step-n">4</span>
					<span class="aiil-step-body">
						<strong><?php esc_html_e( 'Verify with AI', 'ai-internal-linking' ); ?></strong>
						<span class="aiil-step-note"><?php echo $awaiting ? esc_html( sprintf( __( '%d awaiting', 'ai-internal-linking' ), $awaiting ) ) : esc_html__( 'checks each link truly helps the reader', 'ai-internal-linking' ); ?></span>
					</span>
				</li>
			<?php endif; ?>
		</ol>

		<p class="aiil-pipeline-next">
			<strong><?php echo (int) $ready_insert; ?></strong>
			<?php esc_html_e( 'links ready to review', 'ai-internal-linking' ); ?>
			&rarr; <a href="<?php echo esc_url( $review_url ); ?>"><?php esc_html_e( 'Review &amp; insert', 'ai-internal-linking' ); ?></a>
		</p>

		<noscript>
			<p class="description"><?php esc_html_e( 'JavaScript is off. Use the per-step buttons on the Posts and Review pages instead.', 'ai-internal-linking' ); ?></p>
		</noscript>
	</div>

	<!-- ============================ AT A GLANCE ============================ -->
	<!-- ============ NEW POSTS ONLY ============ -->
	<div class="aiil-panel">
		<h2><?php esc_html_e( 'Only link new posts', 'ai-internal-linking' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Leave your existing posts exactly as they are, and only link posts you publish from now on. This builds the search index for your back catalogue — which is required, because a new post can only be matched against posts that have been indexed — but proposes no links for them, so there is no review pile and no AI verification spend on old content.', 'ai-internal-linking' ); ?>
		</p>
		<p class="description">
			<strong><?php esc_html_e( 'Cost:', 'ai-internal-linking' ); ?></strong>
			<?php esc_html_e( 'embeddings only, once per post (a fraction of a cent each). Nothing else runs until you publish something.', 'ai-internal-linking' ); ?>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="aiil_index_only" />
			<?php wp_nonce_field( AIIL_Admin::NONCE ); ?>
			<?php submit_button( __( 'Build index only (no suggestions for old posts)', 'ai-internal-linking' ), 'secondary', 'submit', false ); ?>
		</form>
		<p class="description" style="margin-top:8px">
			<?php
			printf(
				/* translators: %s: on/off state of the Auto-Link New Posts setting */
				esc_html__( 'After indexing, every new post links itself automatically. Auto-Link New Posts is currently %s.', 'ai-internal-linking' ),
				(int) AIIL_Settings::get( 'auto_link_new', 1 ) === 1 ? esc_html__( 'ON', 'ai-internal-linking' ) : esc_html__( 'OFF — turn it on in Settings', 'ai-internal-linking' )
			);
			?>
		</p>
	</div>

	<div class="aiil-stats">
		<div class="aiil-stat"><span class="n"><?php echo esc_html( $ready_insert ); ?></span><span class="l"><?php esc_html_e( 'Ready to insert', 'ai-internal-linking' ); ?></span></div>
		<div class="aiil-stat"><span class="n"><?php echo esc_html( $not_linked ); ?></span><span class="l"><?php esc_html_e( 'Not linked', 'ai-internal-linking' ); ?></span></div>
		<div class="aiil-stat"><span class="n"><?php echo esc_html( $live_links ); ?></span><span class="l"><?php esc_html_e( 'Live links', 'ai-internal-linking' ); ?></span></div>
		<div class="aiil-stat"><span class="n"><?php echo esc_html( $indexed_posts ); ?></span><span class="l"><?php esc_html_e( 'Indexed posts', 'ai-internal-linking' ); ?></span></div>
		<div class="aiil-stat"><span class="n"><?php echo esc_html( $orphans ); ?></span><span class="l"><?php esc_html_e( 'Orphan posts', 'ai-internal-linking' ); ?></span></div>
	</div>

	<!-- ============================ HOW IT WORKS ============================ -->
	<details class="aiil-panel aiil-how">
		<summary><?php esc_html_e( 'How it works', 'ai-internal-linking' ); ?></summary>
		<ol>
			<li><?php esc_html_e( 'Index — each post is split into passages and turned into an embedding (a numeric fingerprint of its meaning). Titles are indexed for matching but never used as link anchors.', 'ai-internal-linking' ); ?></li>
			<li><?php esc_html_e( 'Match — every post is compared to every other by meaning; the closest few become link candidates in both directions.', 'ai-internal-linking' ); ?></li>
			<li><?php esc_html_e( 'Prepare — for each candidate the plugin finds the most relevant sentence in the source post and picks a specific anchor phrase that already exists there. If nothing distinctive fits, it is set aside rather than forcing a weak link.', 'ai-internal-linking' ); ?></li>
			<?php if ( $rerank_on ) : ?>
				<li><?php esc_html_e( 'Verify (optional AI) — one scoped AI call judges whether the target genuinely helps the reader and whether the two posts are product/jurisdiction compatible. Only the strongest few per post are kept; the reverse direction of a pair is blocked only after one direction is verified.', 'ai-internal-linking' ); ?></li>
			<?php endif; ?>
			<li><?php esc_html_e( 'Review — you approve or reject each suggestion. Nothing is written to a post until you insert it (unless you turn on auto-insert in Settings).', 'ai-internal-linking' ); ?></li>
		</ol>
	</details>

	<!-- ============================ ADVANCED ============================ -->
	<details class="aiil-panel aiil-advanced">
		<summary><?php esc_html_e( 'Advanced & background', 'ai-internal-linking' ); ?></summary>

		<h3><?php esc_html_e( 'Queue', 'ai-internal-linking' ); ?></h3>
		<table class="widefat striped" style="max-width:420px">
			<tbody>
				<?php foreach ( $queue as $status => $count ) : ?>
					<tr><td><?php echo esc_html( ucfirst( $status ) ); ?></td><td><?php echo esc_html( $count ); ?></td></tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<h3><?php esc_html_e( 'Run a single step', 'ai-internal-linking' ); ?></h3>
		<p class="description"><?php esc_html_e( 'The “Run pipeline” button above does all of these in order. These are here if you want to run one step on its own.', 'ai-internal-linking' ); ?></p>
		<p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block">
				<input type="hidden" name="action" value="aiil_initial_scan" />
				<?php wp_nonce_field( AIIL_Admin::NONCE ); ?>
				<?php submit_button( __( 'Index posts', 'ai-internal-linking' ), 'secondary', 'submit', false ); ?>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block">
				<input type="hidden" name="action" value="aiil_run_queue" />
				<?php wp_nonce_field( AIIL_Admin::NONCE ); ?>
				<?php submit_button( __( 'Process queue once', 'ai-internal-linking' ), 'secondary', 'submit', false ); ?>
			</form>
		</p>
	</details>
</div>
