/**
 * Starter Content Notice JavaScript
 */
(function($) {
	'use strict';

	$(document).ready(function() {
		// Function to dismiss the notice permanently
		function dismissNotice() {
			$.ajax({
				url: inspiroStarterContent.ajaxurl,
				type: 'POST',
				data: {
					action: 'inspiro_dismiss_starter_content_notice',
					nonce: inspiroStarterContent.dismissNonce
				}
			});
		}

		// Handle delete button click
		$('#inspiro-delete-starter-content').on('click', function(e) {
			e.preventDefault();

			var $button = $(this);
			var $spinner = $button.siblings('.spinner');
			var $result = $('.inspiro-delete-result');
			var $notice = $('.inspiro-starter-content-notice');

			// Confirm with user
			if (!confirm('Are you sure you want to delete all starter content? This action cannot be undone.')) {
				return;
			}

			// Disable button and show spinner
			$button.prop('disabled', true);
			$spinner.addClass('is-active');
			$result.html('').removeClass('error success');

			// Send AJAX request
			$.ajax({
				url: inspiroStarterContent.ajaxurl,
				type: 'POST',
				data: {
					action: 'inspiro_delete_starter_content',
					nonce: inspiroStarterContent.nonce
				},
				success: function(response) {
					$spinner.removeClass('is-active');

					if (response.success) {
						$result.html('<span class="success" style="color: #46b450; font-weight: 600;">✓ ' + response.message + '</span>');

						// Dismiss the notice permanently
						dismissNotice();

						// Hide the notice after 2 seconds
						setTimeout(function() {
							$notice.fadeOut(400, function() {
								$(this).remove();
							});
						}, 2000);
					} else {
						$result.html('<span class="error" style="color: #dc3232; font-weight: 600;">✗ ' + response.message + '</span>');
						$button.prop('disabled', false);
					}
				},
				error: function() {
					$spinner.removeClass('is-active');
					$result.html('<span class="error" style="color: #dc3232; font-weight: 600;">✗ ' + inspiroStarterContent.error + '</span>');
					$button.prop('disabled', false);
				}
			});
		});

		// Handle dismiss button click
		$('.inspiro-dismiss-notice').on('click', function(e) {
			e.preventDefault();
			var $notice = $('.inspiro-starter-content-notice');

			// Dismiss the notice permanently
			dismissNotice();

			// Hide the notice
			$notice.fadeOut(400, function() {
				$(this).remove();
			});
		});

		// Handle WordPress's built-in dismiss button (X button)
		$(document).on('click', '.inspiro-starter-content-notice .notice-dismiss', function(e) {
			// Dismiss the notice permanently
			dismissNotice();
		});
	});

})(jQuery);