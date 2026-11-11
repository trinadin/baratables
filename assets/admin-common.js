jQuery(function($) {
	var adminCore = window.BaraTablesAdminCore;
	var isActivationEvent = adminCore.isActivationEvent;
	var announce = adminCore.announce;

	function showCopiedState($element) {
		if (!$element || !$element.length) {
			return;
		}
		$element.addClass('is-copied');
		announce($element.data('copied-label') || '');
		setTimeout(function() { $element.removeClass('is-copied'); }, 1500);
	}

	function copyShortcode(text, $element) {
		if (!text) {
			return;
		}
		// A rejection here means the clipboard refused (non-secure origin, dismissed
		// permission); try the shared textarea fallback, and only report if that fails too.
		adminCore.copyText(text).then(function() {
			showCopiedState($element);
		}, function() {
			adminCore.copyTextFallback(text).then(function() {
				showCopiedState($element);
			}, function(error) {
				if (window.console && console.warn) { console.warn('Copy failed', error); }
			});
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
		var label = hide ? ($toggle.data('show-label') || '') : ($toggle.data('hide-label') || '');
		// The pressed state travels with the label: "true" means the help text is
		// currently hidden, and the label stays the ACTION that offers to change it.
		// Updating it here, in the same handler that hides the help text, keeps the
		// state and the page in step -- a static attribute would go stale after the
		// first click.
		$toggle.attr({'aria-label': label, title: label, 'aria-pressed': hide ? 'true' : 'false'});
		$.post(window.ajaxurl, {action: 'btbl_toggle_help', hide: hide ? '1' : '0', _wpnonce: $toggle.data('nonce')});
	});
});
