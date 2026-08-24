(function ($) {
	'use strict';

	$(function () {
		// Confirm reject actions to avoid mis-clicks.
		$('.aiil-inline-form').on('click', 'button[value="reject"]', function (e) {
			if (!window.confirm('Reject this opportunity?')) {
				e.preventDefault();
				e.stopImmediatePropagation();
			}
		});

		// Confirm destructive forms (e.g. the "Clear All Plugin Data" testing tool).
		$('.aiil-danger-form').on('submit', function (e) {
			var msg = $(this).data('confirm') || 'Are you sure?';
			if (!window.confirm(msg)) {
				e.preventDefault();
			}
		});

		initOpportunityActions();
		initPrepareAll();
		initVerifyAll();
		initInsertAll();
		initQueueRunner();
		initPipeline();
	});

	/**
	 * "Insert all links" — loops an AJAX batch endpoint that writes ready/verified links into
	 * their source posts until none remain, then reloads.
	 */
	function initInsertAll() {
		var $wrap = $('#aiil-insert-all');
		if (!$wrap.length || typeof AIIL === 'undefined') {
			return;
		}
		var $btn = $('#aiil-insert-all-start');
		var $label = $wrap.find('.aiil-insert-all-label');
		var total = parseInt($wrap.data('remaining'), 10) || 0;
		var insTotal = 0;
		var failTotal = 0;
		var running = false;

		function tick() {
			if (!running) { return; }
			$.post(AIIL.ajaxUrl, { action: 'aiil_insert_all', nonce: AIIL.nonce })
				.done(function (res) {
					if (!res || !res.success) {
						$label.text((res && res.data && res.data.message) ? res.data.message : 'Error — check the Logs tab.');
						running = false; $btn.prop('disabled', false);
						return;
					}
					insTotal += res.data.inserted;
					failTotal += res.data.failed;
					var remaining = res.data.remaining;
					var done = total > 0 ? Math.round(((total - remaining) / total) * 100) : 100;
					$label.text('Inserted ' + insTotal + (failTotal ? ', skipped ' + failTotal : '') + ' — ' + remaining + ' remaining (' + done + '%)');
					if (remaining > 0) {
						tick();
					} else {
						$label.text('Done. Inserted ' + insTotal + (failTotal ? ', skipped ' + failTotal + ' (see Logs)' : '') + '. Reloading…');
						window.location.reload();
					}
				})
				.fail(function () {
					$label.text('Request failed — check the Logs tab.');
					running = false; $btn.prop('disabled', false);
				});
		}

		$btn.on('click', function () {
			if (!window.confirm('Insert all ' + total + ' links into your posts now?')) { return; }
			running = true; insTotal = 0; failTotal = 0;
			total = parseInt($wrap.data('remaining'), 10) || 0;
			$btn.prop('disabled', true);
			$label.text('Working…');
			tick();
		});
	}

	/**
	 * One-click dashboard pipeline: index -> process queue (match) -> prepare anchors ->
	 * verify (if AI verification is on). Chains the existing AJAX batch endpoints so the user
	 * never has to run the steps by hand across pages.
	 */
	function initPipeline() {
		var $p = $('.aiil-pipeline');
		if (!$p.length || typeof AIIL === 'undefined') {
			return;
		}

		var $run = $('#aiil-pipeline-run');
		var $stop = $('#aiil-pipeline-stop');
		var $progress = $p.find('.aiil-progress');
		var $bar = $p.find('.aiil-progress-bar > span');
		var $label = $p.find('.aiil-progress-label');
		var rerank = String($p.data('rerank')) === '1';
		var running = false;

		function setStep(phase, state) {
			$p.find('.aiil-steps li').removeClass('active');
			var $li = $p.find('.aiil-steps li[data-phase="' + phase + '"]');
			if (state === 'active') { $li.addClass('active'); }
			if (state === 'done') { $li.addClass('done'); }
		}
		function say(txt, pct) {
			$label.text(txt);
			if (typeof pct === 'number') { $bar.css('width', Math.max(0, Math.min(100, pct)) + '%'); }
		}
		function fail(msg) {
			running = false;
			$stop.hide();
			$run.prop('disabled', false).show().text('Run pipeline');
			say(msg || 'Something went wrong — check the Logs tab.');
		}

		// Generic "loop a batch endpoint until remaining === 0".
		function drain(action, phase, label, done) {
			var total = 0;
			(function tick() {
				if (!running) { return; }
				$.post(AIIL.ajaxUrl, { action: action, nonce: AIIL.nonce }).done(function (res) {
					if (!res || !res.success) { return fail(res && res.data && res.data.message); }
					var remaining = res.data.remaining || 0;
					if (remaining > total) { total = remaining; }
					var pct = total > 0 ? Math.round(((total - remaining) / total) * 100) : 100;
					say(label + ' — ' + remaining + ' left', pct);
					if (remaining > 0) {
						(res.data.processed === 0) ? window.setTimeout(tick, 1200) : tick();
					} else {
						setStep(phase, 'done');
						done();
					}
				}).fail(function () { fail(); });
			})();
		}

		function phaseIndex() {
			setStep('index', 'active');
			say('Indexing posts…', 2);
			$.post(AIIL.ajaxUrl, { action: 'aiil_index_enqueue', nonce: AIIL.nonce }).done(function (res) {
				if (!res || !res.success) { return fail(res && res.data && res.data.message); }
				setStep('index', 'done');
				phaseProcess();
			}).fail(function () { fail(); });
		}
		function phaseProcess() {
			setStep('match', 'active');
			drain('aiil_process_batch', 'match', 'Reading & matching posts', phasePrepare);
		}
		function phasePrepare() {
			setStep('prepare', 'active');
			drain('aiil_prepare_all', 'prepare', 'Preparing anchors', phaseVerify);
		}
		function phaseVerify() {
			if (!rerank) { return finish(); }
			setStep('verify', 'active');
			drain('aiil_verify_all', 'verify', 'Verifying with AI', finish);
		}
		function finish() {
			running = false;
			$stop.hide();
			say('Done. Reloading…', 100);
			window.location = $p.data('review-url');
		}

		$run.on('click', function () {
			running = true;
			$run.prop('disabled', true).hide();
			$stop.show();
			$progress.show();
			$bar.css('width', '0%');
			phaseIndex();
		});
		$stop.on('click', function () {
			running = false;
			$stop.hide();
			$run.prop('disabled', false).show().text('Resume');
			say('Stopped.');
		});
	}

	/**
	 * "Verify ready links with AI" — loops an AJAX batch endpoint until no ready links
	 * remain, then reloads to the Verified tab.
	 */
	function initVerifyAll() {
		var $wrap = $('#aiil-verify-all');
		if (!$wrap.length || typeof AIIL === 'undefined') {
			return;
		}
		var $btn = $('#aiil-verify-all-start');
		var $label = $wrap.find('.aiil-verify-all-label');
		var total = parseInt($wrap.data('remaining'), 10) || 0;
		var keptTotal = 0;
		var rejTotal = 0;
		var insTotal = 0;
		var running = false;

		function tick() {
			if (!running) {
				return;
			}
			$.post(AIIL.ajaxUrl, { action: 'aiil_verify_all', nonce: AIIL.nonce })
				.done(function (res) {
					if (!res || !res.success) {
						$label.text((res && res.data && res.data.message) ? res.data.message : 'Error — check the Logs tab.');
						running = false;
						$btn.prop('disabled', false);
						return;
					}
					keptTotal += res.data.kept;
					rejTotal += res.data.rejected;
					insTotal += res.data.inserted;
					var remaining = res.data.remaining;
					var done = total > 0 ? Math.round(((total - remaining) / total) * 100) : 100;
					$label.text('Kept ' + keptTotal + ', rejected ' + rejTotal + (insTotal ? ', inserted ' + insTotal : '') + ' — ' + remaining + ' remaining (' + done + '%)');
					if (remaining > 0) {
						tick();
					} else {
						$label.text('Done. Kept ' + keptTotal + ', rejected ' + rejTotal + '. Reloading…');
						window.location = $wrap.data('verified-url');
					}
				})
				.fail(function () {
					$label.text('Request failed — check the Logs tab.');
					running = false;
					$btn.prop('disabled', false);
				});
		}

		$btn.on('click', function () {
			running = true;
			keptTotal = 0;
			rejTotal = 0;
			insTotal = 0;
			total = parseInt($wrap.data('remaining'), 10) || 0;
			$btn.prop('disabled', true);
			$label.text('Working…');
			tick();
		});
	}

	/**
	 * "Prepare all pending anchors" — loops an AJAX batch endpoint until no pending
	 * opportunities remain, then reloads to the Ready tab so results are visible.
	 */
	function initPrepareAll() {
		var $wrap = $('#aiil-prepare-all');
		if (!$wrap.length || typeof AIIL === 'undefined') {
			return;
		}

		var $btn = $('#aiil-prepare-all-start');
		var $label = $wrap.find('.aiil-prepare-all-label');
		var total = parseInt($wrap.data('remaining'), 10) || 0;
		var preparedTotal = 0;
		var noAnchorTotal = 0;
		var running = false;

		function tick() {
			if (!running) {
				return;
			}
			$.post(AIIL.ajaxUrl, { action: 'aiil_prepare_all', nonce: AIIL.nonce })
				.done(function (res) {
					if (!res || !res.success) {
						$label.text('Error — check the Logs tab.');
						running = false;
						$btn.prop('disabled', false);
						return;
					}
					preparedTotal += res.data.prepared;
					noAnchorTotal += res.data.no_anchor;
					var remaining = res.data.remaining;
					var done = total > 0 ? Math.round(((total - remaining) / total) * 100) : 100;
					$label.text('Prepared ' + preparedTotal + ', no-anchor ' + noAnchorTotal + ' — ' + remaining + ' remaining (' + done + '%)');

					if (remaining > 0) {
						tick();
					} else {
						var capped = res.data.capped || 0;
						$label.text('Done. Prepared ' + preparedTotal + ', no anchor for ' + noAnchorTotal + (capped ? ', ' + capped + ' over cap' : '') + '. Reloading…');
						window.location = $wrap.data('ready-url');
					}
				})
				.fail(function () {
					$label.text('Request failed — check the Logs tab.');
					running = false;
					$btn.prop('disabled', false);
				});
		}

		$btn.on('click', function () {
			running = true;
			preparedTotal = 0;
			noAnchorTotal = 0;
			total = parseInt($wrap.data('remaining'), 10) || 0;
			$btn.prop('disabled', true);
			$label.text('Working…');
			tick();
		});
	}

	/**
	 * Opportunity row actions (prepare / approve / reject) via AJAX so the row updates in
	 * place with no full-page reload. Falls back to a normal form POST if AIIL is missing.
	 */
	function initOpportunityActions() {
		if (typeof AIIL === 'undefined') {
			return;
		}

		// Record which submit button was clicked so we know the intended op.
		$(document).on('click', '.aiil-inline-form button[name="op"]', function () {
			$(this).closest('form').data('op', $(this).val());
		});

		$(document).on('submit', '.aiil-inline-form', function (e) {
			var $form = $(this);
			var op = $form.data('op');
			if (e.originalEvent && e.originalEvent.submitter) {
				op = e.originalEvent.submitter.value;
			}
			if (!op) {
				return; // let it submit normally
			}
			e.preventDefault();

			var $msg = $form.find('.aiil-op-msg').text('…');
			$form.find('button').prop('disabled', true);

			$.post(AIIL.ajaxUrl, {
				action: 'aiil_opportunity_ajax',
				nonce: AIIL.nonce,
				opportunity_id: $form.data('id'),
				op: op,
				anchor_text: $form.find('input[name="anchor_text"]').val()
			}).done(function (res) {
				$form.find('button').prop('disabled', false);
				if (!res || !res.success) {
					$msg.text((res && res.data && res.data.message) ? res.data.message : 'Error');
					return;
				}
				updateOpportunityRow($form, res.data);
			}).fail(function () {
				$form.find('button').prop('disabled', false);
				$msg.text('Request failed');
			});
		});
	}

	function updateOpportunityRow($form, data) {
		var status = data.status;
		$form.attr('data-status', status).data('status', status);
		$form.find('input[name="anchor_text"]').val(data.anchor_text || '');

		var $row = $form.closest('tr');
		$row.find('.aiil-confidence').text(data.confidence ? Math.round(data.confidence) : '—');

		var isPending = status === 'pending';
		var isApprovable = status === 'ready' || status === 'verified' || status === 'rewrite_suggested' || status === 'insert_failed';
		var isPrepared = status === 'ready' || status === 'rewrite_suggested';
		var canPrepare = isPending || isPrepared || status === 'no_anchor' || status === 'low_relevance' || status === 'insert_failed';

		$form.find('.aiil-op-prepare')
			.toggle(canPrepare)
			.text(isPrepared ? 'Re-prepare' : 'Prepare anchor');
		$form.find('.aiil-op-approve').toggle(isApprovable);
		$form.find('.aiil-op-reject').toggle(isPending || isApprovable);

		var labels = {
			ready: 'Anchor ready',
			verified: 'Verified by AI ✓',
			rewrite_suggested: 'Rewrite suggested — review sentence',
			inserted: 'Inserted ✓',
			rejected: 'Rejected',
			rejected_relevance: 'AI rejected — poor fit',
			no_anchor: 'No distinctive anchor in source',
			low_relevance: 'No relevant passage to link from',
			capped: 'Over the per-source link cap',
			insert_failed: 'Anchor could not be placed — re-prepare or edit the anchor',
			reciprocal: 'Reverse link kept instead',
			invalid: 'Invalid (post missing)',
			deleted: 'Removed'
		};
		$form.find('.aiil-op-msg').text(labels[status] || status);

		if (['inserted', 'rejected', 'rejected_relevance', 'deleted'].indexOf(status) !== -1) {
			$row.css('opacity', 0.5);
		}
	}

	/**
	 * Browser-driven queue runner: fires AJAX batches back-to-back until the
	 * queue is empty, with a progress bar. No cron needed.
	 */
	function initQueueRunner() {
		var $runner = $('#aiil-runner');
		if (!$runner.length || typeof AIIL === 'undefined') {
			return;
		}

		var $start = $('#aiil-run-start');
		var $stop = $('#aiil-run-stop');
		var $progress = $runner.find('.aiil-progress');
		var $bar = $runner.find('.aiil-progress-bar > span');
		var $label = $runner.find('.aiil-progress-label');

		var running = false;
		var total = parseInt($runner.data('remaining'), 10) || 0;

		function render(remaining) {
			// The denominator can grow as analyze jobs spawn match jobs; keep it monotonic.
			if (remaining > total) {
				total = remaining;
			}
			var pct = total > 0 ? Math.round(((total - remaining) / total) * 100) : 100;
			$bar.css('width', pct + '%');
			$label.text(AIIL.i18n.working + ' ' + remaining + ' ' + AIIL.i18n.remaining + ' (' + pct + '%)');
		}

		function tick() {
			if (!running) {
				return;
			}
			$.post(AIIL.ajaxUrl, { action: 'aiil_process_batch', nonce: AIIL.nonce })
				.done(function (res) {
					if (!res || !res.success) {
						$label.text(AIIL.i18n.error);
						stop();
						return;
					}
					var remaining = res.data.remaining;
					var processed = res.data.processed;
					render(remaining);

					if (remaining > 0) {
						// If nothing was processed this round, another worker likely holds the
						// queue lock (or claims briefly collided) — back off before retrying so
						// we don't busy-spin the server.
						if (processed === 0) {
							window.setTimeout(tick, 1500);
						} else {
							tick();
						}
					} else {
						$bar.css('width', '100%');
						$label.text(AIIL.i18n.done);
						stop(true);
					}
				})
				.fail(function () {
					$label.text(AIIL.i18n.error);
					stop();
				});
		}

		function start() {
			running = true;
			total = parseInt($runner.data('remaining'), 10) || 0;
			$start.hide();
			$stop.show();
			$progress.show();
			tick();
		}

		function stop(finished) {
			running = false;
			$stop.hide();
			$start.show();
			if (!finished) {
				$start.text(AIIL.i18n.resume);
			}
		}

		$start.on('click', start);
		$stop.on('click', function () { stop(false); });
	}
})(jQuery);
