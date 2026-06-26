(function ($) {
	var data = autoaltBulkData;
	if (!data || !data.mode) return;

	var mode = data.mode;
	var batchSize = data.batchSize || 5;
	var total = 0;
	var done = 0;
	var offset = 0;
	var results = [];
	var running = true;

	function getActionLabel() {
		if (mode === 'missing') return 'Fill Missing';
		if (mode === 'review') return 'Review & Improve';
		return 'Regenerate';
	}

	var $resultsContainer = $('#autoalt-results');
	var isProcessingPage = $resultsContainer.length > 0;

	var $statusEl, $notice;

	if (isProcessingPage) {
		$statusEl = $('#autoalt-status');
	} else {
		$notice = $(
			'<div class="notice notice-info is-dismissible">' +
				'<p><strong>Auto Alt Text:</strong> ' + getActionLabel() + ' — starting... <a href="#" class="autoalt-stop-link" style="color:#d63638;">stop</a></p>' +
				'<div class="autoalt-results" style="margin:8px 0 4px;max-height:320px;overflow-y:auto;font-family:monospace;font-size:12px;line-height:1.5;"></div>' +
			'</div>'
		).insertAfter('.wp-header-end');
		$resultsContainer = $notice.find('.autoalt-results');
	}

	function setStatus(text) {
		if (isProcessingPage) {
			$statusEl.text(text);
		} else {
			$notice.find('p').html('<strong>Auto Alt Text:</strong> ' + text + ' <a href="#" class="autoalt-stop-link" style="color:#d63638;">stop</a>');
		}
	}

	function stop(manual) {
		if (!running) return;
		running = false;
		if (manual) {
			var ok = 0, errs = 0;
			results.forEach(function (r) {
				if (r.status === 'success') ok++;
				else if (r.status === 'error') errs++;
			});
			var summary = 'Stopped. ' + done + ' of ' + total + ' processed (' + ok + ' ok';
			if (errs > 0) summary += ', ' + errs + ' errors';
			summary += ')';
			setStatus(summary);
			if (!isProcessingPage) {
				$notice.removeClass('notice-info').addClass('notice-warning');
			}
		}
		cleanUrl();
	}

	if (!isProcessingPage) {
		$notice.on('click', '.notice-dismiss', function () {
			stop(true);
		});
		$notice.on('click', '.autoalt-stop-link', function (e) {
			e.preventDefault();
			stop(true);
		});
	}

	$(document).on('click', '.autoalt-stop-link', function (e) {
		e.preventDefault();
		stop(true);
	});

	function addEntry(r) {
		var $entry = $('<div style="padding:3px 6px;margin:1px 0;border-radius:2px;">');
		var text;

		if (r.status === 'success') {
			var cur = r.previous ? r.previous.substring(0, 200) : '';
			var gen = (r.generated || '(decorative)').substring(0, 200);

			if (r.changed && cur) {
				text = '#' + r.id + ' ' + (r.title || '') + ' → REPLACED\n  was: "' + cur + '"\n  now: "' + gen + '"';
				$entry.css('background', '#edfaef').css('border-left', '3px solid #00a32a');
			} else if (r.changed) {
				text = '#' + r.id + ' ' + (r.title || '') + ' + ADDED\n  alt: "' + gen + '"';
				$entry.css('background', '#edfaef').css('border-left', '3px solid #00a32a');
			} else {
				text = '#' + r.id + ' ' + (r.title || '') + ' ✓ KEPT\n  alt: "' + gen + '"';
				$entry.css('background', '#fef8ee').css('border-left', '3px solid #dba617');
			}
		} else if (r.status === 'error') {
			text = '#' + r.id + ' ' + (r.title || '') + ' ✗ ' + (r.error || 'Error');
			$entry.css('background', '#fcf0f1').css('border-left', '3px solid #d63638');
		} else {
			text = '#' + r.id + ' ' + (r.title || '') + ' — ' + (r.reason || 'Skipped');
			$entry.css('background', '#f6f7f7').css('border-left', '3px solid #c3c4c7');
		}

		$entry.css('white-space', 'pre-wrap').css('word-break', 'break-word');
		$entry.text(text);
		$resultsContainer.append($entry);
		$resultsContainer.scrollTop($resultsContainer[0].scrollHeight);
	}

	function updateSummary() {
		var ok = 0, errs = 0;
		results.forEach(function (r) {
			if (r.status === 'success') ok++;
			else if (r.status === 'error') errs++;
		});
		var summary = 'Complete. ' + ok + ' ok';
		if (errs > 0) summary += ', ' + errs + ' errors';
		setStatus(summary);
		if (!isProcessingPage) {
			$notice.removeClass('notice-info').addClass(errs > 0 ? 'notice-warning' : 'notice-success');
		}
	}

	function processId(id, cb) {
		setStatus(getActionLabel() + ' — ' + (done + 1) + ' / ' + total + '...');

		$.ajax({
			url: data.ajaxUrl,
			method: 'POST',
			data: {
				action: 'autoalt_process_single',
				nonce: data.nonce,
				id: id,
				mode: mode,
			},
			success: function (response) {
				if (!running) return;
				var r = response.data;
				results.push(r);
				addEntry(r);
			},
			error: function () {
				if (!running) return;
				results.push({ id: id, status: 'error' });
				addEntry({ id: id, title: '', status: 'error', error: 'Request failed' });
			},
			complete: function () {
				done++;
				cb();
			},
		});
	}

	function processBatch(ids, cb) {
		if (!running || ids.length === 0) {
			cb();
			return;
		}

		var i = 0;
		function nextInBatch() {
			if (!running || i >= ids.length) {
				cb();
				return;
			}
			processId(ids[i], function () {
				i++;
				setTimeout(nextInBatch, 300);
			});
		}
		nextInBatch();
	}

	function fetchBatch() {
		if (!running) return;

		$.ajax({
			url: data.ajaxUrl,
			method: 'POST',
			data: {
				action: 'autoalt_get_ids',
				nonce: data.nonce,
				mode: mode,
				offset: offset,
				batch: batchSize,
			},
			success: function (response) {
				if (!running) return;

				var d = response.data;
				total = d.total;
				var ids = d.ids || [];

				if (ids.length === 0) {
					running = false;
					updateSummary();
					cleanUrl();
					return;
				}

				processBatch(ids, function () {
					if (!running) return;
					offset += ids.length;
					setTimeout(fetchBatch, 100);
				});
			},
			error: function () {
				if (!running) return;
				running = false;
				setStatus('Failed to fetch image list.');
				if (!isProcessingPage) {
					$notice.removeClass('notice-info').addClass('notice-error');
				}
				cleanUrl();
			},
		});
	}

	function cleanUrl() {
		if (!window.history.replaceState) return;
		var url = window.location.pathname + window.location.search;
		url = url.replace(/([?&])autoalt_action=[^&]*&?/g, '$1');
		url = url.replace(/[?&]$/, '');
		window.history.replaceState({}, document.title, url);
	}

	fetchBatch();
})(jQuery);
