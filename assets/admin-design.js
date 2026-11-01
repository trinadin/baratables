/**
 * BaraTables Design tab: live style binding for the Table preview.
 *
 * The Design tab's controls are plain btbl_table_options fields; this file mirrors the
 * server-side STYLE_VAR_MAP client-side so every keystroke lands on the preview wrapper as
 * an inline custom-property block -- the exact block the front end prints from the saved
 * values, and the exact block Copy CSS hands back for a theme. Nothing here talks to the
 * server: data and structure changes still go through the Refresh-preview button, style
 * changes never need to.
 */
(function(window, document, $) {
	'use strict';

	var config = null;
	var customCssTimer;
	var adminCore = window.BaraTablesAdminCore;
	// The same helpers the server's sanitizers are mirrored by. They live in admin-core.js
	// because the chart editor mirrors the same colour contract.
	var normalizeHex = adminCore.normalizeHex;
	var sanitizeCustomCss = adminCore.sanitizeCustomCss;
	var CSS_SIZE = /^\d{1,4}(\.\d{1,2})?(px|em|rem|%)( \d{1,4}(\.\d{1,2})?(px|em|rem|%))?$/;
	var CSS_WEIGHTS = ['400', '500', '600', '700', 'normal', 'bold'];
	// The DataTables style-feature toggles render through classes, not custom properties, so
	// they get the same live treatment via class toggling on the preview table.
	var FEATURE_CLASS_MAP = {
		stripe: 'stripe',
		rowBorder: 'row-border',
		cellBorder: 'cell-border',
		hover: 'hover',
		orderColumn: 'order-column',
		compact: 'compact'
	};
	// Curated starter looks. Values map 1:1 onto btbl_table_options keys; the "default"
	// preset is special-cased below as "clear every token field" (Additional CSS and the
	// output mode are the admin's own and are never touched by a preset).
	var PRESETS = {
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
	};

	function field(key) {
		return $('#btbl-tab-design [name="btbl_table_options[' + key + ']"]');
	}

	function fieldValue(key) {
		var $f = field(key);
		if (!$f.length) { return ''; }
		if ($f.attr('type') === 'radio') {
			return $f.filter(':checked').val() || '';
		}
		return String($f.val() || '');
	}

	// Same contract as the PHP sanitizers: a value that would be dropped on save never
	// reaches the preview, so the preview can never diverge from what will persist.
	function sanitizedValue(key, raw) {
		if (!raw) { return ''; }
		if (/Color$/.test(key) || key === 'accentColor') {
			return normalizeHex(raw);
		}
		if (key === 'headerWeight') {
			return CSS_WEIGHTS.indexOf(raw) !== -1 ? raw : '';
		}
		return CSS_SIZE.test(raw) ? raw : '';
	}

	function buildVars() {
		var vars = [];
		var map = (config && config.varMap) || {};
		Object.keys(map).forEach(function(key) {
			var value = sanitizedValue(key, fieldValue(key));
			if (!value) { return; }
			map[key].forEach(function(property) {
				vars.push(property + ':' + value);
			});
		});
		return vars;
	}

	function previewWrapper() {
		var selector = (config && config.previewSelector) || '#btbl-preview-target .btbl-table-wrapper';
		return $(selector).first();
	}

	// The wrapper carries the base id (the table's own slug) in data-btbl-table-id; the
	// per-render instance counter lives in data-table-id. Scoping and Copy CSS key on the base
	// id so every instance of the table matches -- and only that table.
	function previewBaseId() {
		var $wrapper = previewWrapper();
		if (!$wrapper.length) { return ''; }
		var base = String($wrapper.attr('data-btbl-table-id') || '');
		if (base) { return base; }
		return String($wrapper.attr('data-table-id') || '').replace(/-\d+$/, '');
	}

	// Mirrors BaraTables_Service::scope_table_css(): prefixes each selector of every
	// top-level rule (and @media/@supports bodies) with the table scope; @keyframes steps
	// and selector-less at-rules pass through. Keep the two in step.
	function scopeCss(css, targetId) {
		var trimmed = String(css || '').trim();
		var id = String(targetId || '');
		if (!trimmed || !id) { return css; }
		var scope = '.btbl-table-wrapper[data-btbl-table-id="' + id + '"]';
		var i = 0;

		// Fragments are RETURNED so each caller appends to its own buffer, mirroring
		// BaraTables_Service::scope_table_css() (whose writers share one output string).
		function copyComment() {
			var end = css.indexOf('*/', i + 2);
			end = end === -1 ? css.length - 1 : end + 1;
			var fragment = css.slice(i, end + 1);
			i = end + 1;
			return fragment;
		}
		function copyString() {
			var quote = css[i];
			var j = i + 1;
			while (j < css.length) {
				if (css[j] === '\\') { j += 2; continue; }
				if (css[j] === quote) { break; }
				j++;
			}
			j = Math.min(j + 1, css.length);
			var fragment = css.slice(i, j);
			i = j;
			return fragment;
		}
		function copyBlock() {
			var body = '';
			while (i < css.length) {
				var c = css[i];
				if (c === '"' || c === "'") { body += copyString(); continue; }
				if (c === '}') { i++; return body; }
				body += c;
				i++;
			}
			return body;
		}
		function scopeSelectorList(prelude) {
			var selectors = [];
			var current = '';
			var depth = 0;
			for (var k = 0; k < prelude.length; k++) {
				var ch = prelude[k];
				if (ch === '"' || ch === "'") {
					var q = ch, m = k + 1;
					while (m < prelude.length) {
						if (prelude[m] === '\\') { m += 2; continue; }
						if (prelude[m] === q) { break; }
						m++;
					}
					current += prelude.slice(k, Math.min(m + 1, prelude.length));
					k = m;
					continue;
				}
				if (ch === '(') { depth++; }
				else if (ch === ')') { depth = Math.max(0, depth - 1); }
				else if (ch === ',' && depth === 0) { selectors.push(current); current = ''; continue; }
				current += ch;
			}
			selectors.push(current);
			var scoped = [];
			selectors.forEach(function(raw) {
				var sel = raw.trim();
				if (sel) { scoped.push(scope + ' ' + sel); }
			});
			return scoped.length ? scoped.join(', ') : scope;
		}

		function readSegment(scoping) {
			var segment = '';
			while (i < css.length) {
				if (css[i] === '}') { return segment; }
				if (css.slice(i, i + 2) === '/*') { segment += copyComment(); continue; }
				var prelude = '';
				var opened = false;
				while (i < css.length) {
					var c = css[i];
					if (c === '"' || c === "'") { prelude += copyString(); continue; }
					if (css.slice(i, i + 2) === '/*') { prelude += copyComment(); continue; }
					if (c === '{') { i++; opened = true; break; }
					if (c === ';') { segment += prelude + c; prelude = ''; i++; continue; }
					if (c === '}') { segment += prelude; return segment; }
					prelude += c;
					i++;
				}
				if (!prelude && !opened) { break; }
				prelude = prelude.trim();
				var atMatch = prelude.match(/^@([-a-z]+)/i);
				var atName = atMatch ? atMatch[1].toLowerCase() : '';
				if (atName === 'keyframes' || atName === '-webkit-keyframes') {
					segment += prelude + ' {' + readSegment(false) + '}';
				} else if (atMatch) {
					segment += prelude + ' {' + readSegment(scoping) + '}';
				} else if (scoping) {
					segment += scopeSelectorList(prelude) + ' {' + copyBlock() + '}';
				} else {
					segment += prelude + ' {' + copyBlock() + '}';
				}
			}
			return segment;
		}

		return readSegment(true);
	}

	function applyStyle() {
		var $wrapper = previewWrapper();
		if (!$wrapper.length) { return; }
		$wrapper.attr('style', buildVars().join(';'));
		applyFeatureClasses($wrapper);
	}

	function applyFeatureClasses($wrapper) {
		var $table = $wrapper.find('table').first();
		if (!$table.length) { return; }
		Object.keys(FEATURE_CLASS_MAP).forEach(function(key) {
			var $f = field(key);
			if (!$f.length) { return; }
			var on = $f.is(':checked');
			if (key === 'compact') {
				$wrapper.toggleClass('is-compact', on);
			}
			$table.toggleClass(FEATURE_CLASS_MAP[key], on);
		});
	}

	// The Additional CSS byte budget, straight from the server constant, so the preview
	// and the published page can never disagree about where the cap falls.
	function cssByteBudget() {
		return (config && typeof config.maxCssBytes === 'number' && config.maxCssBytes > 0)
			? config.maxCssBytes
			: 20000;
	}

	// The Additional CSS textarea manages its own preview tag and removes the one the server
	// printed, so the CSS is never applied twice while editing. Content is scoped exactly
	// like the print path (scope_table_css), so the preview shows what will publish.
	function applyCustomCss() {
		var $target = $('#btbl-preview-target');
		if (!$target.length) { return; }
		var css = sanitizeCustomCss(field('customCss').val(), cssByteBudget());
		$target.children('style[data-btbl-css]').remove();
		var $style = $target.children('style[data-btbl-preview-css]');
		if (!css) {
			$style.remove();
			return;
		}
		css = scopeCss(css, previewBaseId());
		if (!$style.length) {
			$style = $('<style>').attr('data-btbl-preview-css', '1').appendTo($target);
		}
		$style.text(css);
	}

	function buildCssText() {
		var vars = buildVars();
		var tableId = previewBaseId();
		var scope = '.btbl-table-wrapper[data-btbl-table-id="' + (tableId || 'your-table-id') + '"]';
		var lines = [
			'/* BaraTables table styles. Paste into Appearance > Customize > Additional CSS (or a',
			'   child theme stylesheet), then switch the Design tab to "Theme CSS" so this plugin',
			'   stops printing its own inline copy. */'
		];
		if (vars.length) {
			lines.push(scope + ' {');
			vars.forEach(function(pair) {
				lines.push('\t' + pair + ';');
			});
			lines.push('}');
		}
		var custom = sanitizeCustomCss(field('customCss').val(), cssByteBudget());
		if (custom) {
			lines.push('', scopeCss(custom, tableId));
		}
		return lines.join('\n');
	}

	function copyText(text) {
		if (navigator.clipboard && navigator.clipboard.writeText) {
			return navigator.clipboard.writeText(text);
		}
		// Non-secure contexts (plain http on a LAN) have no async clipboard API; fall back to
		// the classic hidden-textarea/execCommand route.
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

	function applyPreset(name) {
		var isDefault = name === 'default';
		var values = PRESETS[name];
		if (!values && !isDefault) { return; }
		var tokenKeys = Object.keys((config && config.varMap) || {});
		tokenKeys.forEach(function(key) {
			var value;
			if (values && Object.prototype.hasOwnProperty.call(values, key)) {
				value = values[key];
			} else if (isDefault) {
				value = '';
			} else {
				return;
			}
			var $f = field(key);
			if (!$f.length) { return; }
			if ($f.attr('type') === 'radio') {
				$f.filter('[value="' + value + '"]').prop('checked', true).trigger('change');
				return;
			}
			$f.val(value).trigger('input').trigger('change');
		});
		applyStyle();
	}

	$(function() {
		var $configEl = $('[data-btbl-design-config]');
		if (!$configEl.length) { return; }
		config = $configEl.data('btbl-design-config');
		if (!config || !config.varMap) { return; }

		// Theme CSS mode means the plugin prints nothing, so the Additional CSS box becomes
	// read-only: visible (and still selectable/copyable, and still submitted so its stored
	// content survives saves), but clearly not in effect here. Edit it by switching back to
	// Inline -- that keeps the theme copy and the box from silently diverging.
	function applyStyleMode() {
		var external = fieldValue('styleMode') === 'external';
		$('[data-btbl-external-note]').prop('hidden', !external);
		field('customCss').prop('readonly', external).toggleClass('is-mode-disabled', external);
	}

	$(document).on('input change', '#btbl-tab-design [name^="btbl_table_options["]', function() {
			var match = String($(this).attr('name') || '').match(/^btbl_table_options\[(.+?)\]$/);
			if (!match) { return; }
			var key = match[1];
			if (key === 'customCss') {
				clearTimeout(customCssTimer);
				customCssTimer = setTimeout(applyCustomCss, 250);
				return;
			}
			if (key === 'styleMode') {
				applyStyleMode();
				return;
			}
			if (Object.prototype.hasOwnProperty.call(config.varMap, key) || Object.prototype.hasOwnProperty.call(FEATURE_CLASS_MAP, key)) {
				applyStyle();
			}
		});

		$(document).on('click', '.btbl-preset-button', function(e) {
			e.preventDefault();
			applyPreset(String($(this).data('btbl-preset') || ''));
		});

		$(document).on('click', '[data-btbl-copy-css]', function(e) {
			e.preventDefault();
			var $status = $('[data-btbl-copy-status]');
			var $output = $('.btbl-copy-css-output');
			copyText(buildCssText()).then(function() {
				$status.text(config.copiedLabel);
				$output.prop('hidden', true);
			}, function() {
				// Clipboard access is denied on non-secure origins and some webviews: surface the
				// generated CSS on the page, selected, so the manual copy is one Cmd/Ctrl+C away.
				$status.text(config.clipboardUnavailableLabel);
				$output.text(buildCssText()).prop('hidden', false);
				var range = document.createRange();
				range.selectNodeContents($output[0]);
				var selection = window.getSelection();
				selection.removeAllRanges();
				selection.addRange(range);
			});
		});

		// After a Refresh-preview swap, the server markup carries the just-POSTed values; the
		// form is the source of truth while editing, so re-apply it (and re-manage the
		// Additional CSS tag) on top.
		$(document).on('btbl:preview-refreshed', function() {
			applyStyle();
			applyCustomCss();
			applyStyleMode();
		});

		applyStyle();
		applyCustomCss();
		applyStyleMode();
	});
})(window, document, jQuery);
