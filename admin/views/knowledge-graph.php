<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$min_default = (int) AIIL_Settings::get( 'min_doc_similarity', 55 );
?>
<div class="wrap aiil-wrap">
	<h1><?php esc_html_e( 'Knowledge Graph', 'ai-internal-linking' ); ?></h1>
	<p class="description">
		<?php esc_html_e( 'A map of your posts built from the semantic index. Each dot is a post; lines are semantic connections the plugin discovered. Colours are topic clusters detected automatically. Inserted internal links are drawn solid. Drag to rearrange, scroll to zoom, hover for details, click a node to edit the post.', 'ai-internal-linking' ); ?>
	</p>
	<p><?php echo AIIL_Admin::export_button( 'graph', __( 'Export Graph (JSON)', 'ai-internal-linking' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>

	<div id="aiil-graph-app"
		data-min="<?php echo esc_attr( $min_default ); ?>"
		data-nonce="<?php echo esc_attr( wp_create_nonce( AIIL_Admin::NONCE ) ); ?>">

		<div class="aiil-graph-toolbar">
			<label>
				<?php esc_html_e( 'Edges', 'ai-internal-linking' ); ?>
				<select id="aiil-graph-edge-type">
					<option value="semantic"><?php esc_html_e( 'Semantic connections', 'ai-internal-linking' ); ?></option>
					<option value="links"><?php esc_html_e( 'Inserted links only', 'ai-internal-linking' ); ?></option>
					<option value="both"><?php esc_html_e( 'Both', 'ai-internal-linking' ); ?></option>
				</select>
			</label>

			<label class="aiil-graph-simwrap">
				<?php esc_html_e( 'Min similarity', 'ai-internal-linking' ); ?>
				<input type="range" id="aiil-graph-min" min="0" max="100" step="1" value="<?php echo esc_attr( $min_default ); ?>" />
				<span id="aiil-graph-min-val"><?php echo esc_html( $min_default ); ?></span>
			</label>

			<label>
				<input type="checkbox" id="aiil-graph-orphans" />
				<?php esc_html_e( 'Show unconnected posts', 'ai-internal-linking' ); ?>
			</label>

			<button type="button" class="button" id="aiil-graph-reload"><?php esc_html_e( 'Apply', 'ai-internal-linking' ); ?></button>
			<button type="button" class="button" id="aiil-graph-fit"><?php esc_html_e( 'Fit', 'ai-internal-linking' ); ?></button>
			<span id="aiil-graph-status" class="description"></span>
		</div>

		<div class="aiil-graph-stage">
			<canvas id="aiil-graph-canvas"></canvas>
			<div id="aiil-graph-tooltip" class="aiil-graph-tooltip" hidden></div>
		</div>

		<div class="aiil-graph-legend">
			<span><strong><?php esc_html_e( 'Node size', 'ai-internal-linking' ); ?></strong>: <?php esc_html_e( 'inbound links (authority)', 'ai-internal-linking' ); ?></span>
			<span><strong><?php esc_html_e( 'Colour', 'ai-internal-linking' ); ?></strong>: <?php esc_html_e( 'topic cluster', 'ai-internal-linking' ); ?></span>
			<span><strong><?php esc_html_e( 'Solid line', 'ai-internal-linking' ); ?></strong>: <?php esc_html_e( 'inserted link', 'ai-internal-linking' ); ?></span>
			<span><strong><?php esc_html_e( 'Faint line', 'ai-internal-linking' ); ?></strong>: <?php esc_html_e( 'semantic connection', 'ai-internal-linking' ); ?></span>
		</div>
	</div>
</div>
