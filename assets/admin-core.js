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

	// Mirrors BaraTables_Service::sanitize_css_size(): one or two non-negative CSS lengths
	// with an explicit safe unit, e.g. "12px", "1.5em", "8px 12px", "50%". The server
	// trims before it matches, so this mirror trims too -- a value the server would keep
	// must not be rejected here, or the preview would drop a size the page publishes.
	function normalizeCssSize(raw) {
		var value = String(raw === null || raw === undefined ? '' : raw).trim();
		return /^\d{1,4}(?:\.\d{1,2})?(?:px|em|rem|%)(?: \d{1,4}(?:\.\d{1,2})?(?:px|em|rem|%))?$/.test(value) ? value : '';
	}

	// Mirrors BaraTables_Service::sanitize_chart_font_size(): a chart's font size is a
	// number of PIXELS, because ECharts' fontSize is not a CSS length -- "2em" read as a
	// number is 2, which renders a 2px label. Only pixels are accepted, and a bare
	// number is normalized to the px form so the preview and the saved value agree.
	function normalizeChartFontSize(raw) {
		var value = String(raw === null || raw === undefined ? '' : raw).trim();
		var match = /^(\d{1,4})(?:px)?$/.exec(value);
		// Zero would make the chart's text invisible: ECharts draws every label at
		// fontSize 0, so the axis, legend and tooltip would all disappear.
		if (!match || parseInt(match[1], 10) < 1) { return ''; }
		return match[1] + 'px';
	}

	// Mirrors BaraTables_Service::sanitize_css_font_size(): ONE non-negative CSS length
	// with an explicit safe unit. A font size is not a two-value length: "12px 16px" is
	// invalid CSS, so the browser drops the declaration and the field does nothing.
	function normalizeCssFontSize(raw) {
		var value = String(raw === null || raw === undefined ? '' : raw).trim();
		return /^\d{1,4}(?:\.\d{1,2})?(?:px|em|rem|%)$/.test(value) ? value : '';
	}

	// One chart design value, sanitized the way the server sanitizes it. A chart's
	// font size is pixels, its container's padding and radius are CSS lengths, and
	// its colours are hex: three contracts, one entry point.
	//
	// A key with no known contract has no value. The server says the same, so the
	// preview cannot show something the page will not publish.
	function sanitizeChartValue(key, raw) {
		switch (key) {
			case 'styleFontSize':
				return normalizeChartFontSize(raw);
			case 'stylePadding':
			case 'styleRadius':
				return normalizeCssSize(raw);
			case 'styleBackground':
			case 'styleTextColor':
			case 'styleGridColor':
				return normalizeHex(raw);
			default:
				return '';
		}
	}

	// The chart container's CSS custom properties, from resolved values.
	//
	// The PROPERTY NAMES come from the server's chart var map, so the client cannot
	// drift from the server: a token added there reaches the preview and the copied
	// CSS without a second edit here.
	function chartCssVars(varMap, values) {
		var pairs = [];
		var map = varMap || {};
		Object.keys(map).forEach(function(key) {
			var raw = values && values[key] !== undefined && values[key] !== null ? values[key] : '';
			var value = sanitizeChartValue(key, raw);
			if (!value) { return; }
			map[key].forEach(function(property) {
				pairs.push({ property: property, value: value });
			});
		});
		return pairs;
	}

	// The effective value of one design key: the entity's own value when it has one,
	// otherwise the inherited site default. An entity that switched the site design off
	// inherits nothing, so only its own value counts.
	//
	// This is the client-side mirror of BaraTables_Design::resolve_table_options() and
	// resolve_chart_options(): the preview and Copy CSS must show the design the page
	// will actually publish, not the raw form state.
	function effectiveValue(key, ownValue, siteDefault, useSiteDesign) {
		var own = ownValue === null || ownValue === undefined ? '' : String(ownValue);
		if (own !== '') { return own; }
		if (!useSiteDesign) { return ''; }
		var defaults = siteDefault || {};
		var inherited = defaults[key] === null || defaults[key] === undefined ? '' : String(defaults[key]);
		return inherited;
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

	// The shared design-field feedback. Colours and sizes are checked as you type on
	// every design surface -- the table editor's Design tab, the chart editor's Design
	// tab and the site Design screen -- so a value the server would discard is flagged
	// instead of disappearing silently on save. The checks are the server's own
	// contracts, so a field and a save can never disagree.
	function markValidity($field, valid) {
		$field.toggleClass('is-invalid', !valid);
		if (valid) {
			$field.removeAttr('aria-invalid');
		} else {
			$field.attr('aria-invalid', 'true');
		}
	}

	// Whether a size field's value is one the server would keep. An empty field is
	// always fine: blank means "inherit".
	function sizeValueIsValid(value, kind) {
		var raw = String(value === null || value === undefined ? '' : value);
		if (raw.trim() === '') { return true; }
		if (kind === 'chart-font') { return normalizeChartFontSize(raw) !== ''; }
		if (kind === 'font') { return normalizeCssFontSize(raw) !== ''; }
		return normalizeCssSize(raw) !== '';
	}

	function initDesignFieldValidation() {
		// The native swatch writes the hex value into its text field.
		$(document).on('input', '.btbl-color-picker', function() {
			var $value = $(this).closest('.btbl-color-field').find('.btbl-color-value');
			if ($value.length) {
				$value.val($(this).val()).trigger('change');
			}
		});
		$(document).on('input change', '.btbl-color-value', function() {
			var $field = $(this).closest('.btbl-color-field');
			var $picker = $field.find('.btbl-color-picker');
			// Masked as you type: only "#" and hex digits, seven characters at most. The value
			// is rewritten ONLY when something was stripped, so normal typing never disturbs the
			// caret. A non-empty value that is not yet a complete #rgb or #rrggbb flags the
			// field as invalid -- the save pipeline drops such a value back to the theme color.
			var raw = String($(this).val() || '');
			var masked = raw.replace(/[^#0-9a-fA-F]/g, '').slice(0, 7);
			if (masked !== raw) {
				$(this).val(masked);
				this.setSelectionRange(masked.length, masked.length);
			}
			// The server accepts "#" plus exactly three or six hex digits and rejects
			// everything else, so the field has to agree with it exactly: a valid #rgb is not
			// "not finished yet", and a bare "fff" or a doubled "##fff" is not a colour.
			var complete = /^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/.test(masked);
			markValidity($(this), masked === '' || complete);
			if (complete) {
				$picker.val(masked);
			} else if (masked === '') {
				// The field is empty, so the swatch rests on the colour the server
				// rendered for it. The two agree, so clearing a field never moves
				// the swatch to a colour the field never had.
				$picker.val($field.data('btbl-resting-color') || '#ffffff');
			}
		});
		$(document).on('input change', '[data-btbl-size-field]', function() {
			markValidity($(this), sizeValueIsValid($(this).val(), String($(this).attr('data-btbl-size-kind') || 'css')));
		});
	}

	// Copy text to the clipboard. The async Clipboard API is unavailable on a non-secure
	// origin (plain http on a LAN) and in some webviews, so the classic hidden-textarea
	// route is the fallback. Shared: both editors copy CSS, and a fallback that only
	// one of them had would fail on exactly the page that needed it.
	function copyText(text) {
		if (navigator.clipboard && navigator.clipboard.writeText) {
			return navigator.clipboard.writeText(text);
		}
		var deferred = $.Deferred();
		var $area = $('<textarea>').val(text).css({position: 'fixed', opacity: '0'}).appendTo('body');
		$area[0].select();
		try {
			document.execCommand('copy');
			deferred.resolve();
		} catch (error) {
			deferred.reject(error);
		}
		$area.remove();
		return deferred.promise();
	}

	// A live region for saying something the screen cannot show.
	//
	// A reorder moves an item without changing anything else on the page, so there is
	// nothing for a screen reader to notice. One region for the page, reused by every
	// script that has something to report. It is created when the page loads, because
	// a region that appears at the same moment as its first message is not reliably
	// announced.
	var liveRegion = null;
	function liveRegionElement() {
		if (liveRegion && liveRegion.length) {
			return liveRegion;
		}
		liveRegion = $('#btbl-live-region');
		if (!liveRegion.length) {
			liveRegion = $('<div id="btbl-live-region" class="screen-reader-text" aria-live="polite" aria-atomic="true"></div>');
			$('body').append(liveRegion);
		}
		return liveRegion;
	}
	function announce(message) {
		var $region = liveRegionElement();
		// Clearing first means the same message twice is announced twice.
		$region.text('');
		window.setTimeout(function() {
			$region.text(message);
		}, 50);
	}
	// A move announcement, from a template the server shipped.
	//
	// The template is a translatable string with two placeholders: %1$s is the thing
	// that moved and %2$s (or %2$d) is where it went. Shipping it from PHP keeps the
	// wording in the translation file, where a translator can find it.
	function announceMoved(template, name, where) {
		if (!template) {
			return;
		}
		var message = String(template)
			.replace('%1$s', String(name))
			.replace('%2$s', String(where))
			.replace('%2$d', String(where));
		announce(message);
	}
	$(function() {
		liveRegionElement();
	});

	$(initDesignFieldValidation);

	window.BaraTablesAdminCore = {
		formAuth: formAuth,
		designPresets: DESIGN_PRESETS,
		normalizeHex: normalizeHex,
		normalizeCssSize: normalizeCssSize,
		normalizeCssFontSize: normalizeCssFontSize,
		normalizeChartFontSize: normalizeChartFontSize,
		sanitizeChartValue: sanitizeChartValue,
		chartCssVars: chartCssVars,
		effectiveValue: effectiveValue,
		copyText: copyText,
		sanitizeCustomCss: sanitizeCustomCss,
		truncateUtf8: truncateUtf8,
		requestFragment: requestFragment,
		isActivationEvent: isActivationEvent,
		applyDefaultIfUntouched: applyDefaultIfUntouched,
		announce: announce,
		announceMoved: announceMoved,
		redirectWithQuery: redirectWithQuery
	};
})(window, jQuery);
