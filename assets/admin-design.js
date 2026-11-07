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
	var normalizeCssSize = adminCore.normalizeCssSize;
	var normalizeCssFontSize = adminCore.normalizeCssFontSize;
	var sanitizeCustomCss = adminCore.sanitizeCustomCss;
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
	// The presets live in admin-core.js so the table editor, the chart editor and the site
	// Design screen offer the same names for the same looks.
	var PRESETS = adminCore.designPresets.table;

	// The design form this script drives. The table editor's Design tab is the default;
	// the site-level Design screen binds the same script to its own form, which is how
	// the two surfaces stay in step instead of drifting apart.
	function designScope() {
		return (config && config.scope) || '#btbl-tab-design';
	}

	function field(key) {
		return $(designScope() + ' [name="btbl_table_options[' + key + ']"]');
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
	//
	// The TYPE comes from the server, not from the key's name. The server keeps the type
	// of every design token in one place -- the option definitions -- and ships it to
	// this script, so the two sides cannot answer "what kind of value is this?" differently.
	function sanitizedValue(key, raw) {
		if (!raw) { return ''; }
		var types = (config && config.tokenTypes) || {};
		switch (types[key]) {
			case 'color':
				return normalizeHex(raw);
			case 'css_weight':
				var weight = String(raw).trim();
				return CSS_WEIGHTS.indexOf(weight) !== -1 ? weight : '';
			case 'css_font_size':
				return normalizeCssFontSize(raw);
			case 'css_size':
				return normalizeCssSize(raw);
			default:
				// A key with no type is not a design token, so it has no value. The server
				// says the same thing, so the preview cannot paint something the page
				// will not publish.
				return '';
		}
	}

	// Whether this entity currently inherits the site design. The checkbox in the form is
	// authoritative, so switching it off drops the inherited values from the preview
	// straight away; the server-sent flag is the fallback for a form without the toggle.
	function usesSiteDesign() {
		var $toggle = $(designScope() + ' input[type="checkbox"][name="btbl_table_options[useSiteDesign]"]');
		if ($toggle.length) {
			return $toggle.is(':checked');
		}
		return !(config && config.useSiteDesign === false);
	}

	// The site default's value for a token, when this entity inherits the site design.
	// A table that opted out resolves against the theme alone, so it gets nothing here.
	function siteDefaultValue(key) {
		if (!usesSiteDesign()) { return ''; }
		var defaults = (config && config.siteDefault) || {};
		return sanitizedValue(key, defaults[key] || '');
	}

	// The effective value of a token: the table's own value wins, a blank one inherits
	// the site default, and a blank one in both places stays blank. The preview and Copy
	// CSS both read this, so neither can show something the page will not publish.
	function effectiveValue(key) {
		return sanitizedValue(key, fieldValue(key)) || siteDefaultValue(key);
	}

	function buildVars() {
		var vars = [];
		var map = (config && config.varMap) || {};
		Object.keys(map).forEach(function(key) {
			var value = effectiveValue(key);
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

	// The chart container's own tokens, from the form. The site Design screen's chart
	// fields ARE the site default, so a blank one is "no default for this token" and
	// nothing is inherited into it.
	//
	// The property names come from the server's chart var map, so this cannot drift from
	// what the front end prints.
	function chartVars() {
		var map = (config && config.chartVarMap) || {};
		var fieldMap = (config && config.chartFieldMap) || {};
		var values = {};
		Object.keys(map).forEach(function(key) {
			var name = fieldMap[key];
			if (!name) { return; }
			var $f = $('[name="' + name + '"]');
			values[key] = $f.length ? String($f.val() || '') : '';
		});
		return adminCore.chartCssVars(map, values);
	}

	function buildCssText() {
		var vars = buildVars();
		var tableId = previewBaseId();
		// The site Design screen's CSS applies to every table, so its scope is the wrapper
		// itself; a table's own Copy CSS stays scoped to that table.
		var scope = (config && config.siteScope)
			? '.btbl-table-wrapper'
			: '.btbl-table-wrapper[data-btbl-table-id="' + (tableId || 'your-table-id') + '"]';
		var lines = [];
		// The note is shipped by the server, because it differs per surface: the site
		// screen has no "Theme CSS" output mode of its own to point at.
		var note = (config && config.copiedCssNote) ? String(config.copiedCssNote) : '';
		if (note) {
			lines.push('/* BaraTables design.');
			lines.push('   ' + note + ' */');
		}
		if (vars.length) {
			lines.push(scope + ' {');
			vars.forEach(function(pair) {
				lines.push('\t' + pair + ';');
			});
			lines.push('}');
		}
		// The chart container's own tokens. The site design covers the charts too, so a
		// copy that carried only the tables would leave the charts behind.
		var chart = chartVars();
		if (chart.length) {
			lines.push('', '.btbl-chart {');
			chart.forEach(function(pair) {
				lines.push('\t' + pair.property + ':' + pair.value + ';');
			});
			lines.push('}');
		}
		var custom = sanitizeCustomCss(field('customCss').val(), cssByteBudget());
		if (custom) {
			lines.push('', scopeCss(custom, tableId));
		}
		return lines.join('\n');
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
		$(designScope() + ' [data-btbl-external-note]').prop('hidden', !external);
		field('customCss').prop('readonly', external).toggleClass('is-mode-disabled', external);
	}

	$(document).on('input change', designScope() + ' [name^="btbl_table_options["]', function() {
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
			if (key === 'useSiteDesign') {
				// The opt-out changes what every blank token inherits, so the preview has
				// to be rebuilt: without this, unchecking the box leaves the inherited
				// colours on screen until some other field changes.
				applyStyle();
				return;
			}
			if (Object.prototype.hasOwnProperty.call(config.varMap, key) || Object.prototype.hasOwnProperty.call(FEATURE_CLASS_MAP, key)) {
				applyStyle();
			}
		});

		$(document).on('click', '[data-btbl-preset]', function(e) {
			e.preventDefault();
			applyPreset(String($(this).data('btbl-preset') || ''));
		});

		$(document).on('click', '[data-btbl-copy-css]', function(e) {
			e.preventDefault();
			var $status = $(designScope() + ' [data-btbl-copy-status]');
			var $output = $(designScope() + ' .btbl-copy-css-output');
			adminCore.copyText(buildCssText()).then(function() {
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
