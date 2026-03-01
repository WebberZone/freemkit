/**
 * Connection validation script for Kit and Freemius.
 *
 * @package WebberZone\FreemKit
 */

jQuery(document).ready(function ($) {
	function adminData() {
		return window.FreemKitAdmin || {};
	}

	function adminString(key, fallback) {
		var strings = adminData().strings || {};
		return strings[key] || fallback;
	}

	function setStatus($node, ok, message) {
		$node
			.text(message)
			.attr('title', message)
			.css('color', ok ? '#008a20' : '#a60000');
	}

	function testKitConnection(e) {
		e.preventDefault();
		var $button = $(this);
		var $status = $button.siblings('.kit-connection-status');

		$button.prop('disabled', true);
		$status.html('<span class="spinner is-active" style="float:none;margin:0;"></span>');

		$.ajax({
			url: adminData().ajax_url || ajaxurl,
			type: 'POST',
			data: {
				action: 'freemkit_test_kit_connection',
				nonce: adminData().nonce || ''
			},
			success: function (response) {
				if (response && response.success) {
					setStatus($status, true, response.data.message || adminString('kit_validation_success', 'Connection successful.'));
				} else {
					setStatus($status, false, (response && response.data && response.data.message) || adminString('api_validation_error', 'Error validating API credentials.'));
				}
			},
			error: function () {
				setStatus($status, false, adminString('api_validation_error', 'Error validating API credentials.'));
			},
			complete: function () {
				$button.prop('disabled', false);
			}
		});
	}

	function buildFreemiusControls($row) {
		var $content = $row.find('.repeater-item-content').first();
		if (!$content.length || $content.find('.freemkit-freemius-validate-wrap').length) {
			return;
		}

		var html = [
			'<div class="freemkit-freemius-validate-wrap" style="display:flex;align-items:flex-start;gap:8px;margin:0 0 12px 0;padding:8px 10px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;">',
			'<button type="button" class="button button-secondary button-small freemkit-test-freemius-keys">', adminString('validate_freemius_keys', 'Validate Keys'), '</button>',
			'<span class="freemkit-freemius-status" style="font-weight:500;font-size:12px;line-height:1.4;white-space:normal;word-break:break-word;"></span>',
			'</div>'
		].join('');

		$content.prepend(html);
	}

	function initFreemiusValidationControls(context) {
		var $scope = context ? $(context) : $(document);

		if (
			$scope.is('.wz-repeater-item') &&
			$scope.find(':input[name$="[fields][id]"]').length &&
			$scope.find(':input[name$="[fields][public_key]"]').length &&
			$scope.find(':input[name$="[fields][secret_key]"]').length
		) {
			buildFreemiusControls($scope);
			return;
		}

		$scope.find('.wz-repeater-item').each(function () {
			var $row = $(this);
			if (
				$row.find(':input[name$="[fields][id]"]').length &&
				$row.find(':input[name$="[fields][public_key]"]').length &&
				$row.find(':input[name$="[fields][secret_key]"]').length
			) {
				buildFreemiusControls($row);
			}
		});
	}

	function readRowData($button) {
		var $row = $button.closest('.wz-repeater-item');
		return {
			row: $row,
			plugin_id: String($row.find(':input[name$="[fields][id]"]').val() || '').trim(),
			public_key: String($row.find(':input[name$="[fields][public_key]"]').val() || '').trim(),
			secret_key: String($row.find(':input[name$="[fields][secret_key]"]').val() || '').trim(),
			row_id: String($row.find(':input[name$="[row_id]"]').val() || '').trim()
		};
	}

	function testFreemiusKeys(e) {
		e.preventDefault();
		var $button = $(this);
		var data = readRowData($button);
		var $status = data.row.find('.freemkit-freemius-status');

		if (!data.plugin_id || !data.public_key || !data.secret_key) {
			setStatus($status, false, adminString('freemius_missing_fields', 'Product ID, public key, and secret key are required.'));
			return;
		}

		$button.prop('disabled', true);
		$status.html('<span class="spinner is-active" style="float:none;margin:0;"></span>');

		$.ajax({
			url: adminData().ajax_url || ajaxurl,
			type: 'POST',
			data: {
				action: 'freemkit_validate_freemius_keys',
				nonce: adminData().nonce || '',
				plugin_id: data.plugin_id,
				public_key: data.public_key,
				secret_key: data.secret_key,
				row_id: data.row_id
			},
			success: function (response) {
				if (response && response.success) {
					setStatus($status, true, response.data.message || adminString('freemius_validation_success', 'Freemius credentials are valid.'));
				} else {
					setStatus($status, false, (response && response.data && response.data.message) || adminString('freemius_validation_error', 'Unable to validate Freemius credentials.'));
				}
			},
			error: function () {
				setStatus($status, false, adminString('freemius_validation_error', 'Unable to validate Freemius credentials.'));
			},
			complete: function () {
				$button.prop('disabled', false);
			}
		});
	}

	$(document).on('click', '.test-kit-connection', testKitConnection);
	$(document).on('click', '.freemkit-test-freemius-keys', testFreemiusKeys);
	$(document).on('wz:repeater-item-added', function (event) {
		var container = event && event.originalEvent && event.originalEvent.detail ? event.originalEvent.detail.container : null;
		initFreemiusValidationControls(container || document);
	});

	initFreemiusValidationControls(document);
});
