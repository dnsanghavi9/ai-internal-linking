<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$level = sanitize_text_field( wp_unslash( $_GET['level'] ?? '' ) );
$logs  = AIIL_Logger::fetch( 200, $level ?: null );
?>
<div class="wrap aiil-wrap">
	<h1><?php esc_html_e( 'AI Internal Linking — Logs', 'ai-internal-linking' ); ?></h1>
	<p><?php echo AIIL_Admin::export_button( 'logs' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>

	<ul class="subsubsub">
		<?php foreach ( array( '', 'info', 'warning', 'error', 'debug' ) as $lvl ) : ?>
			<li>
				<a href="<?php echo esc_url( AIIL_Admin::url( 'logs', $lvl ? array( 'level' => $lvl ) : array() ) ); ?>" class="<?php echo $level === $lvl ? 'current' : ''; ?>">
					<?php echo esc_html( $lvl ? ucfirst( $lvl ) : __( 'All', 'ai-internal-linking' ) ); ?>
				</a>
			</li>
		<?php endforeach; ?>
	</ul>
	<br class="clear" />

	<table class="widefat striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Time', 'ai-internal-linking' ); ?></th>
				<th><?php esc_html_e( 'Level', 'ai-internal-linking' ); ?></th>
				<th><?php esc_html_e( 'Message', 'ai-internal-linking' ); ?></th>
				<th><?php esc_html_e( 'Context', 'ai-internal-linking' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $logs ) ) : ?>
				<tr><td colspan="4"><?php esc_html_e( 'No logs.', 'ai-internal-linking' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $logs as $log ) : ?>
					<tr>
						<td><?php echo esc_html( $log->created_at ); ?></td>
						<td><span class="aiil-level aiil-level-<?php echo esc_attr( $log->level ); ?>"><?php echo esc_html( $log->level ); ?></span></td>
						<td><?php echo esc_html( $log->message ); ?></td>
						<td><code><?php echo esc_html( $log->context ); ?></code></td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>
</div>
