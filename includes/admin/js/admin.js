jQuery(document).ready(function($) {
    function adminData() {
        return window.GlueLinkAdmin || {};
    }

    function glueString(key, fallback) {
        var strings = adminData().strings || {};
        return strings[key] || fallback;
    }

    function setStatus($container, ok) {
        $container.find('.glue-link-webhook-copy-status')
            .text(glueString(ok ? 'copy_success' : 'copy_failed', ok ? 'Webhook URL copied.' : 'Copy failed. Select and copy manually.'))
            .css('color', ok ? '#2271b1' : '#a60000');
    }

    function selectInputText($input) {
        if (!$input.length || !$input[0]) {
            return;
        }
        $input.trigger('focus').trigger('select');
        if (typeof $input[0].setSelectionRange === 'function') {
            $input[0].setSelectionRange(0, String($input.val() || '').length);
        }
    }

    function webhookUrlByType(type, $container) {
        var urls = adminData().webhook_urls || {};
        var restUrl = urls.rest || $container.data('rest-url') || '';
        var queryUrl = urls.query || $container.data('query-url') || '';
        return type === 'query' ? queryUrl : restUrl;
    }

    function updateWebhookUrlPreview() {
        var $select = $('select[name="glue_link_settings[webhook_endpoint_type]"]');
        var $container = $('.webhook-url-container');
        if (!$select.length || !$container.length) {
            return;
        }
        var value = webhookUrlByType($select.val(), $container);
        $container.find('.glue-link-webhook-url-input').val(value);
        $container.find('.glue-link-webhook-url-code').text(value);
    }

    function fallbackExecCopy(value, $input) {
        if (!value) {
            return false;
        }
        if ($input.length) {
            selectInputText($input);
        }

        var copied = false;
        var copyHandler = function(event) {
            event.preventDefault();
            if (event.clipboardData) {
                event.clipboardData.setData('text/plain', value);
            }
        };

        document.addEventListener('copy', copyHandler);
        try {
            copied = !!document.execCommand('copy');
        } catch (e) {
            copied = false;
        }
        document.removeEventListener('copy', copyHandler);

        return copied;
    }

    function copyWebhookUrl($container) {
        var $input = $container.find('.glue-link-webhook-url-input');
        var value = String($input.val() || $container.find('.glue-link-webhook-url-code').text() || '').trim();
        if (!value) {
            return;
        }

        if ($input.length) {
            selectInputText($input);
        }

        var onDone = function(ok) {
            if ($input.length) {
                selectInputText($input);
            }
            setStatus($container, ok);
        };

        if (window.isSecureContext && navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
            navigator.clipboard.writeText(value).then(function() {
                onDone(true);
            }).catch(function() {
                onDone(fallbackExecCopy(value, $input));
            });
            return;
        }

        onDone(fallbackExecCopy(value, $input));
    }

    $(document).on('change', 'select[name="glue_link_settings[webhook_endpoint_type]"]', function() {
        updateWebhookUrlPreview();
    });

    $(document).on('click', '.glue-link-webhook-copy', function(e) {
        e.preventDefault();
        copyWebhookUrl($(this).closest('.webhook-url-container'));
    });

    $(document).on('click', '.glue-link-webhook-url-code', function(e) {
        e.preventDefault();
        copyWebhookUrl($(this).closest('.webhook-url-container'));
    });

    $(document).on('click focus', '.glue-link-webhook-url-input', function() {
        var $input = $(this);
        selectInputText($input);
        copyWebhookUrl($input.closest('.webhook-url-container'));
    });

    updateWebhookUrlPreview();

    $('.glue_link_cache_clear').on('click', function(e) {
        e.preventDefault();
        var $button = $(this);
        var originalText = $button.text();
        var glueAdmin = adminData();

        $button.prop('disabled', true).text('Clearing...');

        var data = {
            'action': 'glue_link_refresh_lists',
            'nonce': glueAdmin.nonce || ''
        };

        $.post(glueAdmin.ajax_url || ajaxurl, data, function(response) {
            if(response.success) {
                alert((glueAdmin.strings && glueAdmin.strings['cache_cleared']) || 'Cache cleared successfully!');
            } else {
                var message = (response && response.data && response.data.message) ? response.data.message : 'Unknown error';
                alert(((glueAdmin.strings && glueAdmin.strings['cache_error']) || 'Error clearing cache: ') + ' ' + message);
            }
        }).always(function() {
            // Re-enable button and restore text
            $button.prop('disabled', false).text(originalText);
        });
    });
});
