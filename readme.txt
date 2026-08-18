=== AI Internal Linking ===
Contributors: aiil
Tags: internal links, seo, ai, gemini, embeddings, content
Requires at least: 5.8
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 2.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Retrieval-based internal linking. Embeds every post at the passage level, matches posts by semantic similarity, and grounds each link anchor in a real source sentence. Works on any niche — no topic lists, no per-niche configuration.

== Description ==

AI Internal Linking indexes each post once by splitting it into passages and embedding them (Gemini embeddings). Posts are matched by document-level embedding similarity, and each link is grounded: the plugin retrieves the source passage most relevant to the target and takes the anchor verbatim from that passage. Because matching and anchoring are both semantic, the plugin generalises across niches without any hardcoded vocabulary.

The generative AI model is optional and used only as a fallback to pick an anchor when a passage has no clean one.

Features:

* Passage-level embedding index per post (re-runs on edit; unchanged content is skipped)
* Semantic, domain-general matching by embedding cosine similarity (top-K neighbours per post)
* Grounded anchors: retrieved from the most relevant source passage, so links never fail to insert
* Precision gate: a link is only prepared when the source has a passage relevant enough to host it
* Both-direction candidates for strongly related pairs, with reciprocal-link avoidance
* Approve / reject / edit suggestions, or auto-insert above a confidence threshold
* Orphan page detection with one-click inbound-opportunity generation
* Daily link health checks for broken or trashed targets
* Browser-driven batch processing (no reliance on WP-Cron) plus WP-Cron background processing
* JSON export of every dataset for auditing

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate through the *Plugins* screen.
3. Go to **AI Linking → Settings** and add your Gemini API key.
4. Click **Index Existing Posts** on the dashboard to run the initial scan.
5. Open **Link Opportunities** and use **Prepare all pending anchors**, then review.

== Changelog ==

= 2.6.0 =
* Better anchors: after the AI picks an anchor, a deterministic refiner (no extra API call) upgrades a lazy single word to the longest phrase that exists in the source passage and overlaps the target title — e.g. "Ceramic" → "Ceramic coatings", "detailing" → "Regular car detailing" — and replaces an off-topic word ("quietly") with the on-topic phrase ("car rental in Singapore") when it is present. Applies at verification and at insertion, so links verified earlier also benefit.

= 2.5.5 =
* Links are no longer placed next to an existing link. The inserter now keeps a minimum word gap (default 3) from any link already in the post and chooses the roomiest spot for the anchor. Configurable under Settings → Insertion → Min Words From Existing Links (0 disables).

= 2.5.4 =
* Inserted internal links can now be bold (anchor wrapped in <strong>). On by default; toggle under Settings → Insertion → Bold Anchor Text. Existing links are unaffected.

= 2.5.3 =
* Links are never inserted into headings. Headings (and figure captions) are now excluded both when choosing where to link from and when writing the link — an internal link only ever lands in body copy, never an H1–H6, caption, or nav. Re-index posts to also stop headings being chosen as a link source.

= 2.5.2 =
* New-install defaults tuned for hands-off use: Max Outgoing Links Per Post 2 (was 3), Auto Insert ON, and Auto-Insert Min Confidence 75 (aligned with the AI verify threshold so verified links actually insert). Existing installs keep their saved settings.

= 2.5.1 =
* Self-updating. The plugin can now check a release source you control and update itself through the normal WordPress update screen, including the per-plugin "Enable auto-updates" toggle. Point AIIL_GITHUB_REPO at your repo to switch it on; the source is a swappable adapter (GitHub releases or your own JSON endpoint) so hosting can change later without a rewrite.

= 2.5.0 =
* Removed the "Needs a human" step. The AI is trusted to choose the anchor, so verification is now binary: a good pair with a real in-passage anchor is Verified; anything else is simply Not linked (with the reason recorded). No manual anchoring step.
* Auto-link new posts: publishing or editing a post now finds, prepares and (if AI verification is on) verifies its links automatically in the background, in both directions — so future content is woven in without a manual run. Insertion still follows Auto Insert. The bulk initial scan stays under your control on the Dashboard. New setting: Auto-Link New Posts (on by default).
* "Insert all links" button on the Ready-to-insert tab applies the whole reviewed set in one go.
* Orphans: the Suggestions in/out numbers are now clickable — they open every suggestion for that post (any status) with the anchor, scores and the reason it was kept or dropped.

= 2.4.3 =
* Link scanner hardening: www/non-www host variants are now treated as the same site (previously a site linking to its www variant could scan as having zero internal links), and document-relative hrefs are skipped rather than guessed at, so the scan can't invent an inbound link and hide a real orphan.

= 2.4.2 =
* The AI's anchor choice is now trusted. Min Anchor Score is a safety floor (default 70), not a quality bar — links no longer land in "Needs a human" when the AI already picked a good, grounded anchor. That bucket is now reserved for pairs where the AI genuinely could not find an anchor in the passage.
* Orphans page shows how many live link suggestions each orphan already has, in both directions ("in" = links pointing at it, which is what fixes the orphan; "out" = links from it).
* Review rows show the anchor the AI actually chose plus its pair/anchor scores, instead of a stale mechanical guess.

= 2.4.1 =
* Recalibrated the AI verification threshold: the new anchor-choosing prompt shifted the model's pair-usefulness scores down, so the old cut-off of 80 was rejecting good 75–79 links. Default Min Pair Score is now 75.
* Added "Re-apply thresholds (no new AI calls)" on the Review page — re-sorts every existing AI verdict under the current Min Pair/Anchor scores instantly and for free, so you can tune the cut-offs without paying to re-verify.

= 2.4.0 =
* The AI verification step now CHOOSES the anchor instead of rubber-stamping a mechanically guessed word. Given the semantically-retrieved source passage, it selects the most specific phrase (preferring a 2–5 word noun phrase, rejecting generic single words) that genuinely refers to the target, and the link is placed on that phrase in that passage.
* The chosen anchor is grounded — it must appear verbatim in the passage — so anchors can never be hallucinated and always insert cleanly.
* When AI verification is on, a relevant pair with no clean anchor now correctly becomes "Rewrite Suggested" (previously this never fired); mechanical anchor extraction is only a hint/fallback.

= 2.3.0 =
* Real orphan detection: a new "Scan existing links" tool reads every published post's HTML, resolves the internal links actually in your content to a real source→target graph, and bases orphan status on that — not just on links this plugin created. Discovered links are exportable.
* Guided one-click Dashboard pipeline (index → match → prepare → verify) with a progress bar and step checklist, so the whole flow runs from a single button.
* Statuses grouped into plain-English buckets (Ready to insert / Needs a human / Live / Not linked / In progress); the review page, menu and layout were reorganised to match.
* Export now emits integer post IDs (were strings) so exports join/compare cleanly.

= 2.2.0 =
* Fixed link pipeline ordering. Both directions of a pair are now prepared independently; a reverse direction is blocked as "reciprocal" only once its counterpart is actually kept/verified — not merely because it was considered first.
* AI verification now backfills: it keeps reviewing a source's candidates past rejected ones until the source fills its outgoing allowance or a per-source rerank budget is reached. The per-source cap is applied to the kept set, not before review.
* Reranker is now structured: it judges pair relevance (topic/product/jurisdiction/usefulness) and anchor quality (does the anchor refer to the target in-sentence) separately. A useful pair with a weak anchor becomes "Rewrite Suggested" instead of being verified or discarded.
* New settings: rerank budget per source, min pair score, min anchor score.
* Knowledge Graph page: an interactive force-directed map of posts and their semantic connections, with auto-detected topic clusters.

= 2.1.0 =
* Titles are embedded for retrieval but never stored as anchorable passages (no links inside the H1).
* Anchor selection is now specificity-aware via corpus IDF (no word lists): single-word acronyms and rare terms are eligible, phrases extend to 6 words, and generic phrases shared across the whole site are rejected instead of becoming weak links.
* Per-source top-N outgoing cap enforced before links are marked final Ready (surplus → "Over Cap").
* Embedding mean-centering + calibrated 0–100 score mapping, so similarity thresholds regain meaning.
* Optional AI verification (rerank) over Ready links: one scoped call judges reader usefulness, jurisdiction and product fit; kept links become "Verified", poor fits "AI Rejected". Auto-insert then acts only on Verified links.

= 2.0.0 =
* Rewritten around a retrieval (RAG-style) architecture: passage-level embeddings for both matching and anchor grounding.
* Removed the previous topic/importance/lexical layer entirely — matching is now purely semantic and niche-agnostic.
* New passages table; document vectors stored per post.
* Anchors are grounded in a real source passage; AI anchor selection is now an optional fallback.

= 1.0.0 =
* Initial release.
