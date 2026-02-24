/**
 * Kit connection validation script.
 *
 * @package WebberZone\FreemKit
 */

jQuery(document).ready(function ($) {
	// Handle connection test.
	$('.test-kit-connection').on('click', function (e) {
		e.preventDefault();
		var $button = $(this);
		var $status = $button.siblings('.kit-connection-status');

		$button.prop('disabled', true);
		$status.html('<span class="spinner is-active" style="float: none; margin: 0;"></span>');

		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: {
				action: 'freemkit_test_kit_connection',
				nonce: FreemKitAdmin.nonce
			},
			success: function (response) {
				if (response.success) {
					$status.html('<span style="color: green;">' + response.data.message + '</span>');
				} else {
					$status.html('<span style="color: #a60000;">' + response.data.message + '</span>');
				}
			},
			error: function () {
				$status.html('<span style="color: #a60000;">' + FreemKitAdmin.strings.api_validation_error + '</span>');
			},
			complete: function () {
				$button.prop('disabled', false);
			}
		});
	});
});
