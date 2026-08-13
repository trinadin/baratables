(function($) {
	var FILTER_SELECTOR = '.btbl-filter-wrapper .btbl-filter';
	var compactValues = window.BaraTablesUtils.compactValues;

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

	function getSearchTerms($element, fallback) {
		if (!$element || !$element.length) {
			return Array.isArray(fallback) ? fallback : (fallback !== undefined ? [fallback] : []);
		}
		var terms = $element.data('searchTerms');
		if (typeof terms === 'string') {
			try {
				terms = JSON.parse(terms);
			} catch (e) {
				terms = terms.split(',').map(function(value) {
					return value.trim();
				});
			}
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

	function selectedValues($filter, selector) {
		return $filter.find(selector).map(function() {
			return $(this).val();
		}).get();
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

	function initializeSelect2(context) {
		if (context.type !== 'dropdown' && context.type !== 'dropdown_multi') {
			return;
		}
		var $select = context.$filter.find('select');
		var $dropdownParent = context.$filter.closest('.btbl-table-wrapper');
		if (!$dropdownParent.length) {
			$dropdownParent = context.$filter.closest('.btbl-filter-wrapper');
		}
		if (!$dropdownParent.length) {
			$dropdownParent = context.$filter;
		}

		$select.select2({
			width: 'resolve',
			placeholder: $select.data('placeholder') || '',
			allowClear: context.type === 'dropdown_multi',
			dropdownParent: $dropdownParent,
			closeOnSelect: context.type === 'dropdown'
		});

		// The native select is hidden by Select2, so carry its accessible name to the
		// replacement control (and to the focusable search field on multi-selects).
		var labelId = $select.attr('aria-labelledby') || '';
		if (labelId) {
			var applyAccessibleName = function() {
				context.$filter.find('.select2-selection').each(function() {
					var $selection = $(this);
					var existing = $selection.attr('aria-labelledby') || '';
					if ((' ' + existing + ' ').indexOf(' ' + labelId + ' ') === -1) {
						$selection.attr('aria-labelledby', (labelId + ' ' + existing).trim());
					}
				});
				context.$filter.find('.select2-selection--multiple .select2-search__field')
					.attr('aria-labelledby', labelId);
			};
			applyAccessibleName();
			$select.on('select2:open select2:close', applyAccessibleName);
		}

		if (context.type === 'dropdown_multi') {
			var suppressOpen = false;
			$select.on('select2:unselect select2:clear', function() {
				suppressOpen = true;
			});
			$select.on('select2:opening', function(event) {
				if (suppressOpen) {
					event.preventDefault();
					suppressOpen = false;
				}
			});
			$select.on('select2:open select2:select', function() {
				suppressOpen = false;
			});
		}
	}

	var singleSelectAdapter = {
		read: function(context) {
			var value = context.$filter.find('select').val();
			return value && value !== '__all' ? [value] : [];
		},
		bind: function(context) {
			var $select = context.$filter.find('select');
			$select.on('change', function() {
				var value = $(this).val();
				if (!value || value === '__all') {
					context.clear();
				} else {
					context.apply(getSearchTerms($(this).find('option:selected'), value));
				}
				context.changed();
			});
			if (context.preset) {
				$select.val(asArray(context.preset)[0]).trigger('change');
			}
		},
		reset: function(context) {
			context.$filter.find('select').val(context.type === 'dropdown' ? '' : '__all').trigger('change');
		}
	};

	var multiSelectAdapter = {
		read: function(context) {
			return compactValues(context.$filter.find('select').val() || []);
		},
		bind: function(context) {
			var $select = context.$filter.find('select');
			var suppressEmptyChange = false;
			$select.on('change', function() {
				if (suppressEmptyChange) {
					suppressEmptyChange = false;
					return;
				}
				var rawValues = $select.val() || [];
				var values = compactValues(rawValues);
				if (values.length !== rawValues.length) {
					suppressEmptyChange = true;
					$select.val(values).trigger('change');
				}
				var terms = [];
				$select.find('option:selected').filter(function() {
					return compactValues([$(this).val()]).length > 0;
				}).each(function() {
					terms = terms.concat(getSearchTerms($(this), $(this).val()));
				});
				terms = compactValues(terms);
				applyTerms(context, terms);
			});
			if (context.preset) {
				$select.val(compactValues(asArray(context.preset))).trigger('change');
			}
		},
		reset: function(context) {
			context.$filter.find('select').val([]).trigger('change');
		}
	};

	var checkboxAdapter = {
		read: function(context) {
			return selectedValues(context.$filter, 'input[type="checkbox"]:checked');
		},
		bind: function(context) {
			var $checkboxes = context.$filter.find('input[type="checkbox"]');
			$checkboxes.on('change', function() {
				var terms = [];
				context.$filter.find('input[type="checkbox"]:checked').each(function() {
					terms = terms.concat(getSearchTerms($(this), $(this).val()));
				});
				applyTerms(context, terms);
			});
			if (context.preset) {
				var values = asArray(context.preset);
				$checkboxes.each(function() {
					if (values.indexOf($(this).val()) !== -1) {
						$(this).prop('checked', true);
					}
				});
				$checkboxes.first().trigger('change');
			}
		},
		reset: function(context) {
			var $checkboxes = context.$filter.find('input[type="checkbox"]');
			$checkboxes.prop('checked', false);
			$checkboxes.first().trigger('change');
		}
	};

	var radioAdapter = {
		read: function(context) {
			var value = context.$filter.find('input[type="radio"]:checked').val();
			return value && value !== '__all' ? [value] : [];
		},
		bind: function(context) {
			var $radios = context.$filter.find('input[type="radio"]');
			$radios.on('change', function() {
				var value = $(this).val();
				if (value === '__all') {
					context.clear();
				} else {
					context.apply(getSearchTerms($(this), value));
				}
				context.changed();
			});
			if (context.preset) {
				var preset = asArray(context.preset)[0];
				var $match = $radios.filter(function() {
					return String($(this).val()) === String(preset);
				});
				if ($match.length) {
					$match.prop('checked', true).trigger('change');
				}
			}
		},
		reset: function(context) {
			var $all = context.$filter.find('input[type="radio"][value="__all"]');
			if ($all.length) {
				$all.prop('checked', true).trigger('change');
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

	function getType($filter) {
		var type = $filter.data('type') || '';
		if (!type && $filter.hasClass('btbl-filter-checkbox')) {
			return 'checkbox';
		}
		if (!type && $filter.hasClass('btbl-filter-radio')) {
			return 'radio';
		}
		return type;
	}

	function readActive($wrapper) {
		var filters = {};
		$wrapper.find(FILTER_SELECTOR).each(function() {
			var $filter = $(this);
			var slug = $filter.data('slug');
			var adapter = adapters[getType($filter)];
			if (!slug || !adapter) {
				return;
			}
			var values = adapter.read({ $filter: $filter });
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
				options.onChange(readActive(options.$wrapper));
			}
		}

		options.$wrapper.find(FILTER_SELECTOR).each(function() {
			var $filter = $(this);
			var type = getType($filter);
			var adapter = adapters[type];
			if (!adapter) {
				return;
			}
			var index = parseInt($filter.data('column'), 10);
			var slug = $filter.data('slug');
			var context = {
				$filter: $filter,
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
			initializeSelect2(context);
			adapter.bind(context);
			if (context.preset !== null && context.preset !== undefined) {
				initialDrawNeeded = true;
			}
		});
		initializing = false;

		return {
			readActive: function() {
				return readActive(options.$wrapper);
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
})(jQuery);
