(function($) {
	var utils = window.BaraTablesUtils;

	function create(options) {
		var table = options.table;
		var resolvedOptions = options.resolvedOptions;
		var presetColumns = options.presetColumns || [];
		var $container = $(table.table().container());
		var $filterWrapper = resolvedOptions.searchBox === false ? $() : $container.find('.dataTables_filter, .dt-search');
		var $searchInput = $filterWrapper.find('input[type="search"]');
		var $dropdown = $();
		var searchableColumns = [];
		var searchableColumnIndices = [];
		var scopedFilter = null;
		var searchState = {
			term: options.presetTerm || table.search() || '',
			columns: []
		};

		function changed() {
			if (typeof options.onChange === 'function') {
				options.onChange(searchState);
			}
		}

		function allColumnIndices() {
			return searchableColumnIndices.slice();
		}

		if ($filterWrapper.length && $searchInput.length) {
			initializeSearchControl();
		}

		function initializeSearchControl() {
			if (options.placeholder) {
				$searchInput.attr('placeholder', options.placeholder);
			}
			var $filterLabel = $filterWrapper.find('label');
			if (!options.labelHtml && $filterLabel.length) {
				$filterLabel.contents().filter(function() {
					return this.nodeType === 3;
				}).remove();
				$filterWrapper.addClass('btbl-search-label-empty');
			}
			if (options.labelHtml && options.labelHtml !== options.labelPlain) {
				if ($filterLabel.length) {
					$filterLabel.contents().filter(function() {
						return this.nodeType === 3;
					}).remove();
				}
				var $visual = $('<span class="btbl-search-placeholder-visual" aria-hidden="true"></span>').html(options.labelHtml);
				if ($filterLabel.length) {
					$filterLabel.prepend($visual);
				} else {
					$filterWrapper.prepend($visual);
				}
				$filterWrapper.addClass('btbl-has-placeholder-visual');
				var syncPlaceholderState = function() {
					$filterWrapper.toggleClass('btbl-search-filled', ($searchInput.val() || '').length > 0);
				};
				$searchInput.on('input change', syncPlaceholderState);
				syncPlaceholderState();
			}

			var enableColumnPicker = resolvedOptions.searchColumns !== false;
			table.columns().every(function(index) {
				var columnSettings = this.settings()[0].aoColumns[index] || {};
				if (columnSettings.bSearchable === false || options.nonSearchable[index]) {
					return;
				}
				var headerHtml = ($(this.header()).html() || columnSettings.sTitle || '').trim();
				var defaultLabel = 'Column ' + (index + 1);
				if (!headerHtml) {
					headerHtml = defaultLabel;
				}
				var headerText = utils.labelToPlainText(headerHtml, defaultLabel) || defaultLabel;
				searchableColumns.push({
					index: index,
					labelHtml: headerHtml,
					labelText: headerText
				});
				searchableColumnIndices.push(index);
			});

			if (!searchableColumns.length) {
				return;
			}

			searchState.columns = allColumnIndices();
			if (enableColumnPicker && presetColumns.length) {
				var searchableIndexSet = {};
				searchableColumns.forEach(function(column) {
					searchableIndexSet[column.index] = true;
				});
				var presetIndices = presetColumns.map(function(slug) {
					return utils.slugIndex(options.slugToIndex, slug);
				}).filter(function(value) {
					return value !== null && value !== undefined && searchableIndexSet[parseInt(value, 10)];
				});
				if (presetIndices.length) {
					searchState.columns = presetIndices;
				}
			}

			var dropdownId = 'btbl-search-columns-' + options.tableId;
			var toggleLabelHtml = utils.resolveLabelHtml(resolvedOptions.searchColumnsLabel, 'Columns');
			var toggleLabelText = utils.labelToPlainText(toggleLabelHtml, 'Columns');
			var headingLabelHtml = utils.resolveLabelHtml(resolvedOptions.searchColumnsHeading, 'Search in');
			var $searchColumns = enableColumnPicker ? $('<div class="btbl-search-columns"></div>') : $();
			var $toggle = enableColumnPicker
				? $('<button type="button" class="btbl-search-columns-toggle" aria-expanded="false" aria-controls="' + dropdownId + '"></button>')
					.html(toggleLabelHtml)
					.attr('aria-label', toggleLabelText)
				: $();
			$dropdown = enableColumnPicker
				? $('<div class="btbl-search-columns-dropdown" id="' + dropdownId + '" role="group" aria-labelledby="' + dropdownId + '-heading"></div>')
				: $();
			var $heading = enableColumnPicker
				? $('<div class="btbl-search-columns-heading" id="' + dropdownId + '-heading"></div>').html(headingLabelHtml)
				: $();
			var $list = enableColumnPicker ? $('<div class="btbl-search-columns-list"></div>') : $();

			if (enableColumnPicker) {
				searchableColumns.forEach(function(column) {
					var checkboxId = dropdownId + '-' + column.index;
					var $item = $('<label class="btbl-search-columns-option" for="' + checkboxId + '"></label>');
					var $checkbox = $('<input type="checkbox" checked />').attr({
						id: checkboxId,
						value: column.index,
						'aria-label': column.labelText
					});
					if (searchState.columns.indexOf(column.index) === -1) {
						$checkbox.prop('checked', false);
					}
					$item.append($checkbox);
					$item.append($('<span></span>').html(column.labelHtml || column.labelText));
					$list.append($item);
				});
				$dropdown.append($heading).append($list);
				$searchColumns.append($toggle).append($dropdown);
				$filterWrapper.append($searchColumns);
			}

			function applyColumnSelection() {
				var selected = $dropdown.find('input[type="checkbox"]:checked').map(function() {
					return parseInt($(this).val(), 10);
				}).get();
				if (!selected.length) {
					selected = allColumnIndices();
					$dropdown.find('input[type="checkbox"]').prop('checked', true);
				}
				searchState.columns = selected;
				searchState.term = $searchInput.val() || '';
				table.draw();
				changed();
			}

			function openDropdown() {
				$dropdown.addClass('is-open');
				$toggle.attr('aria-expanded', 'true');
			}

			function closeDropdown() {
				$dropdown.removeClass('is-open');
				$toggle.attr('aria-expanded', 'false');
			}

			function handleDocumentClick(event) {
				if ($searchColumns.has(event.target).length === 0 && !$searchInput.is(event.target)) {
					closeDropdown();
				}
			}

			if (enableColumnPicker) {
				$toggle.on('click', function() {
					if ($dropdown.hasClass('is-open')) {
						closeDropdown();
					} else {
						openDropdown();
					}
				});
				$dropdown.on('change', 'input[type="checkbox"]', applyColumnSelection);
				$(document).on('click', handleDocumentClick);
			}

			var searchDebounce;
			$searchInput.on('input', function() {
				searchState.term = $searchInput.val() || '';
				clearTimeout(searchDebounce);
				searchDebounce = setTimeout(function() {
					table.search(searchState.term).draw();
					changed();
				}, 250);
			});

			table.on('search.dt', function(event, settings) {
				if (settings.nTable === options.$table[0]) {
					searchState.term = table.search() || '';
				}
			});

			scopedFilter = function(settings, data) {
				if (settings.nTable !== options.$table[0]) {
					return true;
				}
				var term = searchState.term;
				if (!term || !term.trim()) {
					return true;
				}
				var indices = searchState.columns.length ? searchState.columns : searchableColumnIndices;
				for (var index = 0; index < indices.length; index++) {
					var columnIndex = indices[index];
					if (columnIndex < data.length && utils.normalizeSearchText(data[columnIndex]).indexOf(term.toLowerCase()) !== -1) {
						return true;
					}
				}
				return false;
			};

			$.fn.dataTable.ext.search.push(scopedFilter);
			table.on('destroy', function() {
				var filterIndex = $.fn.dataTable.ext.search.indexOf(scopedFilter);
				if (filterIndex !== -1) {
					$.fn.dataTable.ext.search.splice(filterIndex, 1);
				}
				if (enableColumnPicker) {
					$(document).off('click', handleDocumentClick);
				}
			});

			if (searchState.term) {
				$searchInput.val(searchState.term);
				table.search(searchState.term).draw();
				changed();
			}
		}

		return {
			input: $searchInput,
			dropdown: function() {
				return $dropdown;
			},
			getState: function() {
				return searchState;
			},
			getColumns: function() {
				return searchableColumns;
			},
			hasScopedFilter: function() {
				return scopedFilter !== null;
			},
			reset: function() {
				if (!$searchInput.length) {
					return;
				}
				$searchInput.val('');
				table.search('');
				searchState.term = '';
				searchState.columns = allColumnIndices();
				$dropdown.find('input[type="checkbox"]').prop('checked', true);
			}
		};
	}

	window.BaraTablesSearch = { create: create };
})(jQuery);
