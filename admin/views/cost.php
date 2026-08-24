<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$summary = AIIL_Usage::summary();
$indexed = (int) $wpdb->get_var(
	$wpdb->prepare( "SELECT COUNT(*) FROM " . AIIL_DB::posts_table() . " WHERE blog_id = %d AND indexed_at IS NOT NULL", get_current_blog_id() )
);

$type_labels = array(
	'embed'    => __( 'Embeddings (indexing)', 'ai-internal-linking' ),
	'verify'   => __( 'AI Verification', 'ai-internal-linking' ),
	'anchor'   => __( 'AI Anchor Fallback', 'ai-internal-linking' ),
	'generate' => __( 'AI (other)', 'ai-internal-linking' ),
);

// Totals per call type, for the summary cards.
$by_type = array();
foreach ( $summary['rows'] as $r ) {
	$t = $r['call_type'];
	if ( ! isset( $by_type[ $t ] ) ) {
		$by_type[ $t ] = array( 'requests' => 0, 'cost' => 0.0 );
	}
	$by_type[ $t ]['requests'] += $r['requests'];
	$by_type[ $t ]['cost']     += $r['cost'];
}
?>
<div class="wrap aiil-wrap">
	<h1><?php esc_html_e( 'Cost', 'ai-internal-linking' ); ?></h1>
	<p class="aiil-lead"><?php esc_html_e( 'What the pipeline has actually spent on Gemini API calls for this site, based on real token counts from the API.', 'ai-internal-linking' ); ?></p>

	<?php if ( isset( $_GET['usage_reset'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Usage log cleared.', 'ai-internal-linking' ); ?></p></div>
	<?php endif; ?>

	<div class="aiil-panel">
		<p>
			<strong><?php esc_html_e( 'Total estimated cost', 'ai-internal-linking' ); ?>:</strong>
			<span style="font-size:1.5em;font-weight:600">$<?php echo esc_html( number_format( $summary['total_cost'], 4 ) ); ?></span>
			<?php if ( $summary['since'] ) : ?>
				<span class="description"> <?php printf( esc_html__( 'since %s', 'ai-internal-linking' ), esc_html( $summary['since'] ) ); ?></span>
			<?php endif; ?>
			<?php if ( $indexed > 0 ) : ?>
				<span class="description"> · <?php printf( esc_html__( '≈ $%s per indexed post', 'ai-internal-linking' ), number_format( $summary['total_cost'] / $indexed, 5 ) ); ?></span>
			<?php endif; ?>
		</p>
		<p class="description">
			<?php esc_html_e( 'Calculated from real token usage × the per-model rates in Settings.', 'ai-internal-linking' ); ?>
			<a href="<?php echo esc_url( AIIL_Admin::url( 'settings' ) ); ?>#aiil-cost-rates"><?php esc_html_e( 'Edit rates', 'ai-internal-linking' ); ?></a>.
			<?php esc_html_e( 'Gemini pricing changes — verify your rates in Google AI Studio; changing them here re-prices this whole page instantly, no data is re-fetched.', 'ai-internal-linking' ); ?>
			<?php if ( $summary['has_estimated'] ) : ?>
				<br /><?php esc_html_e( 'Rows marked "estimated" are for embedding calls — the embedding API does not report token usage, so those tokens are approximated from text length (~4 characters/token). AI Verification and AI Anchor Fallback rows are exact token counts returned by the API.', 'ai-internal-linking' ); ?>
			<?php endif; ?>
		</p>
	</div>

	<div class="aiil-stats">
		<?php foreach ( $type_labels as $key => $label ) :
			$d = $by_type[ $key ] ?? array( 'requests' => 0, 'cost' => 0.0 );
			if ( 0 === $d['requests'] && ! isset( $by_type[ $key ] ) ) {
				continue; // skip types never used on this site
			}
			?>
			<div class="aiil-stat">
				<span class="n">$<?php echo esc_html( number_format( $d['cost'], 4 ) ); ?></span>
				<span class="l"><?php echo esc_html( $label ); ?> · <?php echo esc_html( sprintf( _n( '%d call', '%d calls', $d['requests'], 'ai-internal-linking' ), $d['requests'] ) ); ?></span>
			</div>
		<?php endforeach; ?>
		<?php if ( empty( $by_type ) ) : ?>
			<p class="description"><?php esc_html_e( 'No API calls recorded yet. Run the pipeline to see cost here.', 'ai-internal-linking' ); ?></p>
		<?php endif; ?>
	</div>

	<table class="widefat striped" style="margin-top:16px">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Call type', 'ai-internal-linking' ); ?></th>
				<th><?php esc_html_e( 'Model', 'ai-internal-linking' ); ?></th>
				<th><?php esc_html_e( 'Tier', 'ai-internal-linking' ); ?></th>
				<th><?php esc_html_e( 'Calls', 'ai-internal-linking' ); ?></th>
				<th><?php esc_html_e( 'Input tokens', 'ai-internal-linking' ); ?></th>
				<th><?php esc_html_e( 'Output tokens', 'ai-internal-linking' ); ?></th>
				<th><?php esc_html_e( 'Source', 'ai-internal-linking' ); ?></th>
				<th><?php esc_html_e( 'Cost', 'ai-internal-linking' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $summary['rows'] ) ) : ?>
				<tr><td colspan="8"><?php esc_html_e( 'Nothing recorded yet.', 'ai-internal-linking' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $summary['rows'] as $r ) : ?>
					<tr>
						<td><?php echo esc_html( $type_labels[ $r['call_type'] ] ?? ucfirst( $r['call_type'] ) ); ?></td>
						<td><code><?php echo esc_html( $r['model'] ); ?></code></td>
						<td><?php echo esc_html( $r['service_tier'] ? ucfirst( $r['service_tier'] ) : '—' ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $r['requests'] ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $r['input_tokens'] ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $r['output_tokens'] ) ); ?></td>
						<td><?php echo $r['measured'] ? esc_html__( 'Measured', 'ai-internal-linking' ) : esc_html__( 'Estimated', 'ai-internal-linking' ); ?></td>
						<td>$<?php echo esc_html( number_format( $r['cost'], 4 ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>

	<div class="aiil-panel aiil-danger-zone" style="margin-top:20px">
		<h2><?php esc_html_e( 'Reset usage log', 'ai-internal-linking' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Clears the recorded token counts on this page (for a fresh cost count, e.g. after a testing run). Does not affect indexed posts, links, or any other plugin data.', 'ai-internal-linking' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="aiil-danger-form"
			data-confirm="<?php esc_attr_e( 'Clear the recorded usage/cost log? This cannot be undone.', 'ai-internal-linking' ); ?>">
			<input type="hidden" name="action" value="aiil_reset_usage" />
			<?php wp_nonce_field( 'aiil_reset_usage' ); ?>
			<button type="submit" class="button button-link-delete"><?php esc_html_e( 'Reset Usage Log', 'ai-internal-linking' ); ?></button>
		</form>
	</div>
</div>
