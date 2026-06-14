(function($) {
	function btblExtractText(data) {
		if (data === null || data === undefined) {
			return '';
		}
		if (typeof data === 'number') {
			return data.toString();
		}
		if (typeof data !== 'string') {
			return '';
		}
		return data.replace(/<[^>]*?>/g, ' ').trim();
	}

	function btblEscapeHtml(value) {
		return String(value === null || value === undefined ? '' : value)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#039;');
	}

	function btblParseDate(value) {
		var text = btblExtractText(value);
		if (!text) {
			return null;
		}
		var parsed = Date.parse(text);
		if (isNaN(parsed)) {
			return null;
		}
		return parsed;
	}

	if ($.fn.dataTable && $.fn.dataTable.ext) {
		$.fn.dataTable.ext.type.detect.unshift(function(d) {
			var parsed = btblParseDate(d);
			return parsed !== null ? 'btbl-date' : null;
		});
		$.fn.dataTable.ext.type.order['btbl-date-pre'] = function(d) {
			var parsed = btblParseDate(d);
			return parsed !== null ? parsed : 0;
		};
	}

	function escapeRegex(text) {
		return text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
	}

	function buildMultiValuePattern(values) {
		var cleaned = [];
		function pushVal(val) {
			if (Array.isArray(val)) {
				val.forEach(pushVal);
				return;
			}
			if (val === null || val === undefined) {
				return;
			}
			cleaned.push(String(val));
		}
		pushVal(values);

		if (!cleaned.length) {
			return '';
		}

		var parts = cleaned.map(function(val) {
			if (val === '') {
				return '^\\s*$';
			}
			return '(?:^|,\\s*)' + escapeRegex(val) + '(?:\\s*,|$)';
		});

		if (parts.length === 1) {
			return parts[0];
		}

		return '(?:' + parts.join('|') + ')';
	}

	function buildSearchRegex(pattern) {
		if (!pattern) {
			return null;
		}
		try {
			return new RegExp(pattern, 'i');
		} catch (e) {
			return null;
		}
	}

	function applyColumnSearch(column, pattern, smart) {
		var regex = buildSearchRegex(pattern);
		if (regex) {
			return column.search(regex);
		}
		return column.search(pattern, true, smart);
	}

	function normalizeSearchText(value) {
		if (value === null || value === undefined) {
			return '';
		}
		return String(value).replace(/<[^>]*?>/g, ' ').replace(/\s+/g, ' ').trim().toLowerCase();
	}

	function btblParseNumber(value) {
		var text = btblExtractText(value);
		if (text === '') {
			return 0;
		}
		var num = parseFloat(text.replace(/[^0-9.+\-eE]/g, ''));
		return isNaN(num) ? 0 : num;
	}

	function initChart(chartConfig, tableInstance, tableId, slugToIndex) {
		if (!chartConfig || !chartConfig.enabled || !window.echarts) {
			return null;
		}
		var container = document.getElementById('btbl-chart-' + tableId);
		if (!container) {
			return null;
		}

		var columnsMeta = {};
		if (Array.isArray(chartConfig.columns)) {
			chartConfig.columns.forEach(function(col) {
				if (col && col.slug) {
					columnsMeta[col.slug] = col.label || col.slug;
				}
			});
		}

		function buildSeriesData() {
			var rows = [];
			if (tableInstance && tableInstance.rows) {
				var data = tableInstance.rows({ search: 'applied' }).data();
				if (data && data.toArray) {
					rows = data.toArray();
				}
			} else if (Array.isArray(chartConfig.rows)) {
				rows = chartConfig.rows;
			}

			var xSlug = chartConfig.x_axis || chartConfig.xAxis;
			var xIdx = slugIdx(slugToIndex, xSlug);
			if (xIdx === null || xIdx === undefined) {
				return null;
			}

			var seriesSlugs = Array.isArray(chartConfig.series) ? chartConfig.series : [];
			if (!seriesSlugs.length && Array.isArray(chartConfig.columns)) {
				chartConfig.columns.some(function(col) {
					if (col.slug && col.slug !== xSlug) {
						seriesSlugs.push(col.slug);
						return true;
					}
					return false;
				});
			}
			if (!seriesSlugs.length) {
				return null;
			}

			var categories = [];
			var dataMap = {};
			seriesSlugs.forEach(function(slug) {
				dataMap[slug] = [];
			});

			rows.forEach(function(row) {
				var category = xIdx < row.length ? btblExtractText(row[xIdx]) : '';
				categories.push(category);
				seriesSlugs.forEach(function(slug) {
					var idx = slugIdx(slugToIndex, slug);
					var val = idx !== null && idx !== undefined && idx < row.length ? btblParseNumber(row[idx]) : 0;
					dataMap[slug].push(val);
				});
			});

			return {
				categories: categories,
				data: dataMap,
				seriesSlugs: seriesSlugs,
				xSlug: xSlug
			};
		}

		function parseGanttDate(value) {
			var text = btblExtractText(value);
			if (!text) {
				return { time: null, hasYear: false };
			}
			var hasYear = /\b\d{4}\b/.test(text);
			var parsed = btblParseDate(text);
			if (parsed !== null) {
				return { time: parsed, hasYear: hasYear };
			}
			if (!hasYear) {
				var fallback = Date.parse(text + ' ' + (new Date().getFullYear()));
				if (!isNaN(fallback)) {
					return { time: fallback, hasYear: false };
				}
			}
			return { time: null, hasYear: hasYear };
		}

		function buildGanttData() {
			var rows = [];
			if (tableInstance && tableInstance.rows) {
				var data = tableInstance.rows({ search: 'applied' }).data();
				if (data && data.toArray) {
					rows = data.toArray();
				}
			} else if (Array.isArray(chartConfig.rows)) {
				rows = chartConfig.rows;
			}

			var labelSlug = chartConfig.gantt_label || chartConfig.ganttLabel;
			var startSlug = chartConfig.gantt_start || chartConfig.ganttStart;
			var endSlug = chartConfig.gantt_end || chartConfig.ganttEnd;
			if (!labelSlug || !startSlug || !endSlug) {
				return null;
			}

			var labelIdx = slugIdx(slugToIndex, labelSlug);
			var startIdx = slugIdx(slugToIndex, startSlug);
			var endIdx = slugIdx(slugToIndex, endSlug);
			if (labelIdx === null || startIdx === null || endIdx === null) {
				return null;
			}

			var groupSlug = chartConfig.gantt_group || chartConfig.ganttGroup;
			var progressSlug = chartConfig.gantt_progress || chartConfig.ganttProgress;
			var groupIdx = slugIdx(slugToIndex, groupSlug);
			var progressIdx = slugIdx(slugToIndex, progressSlug);

			var categories = [];
			var categoryIndex = {};
			var palette = ['#5470C6', '#91CC75', '#EE6666', '#73C0DE', '#FAC858', '#9A60B4', '#EA7CCC'];
			var groupColor = {};

			function getGroupColor(group) {
				if (!group) {
					return null;
				}
				if (groupColor[group]) {
					return groupColor[group];
				}
				var idx = Object.keys(groupColor).length % palette.length;
				groupColor[group] = palette[idx];
				return groupColor[group];
			}

			var items = [];
			rows.forEach(function(row) {
				var label = labelIdx < row.length ? btblExtractText(row[labelIdx]) : '';
				if (!label) {
					return;
				}
				var startParsed = startIdx < row.length ? parseGanttDate(row[startIdx]) : { time: null, hasYear: false };
				var endParsed = endIdx < row.length ? parseGanttDate(row[endIdx]) : { time: null, hasYear: false };
				var start = startParsed.time;
				var end = endParsed.time;
				if (start === null || end === null) {
					return;
				}
				if (end <= start && !startParsed.hasYear && !endParsed.hasYear) {
					var endDateObj = new Date(end);
					endDateObj.setFullYear(endDateObj.getFullYear() + 1);
					end = endDateObj.getTime();
				}
				if (end <= start) {
					return;
				}
				if (!Object.prototype.hasOwnProperty.call(categoryIndex, label)) {
					categoryIndex[label] = categories.length;
					categories.push(label);
				}
				var groupVal = groupIdx !== null && groupIdx < row.length ? btblExtractText(row[groupIdx]) : '';
				var progressVal = null;
				if (progressIdx !== null && progressIdx < row.length) {
					var progressText = btblExtractText(row[progressIdx]);
					if (progressText !== '') {
						var parsedProgress = parseFloat(progressText.replace(/[^0-9.+\-eE]/g, ''));
						if (!isNaN(parsedProgress)) {
							progressVal = Math.min(100, Math.max(0, parsedProgress));
						}
					}
				}
				var color = getGroupColor(groupVal);
				items.push({
					name: label,
					value: [categoryIndex[label], start, end, progressVal],
					group: groupVal,
					progress: progressVal,
					itemStyle: color ? { color: color } : undefined
				});
			});

			if (!items.length) {
				return null;
			}

			return {
				categories: categories,
				items: items
			};
		}

		var chart = echarts.init(container);

		function render() {
			var type = chartConfig.type || 'bar';
			if (type === 'gantt') {
				var ganttPrepared = buildGanttData();
				if (!ganttPrepared) {
					return;
				}
				var ganttOption = {
					tooltip: {
						formatter: function(params) {
							var data = params.data || {};
							var vals = params.value || [];
							var start = vals[1] ? new Date(vals[1]) : null;
							var end = vals[2] ? new Date(vals[2]) : null;
							var lines = [];
							lines.push(btblEscapeHtml(params.name || ''));
							if (data.group) {
								lines.push(btblEscapeHtml(data.group));
							}
							if (start) {
								lines.push('Start: ' + btblEscapeHtml(start.toLocaleString()));
							}
							if (end) {
								lines.push('End: ' + btblEscapeHtml(end.toLocaleString()));
							}
							if (data.progress !== null && data.progress !== undefined && !isNaN(data.progress)) {
								lines.push('Progress: ' + btblEscapeHtml(data.progress) + '%');
							}
							return lines.join('<br/>');
						}
					},
					grid: { containLabel: true, left: '3%', right: '4%', bottom: '3%' },
					xAxis: { type: 'time' },
					yAxis: { type: 'category', data: ganttPrepared.categories, inverse: true },
					series: [{
						type: 'custom',
						renderItem: function(params, api) {
							var categoryIndexVal = api.value(0);
							var startCoord = api.coord([api.value(1), categoryIndexVal]);
							var endCoord = api.coord([api.value(2), categoryIndexVal]);
							var barHeight = api.size([0, 1])[1] * 0.6;
							return {
								type: 'rect',
								shape: {
									x: startCoord[0],
									y: startCoord[1] - barHeight / 2,
									width: endCoord[0] - startCoord[0],
									height: barHeight
								},
								style: api.style()
							};
						},
						encode: { x: [1, 2], y: 0 },
						data: ganttPrepared.items
					}]
				};
				chart.setOption(ganttOption, true);
				return;
			}
			var prepared = buildSeriesData();
			if (!prepared) {
				return;
			}
			var isArea = type === 'area';
			var seriesType = isArea ? 'line' : type;
			var option = {
				tooltip: { trigger: type === 'pie' ? 'item' : 'axis' },
				legend: {},
			};

			if (type === 'pie') {
				var seriesSlug = prepared.seriesSlugs[0];
				var label = columnsMeta[seriesSlug] || seriesSlug;
				var pieData = prepared.categories.map(function(cat, idx) {
					return { name: cat, value: prepared.data[seriesSlug][idx] };
				});
				option.series = [{
					type: 'pie',
					name: label,
					data: pieData,
					emphasis: { focus: 'data' }
				}];
			} else {
				option.xAxis = { type: 'category', data: prepared.categories };
				option.yAxis = { type: 'value' };
				option.series = prepared.seriesSlugs.map(function(slug) {
					return {
						type: seriesType,
						name: columnsMeta[slug] || slug,
						data: prepared.data[slug],
						stack: chartConfig.stack ? 'total' : undefined,
						areaStyle: (seriesType === 'line' && (chartConfig.stack || isArea)) ? {} : undefined
					};
				});
			}

			chart.setOption(option, true);
		}

		render();

		if (tableInstance && tableInstance.on) {
			tableInstance.on('draw', render);
		}

		var resizeTimer;
		window.addEventListener('resize', function() {
			clearTimeout(resizeTimer);
			resizeTimer = setTimeout(function() { chart.resize(); }, 150);
		});

		return chart;
	}

	function getSearchTermsFromElement($el, fallback) {
		if (!$el || !$el.length) {
			return Array.isArray(fallback) ? fallback : (fallback !== undefined ? [fallback] : []);
		}
		var data = $el.data('searchTerms');
		if (typeof data === 'string') {
			try {
				data = JSON.parse(data);
			} catch (e) {
				data = data.split(',').map(function(val) {
					return val.trim();
				});
			}
		}
		if (!Array.isArray(data)) {
			data = [data !== undefined ? data : fallback];
		}
		return data.filter(function(val) {
			return val !== undefined && val !== null;
		}).map(function(val) {
			return String(val);
		});
	}

		function slugIdx(map, slug) {
			return Object.prototype.hasOwnProperty.call(map || {}, slug) ? map[slug] : null;
		}

		function filterByInputValue($items, value) {
			value = String(value);
			return $items.filter(function() {
				return String($(this).val()) === value;
			});
		}

		function initInstance(config) {
		if (!config || !config.tableId) {
			return;
		}
		var tableId = config.tableId;
		var presetSearch = config.presetSearch || {};
		var presetSearchTerm = typeof presetSearch.term === 'string' ? presetSearch.term : '';
		var presetSearchColumns = Array.isArray(presetSearch.columns) ? presetSearch.columns : [];
		var $table = $('#btbl-table-' + tableId);
		var $wrapper = $table.closest('.btbl-table-wrapper');
		if (!$wrapper.length) {
			$wrapper = $('#btbl-chart-' + tableId).closest('.btbl-table-wrapper');
		}
		if (!$wrapper.length) {
			$wrapper = $('.btbl-table-wrapper[data-table-id="' + tableId + '"]');
		}
		var slugToIndex = config.slugToIndex || {};
		
		function resolveLabelHtml(value, fallback) {
			var label = '';
			if (value !== null && value !== undefined) {
				label = String(value);
			}
			if (label.trim) {
				label = label.trim();
			}
			if (label === '' && typeof fallback !== 'undefined') {
				label = String(fallback);
			}
			return label;
		}

		function labelToPlainText(value, fallback) {
			var html = resolveLabelHtml(value, typeof fallback !== 'undefined' ? fallback : '');
			if (html === '') {
				return '';
			}
			var text = html.replace(/<[^>]*?>/g, ' ').replace(/\s+/g, ' ').trim();
			return text;
		}

		function markReady() {
			if ($wrapper && $wrapper.length) {
				$wrapper.removeClass('is-loading');
			}
		}

		if (config.chartOnly && config.chart && config.chart.enabled) {
			initChart(config.chart, null, tableId, slugToIndex);
			markReady();
			return;
		}

		if (!$table.length || !$table.DataTable) {
			if (window.console && console.warn) {
				console.warn('[BaraTables] DataTables unavailable for table ' + tableId);
			}
			markReady();
			return;
		}

		var tableOptions = config.tableOptions || {};
		var resolvedOptions = $.extend(true, {
			paging: true,
			lengthChange: true,
			searchBox: true,
			ordering: true,
			colReorder: false,
			info: true,
			stripe: true,
			rowBorder: true,
			cellBorder: false,
			hover: true,
			orderColumn: true,
			compact: false,
			buttons: [],
			pageLength: 25,
			searchText: '',
			infoText: '',
			infoEmpty: '',
			infoFiltered: '',
			searchPlaceholder: '',
			lengthMenuPrefix: 'Show',
			lengthMenuSuffix: 'entries',
			pagingNumbers: true,
			pagingFirstLast: true,
			pagingPreviousNext: true,
			paginateFirst: '',
			paginatePrevious: '',
			paginateNext: '',
			paginateLast: '',
			searchColumns: true,
			searchColumnsLabel: '',
			searchColumnsHeading: '',
			buttonTextCopy: '',
			buttonTextCsv: '',
			buttonTextPrint: '',
			buttonTextColvis: '',
			buttonTextPagelength: '',
			layoutTopStart: ['pagelength', 'buttons'],
			layoutTopEnd: ['search'],
			layoutBottomStart: ['info'],
			layoutBottomEnd: ['paging']
		}, tableOptions);
		['layoutTopStart', 'layoutTopEnd', 'layoutBottomStart', 'layoutBottomEnd'].forEach(function(key) {
			if (Object.prototype.hasOwnProperty.call(tableOptions, key)) {
				resolvedOptions[key] = Array.isArray(tableOptions[key]) ? tableOptions[key].slice() : [];
			}
		});
		$table.removeClass('display');
		var styleClassMap = {
			stripe: 'stripe',
			rowBorder: 'row-border',
			cellBorder: 'cell-border',
			hover: 'hover',
			orderColumn: 'order-column'
		};
		Object.keys(styleClassMap).forEach(function(key) {
			var enabled = resolvedOptions[key] !== false;
			$table.toggleClass(styleClassMap[key], enabled);
		});
		var isCompact = resolvedOptions.compact === true;
		$table.toggleClass('compact', isCompact);
		if ($wrapper && $wrapper.length) {
			$wrapper.toggleClass('is-compact', isCompact);
		}
		var searchTextHtml = resolveLabelHtml(resolvedOptions.searchText, '');
		var searchTextPlain = labelToPlainText(searchTextHtml, '');
		var searchPlaceholderHtml = resolveLabelHtml(resolvedOptions.searchPlaceholder, '');
		var searchPlaceholderText = labelToPlainText(searchPlaceholderHtml, '');

		var buttonList = Array.isArray(resolvedOptions.buttons) ? resolvedOptions.buttons : [];
		var supportsLayout = $.fn.dataTable && $.fn.dataTable.versionCheck && $.fn.dataTable.versionCheck('2.0.0');
		var pagingConfig = {
			numbers: resolvedOptions.pagingNumbers !== false,
			firstLast: resolvedOptions.pagingFirstLast !== false,
			previousNext: resolvedOptions.pagingPreviousNext !== false
		};
		var layoutConfig = null;
		var domString = '';
			if (supportsLayout) {
				var layoutZones = {
					topStart: Array.isArray(resolvedOptions.layoutTopStart) ? resolvedOptions.layoutTopStart : [],
					topEnd: Array.isArray(resolvedOptions.layoutTopEnd) ? resolvedOptions.layoutTopEnd : [],
					bottomStart: Array.isArray(resolvedOptions.layoutBottomStart) ? resolvedOptions.layoutBottomStart : [],
					bottomEnd: Array.isArray(resolvedOptions.layoutBottomEnd) ? resolvedOptions.layoutBottomEnd : []
				};
				var layoutSeen = {};
				var buildLayoutZone = function(items) {
					var zoneItems = [];
					(items || []).forEach(function(item) {
						if (!item || layoutSeen[item]) {
							return;
						}
						var normalized = null;
						if (item === 'search' && resolvedOptions.searchBox !== false) {
							normalized = 'search';
						} else if (item === 'pagelength' && resolvedOptions.lengthChange !== false) {
							normalized = 'pageLength';
						} else if (item === 'buttons' && buttonList.length) {
							normalized = 'buttons';
						} else if (item === 'info' && resolvedOptions.info !== false) {
							normalized = 'info';
						} else if (item === 'paging' && resolvedOptions.paging !== false) {
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
				layoutConfig = {
					topStart: buildLayoutZone(layoutZones.topStart),
					topEnd: buildLayoutZone(layoutZones.topEnd),
					bottomStart: buildLayoutZone(layoutZones.bottomStart),
					bottomEnd: buildLayoutZone(layoutZones.bottomEnd)
				};
			} else {
				var domParts = [];
				if (resolvedOptions.lengthChange !== false) {
					domParts.push('l');
				}
				if (buttonList.length) {
					domParts.push('B');
				}
				if (resolvedOptions.searchBox !== false) {
					domParts.push('f');
				}
				domParts.push('r');
				domParts.push('t');
				if (resolvedOptions.info !== false) {
					domParts.push('i');
				}
				if (resolvedOptions.paging !== false) {
					domParts.push('p');
				}
				domString = domParts.join('');
			}
		var pageLength = parseInt(resolvedOptions.pageLength, 10);
		if (!pageLength || pageLength < 1) {
			pageLength = 25;
		}

		var columnDefs = [];
		if (Array.isArray(config.hiddenColumns) && config.hiddenColumns.length) {
			columnDefs.push({
				targets: config.hiddenColumns,
				visible: false
			});
		}
		if (Array.isArray(config.nonSortable) && config.nonSortable.length) {
			columnDefs.push({
				targets: config.nonSortable,
				orderable: false
			});
		}

		function makeColumnClass(slug, idx) {
			var base = String(slug || '');
			if (!base) {
				base = 'col-' + idx;
			}
			base = base.toLowerCase().replace(/[^a-z0-9_-]+/g, '-').replace(/-+/g, '-').replace(/^-+|-+$/g, '');
			if (!base) {
				base = 'col-' + idx;
			}
			return 'btbl-col btbl-col-' + base;
		}

		if (config.slugToIndex && typeof config.slugToIndex === 'object') {
			Object.keys(config.slugToIndex).forEach(function(slug) {
				var idx = config.slugToIndex[slug];
				if (idx === null || idx === undefined) {
					return;
				}
				columnDefs.push({
					targets: parseInt(idx, 10),
					className: makeColumnClass(slug, idx)
				});
			});
		}

		var buttonDefs = [];
		if (buttonList.length) {
			var seenButtons = {};
			var buttonRegistry = {
				copy:       { extend: 'copyHtml5',  defaultText: 'Copy',              optionKey: 'buttonTextCopy' },
				csv:        { extend: 'csvHtml5',   defaultText: 'Export CSV',         optionKey: 'buttonTextCsv' },
				print:      { extend: 'print',      defaultText: 'Print',              optionKey: 'buttonTextPrint' },
				colvis:     { extend: 'colvis',     defaultText: 'Column visibility',  optionKey: 'buttonTextColvis' },
				pagelength: { extend: 'pageLength', defaultText: 'Page length',        optionKey: 'buttonTextPagelength' }
			};
			buttonList.forEach(function(btn) {
				var key = typeof btn === 'string' ? btn.toLowerCase() : '';
				if (!key || !buttonRegistry[key] || seenButtons[key]) {
					return;
				}
				seenButtons[key] = true;
				var reg = buttonRegistry[key];
				var buttonText = resolveLabelHtml(resolvedOptions[reg.optionKey] || '', reg.defaultText);
				var buttonConfig = { extend: reg.extend };
				if (buttonText !== '') {
					buttonConfig.text = buttonText;
				}
				buttonDefs.push(buttonConfig);
			});
		}

		var orderDefs = [];
		var indexToSlug = {};
		if (Array.isArray(config.defaultOrder)) {
			config.defaultOrder.forEach(function(item) {
				if (!item || typeof item.slug === 'undefined') {
					return;
				}
				var slug = item.slug;
				var dir = item.direction === 'desc' ? 'desc' : 'asc';
				var idx = slugIdx(slugToIndex, slug);
				if (idx !== null && idx !== undefined) {
					orderDefs.push([parseInt(idx, 10), dir]);
				}
			});
		}

		var languageConfig = {
			zeroRecords: '',
			emptyTable: '',
			searchPlaceholder: searchPlaceholderText
		};
		languageConfig.search = searchTextPlain;
		var infoText = resolveLabelHtml(resolvedOptions.infoText, '');
		if (infoText) {
			languageConfig.info = infoText;
		}
		var infoEmptyText = resolveLabelHtml(resolvedOptions.infoEmpty, '');
		if (infoEmptyText) {
			languageConfig.infoEmpty = infoEmptyText;
		}
		var infoFilteredText = resolveLabelHtml(resolvedOptions.infoFiltered, '');
		if (infoFilteredText) {
			languageConfig.infoFiltered = infoFilteredText;
		}
		var paginateConfig = {};
		var paginateFirst = resolveLabelHtml(resolvedOptions.paginateFirst, '');
		if (paginateFirst) {
			paginateConfig.first = paginateFirst;
		}
		var paginatePrevious = resolveLabelHtml(resolvedOptions.paginatePrevious, '');
		if (paginatePrevious) {
			paginateConfig.previous = paginatePrevious;
		}
		var paginateNext = resolveLabelHtml(resolvedOptions.paginateNext, '');
		if (paginateNext) {
			paginateConfig.next = paginateNext;
		}
		var paginateLast = resolveLabelHtml(resolvedOptions.paginateLast, '');
		if (paginateLast) {
			paginateConfig.last = paginateLast;
		}
		if (Object.keys(paginateConfig).length) {
			languageConfig.paginate = paginateConfig;
		}
		var lengthMenuPrefixHtml = resolveLabelHtml(resolvedOptions.lengthMenuPrefix, '');
		var lengthMenuPrefixText = labelToPlainText(lengthMenuPrefixHtml, '');
		var lengthMenuSuffixHtml = resolveLabelHtml(resolvedOptions.lengthMenuSuffix, '');
		var lengthMenuSuffixText = labelToPlainText(lengthMenuSuffixHtml, '');
		var lengthMenuParts = [];
		if (lengthMenuPrefixText) {
			lengthMenuParts.push(lengthMenuPrefixText);
		}
		lengthMenuParts.push('_MENU_');
		if (lengthMenuSuffixText) {
			lengthMenuParts.push(lengthMenuSuffixText);
		}
		var lengthMenuLabel = lengthMenuParts.join(' ');
		if (lengthMenuPrefixHtml !== lengthMenuPrefixText || lengthMenuSuffixHtml !== lengthMenuSuffixText) {
			var prefixVisual = lengthMenuPrefixHtml && lengthMenuPrefixHtml !== lengthMenuPrefixText ? lengthMenuPrefixHtml : lengthMenuPrefixText;
			var suffixVisual = lengthMenuSuffixHtml && lengthMenuSuffixHtml !== lengthMenuSuffixText ? lengthMenuSuffixHtml : lengthMenuSuffixText;
			var visualParts = [];
			if (prefixVisual) {
				visualParts.push(prefixVisual);
			}
			visualParts.push('_MENU_');
			if (suffixVisual) {
				visualParts.push(suffixVisual);
			}
			lengthMenuLabel = visualParts.join(' ');
		}
		languageConfig.lengthMenu = lengthMenuLabel;

		var tableConfig = {
			pageLength: pageLength,
			paging: resolvedOptions.paging !== false,
			ordering: resolvedOptions.ordering !== false,
			colReorder: resolvedOptions.colReorder === true,
			info: resolvedOptions.info !== false,
			lengthChange: resolvedOptions.lengthChange !== false,
			buttons: buttonDefs,
			order: orderDefs.length ? orderDefs : [],
			columnDefs: columnDefs,
			language: languageConfig,
			search: {
				search: '',
				smart: true
			}
		};
		if (supportsLayout) {
			tableConfig.layout = layoutConfig;
		} else {
			tableConfig.dom = domString;
		}
		var table = $table.DataTable(tableConfig);

		var stripeEnabled = resolvedOptions.stripe !== false;
		$table
			.toggleClass('btbl-has-stripes', stripeEnabled)
			.toggleClass('btbl-no-stripes', !stripeEnabled);

		var nonSearchableSet = {};
		if (Array.isArray(config.nonSearchable)) {
			config.nonSearchable.forEach(function(idx) {
				nonSearchableSet[idx] = true;
			});
		}

		var $container = $(table.table().container());
		var $filterWrapper = resolvedOptions.searchBox === false ? $() : $container.find('.dataTables_filter, .dt-search');
		var $searchInput = $filterWrapper.find('input[type="search"]');
		if ($filterWrapper.length && $searchInput.length) {
			if (searchPlaceholderText) {
				$searchInput.attr('placeholder', searchPlaceholderText);
			}
			var $filterLabel = $filterWrapper.find('label');
			if (!searchTextHtml && $filterLabel.length) {
				$filterLabel.contents().filter(function() {
					return this.nodeType === 3;
				}).remove();
				$filterWrapper.addClass('btbl-search-label-empty');
			}
			if (searchTextHtml && searchTextHtml !== searchTextPlain) {
					if ($filterLabel.length) {
						$filterLabel.contents().filter(function() {
							return this.nodeType === 3;
						}).remove();
					}
					var $visual = $('<span class="btbl-search-placeholder-visual" aria-hidden="true"></span>').html(searchTextHtml);
					if ($filterLabel.length) {
						$filterLabel.prepend($visual);
					} else {
						$filterWrapper.prepend($visual);
					}
				$filterWrapper.addClass('btbl-has-placeholder-visual');
				var syncPlaceholderState = function() {
					var hasValue = ($searchInput.val() || '').length > 0;
					$filterWrapper.toggleClass('btbl-search-filled', hasValue);
				};
				$searchInput.on('input change', syncPlaceholderState);
				syncPlaceholderState();
			}
			var searchableColumns = [];
			var enableSearchColumns = resolvedOptions.searchColumns !== false;
			var defaultToggleLabel = 'Columns';
			var defaultHeadingLabel = 'Search in';
			table.columns().every(function(idx) {
				var colSettings = this.settings()[0].aoColumns[idx] || {};
				if (colSettings.bSearchable === false || nonSearchableSet[idx]) {
					return;
				}
				var headerHtml = $.trim($(this.header()).html() || colSettings.sTitle || '');
				var defaultLabel = 'Column ' + (idx + 1);
				if (!headerHtml) {
					headerHtml = defaultLabel;
				}
				var headerText = labelToPlainText(headerHtml, defaultLabel);
				headerText = headerText || defaultLabel;
				searchableColumns.push({
					index: idx,
					labelHtml: headerHtml,
					labelText: headerText
				});
				});

				if (searchableColumns.length) {
					var searchableIndexSet = {};
					searchableColumns.forEach(function(col) {
						searchableIndexSet[col.index] = true;
					});
					var searchState = {
						term: presetSearchTerm || '',
						columns: searchableColumns.map(function(col) { return col.index; })
					};

					if (enableSearchColumns && presetSearchColumns.length) {
						var presetIndices = presetSearchColumns.map(function(slug) {
							return slugIdx(slugToIndex, slug);
						}).filter(function(val) {
							return val !== null && val !== undefined && searchableIndexSet[parseInt(val, 10)];
						});
						if (presetIndices.length) {
							searchState.columns = presetIndices;
					}
				}

				var dropdownId = 'btbl-search-columns-' + tableId;
				var toggleLabelHtml = resolveLabelHtml(resolvedOptions.searchColumnsLabel, defaultToggleLabel);
				var toggleLabelText = labelToPlainText(toggleLabelHtml, defaultToggleLabel);
				var headingLabelHtml = resolveLabelHtml(resolvedOptions.searchColumnsHeading, defaultHeadingLabel);
				var $searchColumns = enableSearchColumns ? $('<div class="btbl-search-columns"></div>') : $();
				var $toggle = enableSearchColumns
					? $('<button type="button" class="btbl-search-columns-toggle" aria-haspopup="true" aria-expanded="false" aria-controls="' + dropdownId + '"></button>')
						.html(toggleLabelHtml)
						.attr('aria-label', toggleLabelText)
					: $();
				var $dropdown = enableSearchColumns ? $('<div class="btbl-search-columns-dropdown" id="' + dropdownId + '" role="menu"></div>') : $('<div></div>');
				var $dropdownLabel = enableSearchColumns
					? $('<div class="btbl-search-columns-heading"></div>').html(headingLabelHtml)
					: $('<div></div>');
				var $list = enableSearchColumns ? $('<div class="btbl-search-columns-list"></div>') : $('<div></div>');

				if (enableSearchColumns) {
					searchableColumns.forEach(function(col) {
						var checkboxId = dropdownId + '-' + col.index;
						var $item = $('<label class="btbl-search-columns-option" for="' + checkboxId + '"></label>');
						var $checkbox = $('<input type="checkbox" checked />').attr({
							id: checkboxId,
							value: col.index,
							'aria-label': col.labelText
						});
						if (searchState.columns.indexOf(col.index) === -1) {
							$checkbox.prop('checked', false);
						}
						$item.append($checkbox);
						var optionLabelHtml = col.labelHtml || col.labelText;
						$item.append($('<span></span>').html(optionLabelHtml));
						$list.append($item);
					});

					$dropdown.append($dropdownLabel);
					$dropdown.append($list);
					$searchColumns.append($toggle).append($dropdown);
					$filterWrapper.append($searchColumns);
				}

				function applyColumnSelection() {
					var selected = $dropdown.find('input[type="checkbox"]:checked').map(function() {
						return parseInt($(this).val(), 10);
					}).get();
					if (!selected.length) {
						selected = searchableColumns.map(function(col) { return col.index; });
						$dropdown.find('input[type="checkbox"]').prop('checked', true);
					}
					searchState.columns = selected;
					searchState.term = $searchInput.val() || '';
					table.draw();
					syncStateToUrl();
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

				if (enableSearchColumns) {
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

				$searchInput.on('input', function() {
					searchState.term = $searchInput.val() || '';
					table.search(searchState.term).draw();
					syncStateToUrl();
				});

				table.on('search.dt', function(event, settings) {
					if (settings.nTable === $table[0]) {
						searchState.term = table.search() || '';
					}
				});

				var scopedSearchFilter = function(settings, data) {
					if (settings.nTable !== $table[0]) {
						return true;
					}
					var term = searchState.term;
					if (!term || !term.trim()) {
						return true;
					}
					var normalizedTerm = term.toLowerCase();
					var indices = searchState.columns.length ? searchState.columns : searchableColumns.map(function(col) { return col.index; });
					for (var i = 0; i < indices.length; i++) {
						var idx = indices[i];
						if (idx >= data.length) {
							continue;
						}
						if (normalizeSearchText(data[idx]).indexOf(normalizedTerm) !== -1) {
							return true;
						}
					}
					return false;
				};

				$.fn.dataTable.ext.search.push(scopedSearchFilter);
				table.on('destroy', function() {
					var filterIndex = $.fn.dataTable.ext.search.indexOf(scopedSearchFilter);
					if (filterIndex !== -1) {
						$.fn.dataTable.ext.search.splice(filterIndex, 1);
					}
					var globalIndex = $.fn.dataTable.ext.search.indexOf(globalSearchLimiter);
					if (globalIndex !== -1) {
						$.fn.dataTable.ext.search.splice(globalIndex, 1);
					}
					if (enableSearchColumns) {
						$(document).off('click', handleDocumentClick);
					}
				});
				if (searchState.term) {
					$searchInput.val(searchState.term);
					table.search(searchState.term).draw();
					syncStateToUrl();
				}
			}
		}

	function buildGlobalSearchLimiter(tableInstance, nonSearchable) {
		return function(settings, data) {
			if (settings.nTable !== tableInstance.table().node()) {
				return true;
			}
			var term = tableInstance.search();
			if (!term || !term.trim()) {
				return true;
			}
			var normalizedTerm = term.toLowerCase();
			for (var i = 0; i < data.length; i++) {
				if (nonSearchable[i]) {
					continue;
				}
				if (normalizeSearchText(data[i]).indexOf(normalizedTerm) !== -1) {
					return true;
				}
			}
			return false;
		};
			}

			var presetFilters = config.presetFilters || {};
			var $emptyState = $wrapper.find('.btbl-empty-state');
			var globalSearchLimiter = buildGlobalSearchLimiter(table, nonSearchableSet);
			$.fn.dataTable.ext.search.push(globalSearchLimiter);

		function toggleEmptyState() {
			var hasRows = table.rows({ search: 'applied' }).data().length > 0;
			$emptyState.toggle(!hasRows);
		}

		table.on('draw', toggleEmptyState);
		indexToSlug = {};
		Object.keys(slugToIndex || {}).forEach(function(slug) {
			if (slugIdx(slugToIndex, slug) !== null) {
				indexToSlug[slugToIndex[slug]] = slug;
			}
		});

				function getActiveFilters() {
					var filters = {};
					$wrapper.find('.btbl-filter-wrapper .btbl-filter').each(function() {
					var $filter = $(this);
					var slug = $filter.data('slug');
					var type = $filter.data('type');
					if (!slug || !type) {
						return;
					}
					if (type === 'dropdown' || type === 'dropdown_plain') {
						var val = $filter.find('select').val();
						if (val && val !== '__all') {
							filters[slug] = [val];
						}
					} else if (type === 'dropdown_multi' || type === 'dropdown_plain_multi') {
						var multiVals = $filter.find('select').val() || [];
						multiVals = multiVals.filter(function(val) {
							return val !== null && val !== undefined && val !== '';
						});
						if (multiVals.length) {
							filters[slug] = multiVals;
						}
					} else if (type === 'checkbox') {
						var vals = $filter.find('input[type="checkbox"]:checked').map(function() {
							return $(this).val();
						}).get();
						if (vals.length) {
							filters[slug] = vals;
						}
					} else if (type === 'radio') {
						var radioVal = $filter.find('input[type="radio"]:checked').val();
						if (radioVal && radioVal !== '__all') {
							filters[slug] = [radioVal];
						}
					}
				});
				return filters;
			}

		function updateFilterStateClass() {
			var filters = getActiveFilters();
				var hasFilters = Object.keys(filters).some(function(key) {
					var val = filters[key];
					if (Array.isArray(val)) {
						return val.some(function(item) {
							return item !== null && item !== undefined && item !== '';
						});
					}
					return val !== null && val !== undefined && val !== '' && val !== '__all';
				});
				var term = '';
				if ($searchInput && $searchInput.length) {
					term = $searchInput.val() || '';
				} else if (typeof searchState !== 'undefined' && searchState && searchState.term) {
					term = searchState.term;
				} else if (table && typeof table.search === 'function') {
					term = table.search() || '';
				}
				var hasSearch = term && term.trim();
				$wrapper.toggleClass('is-filtered', hasFilters || !!hasSearch);
			}

		function syncStateToUrl() {
			var filters = getActiveFilters();
				var url = new URL(window.location.href);
				var keysToDelete = [];
				url.searchParams.forEach(function(_, key) {
					if (key.indexOf('btbl_filter[') === 0) {
						keysToDelete.push(key);
					}
				});
				keysToDelete.forEach(function(key) {
					url.searchParams.delete(key);
				});
				url.searchParams.delete('btbl_search');
				url.searchParams.delete('btbl_search_cols');

				Object.keys(filters).forEach(function(slug) {
					var values = Array.isArray(filters[slug]) ? filters[slug] : [filters[slug]];
					values = values.filter(function(val) {
						return val !== undefined && val !== null && val !== '';
					});
					if (!values.length) {
						return;
					}
					url.searchParams.append('btbl_filter[' + slug + ']', values.join(','));
				});

				var term = searchState && searchState.term ? searchState.term : '';
				var selectedColumns = searchState && Array.isArray(searchState.columns) ? searchState.columns : (searchableColumns || []).map(function(col) { return col.index; });
				if (term && term.trim()) {
					url.searchParams.set('btbl_search', term);
					var selectedSlugs = selectedColumns.map(function(idx) {
						return slugIdx(indexToSlug, idx);
					}).filter(function(slug) {
						return !!slug;
					});
					if (selectedSlugs.length && selectedSlugs.length !== (searchableColumns || []).length) {
						url.searchParams.set('btbl_search_cols', selectedSlugs.join(','));
					}
				}

				window.history.replaceState({}, '', url.toString());
				updateFilterStateClass();
			}

		$wrapper.find('.btbl-filter-wrapper .btbl-filter').each(function() {
			var $filter = $(this);
			var colIdx = parseInt($filter.data('column'), 10);
			var column = table.column(colIdx);
			var slug = $filter.data('slug');
			var preset = presetFilters[slug] || null;
			var type = $filter.data('type') || '';
			var filterStrict = $filter.data('strict') === true || $filter.data('strict') === 1 || $filter.data('strict') === '1';
			var filterSmart = filterStrict ? false : true;

			if (type === 'dropdown' || type === 'dropdown_multi') {
				var $select = $filter.find('select');
				var placeholder = $select.data('placeholder') || '';
				var $dropdownParent = $filter.closest('.btbl-table-wrapper');
				if (!$dropdownParent.length) {
					$dropdownParent = $filter.closest('.btbl-filter-wrapper');
				}
				if (!$dropdownParent.length) {
					$dropdownParent = $filter;
				}
				$select.select2({
					width: 'resolve',
					placeholder,
					allowClear: type === 'dropdown_multi',
					dropdownParent: $dropdownParent,
					closeOnSelect: type === 'dropdown'
				});
				if (type === 'dropdown_multi') {
					var suppressOpen = false;
					$select.on('select2:unselect select2:clear', function() {
						suppressOpen = true;
					});
					$select.on('select2:opening', function(e) {
						if (suppressOpen) {
							e.preventDefault();
							suppressOpen = false;
						}
					});
					$select.on('select2:open select2:select', function() {
						suppressOpen = false;
					});
				}
			}

			if (type === 'dropdown' || type === 'dropdown_plain') {
				var $singleSelect = $filter.find('select');
				$singleSelect.on('change', function() {
					var val = $(this).val();
					if (!val || val === '__all') {
						column.search('').draw();
					} else {
						var terms = getSearchTermsFromElement($(this).find('option:selected'), val);
						applyColumnSearch(column, buildMultiValuePattern(terms), filterSmart).draw();
					}
					syncStateToUrl();
				});
				if (preset) {
					var presetVal = Array.isArray(preset) ? preset[0] : preset;
					$singleSelect.val(presetVal).trigger('change');
				}
			} else if (type === 'dropdown_multi' || type === 'dropdown_plain_multi') {
				var $multiSelect = $filter.find('select');
				var suppressEmptyChange = false;
				$multiSelect.on('change', function() {
					if (suppressEmptyChange) {
						suppressEmptyChange = false;
						return;
					}
					var rawVals = $multiSelect.val() || [];
					var cleanedVals = rawVals.filter(function(val) {
						return val !== null && val !== undefined && val !== '';
					});
					if (cleanedVals.length !== rawVals.length) {
						suppressEmptyChange = true;
						$multiSelect.val(cleanedVals).trigger('change');
					}
					var selectedOptions = $multiSelect.find('option:selected').filter(function() {
						var v = $(this).val();
						return v !== null && v !== undefined && v !== '';
					});
					var terms = [];
					selectedOptions.each(function() {
						var valAttr = $(this).val();
						var t = getSearchTermsFromElement($(this), valAttr);
						terms = terms.concat(t);
					});
					terms = terms.filter(function(val) {
						return val !== '' && val !== null && val !== undefined;
					});
					if (!terms.length) {
						column.search('').draw();
					} else {
						applyColumnSearch(column, buildMultiValuePattern(terms), filterSmart).draw();
					}
					syncStateToUrl();
				});
				if (preset) {
					var presetMulti = (Array.isArray(preset) ? preset : [preset]).filter(function(val) {
						return val !== null && val !== undefined && val !== '';
					});
					$multiSelect.val(presetMulti).trigger('change');
				}
			} else if (type === 'checkbox') {
				var $checkboxes = $filter.find('input[type="checkbox"]');
				$checkboxes.on('change', function() {
					var termsCheckbox = [];
					$filter.find('input[type="checkbox"]:checked').each(function() {
						var t = getSearchTermsFromElement($(this), $(this).val());
						termsCheckbox = termsCheckbox.concat(t);
					});
					if (termsCheckbox.length === 0) {
						column.search('').draw();
					} else {
						var patternCheckbox = buildMultiValuePattern(termsCheckbox);
						applyColumnSearch(column, patternCheckbox, filterSmart).draw();
					}
						syncStateToUrl();
				});
				if (preset) {
					var presetVals = Array.isArray(preset) ? preset : [preset];
					$checkboxes.each(function() {
						if (presetVals.indexOf($(this).val()) !== -1) {
							$(this).prop('checked', true);
						}
					});
					$checkboxes.first().trigger('change');
				}
			} else if (type === 'radio') {
				var $radios = $filter.find('input[type="radio"]');
				$radios.on('change', function() {
					var val = $(this).val();
					if (val === '__all') {
						column.search('').draw();
					} else {
						var termsRadio = getSearchTermsFromElement($(this), val);
						var patternRadio = buildMultiValuePattern(termsRadio);
						applyColumnSearch(column, patternRadio, filterSmart).draw();
					}
						syncStateToUrl();
				});
				if (preset) {
					var presetRadio = Array.isArray(preset) ? preset[0] : preset;
					var $match = filterByInputValue($radios, presetRadio);
					if ($match.length) {
						$match.prop('checked', true).trigger('change');
					}
				}
			}
			});

			$wrapper.find('.btbl-reset-button').on('click', function() {
				table.columns().search('');
				if ($searchInput && $searchInput.length) {
					$searchInput.val('');
					table.search('');
					if (typeof searchState !== 'undefined') {
						searchState.term = '';
						searchState.columns = searchableColumns.map(function(col) { return col.index; });
					}
					if (typeof $dropdown !== 'undefined' && $dropdown.length) {
						$dropdown.find('input[type="checkbox"]').prop('checked', true);
					}
				}
				$wrapper.find('.btbl-filter-wrapper .btbl-filter').each(function() {
					var $filter = $(this);
					var type = $filter.data('type');
					if (type === 'dropdown' || type === 'dropdown_plain') {
					var $select = $filter.find('select');
					var resetVal = type === 'dropdown' ? '' : '__all';
					$select.val(resetVal).trigger('change');
				} else if (type === 'dropdown_multi' || type === 'dropdown_plain_multi') {
					var $multiSelect = $filter.find('select');
					$multiSelect.val([]).trigger('change');
				} else if ($filter.hasClass('btbl-filter-checkbox') || type === 'checkbox') {
					var $checkboxes = $filter.find('input[type="checkbox"]');
					$checkboxes.prop('checked', false);
					$checkboxes.first().trigger('change');
				} else if ($filter.hasClass('btbl-filter-radio') || type === 'radio') {
					var $allRadio = $filter.find('input[type="radio"][value="__all"]');
					if ($allRadio.length) {
						$allRadio.prop('checked', true).trigger('change');
					}
				}
			});
			table.draw();
		syncStateToUrl();
		});

				table.draw();
			toggleEmptyState();
			updateFilterStateClass();
			var chartInstance = initChart(config.chart, table, tableId, slugToIndex);
		if (chartInstance && table) {
			table.on('destroy', function() {
				if (chartInstance.dispose) {
					chartInstance.dispose();
				}
			});
		}
		markReady();
	}

	function bootQueue() {
		var queue = Array.isArray(window.BaraTablesFrontendQueue) ? window.BaraTablesFrontendQueue : [];
		function drain() {
			while (queue.length) {
				initInstance(queue.shift());
			}
		}
		$(drain);
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
})(jQuery);
