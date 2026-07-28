<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$rows = $wpdb->get_results(
	"SELECT l.*, s.post_title AS source_title, t.post_title AS target_title
	 FROM " . AIIL_DB::links_table() . " l
	 LEFT JOIN {$wpdb->posts} s ON s.ID = l.source_post_id
	 LEFT JOIN {$wpdb->posts} t ON t.ID = l.target_post_id
	 ORDER BY l.inserted_at DESC
	 LIMIT 200"
);
?>
<div class="wrap aiil-wrap">
	<h1><?php esc_html_e( 'Inserted Links', 'ai-internal-linking' ); ?></h1>
	<p><?php echo AIIL_Admin::export_button( 'links' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
	<table class="widefat striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Source', 'ai-internal-linking' ); ?></th>
				<th><?php esc_html_e( 'Target', 'ai-internal-linking' ); ?></th>
				<th><?php esc_html_e( 'Anchor', 'ai-internal-linking' ); ?></th>
				<th><?php esc_html_e( 'Status', 'ai-internal-linking' ); ?></th>
				<th><?php esc_html_e( 'Inserted', 'ai-internal-linking' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $rows ) ) : ?>
				<tr><td colspan="5"><?php esc_html_e( 'No links inserted yet.', 'ai-internal-linking' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( get_edit_post_link( (int) $row->source_post_id ) ); ?>"><?php echo esc_html( $row->source_title ); ?></a></td>
						<td><a href="<?php echo esc_url( get_edit_post_link( (int) $row->target_post_id ) ); ?>"><?php echo esc_html( $row->target_title ); ?></a></td>
						<td><?php echo esc_html( $row->anchor_text ); ?></td>
						<td><?php echo esc_html( $row->status ); ?></td>
						<td><?php echo esc_html( $row->inserted_at ); ?></td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>
</div>
