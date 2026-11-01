(function(window, $) {
	function formAuth($control) {
		var $form = $control && $control.length ? $control.closest('form') : $();
		if (!$form.length) { $form = $('#post'); }
		return {
			nonce: $form.find('input[name="_baratables_nonce"]').val() || '',
			postId: $('#post_ID').val() || $form.find('input[name="post_ID"]').val() || 0
		};
	}

	var activeRequests = {};
	function startRequest(key, data) {
		var previous = activeRequests[key];
		var state = {xhr: null};
		activeRequests[key] = state;
		if (previous && previous.xhr && previous.xhr.readyState !== 4) {
			previous.xhr.abort();
		}
		state.xhr = $.post(window.ajaxurl, data);
		return {
			xhr: state.xhr,
			isCurrent: function() { return activeRequests[key] === state; }
		};
	}

	function requestFragment(options) {
		var data = options.data;
		function unavailable() {
			if (options.unavailable) { options.unavailable(); }
			else if (options.fallback) { options.fallback(); }
			if (options.always) { options.always(true); }
		}
		if (options.auth !== false) {
			var auth = formAuth(options.control);
			if (!auth.nonce || !window.ajaxurl) {
				unavailable();
				return null;
			}
			data = $.extend({}, data, {
				post_id: auth.postId,
				_baratables_nonce: auth.nonce
			});
		} else if (!window.ajaxurl) {
			unavailable();
			return null;
		}

		var request = startRequest(options.key, data);
		request.xhr.done(function(response) {
			if (!request.isCurrent()) { return; }
			var valid = options.validate ? options.validate(response) : !!(response && response.success);
			if (valid) {
				if (options.success) { options.success(response.data, response); }
			} else if (options.fallback) {
				options.fallback();
			}
		}).fail(function() {
			if (request.isCurrent() && options.fallback) { options.fallback(); }
		}).always(function() {
			if (options.always) { options.always(request.isCurrent()); }
		});
		return request;
	}

	function isActivationEvent(event) {
		return event.type === 'click' || (event.type === 'keydown' && (event.key === 'Enter' || event.key === ' '));
	}

	function isDefaultChecked($checkbox) {
		if (!$checkbox || !$checkbox.length) { return false; }
		var raw = $checkbox.data('default');
		if (raw === undefined) { raw = $checkbox.attr('data-default'); }
		if (typeof raw === 'string') {
			raw = raw.toLowerCase();
			return raw === '1' || raw === 'true';
		}
		return !!raw;
	}

	function applyDefaultIfUntouched($checkbox) {
		if (!$checkbox || !$checkbox.length || $checkbox.data('touched')) { return; }
		if (isDefaultChecked($checkbox)) { $checkbox.prop('checked', true); }
	}

	function redirectWithQuery(values, removedKeys) {
		var url = new URL(window.location.href);
		(removedKeys || []).forEach(function(key) { url.searchParams.delete(key); });
		Object.keys(values || {}).forEach(function(key) { url.searchParams.set(key, values[key]); });
		window.location.href = url.toString();
	}

	// Mirrors BaraTables_Service::normalize_hex_color(): a "#" followed by exactly three
	// or six hex digits, returned verbatim. Every other value -- a bare "fff", a doubled
	// "##fff", a seven-digit value -- is rejected, exactly as the server rejects it, so
	// the admin preview can never paint a colour that a save would discard.
	function normalizeHex(raw) {
		var value = String(raw === null || raw === undefined ? '' : raw);
		return /^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/.test(value) ? value : '';
	}

	// Mirrors BaraTables_Service::sanitize_custom_css(): null bytes go, a maximal run of
	// "<" that is immediately followed by "/" or "?" goes with it -- those are the only two
	// characters that can close the surrounding <style> element early or open a PHP
	// context -- and the result is capped at the server's byte budget on a character
	// boundary.
	//
	// The transform only ever removes "<", so it cannot create a new "<" before a "/" or
	// "?", which makes it a fixpoint: sanitizing an already-sanitized value changes
	// nothing, so the published page carries exactly the CSS the editor showed.
	function sanitizeCustomCss(raw, maxBytes) {
		var css = String(raw === null || raw === undefined ? '' : raw).replace(/\0/g, '');
		css = css.replace(/<+([/?])/g, '$1');
		if (typeof maxBytes !== 'number' || maxBytes <= 0) { return css; }
		return truncateUtf8(css, maxBytes);
	}

	// Caps a string at a byte budget without cutting a UTF-8 character in half. The budget
	// is in BYTES on both sides of the wire, so the preview and the published page carry
	// the same CSS; the cut lands on a character boundary, so neither side emits a broken
	// character.
	function truncateUtf8(value, maxBytes) {
		var bytes = new TextEncoder().encode(value);
		if (bytes.length <= maxBytes) { return value; }
		var end = maxBytes;
		while (end > 0 && (bytes[end] & 0xc0) === 0x80) { end--; }
		return new TextDecoder().decode(bytes.subarray(0, end));
	}

	window.BaraTablesAdminCore = {
		formAuth: formAuth,
		normalizeHex: normalizeHex,
		sanitizeCustomCss: sanitizeCustomCss,
		truncateUtf8: truncateUtf8,
		requestFragment: requestFragment,
		isActivationEvent: isActivationEvent,
		applyDefaultIfUntouched: applyDefaultIfUntouched,
		redirectWithQuery: redirectWithQuery
	};
})(window, jQuery);
