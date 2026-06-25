(function ($) {
	var state = {
		running: false,
		paused: false,
		cancelled: false,
		mode: 'missing',
		batch: 5,
		offset: 0,
		total: 0,
		processed: 0,
		success: 0,
		errors: 0,
		skipped: 0,
	};

	function logEntry(type, message) {
		var $log = $('#aat-log');
		var $content = $log.find('.aat-log-content');
		$log.show();
		$content.append(
			$('<div>').addClass('aat-log-entry ' + type).text(message)
		);
		$log.scrollTop($log[0].scrollHeight);
	}

	function updateProgress() {
		var $progress = $('#aat-progress');
		var $bar = $progress.find('.aat-progress-bar');
		var $text = $progress.find('.aat-progress-text');
		$progress.show();

		var pct = state.total > 0 ? Math.min((state.offset / state.total) * 100, 100) : 0;
		$bar.css('width', pct + '%');

		var doneCount = state.success + state.errors + state.skipped;
		$text.text(
			'Processed ' + doneCount + ' / ' + state.total +
			' (' + state.success + ' ok, ' + state.errors + ' errors, ' + state.skipped + ' skipped)'
		);

		if (state.cancelled) {
			$text.text($text.text() + ' — Cancelled');
		} else if (doneCount >= state.total) {
			$text.text($text.text() + ' — Complete!');
		}
	}

	function processNextBatch() {
		if (!state.running || state.paused || state.cancelled) {
			return;
		}

		if (state.offset >= state.total) {
			state.running = false;
			updateProgress();
			setButtonsIdle();
			logEntry('info', 'Processing complete.');
			return;
		}

		$.ajax({
			url: aatData.ajaxUrl,
			method: 'POST',
			data: {
				action: 'aat_process_batch',
				nonce: aatData.nonce,
				mode: state.mode,
				batch: state.batch,
				offset: state.offset,
			},
			success: function (response) {
				if (!response.success) {
					logEntry('error', 'AJAX error: ' + (response.data.message || 'Unknown error'));
					state.running = false;
					setButtonsIdle();
					return;
				}

				var data = response.data;

				$.each(data.batch.results, function (i, r) {
					var title = r.title || '#' + r.id;
					if (r.status === 'success') {
						state.success++;
						logEntry('success', '#' + r.id + ' ' + title + ' → "' + (r.generated || '(decorative)') + '"');
					} else if (r.status === 'error') {
						state.errors++;
						logEntry('error', '#' + r.id + ' ' + title + ' ✗ ' + (r.error || 'Unknown error'));
					} else if (r.status === 'skipped') {
						state.skipped++;
						logEntry('skipped', '#' + r.id + ' ' + title + ' — ' + (r.reason || 'Skipped'));
					}
				});

				state.offset = data.offset;

				if (state.total === 0 && data.total > 0) {
					state.total = data.total;
				} else if (data.total > 0) {
					state.total = data.total;
				}

				updateProgress();

				if (data.done || state.offset >= state.total) {
					state.running = false;
					setButtonsIdle();
					logEntry('info', 'Processing complete.');
					return;
				}

				if (!state.paused && !state.cancelled) {
					setTimeout(processNextBatch, 500);
				}
			},
			error: function (jqXHR) {
				logEntry('error', 'Request failed: ' + jqXHR.statusText);
				state.running = false;
				setButtonsIdle();
			},
		});
	}

	function setButtonsRunning() {
		$('#aat-start').hide();
		$('#aat-pause').show().prop('disabled', false);
		$('#aat-resume').hide();
		$('#aat-cancel').show().prop('disabled', false);
	}

	function setButtonsPaused() {
		$('#aat-start').hide();
		$('#aat-pause').hide();
		$('#aat-resume').show().prop('disabled', false);
		$('#aat-cancel').show().prop('disabled', false);
	}

	function setButtonsIdle() {
		$('#aat-start').show().prop('disabled', false);
		$('#aat-pause').hide();
		$('#aat-resume').hide();
		$('#aat-cancel').hide();
	}

	$('#aat-start').on('click', function () {
		if (!aatData.aiAvailable) {
			alert('AI Client is not available. Configure an AI provider under Settings > Connectors.');
			return;
		}

		state.running = true;
		state.paused = false;
		state.cancelled = false;
		state.mode = $('#aat-mode').val();
		state.batch = parseInt($('#aat-batch').val(), 10);
		state.offset = 0;
		state.processed = 0;
		state.success = 0;
		state.errors = 0;
		state.skipped = 0;

		$('#aat-log .aat-log-content').empty();
		$('#aat-progress').hide();

		setButtonsRunning();
		logEntry('info', 'Starting processing (mode: ' + state.mode + ', batch: ' + state.batch + ')...');
		logEntry('info', 'Counting total images...');

		processNextBatch();
	});

	$('#aat-pause').on('click', function () {
		state.paused = true;
		setButtonsPaused();
		logEntry('info', 'Paused.');
	});

	$('#aat-resume').on('click', function () {
		state.paused = false;
		setButtonsRunning();
		logEntry('info', 'Resuming...');
		processNextBatch();
	});

	$('#aat-cancel').on('click', function () {
		state.cancelled = true;
		state.paused = false;
		state.running = false;
		setButtonsIdle();
		logEntry('info', 'Cancelled.');
	});
})(jQuery);
