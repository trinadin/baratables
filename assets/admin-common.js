jQuery(function($) {
	var adminCore = window.BaraTablesAdminCore;
	var isActivationEvent = adminCore.isActivationEvent;
	var announce = adminCore.announce;

	function showCopiedState($element) {
		if (!$element || !$element.length) {
			return;
		}
		$element.addClass('is-copied');
		announce($element.data('copied-label') || 'Copied');
		setTimeout(function() { $element.removeClass('is-copied'); }, 1500);
	}

	function copyShortcode(text, $element) {
		if (!text) {
			return;
		}
		adminCore.copyText(text).then(function() {
			showCopiedState($element);
		}).catch(function(error) {
			if (window.console && console.warn) { console.warn('Copy failed', error); }
		});
	}

	$(document).on('click keydown', '.btbl-shortcode', function(event) {
		if (!isActivationEvent(event)) {
			return;
		}
		event.preventDefault();
		var $element = $(this);
		copyShortcode($element.data('shortcode') || $element.text(), $element);
	});

	$(document).on('click', '#btbl-help-toggle', function(event) {
		event.preventDefault();
		var $toggle = $(this);
		var hide = !document.body.classList.contains('btbl-help-hidden');
		document.body.classList.toggle('btbl-help-hidden', hide);
		var label = hide ? ($toggle.data('show-label') || 'Show help text') : ($toggle.data('hide-label') || 'Hide help text');
		$toggle.attr({'aria-label': label, title: label});
		$.post(window.ajaxurl, {action: 'btbl_toggle_help', hide: hide ? '1' : '0', _wpnonce: $toggle.data('nonce')});
	});
});
