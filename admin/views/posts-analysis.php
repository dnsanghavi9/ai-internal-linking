<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$paged    = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
$per_page = 25;
$offset   = ( $paged - 1 ) * $per_page;

$rows = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT m.*, p.post_title FROM " . AIIL_DB::posts_table() . " m
		 INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
		 WHERE m.blog_id = %d
		 ORDER BY m.indexed_at DESC
		 LIMIT %d OFFSET %d",
		get_current_blog_id(),
		$per_page,
		$offset
	)
);
$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . AIIL_DB::posts_table() );
?>
<div class="wrap aiil-wrap">
	<h1><?php esc_html_e( 'Indexed Posts', 'ai-internal-linking' ); ?></h1>
	<p><?php esc_html_e( 'Each post is split into passages and embedded. The matcher compares these embeddings by meaning — there are no topic labels to inspect.', 'ai-internal-linking' ); ?></p>
	<p><?php echo AIIL_Admin::export_button( 'posts' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>

	<table class="widefat striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Post', 'ai-internal-linking' ); ?></th>
				<th><?php esc_html_e( 'Passages', 'ai-internal-linking' ); ?></th>
				<th><?php esc_html_e( 'In / Out', 'ai-internal-linking' ); ?></th>
				<th><?php esc_html_e( 'Max Out', 'ai-internal-linking' ); ?></th>
				<th><?php esc_html_e( 'Indexed', 'ai-internal-linking' ); ?></th>
				<th></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $rows ) ) : ?>
				<tr><td colspan="6"><?php esc_html_e( 'No indexed posts yet. Run the initial scan from the dashboard.', 'ai-internal-linking' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( get_edit_post_link( (int) $row->post_id ) ); ?>"><?php echo esc_html( $row->post_title ); ?></a></td>
						<td><?php echo esc_html( (int) $row->passage_count ); ?></td>
						<td><?php echo esc_html( $row->incoming_links . ' / ' . $row->outgoing_links ); ?></td>
						<td><?php echo esc_html( (int) $row->max_outgoing_links ); ?></td>
						<td><?php echo esc_html( $row->indexed_at ); ?></td>
						<td>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
								<input type="hidden" name="action" value="aiil_index_post" />
								<input type="hidden" name="post_id" value="<?php echo (int) $row->post_id; ?>" />
								<?php wp_nonce_field( AIIL_Admin::NONCE ); ?>
								<button type="submit" class="button button-small"><?php esc_html_e( 'Re-index', 'ai-internal-linking' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>

	<?php
	$total_pages = (int) ceil( $total / $per_page );
	if ( $total_pages > 1 ) :
		echo '<div class="tablenav"><div class="tablenav-pages">';
		echo paginate_links( array(
			'base'    => add_query_arg( 'paged', '%#%' ),
			'format'  => '',
			'current' => $paged,
			'total'   => $total_pages,
		) );
		echo '</div></div>';
	endif;
	?>
</div>
