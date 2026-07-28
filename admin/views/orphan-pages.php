<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$orphans   = AIIL_Orphans::fetch( 100 );
$sugg      = AIIL_Orphans::suggestion_counts( wp_list_pluck( $orphans, 'post_id' ) );
$has_scan  = AIIL_Link_Scanner::has_scan();
$last_scan = AIIL_Link_Scanner::last_scan();
$total     = AIIL_Orphans::count();
?>
<div class="wrap aiil-wrap">
	<h1><?php esc_html_e( 'Orphans', 'ai-internal-linking' ); ?></h1>
	<p class="aiil-lead"><?php esc_html_e( 'Published posts that no other post links to. Scan your site first so this reflects the internal links actually in your content — then generate inbound link suggestions for them.', 'ai-internal-linking' ); ?></p>

	<?php if ( isset( $_GET['scanned_posts'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p>
			<?php
			printf(
				esc_html__( 'Scanned %1$d posts, found %2$d real internal links. %3$d posts have no inbound links.', 'ai-internal-linking' ),
				(int) $_GET['scanned_posts'],
				(int) ( $_GET['scanned_links'] ?? 0 ),
				(int) ( $_GET['scan_orphans'] ?? 0 )
			);
			?>
		</p></div>
	<?php endif; ?>
	<?php if ( isset( $_GET['found'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php printf( esc_html__( 'Created %d inbound suggestions for this orphan.', 'ai-internal-linking' ), (int) $_GET['found'] ); ?></p></div>
	<?php endif; ?>
	<?php if ( isset( $_GET['found_all'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p>
			<?php printf( esc_html__( 'Scanned %2$d orphans and created %1$d inbound suggestions.', 'ai-internal-linking' ), (int) $_GET['found_all'], isset( $_GET['scanned'] ) ? (int) $_GET['scanned'] : 0 ); ?>
		</p></div>
	<?php endif; ?>

	<div class="aiil-panel">
		<p>
			<strong><?php esc_html_e( 'Step 1 — Scan existing links', 'ai-internal-linking' ); ?></strong><br />
			<?php
			if ( $has_scan ) {
				printf(
					esc_html__( 'Last scanned %s. Orphan counts below reflect the real internal links in your posts.', 'ai-internal-linking' ),
					'<em>' . esc_html( $last_scan ) . '</em>'
				);
			} else {
				esc_html_e( 'Not scanned yet — orphan counts below are based only on links this plugin created. Run a scan to reflect your real internal links.', 'ai-internal-linking' );
			}
			?>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="aiil_scan_links" />
			<?php wp_nonce_field( 'aiil_scan_links' ); ?>
			<?php submit_button( $has_scan ? __( 'Re-scan existing links', 'ai-internal-linking' ) : __( 'Scan existing links', 'ai-internal-linking' ), 'secondary', 'submit', false ); ?>
			<span class="description"><?php esc_html_e( 'Reads every published post’s HTML — no AI, no API calls.', 'ai-internal-linking' ); ?></span>
		</form>
	</div>

	<div class="aiil-panel">
		<p>
			<strong><?php printf( esc_html__( 'Step 2 — Give the %d orphans inbound links', 'ai-internal-linking' ), (int) $total ); ?></strong><br />
			<span class="description"><?php esc_html_e( 'Finds the most semantically related posts and proposes links pointing at each orphan. These appear under Review Links. No AI calls. The table below shows how many live suggestions each orphan already has — “in” means links pointing at it (what fixes the orphan), “out” means links from it to other posts.', 'ai-internal-linking' ); ?></span>
		</p>
		<?php if ( ! empty( $orphans ) ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="aiil_orphan_find_all" />
				<?php wp_nonce_field( AIIL_Admin::NONCE ); ?>
				<?php submit_button( __( 'Suggest inbound links for all orphans', 'ai-internal-linking' ), 'primary', 'submit', false ); ?>
			</form>
		<?php endif; ?>
		<p style="margin-top:8px"><?php echo AIIL_Admin::export_button( 'orphans' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
	</div>

	<table class="widefat striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Post', 'ai-internal-linking' ); ?></th>
				<th><?php esc_html_e( 'Suggestions in', 'ai-internal-linking' ); ?></th>
				<th><?php esc_html_e( 'Suggestions out', 'ai-internal-linking' ); ?></th>
				<th><?php esc_html_e( 'Passages', 'ai-internal-linking' ); ?></th>
				<th><?php esc_html_e( 'Indexed', 'ai-internal-linking' ); ?></th>
				<th></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $orphans ) ) : ?>
				<tr><td colspan="6"><?php echo $has_scan ? esc_html__( 'No orphans — every post has at least one inbound internal link. 🎉', 'ai-internal-linking' ) : esc_html__( 'No orphans recorded yet. Run a scan above.', 'ai-internal-linking' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $orphans as $row ) :
					$post = get_post( (int) $row->post_id );
					if ( ! $post ) {
						continue;
					}
				?>
					<?php
					$counts = $sugg[ (int) $row->post_id ] ?? array( 'in' => 0, 'out' => 0 );
					$in_url = AIIL_Admin::url( 'link-opportunities', array( 'post' => (int) $row->post_id, 'dir' => 'in' ) );
					$out_url = AIIL_Admin::url( 'link-opportunities', array( 'post' => (int) $row->post_id, 'dir' => 'out' ) );
					?>
					<tr>
						<td><a href="<?php echo esc_url( get_edit_post_link( (int) $row->post_id ) ); ?>"><?php echo esc_html( $post->post_title ); ?></a></td>
						<td>
							<?php if ( $counts['in'] ) : ?>
								<a href="<?php echo esc_url( $in_url ); ?>" title="<?php esc_attr_e( 'Show all inbound suggestions and why each was kept or dropped', 'ai-internal-linking' ); ?>"><strong><?php echo esc_html( $counts['in'] ); ?></strong></a>
							<?php else : ?>
								<span class="aiil-simnote">—</span>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( $counts['out'] ) : ?>
								<a href="<?php echo esc_url( $out_url ); ?>" title="<?php esc_attr_e( 'Show all outbound suggestions and why each was kept or dropped', 'ai-internal-linking' ); ?>"><?php echo esc_html( $counts['out'] ); ?></a>
							<?php else : ?>
								<span class="aiil-simnote">—</span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( (int) $row->passage_count ); ?></td>
						<td><?php echo esc_html( $row->indexed_at ); ?></td>
						<td>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="aiil_orphan_find" />
								<input type="hidden" name="post_id" value="<?php echo (int) $row->post_id; ?>" />
								<?php wp_nonce_field( AIIL_Admin::NONCE ); ?>
								<button type="submit" class="button button-small"><?php esc_html_e( 'Suggest inbound links', 'ai-internal-linking' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>
</div>
