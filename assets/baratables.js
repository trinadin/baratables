(function() {
	var utils = window.BaraTablesUtils;

	function findWrapper(tableId) {
		var wrappers = document.querySelectorAll('.btbl-table-wrapper[data-table-id]');
		for (var i = 0; i < wrappers.length; i++) {
			if (wrappers[i].getAttribute('data-table-id') === String(tableId || '')) {
				return wrappers[i];
			}
		}
		return null;
	}

	function showRuntimeError(wrapper) {
		if (!wrapper) { return; }
		var message = wrapper.querySelector('.btbl-runtime-error');
		if (message) { message.hidden = false; }
	}

	function revealFailure(wrapper, error) {
		if (wrapper) {
			wrapper.classList.remove('is-loading');
			wrapper.classList.add('is-init-failed');
			showRuntimeError(wrapper);
		}
		if (window.console && console.error) {
			console.error('[BaraTables] Front-end initialization failed.', error || 'Required runtime unavailable.');
		}
	}

	if (!utils) {
		var revealUninitializedTables = function() {
			document.querySelectorAll('.btbl-table-wrapper:not(.is-chart-only)').forEach(function(wrapper) {
				revealFailure(wrapper, 'BaraTables utilities unavailable.');
			});
		};
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', revealUninitializedTables, {once: true});
		} else {
			revealUninitializedTables();
		}
		return;
	}
	var btblExtractText = utils.extractText;
	var btblEscapeHtml = utils.escapeHtml;
	var btblParseDate = utils.parseDate;
	var btblParseNumber = utils.parseNumber;
	var btblParseOptionalNumber = utils.parseOptionalNumber;
	var slugIdx = utils.slugIndex;
	var compactValues = utils.compactValues;
	var resolveLabelHtml = utils.resolveLabelHtml;
	var labelToPlainText = utils.labelToPlainText;
	// Read lazily: this module does not declare DataTables as a script dependency (chart-only
	// pages never load it), so its global may not exist when this file evaluates.
	function dataTablesLib() {
		return window.DataTable;
	}

	// API methods such as column().header() return raw elements, but defensive unwrapping
	// keeps the code correct if a build ever hands back a collection instead.
	function asNodeList(value) {
		if (!value) { return []; }
		if (value.nodeType) { return [value]; }
		if (typeof value.get === 'function') { value = value.get(); }
		if (Array.isArray(value)) { return value; }
		if (typeof value.length === 'number') { return Array.prototype.slice.call(value); }
		return [value];
	}

	// Registered lazily, NOT at parse time.
	//
	// Register the custom date type at table initialization, when DataTables is guaranteed to be
	// present. Making this file depend on DataTables would also load it on chart-only pages.
	var btblDateTypeRegistered = false;
	function btblRegisterDateType() {
		var ext = dataTablesLib() && dataTablesLib().ext;
		if (btblDateTypeRegistered || !ext || !ext.type) {
			return;
		}
		btblDateTypeRegistered = true;
		ext.type.detect.unshift(function(d) {
			var text = btblExtractText(d);
			// An empty cell must not veto the column's date type. DataTables lets a single
			// non-matching (null) detection downgrade the whole column (a detector is abandoned
			// on its first null in both 2.x and 3.x), so one blank row in an otherwise-date
			// column would drop it to string (alphabetical) sorting. Treat blank as neutral;
			// btbl-date-pre already maps empty/unparseable to 0 for ordering.
			if (!text) {
				return 'btbl-date';
			}
			// Bare numbers/decimals are NOT dates -- Date.parse() accepts "3.2", "12", "184000",
			// etc., which would mis-detect numeric columns and sort them by bogus timestamps.
			if (/^[+-]?\d+(\.\d+)?$/.test(text)) {
				return null;
			}
			// Neither are measurements. Date.parse() accepts "12.5%" (-> 5 Dec 2001) and "100%"
			// (-> 1 Jan 0100), so without this a percentage column containing one blank cell types
			// as a date and sorts by nonsense timestamps. Units bind tighter than the date guess.
			if (/^[+-]?[\d.,]+\s*(%|px|em|rem|pt|kg|g|lb|mm|cm|m|km|mi|ft|in)$/i.test(text)) {
				return null;
			}
			var parsed = btblParseDate(d);
			return parsed !== null ? 'btbl-date' : null;
		});
		ext.type.order['btbl-date-pre'] = function(d) {
			var parsed = btblParseDate(d);
			return parsed !== null ? parsed : 0;
		};
	}

	function btblTypedNumber(data) {
		if (typeof data !== 'string') { return null; }
		var match = data.match(/data-btbl-number=(['"])([+-]?(?:\d+(?:\.\d*)?|\.\d+))\1/);
		if (!match) { return null; }
		var value = Number(match[2]);
		return Number.isFinite(value) && Math.abs(value) <= 1000000000000 ? value : null;
	}

	var btblNumberTypeRegistered = false;
	function btblRegisterNumberType() {
		var ext = dataTablesLib() && dataTablesLib().ext;
		if (btblNumberTypeRegistered || !ext || !ext.type) { return; }
		btblNumberTypeRegistered = true;
		ext.type.order['btbl-number-pre'] = function(data) {
			var value = btblTypedNumber(data);
			// Empty and malformed display values sort after valid values in ascending order and
			// before them when the visitor reverses that order. They never become a fake zero.
			return value === null ? Number.POSITIVE_INFINITY : value;
		};
	}

	// Column filters apply a RegExp via column().search(regex), and the scoped search box owns
	// a native column-group search; DataTables cannot serialize the RegExp (JSON.stringify turns
	// it into {}, restoring as "[object Object]" and hiding every row), and both filter kinds
	// are always re-derived from the plugin's own controls or the URL. Strip them from the
	// persisted state and keep only sort, paging, length, and the plain global search. Run on
	// both save and load; the load pass also repairs any poisoned entry written by an older
	// plugin version, so returning visitors recover on their next page view without touching
	// localStorage.
	function sanitizeSavedTableState(data, maxPageLength, fallbackPageLength) {
		if (!data || typeof data !== 'object') {
			return;
		}
		if (Array.isArray(data.columns)) {
			data.columns.forEach(function(col) {
				if (col && typeof col === 'object') {
					// DataTables 3's canonical empty search (SearchOptions defaults).
					col.search = { search: '', regex: false, smart: true, caseInsensitive: true, exact: false, return: false };
				}
			});
		}
		// Native column-group searches persist under searchGroups; the URL owns the term.
		delete data.searchGroups;
		// ColReorder 2.x and 3.x persist under the lower-case key -- their stateSaveParams
		// writes `r.colReorder` and state restore reads it back from the loaded state. The
		// capitalised key is what ColReorder 1.x used, so delete both: one is the live contract,
		// the other repairs state left behind by an older bundle. Getting this wrong is silent
		// -- the delete simply misses, the order restores, and the filters (which address
		// columns by their ORIGINAL index via data-column/slugToIndex) then point at whatever
		// column moved into that slot.
		delete data.colReorder;
		delete data.ColReorder;
		if (maxPageLength) {
			if (Array.isArray(data.order) && data.order.length > 3) {
				data.order = data.order.slice(0, 3);
			}
			var savedTerm = data.search && typeof data.search.search === 'string' ? data.search.search : '';
			if (savedTerm && new TextEncoder().encode(savedTerm).length > 100) {
				data.search.search = '';
			}
			var length = parseInt(data.length, 10);
			if (!length || length < 1 || length > maxPageLength) {
				data.length = fallbackPageLength;
			}
			var start = parseInt(data.start, 10);
			if (isNaN(start) || start < 0 || start + parseInt(data.length, 10) > 100000) {
				data.start = 0;
			}
		}
	}

	function savedStateMatchesServerFirstPage(data, pageLength, order) {
		if (!data || typeof data !== 'object' || !Object.keys(data).length) { return true; }
		var search = data.search && typeof data.search.search === 'string' ? data.search.search : '';
		var savedOrder = Array.isArray(data.order) ? data.order : order;
		return (parseInt(data.start, 10) || 0) === 0 &&
			(parseInt(data.length, 10) || pageLength) === pageLength &&
			search === '' && JSON.stringify(savedOrder) === JSON.stringify(order);
	}

	function initChart(chartConfig, tableInstance, tableId, slugToIndex) {
		if (!window.BaraTablesCharts) {
			if (chartConfig && chartConfig.enabled) { showRuntimeError(findWrapper(tableId)); }
			return null;
		}
		try {
			var chart = window.BaraTablesCharts.init(chartConfig, tableInstance, tableId, slugToIndex, {
				extractText: btblExtractText,
				escapeHtml: btblEscapeHtml,
				parseDate: btblParseDate,
				parseNumber: btblParseNumber,
				parseOptionalNumber: btblParseOptionalNumber,
				slugIdx: slugIdx
			});
			if (chartConfig && chartConfig.enabled && !chart) { showRuntimeError(findWrapper(tableId)); }
			return chart;
		} catch (error) {
			showRuntimeError(findWrapper(tableId));
			if (window.console && console.error) { console.error('[BaraTables] Chart initialization failed.', error); }
			return null;
		}
	}
	function resolveTableOptions(tableOptions) {
		var source = tableOptions || {};
		var resolved = {};
		Object.keys(source).forEach(function(key) {
			resolved[key] = source[key];
		});
		['layoutTopStart', 'layoutTopEnd', 'layoutBottomStart', 'layoutBottomEnd'].forEach(function(key) {
			if (Object.prototype.hasOwnProperty.call(source, key)) {
				resolved[key] = Array.isArray(source[key]) ? source[key].slice() : [];
			}
		});
		return resolved;
	}

	function applyTableStyleClasses(tableEl, wrapper, config, options) {
		tableEl.classList.remove('display');
		var styleClasses = Array.isArray(config.tableClasses) ? config.tableClasses : [
			options.stripe !== false ? 'stripe' : '',
			options.rowBorder !== false ? 'row-border' : '',
			options.cellBorder !== false ? 'cell-border' : '',
			options.hover !== false ? 'hover' : '',
			options.orderColumn !== false ? 'order-column' : '',
			options.compact === true ? 'compact' : ''
		].filter(Boolean);
		['stripe', 'row-border', 'cell-border', 'hover', 'order-column', 'compact'].forEach(function(className) {
			tableEl.classList.toggle(className, styleClasses.indexOf(className) !== -1);
		});
		var isCompact = Object.prototype.hasOwnProperty.call(config, 'compact') ? config.compact === true : options.compact === true;
		if (wrapper) {
			wrapper.classList.toggle('is-compact', isCompact);
		}
	}

	function buildLayoutConfiguration(options, buttonList, searchFeatureItem) {
		// The vendored DataTables 3 bundle always supports the layout option, so controls are
		// placed through it; there is no legacy dom string to maintain alongside.
		var pagingConfig = {
			numbers: options.pagingNumbers !== false,
			firstLast: options.pagingFirstLast !== false,
			previousNext: options.pagingPreviousNext !== false
		};
		var layoutZones = {
			topStart: Array.isArray(options.layoutTopStart) ? options.layoutTopStart : [],
			topEnd: Array.isArray(options.layoutTopEnd) ? options.layoutTopEnd : [],
			bottomStart: Array.isArray(options.layoutBottomStart) ? options.layoutBottomStart : [],
			bottomEnd: Array.isArray(options.layoutBottomEnd) ? options.layoutBottomEnd : []
		};
		var layoutSeen = {};
		var buildLayoutZone = function(items) {
			var zoneItems = [];
			(items || []).forEach(function(item) {
				if (!item || layoutSeen[item]) {
					return;
				}
				var normalized = null;
				if (item === 'search' && options.searchBox !== false) {
					// BaraTables' own search control: same placement as the stock feature, but
					// scoped column-group searches and the column picker ride natively. Falls
					// back to DataTables' stock search when the module or its feature
					// registration is unavailable.
					normalized = searchFeatureItem || 'search';
				} else if (item === 'pagelength' && options.lengthChange !== false) {
					normalized = 'pageLength';
				} else if (item === 'buttons' && buttonList.length) {
					normalized = 'buttons';
				} else if (item === 'info' && options.info !== false) {
					normalized = 'info';
				} else if (item === 'paging' && options.paging !== false) {
					normalized = { paging: pagingConfig };
				}
				if (!normalized) {
					return;
				}
				layoutSeen[item] = true;
				zoneItems.push(normalized);
			});
			if (!zoneItems.length) {
				return null;
			}
			return zoneItems.length === 1 ? zoneItems[0] : zoneItems;
		};
		return {
			topStart: buildLayoutZone(layoutZones.topStart),
			topEnd: buildLayoutZone(layoutZones.topEnd),
			bottomStart: buildLayoutZone(layoutZones.bottomStart),
			bottomEnd: buildLayoutZone(layoutZones.bottomEnd)
		};
	}

	function makeColumnClass(slug, idx) {
		var base = String(slug || '');
		if (!base) { base = 'col-' + idx; }
		base = base.toLowerCase().replace(/[^a-z0-9_-]+/g, '-').replace(/-+/g, '-').replace(/^-+|-+$/g, '');
		if (!base) { base = 'col-' + idx; }
		return 'btbl-col btbl-col-' + base;
	}

	function buildColumnDefinitions(config) {
		var definitions = [];
		if (Array.isArray(config.hiddenColumns) && config.hiddenColumns.length) {
			definitions.push({ targets: config.hiddenColumns, visible: false });
		}
		if (Array.isArray(config.nonSortable) && config.nonSortable.length) {
			definitions.push({ targets: config.nonSortable, orderable: false });
		}
		if (Array.isArray(config.nonSearchable) && config.nonSearchable.length) {
			definitions.push({ targets: config.nonSearchable, searchable: false });
		}
		if (config.slugToIndex && typeof config.slugToIndex === 'object') {
			Object.keys(config.slugToIndex).forEach(function(slug) {
				var idx = config.slugToIndex[slug];
				if (idx !== null && idx !== undefined) {
					definitions.push({ targets: parseInt(idx, 10), className: makeColumnClass(slug, idx) });
				}
			});
		}
		if (config.typedColumns && typeof config.typedColumns === 'object') {
			Object.keys(config.typedColumns).forEach(function(index) {
				var column = config.typedColumns[index];
				if (column && column.data_type === 'number') {
					definitions.push({ targets: parseInt(index, 10), type: 'btbl-number' });
				}
			});
		}
		return definitions;
	}

	function buildButtonDefinitions(options, buttonList) {
		var definitions = [];
		var utilityDefinitions = [];
		var seen = {};
		var registry = {
			copy:       { extend: 'copyHtml5',  defaultText: 'Copy',              optionKey: 'buttonTextCopy' },
			csv:        { extend: 'csvHtml5',   defaultText: 'Export CSV',        optionKey: 'buttonTextCsv' },
			excel:      { extend: 'excelHtml5', defaultText: 'Export Excel',      optionKey: 'buttonTextExcel' },
			pdf:        { extend: 'pdfHtml5',   defaultText: 'Export PDF',        optionKey: 'buttonTextPdf' },
			print:      { extend: 'print',      defaultText: 'Print',             optionKey: 'buttonTextPrint' },
			colvis:     { extend: 'colvis',     defaultText: 'Column visibility', optionKey: 'buttonTextColvis' },
			pagelength: { extend: 'pageLength', defaultText: 'Page length',       optionKey: 'buttonTextPagelength' }
		};
		var keys = buttonList.map(function(button) {
			return typeof button === 'string' ? button.toLowerCase() : '';
		}).filter(Boolean);
		// With three or more toolbar controls (an export button alongside both utility
		// controls), the row gets crowded, so the column-visibility and page-length controls
		// fold into one native "Table options" dropdown. Buttons' collections nest, so both
		// keep their own submenus; below that threshold every control stays top level.
		var utilityKeys = ['colvis', 'pagelength'];
		var groupUtilities = utilityKeys.every(function(key) { return keys.indexOf(key) !== -1; })
			&& keys.some(function(key) { return key !== 'colvis' && key !== 'pagelength'; });
		buttonList.forEach(function(button) {
			var key = typeof button === 'string' ? button.toLowerCase() : '';
			if (!key || !registry[key] || seen[key]) { return; }
			seen[key] = true;
			var definition = { extend: registry[key].extend };
			if (['copy', 'csv', 'excel', 'pdf', 'print'].indexOf(key) !== -1) {
				definition.footer = false;
			}
			var buttonText = resolveLabelHtml(options[registry[key].optionKey] || '', registry[key].defaultText);
			if (buttonText !== '') { definition.text = buttonText; }
			(utilityKeys.indexOf(key) !== -1 ? utilityDefinitions : definitions).push(definition);
		});
		if (groupUtilities && utilityDefinitions.length === utilityKeys.length) {
			definitions.push({
				extend: 'collection',
				text: 'Table options',
				buttons: utilityDefinitions
			});
		} else {
			definitions = definitions.concat(utilityDefinitions);
		}
		return definitions;
	}

	function buildOrderDefinitions(config, slugToIndex, maxOrders) {
		var definitions = [];
		if (!Array.isArray(config.defaultOrder)) { return definitions; }
		var source = maxOrders ? config.defaultOrder.slice(0, maxOrders) : config.defaultOrder;
		source.forEach(function(item) {
			if (!item || typeof item.slug === 'undefined') { return; }
			var idx = slugIdx(slugToIndex, item.slug);
			if (idx !== null && idx !== undefined) {
				definitions.push([parseInt(idx, 10), item.direction === 'desc' ? 'desc' : 'asc']);
			}
		});
		return definitions;
	}

	function buildLanguageConfiguration(options) {
		var searchTextHtml = resolveLabelHtml(options.searchText, '');
		var searchTextPlain = labelToPlainText(searchTextHtml, '');
		var searchPlaceholderText = labelToPlainText(resolveLabelHtml(options.searchPlaceholder, ''), '');
		var language = { zeroRecords: '', emptyTable: '', searchPlaceholder: searchPlaceholderText, search: searchTextPlain };
		[
			['infoText', 'info'],
			['infoEmpty', 'infoEmpty'],
			['infoFiltered', 'infoFiltered']
		].forEach(function(keys) {
			var value = resolveLabelHtml(options[keys[0]], '');
			if (value) { language[keys[1]] = value; }
		});
		var paginate = {};
		[
			['paginateFirst', 'first'],
			['paginatePrevious', 'previous'],
			['paginateNext', 'next'],
			['paginateLast', 'last']
		].forEach(function(keys) {
			var value = resolveLabelHtml(options[keys[0]], '');
			if (value) { paginate[keys[1]] = value; }
		});
		if (Object.keys(paginate).length) { language.paginate = paginate; }

		var prefixHtml = resolveLabelHtml(options.lengthMenuPrefix, '');
		var prefixText = labelToPlainText(prefixHtml, '');
		var suffixHtml = resolveLabelHtml(options.lengthMenuSuffix, '');
		var suffixText = labelToPlainText(suffixHtml, '');
		var prefix = prefixHtml !== prefixText ? prefixHtml : prefixText;
		var suffix = suffixHtml !== suffixText ? suffixHtml : suffixText;
		language.lengthMenu = [prefix, '_MENU_', suffix].filter(function(part) { return part !== ''; }).join(' ');
		return {
			language: language,
			searchTextHtml: searchTextHtml,
			searchTextPlain: searchTextPlain,
			searchPlaceholderText: searchPlaceholderText
		};
	}

	function fixedHeaderOffset() {
		var adminBar = document.getElementById('wpadminbar');
		if (!adminBar || window.getComputedStyle(adminBar).position !== 'fixed') {
			return 0;
		}
		var rect = adminBar.getBoundingClientRect();
		return rect.bottom > 0 ? Math.max(0, Math.round(rect.bottom)) : 0;
	}

	function buildDataTableConfiguration(options, config, slugToIndex, languageResult, searchFeatureItem, searchController) {
		var buttonList = Array.isArray(options.buttons) ? options.buttons : [];
		var layout = buildLayoutConfiguration(options, buttonList, searchFeatureItem);
		var pageLength = parseInt(options.pageLength, 10);
		if (!pageLength || pageLength < 1) { pageLength = 25; }
		var scrollY = parseInt(options.scrollY, 10);
		if (isNaN(scrollY) || scrollY < 0 || options.scrollYEnabled === false) { scrollY = 0; }
		var fixedColumnsStart = parseInt(options.fixedColumnsStart, 10);
		if (isNaN(fixedColumnsStart) || fixedColumnsStart < 1) { fixedColumnsStart = 1; }
		var isServer = !!(config.serverSide && config.serverSide.url);
		var order = buildOrderDefinitions(config, slugToIndex, isServer ? 3 : 0);
		var tableConfig = {
			pageLength: pageLength,
			paging: options.paging !== false,
			ordering: options.ordering !== false,
			colReorder: options.colReorder === true,
			stateSave: options.stateSave === true,
			stateDuration: 0,
			stateSaveParams: function(settings, data) {
				sanitizeSavedTableState(data, isServer ? 100 : 0, pageLength);
			},
			stateLoadParams: function(settings, data) {
				sanitizeSavedTableState(data, isServer ? 100 : 0, pageLength);
				if (!isServer) { return; }
				var savedTerm = data && data.search && typeof data.search.search === 'string' ? data.search.search : '';
				if (searchController && searchController.adoptState) {
					searchController.adoptState(savedTerm);
				}
				// deferLoading is safe only while the restored state still describes the exact
				// server-rendered first page. Any other state must issue one authoritative draw.
				if (!savedStateMatchesServerFirstPage(data, pageLength, order)) {
					settings.deferLoading = false;
				}
			},
			autoWidth: options.autoWidth !== false,
			// The Responsive extension does not support horizontal scrolling; when responsive
			// stacking is on it takes over and scrollX is never sent to DataTables.
			scrollX: options.scrollX === true && options.responsive !== true,
			info: options.info !== false,
			lengthChange: options.lengthChange !== false,
			buttons: buildButtonDefinitions(options, buttonList),
			order: order,
			columnDefs: buildColumnDefinitions(config),
			language: languageResult.language,
			search: { search: '', smart: true },
			searchDelay: config.serverSide && config.serverSide.url ? 500 : 250
		};
		if (config.serverSide && config.serverSide.url) {
			var indexedSlugs = [];
			Object.keys(slugToIndex || {}).forEach(function(slug) {
				var index = parseInt(slugToIndex[slug], 10);
				if (!isNaN(index) && index >= 0) { indexedSlugs[index] = slug; }
			});
			tableConfig.serverSide = true;
			tableConfig.orderMulti = false;
			tableConfig.processing = true;
			var initialTerm = config.presetSearch && typeof config.presetSearch.term === 'string'
				? config.presetSearch.term
				: '';
			if (!initialTerm) {
				tableConfig.deferLoading = [
					parseInt(config.serverSide.recordsFiltered, 10) || 0,
					parseInt(config.serverSide.recordsTotal, 10) || 0
				];
			}
			tableConfig.columns = indexedSlugs.map(function(slug) { return {name: slug || ''}; });
			tableConfig.ajax = {
				url: config.serverSide.url,
				type: 'POST',
				data: function(data) {
					var state = searchController && searchController.getState
						? searchController.getState()
						: {term: '', columns: []};
					data.action = config.serverSide.action;
					data.table_id = config.serverSide.tableId;
					data.token = config.serverSide.token;
					data.btbl_search_term = state.term || '';
					data.btbl_search_columns = JSON.stringify((state.columns || []).map(function(index) {
						return indexedSlugs[parseInt(index, 10)] || '';
					}).filter(Boolean));
				}
			};
		}
		if (scrollY > 0) {
			tableConfig.scrollY = scrollY + 'px';
			tableConfig.scrollCollapse = options.scrollCollapse !== false;
		}
		// Only present when the option is on: DataTables core ignores the key entirely when
		// the Responsive extension is not loaded, so pages without it are unaffected.
		if (options.responsive === true) {
			tableConfig.responsive = true;
		}
		if (options.fixedHeader === true) {
			tableConfig.fixedHeader = { header: true, headerOffset: fixedHeaderOffset() };
		}
		// The server normalizes this relationship as well. Keeping the guard here makes the
		// compiler safe when called directly in tests or by third-party integrations with raw
		// options rather than the server-resolved payload.
		if (options.fixedColumnsEnabled === true && options.responsive !== true) {
			tableConfig.scrollX = true;
			tableConfig.fixedColumns = { start: fixedColumnsStart };
		}
		tableConfig.layout = layout;
		return tableConfig;
	}

	function formatAggregateNumber(value) {
		if (!Number.isFinite(value)) { return ''; }
		// Match PHP_ROUND_HALF_UP: ties round away from zero on both signs.
		var magnitude = Math.floor((Math.abs(value) * 1000000) + 0.5 + 0.000000001);
		var rounded = (value < 0 ? -magnitude : magnitude) / 1000000;
		if (Math.abs(rounded) < 0.0000005) { rounded = 0; }
		return rounded.toFixed(6).replace(/\.?0+$/, '');
	}

	function updateTypedFooters(table, tableEl, typedColumns) {
		if (!typedColumns || typeof typedColumns !== 'object') { return; }
		var aggregateIndexes = Object.keys(typedColumns).filter(function(index) {
			var aggregate = typedColumns[index] && typedColumns[index].footer_aggregate;
			return ['sum', 'average', 'min', 'max'].indexOf(aggregate) !== -1;
		});
		if (!aggregateIndexes.length) { return; }
		var stats = {};
		aggregateIndexes.forEach(function(index) {
			stats[index] = {sum: 0, count: 0, min: Number.POSITIVE_INFINITY, max: Number.NEGATIVE_INFINITY};
		});
		var rowData = table.rows({search: 'applied'}).data();
		var rows = rowData && typeof rowData.toArray === 'function' ? rowData.toArray() : Array.from(rowData || []);
		rows.forEach(function(row) {
			aggregateIndexes.forEach(function(index) {
				// DataTables 3 keeps API row arrays in their original data order when ColReorder
				// moves presentation columns. The browser acceptance test filters after a move and
				// proves this original index still produces the logical column's hardcoded total.
				var value = btblTypedNumber(row[parseInt(index, 10)]);
				if (value === null) { return; }
				var stat = stats[index];
				stat.sum += value;
				stat.count += 1;
				stat.min = Math.min(stat.min, value);
				stat.max = Math.max(stat.max, value);
			});
		});
		aggregateIndexes.forEach(function(index) {
			var stat = stats[index];
			var aggregate = typedColumns[index].footer_aggregate;
			var result = null;
			if (stat.count) {
				if (aggregate === 'sum') { result = stat.sum; }
				if (aggregate === 'average') { result = stat.sum / stat.count; }
				if (aggregate === 'min') { result = stat.min; }
				if (aggregate === 'max') { result = stat.max; }
			}
			var text = result === null ? '' : formatAggregateNumber(result);
			var footerRoot = tableEl.closest('.btbl-table-wrapper') || tableEl;
			footerRoot.querySelectorAll('tfoot [data-btbl-footer-column="' + index + '"]').forEach(function(footer) {
				footer.textContent = text;
				var label = footer.getAttribute('data-btbl-aggregate-label') || '';
				footer.setAttribute('aria-label', label + (text ? ': ' + text : ''));
			});
		});
	}

	function syncResponsiveTypedStyles(wrapper) {
		if (!wrapper) { return; }
		var presets = ['accent', 'success', 'warning', 'danger'];
		// AJAX rows carry the trusted numeric marker inside the cell, while server-rendered rows
		// also have the preset class on <td>. Mirror it after every draw so both paths have the
		// same cell background, including FixedColumns' cloned cells.
		wrapper.querySelectorAll('tbody td').forEach(function(cell) {
			presets.forEach(function(preset) { cell.classList.remove('btbl-condition-' + preset); });
			var marker = cell.querySelector('.btbl-number-marker');
			if (!marker) { return; }
			presets.some(function(preset) {
				if (!marker.classList.contains('btbl-condition-' + preset)) { return false; }
				cell.classList.add('btbl-condition-' + preset);
				return true;
			});
		});
		wrapper.querySelectorAll('.dtr-data').forEach(function(container) {
			presets.forEach(function(preset) { container.classList.remove('btbl-condition-' + preset); });
			var marker = container.querySelector('.btbl-number-marker');
			if (!marker) { return; }
			presets.some(function(preset) {
				if (!marker.classList.contains('btbl-condition-' + preset)) { return false; }
				container.classList.add('btbl-condition-' + preset);
				return true;
			});
		});
	}

	function initInstanceUnsafe(config) {
		if (!config || !config.tableId) {
			return;
		}
		var tableId = config.tableId;
		var presetSearch = config.presetSearch || {};
		var presetSearchTerm = typeof presetSearch.term === 'string' ? presetSearch.term : '';
		var presetSearchColumns = Array.isArray(presetSearch.columns) ? presetSearch.columns : [];
		var tableEl = document.getElementById('btbl-table-' + tableId);
		var wrapper = tableEl ? tableEl.closest('.btbl-table-wrapper') : null;
		if (!wrapper) {
			var chartEl = document.getElementById('btbl-chart-' + tableId);
			wrapper = chartEl ? chartEl.closest('.btbl-table-wrapper') : null;
		}
		if (!wrapper) {
			wrapper = document.querySelector('.btbl-table-wrapper[data-table-id="' + tableId + '"]');
		}
		var slugToIndex = config.slugToIndex || {};
		// The btbl_search value this instance last wrote, or null when it does not own the param.
		// Tracking the VALUE rather than a boolean matters: btbl_search is page-wide and carries
		// no table qualifier, so a second table searching silently takes it over. A boolean left
		// this table still believing it was the owner, and its next sync deleted the other
		// table's term out of the shareable link. Seeded from the server's preset, which is ours
		// to clear. See syncStateToUrl().
		var ownedSearchTerm = presetSearchTerm !== '' ? presetSearchTerm : null;
		function markReady() {
			if (wrapper) {
				wrapper.classList.remove('is-loading');
			}
		}

		var DataTableLib = dataTablesLib();
		if (!tableEl || typeof DataTableLib !== 'function') {
			if (window.console && console.warn) {
				console.warn('[BaraTables] DataTables unavailable for table ' + tableId);
			}
			markReady();
			return;
		}

		// PHP supplies the complete resolved option set; the compiler below only translates it
		// into DataTables' configuration and presentation classes.
		var resolvedOptions = resolveTableOptions(config.tableOptions || {});
		applyTableStyleClasses(tableEl, wrapper, config, resolvedOptions);
		var languageResult = buildLanguageConfiguration(resolvedOptions);
		var searchTextHtml = languageResult.searchTextHtml;
		var searchTextPlain = languageResult.searchTextPlain;
		var searchPlaceholderText = languageResult.searchPlaceholderText;
		var indexToSlug = {};
		Object.keys(slugToIndex).forEach(function(slug) {
			if (slugToIndex[slug] !== null && slugToIndex[slug] !== undefined) {
				indexToSlug[slugToIndex[slug]] = slug;
			}
		});

		var nonSearchableSet = {};
		if (Array.isArray(config.nonSearchable)) {
			config.nonSearchable.forEach(function(idx) {
				nonSearchableSet[idx] = true;
			});
		}

		// Created before init so its layout item can take the stock search feature's place in
		// the toolbar; the table constructor calls back into it while building the layout.
		// A missing controller module is an init failure (the server table is revealed), not a
		// silently degraded table -- callers depend on that contract.
		var searchController = window.BaraTablesSearch.create({
			tableId: tableId,
			resolvedOptions: resolvedOptions,
			slugToIndex: slugToIndex,
			nonSearchable: nonSearchableSet,
			presetTerm: presetSearchTerm,
			presetColumns: presetSearchColumns,
			labelHtml: searchTextHtml,
			labelPlain: searchTextPlain,
			placeholder: searchPlaceholderText,
			debounceDelay: config.serverSide && config.serverSide.url ? 500 : 250,
			maxSearchBytes: config.serverSide && config.serverSide.url ? 100 : 0,
			onChange: function(state) {
				syncStateToUrl(null, state);
			}
		});

		var searchFeatureItem = null;
		if (searchController && window.BaraTablesSearch.registerFeature && window.BaraTablesSearch.registerFeature()) {
			// Layout items map a feature name to its options: the controller itself is the
			// registered feature's opts, so each table's instance is bound to its own
			// controller.
			searchFeatureItem = { btblSearch: searchController };
		}
		var tableConfig = buildDataTableConfiguration(resolvedOptions, config, slugToIndex, languageResult, searchFeatureItem, searchController);
		btblRegisterDateType();
		btblRegisterNumberType();
		if (typeof DataTableLib.isDataTable === 'function' && DataTableLib.isDataTable(tableEl)) {
			// A page builder or AJAX swap can re-run the bootstrap while the previous table is
			// still live. DataTables refuses to reinitialise (a modal alert at the visitor, by
			// default) and its half-built return would fall into the catch below, destroying a
			// table that was working. Tear down first; the chart bootstrap supports re-runs too.
			new DataTableLib.Api(tableEl).destroy();
		}
		var table = new DataTableLib(tableEl, tableConfig);
		if (config.serverSide && config.serverSide.url) {
			table.on('xhr', function(event, settings, json) {
				if (!json || json.error) { showRuntimeError(wrapper); }
			});
		}
		var refreshTypedFooters = function() {
			updateTypedFooters(table, tableEl, config.typedColumns || {});
		};
		table.on('draw', refreshTypedFooters);
		refreshTypedFooters();
		table.on('draw responsive-display', function() {
			syncResponsiveTypedStyles(wrapper);
		});
		syncResponsiveTypedStyles(wrapper);
		if (resolvedOptions.fixedHeader === true && table.fixedHeader && typeof table.fixedHeader.headerOffset === 'function') {
			// WordPress changes the admin bar from fixed to absolute at narrow widths. Recompute
			// after a responsive viewport change, and remove the listener with the table so AJAX
			// reinits do not accumulate handlers.
			var syncFixedHeaderOffset = function() {
				table.fixedHeader.headerOffset(fixedHeaderOffset());
			};
			window.addEventListener('resize', syncFixedHeaderOffset);
			table.on('destroy', function() {
				window.removeEventListener('resize', syncFixedHeaderOffset);
			});
		}
		if (searchController) {
			searchController.attach(table);
		}
		var searchInput = searchController ? searchController.inputEl() : null;
		var searchState = searchController ? searchController.getState() : { term: '', columns: [] };
		var searchableColumns = searchController ? searchController.getColumns() : [];

		// Admin-hidden columns carry an inline style="display:none" so they stay hidden during the
		// pre-init render (before columnDefs {visible:false} takes over). Once DataTables owns
		// visibility, that inline style is stale AND breaks the Column-visibility button: showing
		// the column re-attaches the original cell nodes verbatim, so they stay display:none. Clear
		// it from the header + (possibly detached) cell nodes so colvis can actually reveal them.
		if (Array.isArray(config.hiddenColumns)) {
			config.hiddenColumns.forEach(function(colIdx) {
				var column = table.column(colIdx);
				if (!column) {
					return;
				}
				asNodeList(column.header()).forEach(function(node) {
					node.style.display = '';
				});
				asNodeList(column.nodes()).forEach(function(node) {
					node.style.display = '';
				});
				if (typeof column.footer === 'function') {
					asNodeList(column.footer()).forEach(function(node) {
						node.style.display = '';
					});
				}
			});
		}

		var stripeEnabled = resolvedOptions.stripe !== false;
		tableEl.classList.toggle('btbl-has-stripes', stripeEnabled);
		tableEl.classList.toggle('btbl-no-stripes', !stripeEnabled);

		var presetFilters = config.presetFilters || {};
		var filterController = null;
		var emptyState = wrapper ? wrapper.querySelector('.btbl-empty-state') : null;

		function toggleEmptyState() {
			var hasRows = table.rows({ search: 'applied' }).data().length > 0;
			if (emptyState) {
				// The stylesheet defaults this element to display:none, so the visible state
				// must be explicit rather than clearing the inline value.
				emptyState.style.display = hasRows ? 'none' : 'block';
			}
		}

		table.on('draw', toggleEmptyState);
		function getActiveFilters() {
			if (filterController) {
				return filterController.readActive();
			}
			return window.BaraTablesFilters.readActive(wrapper);
		}

		function updateFilterStateClass(activeFilters, activeSearchState) {
			var filters = activeFilters || getActiveFilters();
			var hasFilters = Object.keys(filters).some(function(key) {
				var val = filters[key];
				if (Array.isArray(val)) {
					return val.some(function(item) {
						return item !== null && item !== undefined && item !== '';
					});
				}
				return val !== null && val !== undefined && val !== '' && val !== '__all';
			});
			var term = activeSearchState && activeSearchState.term ? activeSearchState.term : '';
			if (!term && searchInput) {
				term = searchInput.value || '';
			} else if (searchState && searchState.term) {
				term = searchState.term;
			} else {
				term = table.search() || '';
			}
			var hasSearch = term && term.trim();
			if (wrapper) {
				wrapper.classList.toggle('is-filtered', !!(hasFilters || hasSearch));
			}
		}

		function syncStateToUrl(activeFilters, activeSearchState) {
			var filters = activeFilters || getActiveFilters();
			var currentSearch = activeSearchState || (searchController ? searchController.getState() : searchState);
			var url = new URL(window.location.href);
			// Only clear the params this table owns. Deleting every btbl_filter[...] key meant
			// a second table could wipe the first one's filters out of the shareable URL.
			var keysToDelete = [];
			url.searchParams.forEach(function(_, key) {
				if (key.indexOf('btbl_filter[') !== 0) {
					return;
				}
				var slug = key.slice('btbl_filter['.length, -1);
				if (Object.prototype.hasOwnProperty.call(slugToIndex, slug)) {
					keysToDelete.push(key);
				}
			});
			keysToDelete.forEach(function(key) {
				url.searchParams.delete(key);
			});
			// btbl_search is page-wide. Clear it only while it still holds this table's value.
			if (ownedSearchTerm !== null && url.searchParams.get('btbl_search') === ownedSearchTerm) {
				url.searchParams.delete('btbl_search');
				url.searchParams.delete('btbl_search_cols');
			}
			ownedSearchTerm = null;

			Object.keys(filters).forEach(function(slug) {
				var values = compactValues(Array.isArray(filters[slug]) ? filters[slug] : [filters[slug]]);
				if (values.length) {
					url.searchParams.append('btbl_filter[' + slug + ']', values.join(','));
				}
			});

			var term = currentSearch.term || '';
			var selectedColumns = Array.isArray(currentSearch.columns) ? currentSearch.columns : searchableColumns.map(function(col) { return col.index; });
			if (term && term.trim()) {
				ownedSearchTerm = term;
				url.searchParams.set('btbl_search', term);
				var selectedSlugs = selectedColumns.map(function(idx) {
					return slugIdx(indexToSlug, idx);
				}).filter(function(slug) {
					return !!slug;
				});
				if (selectedSlugs.length && selectedSlugs.length !== searchableColumns.length) {
					url.searchParams.set('btbl_search_cols', selectedSlugs.join(','));
				}
			}

			try {
				window.history.replaceState({}, '', url.toString());
			} catch (e) {
				// Safari can throttle replaceState. URL sync must never break the controls.
			}
			updateFilterStateClass(filters, currentSearch);
		}

		filterController = window.BaraTablesFilters.create({
			wrapper: wrapper,
			table: table,
			presets: presetFilters,
			onChange: syncStateToUrl
		});

		var resetButton = wrapper ? wrapper.querySelector('.btbl-reset-button') : null;
		if (resetButton) {
			resetButton.addEventListener('click', function() {
				table.columns().search('');
				if (searchController) {
					searchController.reset();
				}
				if (filterController) {
					filterController.reset();
				}
				table.draw();
				syncStateToUrl();
			});
		}

		var needsInitialDraw = filterController ? filterController.needsInitialDraw() : false;
		if (needsInitialDraw) {
			table.draw(false);
			syncStateToUrl();
		} else {
			toggleEmptyState();
			updateFilterStateClass();
		}
		var chartInstance = initChart(config.chart, table, tableId, slugToIndex);
		if (chartInstance) {
			table.on('destroy', function() {
				if (chartInstance.__btblResize) {
					window.removeEventListener('resize', chartInstance.__btblResize);
				}
				if (chartInstance.dispose) {
					chartInstance.dispose();
				}
			});
		}
		markReady();
	}

	function initInstance(config) {
		try {
			initInstanceUnsafe(config);
		} catch (error) {
			var tableId = config && config.tableId ? config.tableId : '';
			var tableElement = tableId ? document.getElementById('btbl-table-' + tableId) : null;
			try {
				var lib = dataTablesLib();
				if (lib && lib.isDataTable && lib.isDataTable(tableElement)) {
					new lib.Api(tableElement).destroy();
				}
			} catch (destroyError) {
				// The recovery path still reveals the server-rendered wrapper below.
			}
			revealFailure(findWrapper(tableId), error);
		}
	}

	function bootQueue() {
		var queue = Array.isArray(window.BaraTablesFrontendQueue) ? window.BaraTablesFrontendQueue : [];
		function drain() {
			while (queue.length) {
				initInstance(queue.shift());
			}
		}
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', drain, {once: true});
		} else {
			drain();
		}
		window.BaraTablesFrontendQueue = {
			push: function(cfg) {
				initInstance(cfg);
			}
		};
	}

	bootQueue();

	window.BaraTablesFrontend = {
		init: initInstance
	};
})();
