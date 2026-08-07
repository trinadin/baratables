jQuery(function($) {
	var isActivationEvent = window.BaraTablesAdminCore.isActivationEvent;

	function showCopiedState($element) {
		if (!$element || !$element.length) {
			return;
		}
		$element.addClass('is-copied');
		var label = $element.data('copied-label') || 'Copied';
		var $live = $('#btbl-copy-live');
		if (!$live.length) {
			$live = $('<span id="btbl-copy-live" class="screen-reader-text" aria-live="polite"></span>').appendTo('body');
		}
		$live.text('');
		setTimeout(function() { $live.text(label); }, 50);
		setTimeout(function() { $element.removeClass('is-copied'); }, 1500);
	}

	function fallbackCopy(text, $element) {
		var $temporary = $('<textarea>').css({position: 'absolute', left: '-9999px', top: '0'}).val(text).appendTo('body');
		$temporary[0].select();
		try {
			document.execCommand('copy');
			showCopiedState($element);
		} catch (error) {
			if (window.console && console.warn) { console.warn('Copy failed', error); }
		}
		$temporary.remove();
	}

	function copyShortcode(text, $element) {
		if (!text) {
			return;
		}
		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(text).then(function() {
				showCopiedState($element);
			}).catch(function() {
				fallbackCopy(text, $element);
			});
		} else {
			fallbackCopy(text, $element);
		}
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
