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

	// Mirrors BaraTables_Service::sanitize_chart_font_size(): a chart's font size is a
	// number of PIXELS, because ECharts' fontSize is not a CSS length -- "2em" read as a
	// number is 2, which renders a 2px label. Only pixels are accepted, and a bare
	// number is normalized to the px form so the preview and the saved value agree.
	function normalizeChartFontSize(raw) {
		var value = String(raw === null || raw === undefined ? '' : raw).trim();
		var match = /^(\d{1,4})(?:px)?$/.exec(value);
		return match ? match[1] + 'px' : '';
	}

	// The design presets (2.1.0). One registry, two families: the table tokens and the
	// chart's own colours. They live here rather than in one editor's script so the table
	// editor, the chart editor and the site Design screen offer the same names for the
	// same looks, and a preset cannot drift between two surfaces.
	var DESIGN_PRESETS = {
		table: {
			minimal: {
				headerBgColor: '#fafbfc',
				headerTextColor: '#24292f',
				headerWeight: '600',
				stripeColor: '',
				hoverColor: '#f6f7f8',
				borderColor: '#e5e7eb'
			},
			bold: {
				headerBgColor: '#1f2937',
				headerTextColor: '#f9fafb',
				headerWeight: '700',
				stripeColor: '#f3f4f6',
				hoverColor: '#e5e7eb',
				borderColor: '#d1d5db'
			},
			midnight: {
				// Dark chrome on a light page: the body background is the theme's, so body text
				// keeps the theme color and only the striped/hover bands and chrome go dark. A
				// light body text here would strand unstriped rows on the page's white canvas.
				headerBgColor: '#111827',
				headerTextColor: '#f9fafb',
				headerWeight: '600',
				stripeColor: '#e5e7eb',
				hoverColor: '#d1d5db',
				borderColor: '#9ca3af',
				footerBgColor: '#111827',
				footerTextColor: '#f9fafb'
			},
			paper: {
				headerBgColor: '#f5f0e8',
				headerTextColor: '#3f3a34',
				headerWeight: '600',
				stripeColor: '#faf7f2',
				hoverColor: '#f1ebe0',
				borderColor: '#d9d2c5',
				footerBgColor: '#f5f0e8',
				footerTextColor: '#3f3a34'
			}
		},
		chart: {
			minimal: {
				styleBackground: '#ffffff',
				styleTextColor: '#24292f',
				styleGridColor: '#e5e7eb',
				styleFontSize: '14px',
				stylePalette: ['#5070dd', '#b6d634', '#505372', '#ff994d', '#0ca8df']
			},
			bold: {
				styleBackground: '#1f2937',
				styleTextColor: '#f9fafb',
				styleGridColor: '#4b5563',
				styleFontSize: '15px',
				stylePalette: ['#fb628b', '#ffd10a', '#3fbe95', '#0ca8df', '#785db0']
			},
			midnight: {
				styleBackground: '#111827',
				styleTextColor: '#f9fafb',
				styleGridColor: '#374151',
				styleFontSize: '14px',
				stylePalette: ['#5070dd', '#3fbe95', '#b6d634', '#ff994d', '#fb628b']
			},
			paper: {
				styleBackground: '#faf7f2',
				styleTextColor: '#3f3a34',
				styleGridColor: '#e5ded2',
				styleFontSize: '14px',
				stylePalette: ['#9a60b4', '#c98a3b', '#5b8c5a', '#b0555a', '#4a6fa5']
			}
		}
	};

	window.BaraTablesAdminCore = {
		formAuth: formAuth,
		designPresets: DESIGN_PRESETS,
		normalizeHex: normalizeHex,
		normalizeChartFontSize: normalizeChartFontSize,
		sanitizeCustomCss: sanitizeCustomCss,
		truncateUtf8: truncateUtf8,
		requestFragment: requestFragment,
		isActivationEvent: isActivationEvent,
		applyDefaultIfUntouched: applyDefaultIfUntouched,
		redirectWithQuery: redirectWithQuery
	};
})(window, jQuery);
