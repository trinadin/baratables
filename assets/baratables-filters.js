(function() {
	var FILTER_SELECTOR = '.btbl-filter-wrapper .btbl-filter';
	var utils = window.BaraTablesUtils;
	var compactValues = utils.compactValues;

	function escapeRegex(text) {
		return text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
	}

	function buildMultiValuePattern(values) {
		var cleaned = [];
		function pushValue(value) {
			if (Array.isArray(value)) {
				value.forEach(pushValue);
				return;
			}
			if (value !== null && value !== undefined) {
				cleaned.push(String(value));
			}
		}
		pushValue(values);

		if (!cleaned.length) {
			return '';
		}

		var parts = cleaned.map(function(value) {
			if (value === '') {
				return '^\\s*$';
			}
			return '(?:^|,\\s*)' + escapeRegex(value) + '(?:\\s*,|$)';
		});

		return parts.length === 1 ? parts[0] : '(?:' + parts.join('|') + ')';
	}

	function getSearchTerms(element, fallback) {
		if (!element) {
			return Array.isArray(fallback) ? fallback : (fallback !== undefined ? [fallback] : []);
		}
		var raw = element.getAttribute('data-search-terms');
		var terms = raw;
		if (typeof terms === 'string' && terms !== '') {
			try {
				terms = JSON.parse(terms);
			} catch (e) {
				terms = terms.split(',').map(function(value) {
					return value.trim();
				});
			}
		} else {
			terms = undefined;
		}
		if (!Array.isArray(terms)) {
			terms = [terms !== undefined ? terms : fallback];
		}
		return terms.filter(function(value) {
			return value !== undefined && value !== null;
		}).map(function(value) {
			return String(value);
		});
	}

	function asArray(value) {
		return Array.isArray(value) ? value : [value];
	}

	function applyTerms(context, terms) {
		if (terms.length) {
			context.apply(terms);
		} else {
			context.clear();
		}
		context.changed();
	}

	// Dropdown filters get an enhanced picker (type-to-find, chips on multi-selects) from the
	// bundled Tom Select, which like the rest of the front end has no dependencies. WordPress
	// loads it only on pages that render a dropdown filter; without it the native <select>
	// keeps working unchanged.
	function initializeTomSelect(context) {
		if (context.type !== 'dropdown' && context.type !== 'dropdown_multi') {
			return;
		}
		var TomSelectCtor = window.TomSelect;
		var select = context.filter.querySelector('select');
		if (typeof TomSelectCtor !== 'function' || !select || select.tomselect) {
			return;
		}
		var instance;
		try {
			instance = new TomSelectCtor(select, {
				// The default cap of 50 rendered options would hide long value lists.
				maxOptions: null,
				placeholder: select.getAttribute('data-placeholder') || '',
				plugins: context.type === 'dropdown_multi' ? { remove_button: {} } : {}
			});
		} catch (e) {
			return;
		}

		// Tom Select reports user selections on its own event bus, while the adapters, the
		// Reset button, and URL syncing all speak native `change` events on the select.
		// Bridge both directions: selections become bubbling change events, and programmatic
		// value changes (presets, reset) refresh the picker via sync(). The flag keeps the
		// two paths from retriggering each other; Tom Select copies aria-labelledby from the
		// select onto its widgets itself, so the accessible name carries over natively.
		var bridging = false;
		instance.on('change', function() {
			if (bridging) { return; }
			bridging = true;
			try {
				select.dispatchEvent(new Event('change', { bubbles: true }));
			} finally {
				bridging = false;
			}
		});
		select.addEventListener('change', function() {
			if (bridging) { return; }
			bridging = true;
			try {
				instance.sync();
			} finally {
				bridging = false;
			}
		});
	}

	var singleSelectAdapter = {
		read: function(context) {
			var select = context.filter.querySelector('select');
			var value = select ? select.value : '';
			return value && value !== '__all' ? [value] : [];
		},
		bind: function(context) {
			var select = context.filter.querySelector('select');
			if (!select) { return; }
			select.addEventListener('change', function() {
				var value = select.value;
				if (!value || value === '__all') {
					context.clear();
				} else {
					var selected = select.options[select.selectedIndex];
					context.apply(getSearchTerms(selected, value));
				}
				context.changed();
			});
			if (context.preset) {
				select.value = asArray(context.preset)[0];
				select.dispatchEvent(new Event('change', {bubbles: true}));
			}
		},
		reset: function(context) {
			var select = context.filter.querySelector('select');
			if (!select) { return; }
			select.value = context.type === 'dropdown' ? '' : '__all';
			select.dispatchEvent(new Event('change', {bubbles: true}));
		}
	};

	var multiSelectAdapter = {
		read: function(context) {
			var select = context.filter.querySelector('select');
			return compactValues(select && select.multiple ? Array.prototype.map.call(select.selectedOptions || [], function(option) {
				return option.value;
			}) : []);
		},
		bind: function(context) {
			var select = context.filter.querySelector('select');
			if (!select) { return; }
			var suppressEmptyChange = false;
			select.addEventListener('change', function() {
				if (suppressEmptyChange) {
					suppressEmptyChange = false;
					return;
				}
				var rawValues = Array.prototype.map.call(select.selectedOptions || [], function(option) {
					return option.value;
				});
				var values = compactValues(rawValues);
				if (values.length !== rawValues.length) {
					suppressEmptyChange = true;
					Array.prototype.forEach.call(select.options, function(option) {
						option.selected = compactValues([option.value]).length > 0;
					});
					select.dispatchEvent(new Event('change', {bubbles: true}));
				}
				var terms = [];
				Array.prototype.forEach.call(select.selectedOptions || [], function(option) {
					if (compactValues([option.value]).length > 0) {
						terms = terms.concat(getSearchTerms(option, option.value));
					}
				});
				applyTerms(context, compactValues(terms));
			});
			if (context.preset) {
				var presetValues = asArray(context.preset).map(String);
				Array.prototype.forEach.call(select.options, function(option) {
					option.selected = presetValues.indexOf(option.value) !== -1;
				});
				select.dispatchEvent(new Event('change', {bubbles: true}));
			}
		},
		reset: function(context) {
			var select = context.filter.querySelector('select');
			if (!select) { return; }
			Array.prototype.forEach.call(select.options, function(option) {
				option.selected = false;
			});
			select.dispatchEvent(new Event('change', {bubbles: true}));
		}
	};

	var checkboxAdapter = {
		read: function(context) {
			return Array.prototype.map.call(context.filter.querySelectorAll('input[type="checkbox"]:checked'), function(checkbox) {
				return checkbox.value;
			});
		},
		bind: function(context) {
			var checkboxes = Array.prototype.slice.call(context.filter.querySelectorAll('input[type="checkbox"]'));
			checkboxes.forEach(function(checkbox) {
				checkbox.addEventListener('change', function() {
					var terms = [];
					checkboxes.forEach(function(box) {
						if (box.checked) {
							terms = terms.concat(getSearchTerms(box, box.value));
						}
					});
					applyTerms(context, terms);
				});
			});
			if (context.preset) {
				var values = asArray(context.preset).map(String);
				checkboxes.forEach(function(checkbox) {
					if (values.indexOf(checkbox.value) !== -1) {
						checkbox.checked = true;
					}
				});
				if (checkboxes.length) {
					checkboxes[0].dispatchEvent(new Event('change', {bubbles: true}));
				}
			}
		},
		reset: function(context) {
			var checkboxes = Array.prototype.slice.call(context.filter.querySelectorAll('input[type="checkbox"]'));
			checkboxes.forEach(function(checkbox) {
				checkbox.checked = false;
			});
			if (checkboxes.length) {
				checkboxes[0].dispatchEvent(new Event('change', {bubbles: true}));
			}
		}
	};

	var radioAdapter = {
		read: function(context) {
			var checked = context.filter.querySelector('input[type="radio"]:checked');
			var value = checked ? checked.value : '';
			return value && value !== '__all' ? [value] : [];
		},
		bind: function(context) {
			var radios = Array.prototype.slice.call(context.filter.querySelectorAll('input[type="radio"]'));
			radios.forEach(function(radio) {
				radio.addEventListener('change', function() {
					if (radio.value === '__all') {
						context.clear();
					} else {
						context.apply(getSearchTerms(radio, radio.value));
					}
					context.changed();
				});
			});
			if (context.preset) {
				var preset = String(asArray(context.preset)[0]);
				var match = radios.filter(function(radio) {
					return radio.value === preset;
				})[0];
				if (match) {
					match.checked = true;
					match.dispatchEvent(new Event('change', {bubbles: true}));
				}
			}
		},
		reset: function(context) {
			var all = context.filter.querySelector('input[type="radio"][value="__all"]');
			if (all) {
				all.checked = true;
				all.dispatchEvent(new Event('change', {bubbles: true}));
			}
		}
	};

	var adapters = {
		dropdown: singleSelectAdapter,
		dropdown_plain: singleSelectAdapter,
		dropdown_multi: multiSelectAdapter,
		dropdown_plain_multi: multiSelectAdapter,
		checkbox: checkboxAdapter,
		radio: radioAdapter
	};

	function getType(filter) {
		var type = filter.getAttribute('data-type') || '';
		if (!type && filter.classList.contains('btbl-filter-checkbox')) {
			return 'checkbox';
		}
		if (!type && filter.classList.contains('btbl-filter-radio')) {
			return 'radio';
		}
		return type;
	}

	function readActive(wrapper) {
		var filters = {};
		Array.prototype.forEach.call((wrapper || document).querySelectorAll(FILTER_SELECTOR), function(filter) {
			var slug = filter.getAttribute('data-slug');
			var adapter = adapters[getType(filter)];
			if (!slug || !adapter) {
				return;
			}
			var values = adapter.read({ filter: filter });
			if (values.length) {
				filters[slug] = values;
			}
		});
		return filters;
	}

	function create(options) {
		var resetting = false;
		var initializing = true;
		var initialDrawNeeded = false;
		var contexts = [];
		var wrapper = options.wrapper || document;

		// Integer column selectors address CURRENT positions; filter controls are rendered with
		// ORIGINAL indexes, so transpose before touching the column.
		function resolveColumn(originalIndex) {
			var index = originalIndex;
			if (options.table.colReorder && typeof options.table.colReorder.transpose === 'function') {
				try {
					index = options.table.colReorder.transpose(originalIndex, 'toCurrent');
				} catch (e) {
					index = originalIndex;
				}
			}
			return options.table.column(index);
		}

		function search(originalIndex, pattern) {
			var column = resolveColumn(originalIndex);
			var expression;
			try {
				expression = pattern ? new RegExp(pattern, 'i') : null;
			} catch (e) {
			}
			var api = expression ? column.search(expression) : column.search(pattern, true, false);
			if (!resetting && !initializing) {
				api.draw();
			}
		}

		function changed() {
			if (!resetting && !initializing && typeof options.onChange === 'function') {
				options.onChange(readActive(wrapper));
			}
		}

		Array.prototype.forEach.call(wrapper.querySelectorAll(FILTER_SELECTOR), function(filter) {
			var type = getType(filter);
			var adapter = adapters[type];
			if (!adapter) {
				return;
			}
			var index = parseInt(filter.getAttribute('data-column'), 10);
			var slug = filter.getAttribute('data-slug');
			var context = {
				filter: filter,
				type: type,
				preset: (options.presets || {})[slug] || null,
				apply: function(terms) {
					search(index, buildMultiValuePattern(terms));
				},
				clear: function() {
					var api = resolveColumn(index).search('');
					if (!resetting && !initializing) {
						api.draw();
					}
				},
				changed: changed
			};
			contexts.push({ adapter: adapter, context: context });
			initializeTomSelect(context);
			adapter.bind(context);
			if (context.preset !== null && context.preset !== undefined) {
				initialDrawNeeded = true;
			}
		});
		initializing = false;

		return {
			readActive: function() {
				return readActive(wrapper);
			},
			needsInitialDraw: function() {
				return initialDrawNeeded;
			},
			reset: function() {
				resetting = true;
				try {
					contexts.forEach(function(entry) {
						entry.adapter.reset(entry.context);
					});
				} finally {
					resetting = false;
				}
			}
		};
	}

	window.BaraTablesFilters = {
		create: create,
		readActive: readActive
	};
})();
