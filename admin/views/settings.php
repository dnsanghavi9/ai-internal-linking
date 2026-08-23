<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$settings = AIIL_Settings::all();
?>
<div class="wrap aiil-wrap">
	<h1><?php esc_html_e( 'AI Internal Linking — Settings', 'ai-internal-linking' ); ?></h1>

	<?php if ( isset( $_GET['updated'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'ai-internal-linking' ); ?></p></div>
	<?php endif; ?>

	<?php if ( isset( $_GET['reset'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'All plugin data was cleared. Settings were kept.', 'ai-internal-linking' ); ?></p></div>
	<?php endif; ?>
	<?php if ( isset( $_GET['unlinked'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p>
			<?php printf( esc_html__( 'Removed %d inserted internal link(s) from your posts (anchor text kept, links unwrapped).', 'ai-internal-linking' ), (int) $_GET['unlinked'] ); ?>
		</p></div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="aiil_save_settings" />
		<?php wp_nonce_field( AIIL_Admin::NONCE ); ?>

		<h2><?php esc_html_e( 'AI Provider', 'ai-internal-linking' ); ?></h2>
		<table class="form-table">
			<tr>
				<th><label for="aiil_provider"><?php esc_html_e( 'Provider', 'ai-internal-linking' ); ?></label></th>
				<td>
					<select name="aiil[provider]" id="aiil_provider">
						<option value="gemini" <?php selected( $settings['provider'], 'gemini' ); ?>>Gemini</option>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="aiil_model"><?php esc_html_e( 'Model', 'ai-internal-linking' ); ?></label></th>
				<td>
					<input type="text" id="aiil_model" name="aiil[model]" value="<?php echo esc_attr( $settings['model'] ); ?>" class="regular-text" />
					<p class="description"><?php esc_html_e( 'Generative model for AI verification / anchor fallback. Default: gemini-3.1-flash-lite. (gemini-2.5-flash-lite is being retired for new accounts.)', 'ai-internal-linking' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="aiil_service_tier"><?php esc_html_e( 'Service Tier', 'ai-internal-linking' ); ?></label></th>
				<td>
					<select id="aiil_service_tier" name="aiil[service_tier]">
						<option value="flex" <?php selected( $settings['service_tier'], 'flex' ); ?>><?php esc_html_e( 'Flex — cheaper, variable latency (recommended)', 'ai-internal-linking' ); ?></option>
						<option value="standard" <?php selected( $settings['service_tier'], '' ); ?>><?php esc_html_e( 'Standard — full price, fastest', 'ai-internal-linking' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'AI verification runs in the background, so the cheaper Flex tier is ideal. If your Gemini plan does not offer Flex, choose Standard. (Applies to the generative model only, not embeddings.)', 'ai-internal-linking' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="aiil_api_key"><?php esc_html_e( 'API Key', 'ai-internal-linking' ); ?></label></th>
				<td>
					<?php $has_key = AIIL_Settings::has_api_key(); ?>
					<input type="password" id="aiil_api_key" name="aiil[api_key]" value="" class="regular-text" autocomplete="off"
						placeholder="<?php echo $has_key ? esc_attr__( '•••••••• (saved — leave blank to keep)', 'ai-internal-linking' ) : esc_attr__( 'Enter your Gemini API key', 'ai-internal-linking' ); ?>" />
					<p class="description">
						<?php
						echo $has_key
							? esc_html__( 'A key is currently saved. Leave this field blank to keep it, or enter a new key to replace it.', 'ai-internal-linking' )
							: esc_html__( 'Required. Get a key from Google AI Studio.', 'ai-internal-linking' );
						?>
					</p>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Matching', 'ai-internal-linking' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Matching is purely semantic (embedding cosine). These thresholds are on a 0–100 scale — 100 is an identical vector. They generalise across niches, so the defaults are a good starting point for any blog.', 'ai-internal-linking' ); ?></p>
		<table class="form-table">
			<tr>
				<th><label for="aiil_max_outgoing_links"><?php esc_html_e( 'Max Outgoing Links Per Post', 'ai-internal-linking' ); ?></label></th>
				<td>
					<input type="number" id="aiil_max_outgoing_links" name="aiil[max_outgoing_links]" value="<?php echo esc_attr( $settings['max_outgoing_links'] ); ?>" min="1" step="1" />
					<p class="description"><?php esc_html_e( 'A source post will receive at most this many outgoing internal links.', 'ai-internal-linking' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="aiil_match_top_k"><?php esc_html_e( 'Neighbours Per Post (top-K)', 'ai-internal-linking' ); ?></label></th>
				<td>
					<input type="number" id="aiil_match_top_k" name="aiil[match_top_k]" value="<?php echo esc_attr( $settings['match_top_k'] ); ?>" min="1" max="50" step="1" />
					<p class="description"><?php esc_html_e( 'How many nearest posts each post is compared against when generating candidates. Default: 8.', 'ai-internal-linking' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="aiil_min_doc_similarity"><?php esc_html_e( 'Min Document Similarity', 'ai-internal-linking' ); ?></label></th>
				<td>
					<input type="number" id="aiil_min_doc_similarity" name="aiil[min_doc_similarity]" value="<?php echo esc_attr( $settings['min_doc_similarity'] ); ?>" min="0" max="100" step="1" />
					<p class="description"><?php esc_html_e( 'Two posts must be at least this similar (whole-document) to become an opportunity at all. Default: 55.', 'ai-internal-linking' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="aiil_min_passage_score"><?php esc_html_e( 'Min Passage Score', 'ai-internal-linking' ); ?></label></th>
				<td>
					<input type="number" id="aiil_min_passage_score" name="aiil[min_passage_score]" value="<?php echo esc_attr( $settings['min_passage_score'] ); ?>" min="0" max="100" step="1" />
					<p class="description"><?php esc_html_e( 'The precision gate. A link is only prepared if the source post has a passage at least this relevant to the target — i.e. a natural place to link from. Opportunities below this are marked "Low Relevance". Default: 55.', 'ai-internal-linking' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="aiil_both_direction_min"><?php esc_html_e( 'Both-Direction Threshold', 'ai-internal-linking' ); ?></label></th>
				<td>
					<input type="number" id="aiil_both_direction_min" name="aiil[both_direction_min]" value="<?php echo esc_attr( $settings['both_direction_min'] ); ?>" min="0" max="100" step="1" />
					<p class="description"><?php esc_html_e( 'For a strongly related pair at/above this similarity, opportunities are generated in BOTH directions (reciprocal avoidance still keeps only the stronger one as Ready). Default: 60.', 'ai-internal-linking' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="aiil_batch_size"><?php esc_html_e( 'Batch Size', 'ai-internal-linking' ); ?></label></th>
				<td><input type="number" id="aiil_batch_size" name="aiil[batch_size]" value="<?php echo esc_attr( $settings['batch_size'] ); ?>" min="1" max="100" step="1" /></td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Automation', 'ai-internal-linking' ); ?></h2>
		<table class="form-table">
			<tr>
				<th><?php esc_html_e( 'Auto-Link New Posts', 'ai-internal-linking' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="aiil[auto_link_new]" value="1" <?php checked( $settings['auto_link_new'], 1 ); ?> />
						<?php esc_html_e( 'When a post is published or edited, find and prepare its links automatically in the background', 'ai-internal-linking' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'New posts get woven into the link graph (in both directions) without a manual pipeline run. If AI verification is on, they are verified too. They appear under Review Links ready to insert; they are only inserted automatically if “Auto Insert” below is also on.', 'ai-internal-linking' ); ?></p>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Insertion', 'ai-internal-linking' ); ?></h2>
		<table class="form-table">
			<tr>
				<th><?php esc_html_e( 'Bold Anchor Text', 'ai-internal-linking' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="aiil[bold_links]" value="1" <?php checked( $settings['bold_links'], 1 ); ?> />
						<?php esc_html_e( 'Make inserted internal links bold (wrap the anchor in <strong>)', 'ai-internal-linking' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Applies to links inserted from now on. Links already in your posts are unaffected.', 'ai-internal-linking' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="aiil_link_word_gap"><?php esc_html_e( 'Min Words From Existing Links', 'ai-internal-linking' ); ?></label></th>
				<td>
					<input type="number" id="aiil_link_word_gap" name="aiil[link_word_gap]" value="<?php echo esc_attr( $settings['link_word_gap'] ); ?>" min="0" max="20" step="1" />
					<p class="description"><?php esc_html_e( 'Keep at least this many words between a new internal link and any link already in the post, so links are never placed side by side. The plugin picks the roomiest spot for the anchor. Default: 3. Set to 0 to disable.', 'ai-internal-linking' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Avoid Reciprocal Links', 'ai-internal-linking' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="aiil[avoid_reciprocal]" value="1" <?php checked( $settings['avoid_reciprocal'], 1 ); ?> />
						<?php esc_html_e( 'Don\'t insert a link if the reverse direction is already linked', 'ai-internal-linking' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Auto Insert', 'ai-internal-linking' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="aiil[auto_insert]" value="1" <?php checked( $settings['auto_insert'], 1 ); ?> />
						<?php esc_html_e( 'Automatically insert prepared links that clear the confidence minimum below', 'ai-internal-linking' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Leave off until you have reviewed results on 20–30 posts.', 'ai-internal-linking' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="aiil_auto_min_confidence"><?php esc_html_e( 'Auto-Insert Min Confidence', 'ai-internal-linking' ); ?></label></th>
				<td><input type="number" id="aiil_auto_min_confidence" name="aiil[auto_min_confidence]" value="<?php echo esc_attr( $settings['auto_min_confidence'] ); ?>" min="0" max="100" step="1" /></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'AI Anchor Fallback', 'ai-internal-linking' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="aiil[use_ai_anchor]" value="1" <?php checked( $settings['use_ai_anchor'], 1 ); ?> />
						<?php esc_html_e( 'When no natural anchor exists in the chosen passage, ask the AI to pick one (costs API credits)', 'ai-internal-linking' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Off by default. Anchors are normally taken verbatim from text already in the source passage, so links never fail to insert. When on, the AI is called only for the few opportunities with no clean anchor, scoped to a single sentence.', 'ai-internal-linking' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'AI Verification (rerank)', 'ai-internal-linking' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="aiil[use_ai_rerank]" value="1" <?php checked( $settings['use_ai_rerank'], 1 ); ?> />
						<?php esc_html_e( 'Verify each Ready link with one AI call before it can be inserted (costs API credits)', 'ai-internal-linking' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'The judgement embeddings can’t make. For each candidate the AI (1) decides whether the target genuinely helps the reader and the two articles are jurisdiction/product compatible, and (2) CHOOSES the anchor — the best specific phrase that already exists in the retrieved source passage — instead of a mechanically guessed word. Kept links become “Verified” with that anchor; poor pairs become “AI Rejected”; a good pair where no clean anchor exists becomes “Rewrite Suggested”. Verification keeps going per source until the outgoing allowance is filled (backfilling past rejected candidates), up to the budget below. Use the “Verify ready links with AI” button on the Link Opportunities page.', 'ai-internal-linking' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="aiil_rerank_budget"><?php esc_html_e( 'Rerank Budget Per Source', 'ai-internal-linking' ); ?></label></th>
				<td>
					<input type="number" id="aiil_rerank_budget" name="aiil[rerank_budget]" value="<?php echo esc_attr( $settings['rerank_budget'] ); ?>" min="1" max="20" step="1" />
					<p class="description"><?php esc_html_e( 'Max AI verify calls per source post. Verification stops early once the source has its full outgoing allowance of verified links. Default: 8.', 'ai-internal-linking' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="aiil_rerank_pair_min"><?php esc_html_e( 'Min Pair Score', 'ai-internal-linking' ); ?></label></th>
				<td>
					<input type="number" id="aiil_rerank_pair_min" name="aiil[rerank_pair_min]" value="<?php echo esc_attr( $settings['rerank_pair_min'] ); ?>" min="0" max="100" step="1" />
					<p class="description"><?php esc_html_e( 'AI reader-usefulness below this (or a product/jurisdiction mismatch) rejects the pair. The AI also picks the anchor; a pair kept here with a real anchor is verified with no manual step. Default: 75.', 'ai-internal-linking' ); ?></p>
				</td>
			</tr>
		</table>

		<?php submit_button(); ?>
	</form>

	<hr />

	<div class="aiil-danger-zone">
		<h2><?php esc_html_e( 'Testing Tools — Danger Zone', 'ai-internal-linking' ); ?></h2>

		<h3><?php esc_html_e( 'Remove inserted links from posts', 'ai-internal-linking' ); ?></h3>
		<p class="description">
			<?php esc_html_e( 'Unwraps every internal link this plugin inserted, leaving the anchor text in place. Editorial links you added yourself are not touched. This edits post content and cannot be undone. Plugin data (index, opportunities) is kept, so you can re-insert afterwards.', 'ai-internal-linking' ); ?>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="aiil-danger-form"
			data-confirm="<?php esc_attr_e( 'Remove ALL links this plugin inserted from your post content? Anchor text is kept; this edits posts and cannot be undone.', 'ai-internal-linking' ); ?>">
			<input type="hidden" name="action" value="aiil_remove_links" />
			<?php wp_nonce_field( 'aiil_remove_links' ); ?>
			<button type="submit" class="button button-link-delete"><?php esc_html_e( 'Remove Inserted Links', 'ai-internal-linking' ); ?></button>
		</form>

		<hr style="margin:20px 0" />

		<h3><?php esc_html_e( 'Clear all plugin data', 'ai-internal-linking' ); ?></h3>
		<p class="description">
			<?php esc_html_e( 'Empties all plugin data (indexed posts, passages, opportunities, inserted-link records, queue, and logs) so you can test from a clean slate. Your settings and API key are kept.', 'ai-internal-linking' ); ?>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="aiil-danger-form"
			data-confirm="<?php esc_attr_e( 'This permanently clears ALL plugin data (posts analysis, opportunities, links, queue, logs). Continue?', 'ai-internal-linking' ); ?>">
			<input type="hidden" name="action" value="aiil_reset_data" />
			<?php wp_nonce_field( 'aiil_reset_data' ); ?>
			<p>
				<label>
					<input type="checkbox" name="remove_links" value="1" />
					<?php esc_html_e( 'Also remove the plugin’s inserted links from post content (recommended for a full clean slate)', 'ai-internal-linking' ); ?>
				</label>
			</p>
			<button type="submit" class="button button-link-delete"><?php esc_html_e( 'Clear All Plugin Data', 'ai-internal-linking' ); ?></button>
		</form>
	</div>
</div>
