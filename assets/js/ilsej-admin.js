(function ($) {
	$(function () {
		const $btn = $('#ilsej-start-indexing');
		if (!$btn.length) { return; }

		const progressWrap = $('#ilsej-progress');
		const bar = $('#ilsej-progress-bar');
		const text = $('#ilsej-progress-text');
		const $pauseBtn = $('#ilsej-pause-indexing');
		const $stopBtn = $('#ilsej-stop-indexing');
		let isPaused = false;

		function processBatch() {
			$.post(ilsejAdmin.ajaxUrl, {
				action: 'ilsej_process_batch',
				nonce: ilsejAdmin.nonce
			}, function (resp) {
				if (!resp || resp.success === false) {
					alert('Indexing Error: Please check the console for technical details.');
					console.error(resp);
					$btn.prop('disabled', false);
					$pauseBtn.hide();
					$stopBtn.hide();
					return;
				}
				const data = resp;
				if (data.error) {
					alert('Processing Error: ' + data.error);
				}
				bar.css('width', data.progress + '%');
				text.text(data.processed + '/' + data.total);

				// If user clicked pause while batch was processing
				if (isPaused) {
					return;
				}

				if (!data.finished) {
					const delay = data.retry_after ? data.retry_after * 1000 : 1000;
					setTimeout(processBatch, delay);
				} else {
					text.text('Indexing Complete');
					$btn.prop('disabled', false);
					$pauseBtn.hide();
					$stopBtn.hide();
					refreshIndexStats();
				}
			}, 'json').fail(function () {
				text.text('Network error — please try again.');
				$btn.prop('disabled', false);
				$pauseBtn.hide();
				$stopBtn.hide();
			});
		}

		function refreshIndexStats() {
			$.post(ilsejAdmin.ajaxUrl, {
				action: 'ilsej_index_stats',
				nonce: ilsejAdmin.nonce
			}, function (resp) {
				if (resp && resp.success && resp.data) {
					const d = resp.data;
					const $status = $('#ilsej-status-text');
					const $wrap = $('#ilsej-index-status');
					const statusText = d.indexed + ' of ' + d.total + ' posts indexed';

					if ($status.length) {
						$status.text(statusText);
					} else if ($wrap.length === 0) {
						// Create status element if it doesn't exist yet.
						const color = d.indexed >= d.total ? '#46b450' : '#ffb900';
						$btn.closest('.wrap').find('p').first().before(
							'<div id="ilsej-index-status" style="margin-bottom:15px;padding:10px 15px;border-left:4px solid ' + color + ';background:#fff;">' +
                            '<strong>Index Status:</strong> <span id="ilsej-status-text">' + statusText + '</span></div>'
						);
					}

					// Update border color and button text.
					if (d.indexed >= d.total) {
						$('#ilsej-index-status').css('border-left-color', '#46b450');
						$btn.text('Re-index All');
					} else {
						$('#ilsej-index-status').css('border-left-color', '#ffb900');
						$btn.text('Start Indexing');
					}
				}
			}, 'json');
		}

		$btn.on('click', function () {
			$btn.prop('disabled', true);
			progressWrap.show();
			bar.css('width', '0%');
			text.text('0');

			isPaused = false;
			$pauseBtn.text('Pause').show();
			$stopBtn.show();

			$.post(ilsejAdmin.ajaxUrl, {
				action: 'ilsej_start_indexing',
				nonce: ilsejAdmin.nonce
			}, function () {
				processBatch();
			}).fail(function () {
				text.text('Failed to start indexing — please try again.');
				$btn.prop('disabled', false);
				$pauseBtn.hide();
				$stopBtn.hide();
			});
		});

		$pauseBtn.on('click', function () {
			if (isPaused) {
				// Resume
				isPaused = false;
				$pauseBtn.text('Pause');
				processBatch();
			} else {
				// Pause
				isPaused = true;
				$pauseBtn.text('Resume');
			}
		});

		$stopBtn.on('click', function () {
			if (!confirm('Are you sure you want to stop indexing and clear the current queue?')) { return; }
			$.post(ilsejAdmin.ajaxUrl, {
				action: 'ilsej_stop_indexing',
				nonce: ilsejAdmin.nonce
			}, function () {
				bar.css('width', '0%');
				text.text('Indexing Stopped');
				$btn.prop('disabled', false);
				$pauseBtn.hide();
				$stopBtn.hide();
			}).fail(function () {
				text.text('Failed to stop — please reload the page.');
			});
		});

	});

	// MASK TOGGLE LOGIC (Global for all admin fields with this class)
	$(function () {
		$('.ilsej-toggle-mask').on('click', function (e) {
			e.preventDefault();
			const $btn = $(this);
			const $wrapper = $btn.closest('.ilsej-mask-wrapper');
			const $input = $wrapper.find('input, textarea');
			const $icon = $btn.find('.dashicons');

			if ($input.is('input')) {
				// Toggle input type
				const newType = $input.attr('type') === 'password' ? 'text' : 'password';
				$input.attr('type', newType);
			} else {
				// Toggle textarea blur
				$input.toggleClass('ilsej-blur');
				// Ensure focus doesn't reveal it if it was blurred
				if ($input.hasClass('ilsej-blur')) {
					$input.trigger('blur');
				}
			}

			// Sync Icon
			if ($icon.hasClass('dashicons-visibility')) {
				$icon.removeClass('dashicons-visibility').addClass('dashicons-hidden');
			} else {
				$icon.removeClass('dashicons-hidden').addClass('dashicons-visibility');
			}
		});
	});

	// JSON FILE LOADER - Reads file client-side without uploading to server
	$(function () {
		const $loadBtn = $('#ilsej-load-json-file');
		const $fileInput = $('#ilsej-json-file-input');
		const $textarea = $('#ilsej-gcp-json-key');

		if (!$loadBtn.length || !$fileInput.length || !$textarea.length) {
			return;
		}

		// Click the hidden file input when button is clicked
		$loadBtn.on('click', function () {
			$fileInput.trigger('click');
		});

		// Read file contents when a file is selected
		$fileInput.on('change', function (e) {
			const file = e.target.files[0];
			if (!file) return;

			// Validate file type
			if (!file.name.endsWith('.json') && file.type !== 'application/json') {
				alert('Please select a valid JSON file.');
				return;
			}

			// Read file using FileReader API (client-side, no upload)
			const reader = new FileReader();
			reader.onload = function (event) {
				const content = event.target.result;

				// Basic JSON validation
				try {
					JSON.parse(content);
				} catch (err) {
					alert('Invalid JSON file: ' + err.message);
					return;
				}

				// Populate the textarea
				$textarea.val(content);

				// Remove blur class so user can see content was loaded
				$textarea.removeClass('ilsej-blur');

				// Update visibility icon if visible
				const $icon = $textarea.closest('.ilsej-mask-wrapper').find('.dashicons');
				if ($icon.hasClass('dashicons-visibility')) {
					$icon.removeClass('dashicons-visibility').addClass('dashicons-hidden');
				}
			};

			reader.onerror = function () {
				alert('Error reading file. Please try again.');
			};

			reader.readAsText(file);

			// Reset file input so same file can be re-selected
			$fileInput.val('');
		});
	});
})(jQuery);