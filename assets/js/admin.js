/**
 * MiniLoad Admin JavaScript
 *
 * @package MiniLoad
 * @since 1.0.0
 */

(function($) {
	'use strict';

	// Wait for DOM ready
	$(document).ready(function() {

		// Clear cache button
		$('#miniload-clear-cache').on('click', function(e) {
			e.preventDefault();
			miniloadAjaxAction('clear_cache', this);
		});

		// Rebuild indexes button
		$('#miniload-rebuild-indexes').on('click', function(e) {
			e.preventDefault();
			if (confirm(miniload.strings.confirm_rebuild)) {
				miniloadAjaxAction('rebuild_indexes', this);
			}
		});

		// Create missing indexes
		$('#miniload-create-indexes').on('click', function(e) {
			e.preventDefault();
			miniloadAjaxAction('rebuild_indexes', this);
		});

		// Run cleanup
		$('#miniload-run-cleanup').on('click', function(e) {
			e.preventDefault();
			miniloadAjaxAction('run_cleanup', this);
		});

		/**
		 * Generic AJAX action handler
		 */
		function miniloadAjaxAction(action, button) {
			var $button = $(button);
			var originalText = $button.text();

			// Disable button and show processing
			$button.prop('disabled', true).text(miniload.strings.processing);

			// Send AJAX request
			$.ajax({
				url: miniload.ajax_url,
				type: 'POST',
				data: {
					action: 'miniload_ajax',
					miniload_action: action,
					nonce: miniload.nonce
				},
				success: function(response) {
					if (response.success) {
						// Show success message
						showNotice(response.data.message || miniload.strings.success, 'success');

						// Reload page if needed
						if (action === 'rebuild_indexes') {
							setTimeout(function() {
								location.reload();
							}, 1500);
						}
					} else {
						// Show error message
						showNotice(response.data || miniload.strings.error, 'error');
					}
				},
				error: function() {
					showNotice(miniload.strings.error, 'error');
				},
				complete: function() {
					// Re-enable button
					$button.prop('disabled', false).text(originalText);
				}
			});
		}

		/**
		 * Show admin notice
		 */
		function showNotice(message, type) {
			var noticeClass = type === 'success' ? 'notice-success' : 'notice-error';
			var $notice = $('<div class="notice ' + noticeClass + ' is-dismissible"><p>' + message + '</p></div>');

			// Add notice after page title
			$('.wrap > h1').after($notice);

			// Auto dismiss after 5 seconds
			setTimeout(function() {
				$notice.fadeOut(function() {
					$(this).remove();
				});
			}, 5000);
		}

	});

})(jQuery);