(function(window) {
	function validColor(value) {
		return typeof value === 'string' && /^#[0-9a-fA-F]{3,8}$/.test(value) ? value : '';
	}

	// A chart font size is a number of PIXELS. ECharts' fontSize is not a CSS length, so
	// a general CSS length would be read as its bare number -- "2em" as 2px. PHP
	// normalizes the stored value to the px form; this re-checks it at runtime so a
	// hand-built integration payload cannot smuggle a length back in.
	function validSize(value) {
		var match = typeof value === 'string' ? /^(\d{1,4}(?:\.\d{1,2})?)px$/.exec(value.trim()) : null;
		return match ? parseFloat(match[1]) : 0;
	}

	// The palette the vendored ECharts build (v6.1.0) draws with. The plugin used to
	// carry its own hardcoded copy of the older ECharts 5 palette, which no longer
	// matched the library it renders with.
	var CHART_PALETTE = ['#5070dd', '#b6d634', '#505372', '#ff994d', '#0ca8df', '#ffd10a', '#fb628b', '#785db0', '#3fbe95'];

	// The default series colors lead with the table's accent, so a themed accent colors
	// the chart too; the rest of the palette follows.
	function accentPalette(accent) {
		var palette = [accent];
		CHART_PALETTE.forEach(function(color) {
			if (palette.indexOf(color) === -1) {
				palette.push(color);
			}
		});
		return palette;
	}

	// The palette the chart draws with: the admin's own series palette when set, and the
	// accent-led default otherwise. Resolved BEFORE the option is built, because a
	// Gantt bakes its group colors into the data items while the option is assembled.
	function resolveSeriesPalette(chartConfig, container) {
		var style = chartConfig || {};
		var palette = Array.isArray(style.stylePalette) ? style.stylePalette.filter(validColor) : [];
		if (palette.length) {
			return palette;
		}
		var accent = '';
		try {
			accent = validColor(String(window.getComputedStyle(container).getPropertyValue('--btbl-accent') || '').trim());
		} catch (err) {
			accent = '';
		}
		return accent ? accentPalette(accent) : [];
	}

	function normalizeConfig(config) {
		var normalized = Object.assign({}, config || {});
		[
			['x_axis', 'xAxis'],
			['heatmap_x', 'heatmapX'],
			['heatmap_y', 'heatmapY'],
			['heatmap_value', 'heatmapValue'],
			['gantt_label', 'ganttLabel'],
			['gantt_start', 'ganttStart'],
			['gantt_end', 'ganttEnd'],
			['gantt_group', 'ganttGroup'],
			['gantt_progress', 'ganttProgress'],
			['row_slug_index', 'rowSlugIndex']
		].forEach(function(keys) {
			if (!normalized[keys[0]] && normalized[keys[1]]) {
				normalized[keys[0]] = normalized[keys[1]];
			}
		});
		normalized.series = Array.isArray(normalized.series) ? normalized.series.slice() : [];
		return normalized;
	}

	function getRows(chartConfig, tableInstance) {
		if (tableInstance && tableInstance.rows) {
			var data = tableInstance.rows({ search: 'applied' }).data();
			return data && data.toArray ? data.toArray() : [];
		}
		return Array.isArray(chartConfig.rows) ? chartConfig.rows : [];
	}

	// ColReorder physically rewrites the row arrays from the reordered DOM, while the
	// slug=>index map still describes the original column order. Translate per render so
	// the chart keeps reading the columns it was configured against (same guard pattern
	// as the search and filter controllers). Always derived from the base map, never from
	// an already-transposed one. The plugin's own pages never combine a live table with a
	// chart ([bara_table] renders no chart, [bara_chart] passes a null table instance);
	// this guards the public BaraTablesCharts.init() path where an integration passes one.
	function transposeSlugIndex(api, slugToIndex) {
		if (!api || !api.colReorder || typeof api.colReorder.transpose !== 'function') {
			return slugToIndex;
		}
		var out = {};
		Object.keys(slugToIndex || {}).forEach(function(slug) {
			var idx = slugToIndex[slug];
			if (idx !== null && idx !== undefined) {
				out[slug] = api.colReorder.transpose(idx, 'toCurrent');
			}
		});
		return out;
	}

	function buildColumnsMeta(columns) {
		var columnsMeta = {};
		if (!Array.isArray(columns)) {
			return columnsMeta;
		}
		columns.forEach(function(col) {
			if (col && col.slug) {
				columnsMeta[col.slug] = col.label || col.slug;
			}
		});
		return columnsMeta;
	}

	function getRowValue(row, index) {
		return index !== null && index !== undefined && index < row.length ? row[index] : null;
	}

	function getRowText(row, index, helpers) {
		return helpers.extractText(getRowValue(row, index));
	}

	function buildSeriesData(context) {
		var chartConfig = context.chartConfig;
		var xSlug = chartConfig.x_axis;
		var xIdx = context.helpers.slugIdx(context.slugToIndex, xSlug);
		if (xIdx === null || xIdx === undefined) {
			return null;
		}

		var seriesSlugs = chartConfig.series;
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
		getRows(chartConfig, context.tableInstance).forEach(function(row) {
			var category = getRowText(row, xIdx, context.helpers);
			categories.push(category);
			seriesSlugs.forEach(function(slug) {
				var idx = context.helpers.slugIdx(context.slugToIndex, slug);
				var val = context.helpers.parseNumber(getRowValue(row, idx));
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

	function parseGanttDate(value, helpers) {
		var text = helpers.extractText(value);
		if (!text) {
			return { time: null, hasYear: false };
		}
		var hasYear = /\b\d{4}\b/.test(text);
		var parsed = helpers.parseDate(text);
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

	function toGanttItem(row, indexes, helpers, categories, categoryIndex, getGroupColor) {
		var label = getRowText(row, indexes.label, helpers);
		if (!label) {
			return null;
		}
		var startParsed = parseGanttDate(getRowValue(row, indexes.start), helpers);
		var endParsed = parseGanttDate(getRowValue(row, indexes.end), helpers);
		var start = startParsed.time;
		var end = endParsed.time;
		if (start === null || end === null) {
			return null;
		}
		if (end <= start && !startParsed.hasYear && !endParsed.hasYear) {
			var endDateObj = new Date(end);
			endDateObj.setFullYear(endDateObj.getFullYear() + 1);
			end = endDateObj.getTime();
		}
		if (end <= start) {
			return null;
		}
		if (!Object.prototype.hasOwnProperty.call(categoryIndex, label)) {
			categoryIndex[label] = categories.length;
			categories.push(label);
		}

		var group = getRowText(row, indexes.group, helpers);
		var progressText = getRowText(row, indexes.progress, helpers);
		var progress = progressText !== '' ? helpers.parseOptionalNumber(progressText) : null;
		if (progress !== null) {
			progress = Math.min(100, Math.max(0, progress));
		}
		var color = getGroupColor(group);
		return {
			name: label,
			value: [categoryIndex[label], start, end, progress],
			group: group,
			progress: progress,
			itemStyle: color ? { color: color } : undefined
		};
	}

	function buildGanttData(context) {
		var chartConfig = context.chartConfig;
		var helpers = context.helpers;
		var labelSlug = chartConfig.gantt_label;
		var startSlug = chartConfig.gantt_start;
		var endSlug = chartConfig.gantt_end;
		if (!labelSlug || !startSlug || !endSlug) {
			return null;
		}

		var labelIdx = helpers.slugIdx(context.slugToIndex, labelSlug);
		var startIdx = helpers.slugIdx(context.slugToIndex, startSlug);
		var endIdx = helpers.slugIdx(context.slugToIndex, endSlug);
		if (labelIdx === null || startIdx === null || endIdx === null) {
			return null;
		}

		var groupIdx = helpers.slugIdx(context.slugToIndex, chartConfig.gantt_group);
		var progressIdx = helpers.slugIdx(context.slugToIndex, chartConfig.gantt_progress);
		var categories = [];
		var categoryIndex = {};
		// The group colors come from the chart's own palette, so a Gantt is styled by
		// exactly the same controls as every other chart type. Ungrouped tasks fall
		// through to the series color, which leads with the accent.
		var palette = context.palette && context.palette.length ? context.palette : CHART_PALETTE;
		var groupColor = {};

		function getGroupColor(group) {
			if (!group) {
				return null;
			}
			if (!groupColor[group]) {
				groupColor[group] = palette[Object.keys(groupColor).length % palette.length];
			}
			return groupColor[group];
		}

		var indexes = {label: labelIdx, start: startIdx, end: endIdx, group: groupIdx, progress: progressIdx};
		var items = [];
		getRows(chartConfig, context.tableInstance).forEach(function(row) {
			var item = toGanttItem(row, indexes, helpers, categories, categoryIndex, getGroupColor);
			if (item) { items.push(item); }
		});

		// Empty result (no rows, or every row failed date/length validation) is a legitimate
		// empty chart, not a broken configuration: only the slug failures above may return null,
		// which init() treats as a runtime error. An empty option renders the bare axes.
		return { categories: categories, items: items };
	}

	function formatGanttTooltip(params, escapeHtml, labels) {
		labels = labels || {};
		var data = params.data || {};
		var values = params.value || [];
		var lines = [escapeHtml(params.name || '')];
		if (data.group) { lines.push(escapeHtml(data.group)); }
		if (values[1]) { lines.push(escapeHtml(labels.gantt_start || 'Start') + ': ' + escapeHtml(new Date(values[1]).toLocaleString())); }
		if (values[2]) { lines.push(escapeHtml(labels.gantt_end || 'End') + ': ' + escapeHtml(new Date(values[2]).toLocaleString())); }
		if (data.progress !== null && data.progress !== undefined && !isNaN(data.progress)) {
			lines.push(escapeHtml(labels.gantt_progress || 'Progress') + ': ' + escapeHtml(data.progress) + '%');
		}
		return lines.join('<br/>');
	}

	function buildGanttOption(prepared, escapeHtml, labels) {
		return {
			tooltip: {
				formatter: function(params) {
					return formatGanttTooltip(params, escapeHtml, labels);
				}
			},
			grid: { containLabel: true, left: '3%', right: '4%', bottom: '3%' },
			xAxis: { type: 'time' },
			yAxis: { type: 'category', data: prepared.categories, inverse: true },
			series: [{
				type: 'custom',
				renderItem: function(params, api) {
					var categoryIndex = api.value(0);
					var startCoord = api.coord([api.value(1), categoryIndex]);
					var endCoord = api.coord([api.value(2), categoryIndex]);
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
				data: prepared.items
			}]
		};
	}

	function buildCategoryValueData(prepared, seriesSlug) {
		return prepared.categories.map(function(category, index) {
			return { name: category, value: prepared.data[seriesSlug][index] };
		});
	}

	function buildPieLikeOption(type, prepared, columnsMeta) {
		var seriesSlug = prepared.seriesSlugs[0];
		var series = {
			type: type === 'funnel' ? 'funnel' : 'pie',
			name: columnsMeta[seriesSlug] || seriesSlug,
			data: buildCategoryValueData(prepared, seriesSlug),
			emphasis: { focus: 'self' }
		};
		if (type !== 'funnel') {
			series.radius = type === 'donut' ? ['45%', '70%'] : undefined;
		}
		return {
			tooltip: { trigger: 'item' },
			legend: {},
			series: [series]
		};
	}

	function buildTreemapOption(prepared, columnsMeta, escapeHtml) {
		var seriesSlug = prepared.seriesSlugs[0];
		return {
			tooltip: {
				formatter: function(params) {
					return escapeHtml(params.name || '') + ': ' + escapeHtml(params.value);
				}
			},
			series: [{
				type: 'treemap',
				name: columnsMeta[seriesSlug] || seriesSlug,
				roam: false,
				nodeClick: false,
				breadcrumb: { show: false },
				label: { show: true, formatter: '{b}' },
				data: buildCategoryValueData(prepared, seriesSlug)
			}]
		};
	}

	function buildRadarOption(prepared, columnsMeta) {
		var indicators = prepared.categories.map(function(category, index) {
			var values = prepared.seriesSlugs.map(function(slug) {
				return prepared.data[slug][index];
			});
			var min = Math.min.apply(null, [0].concat(values));
			var max = Math.max.apply(null, [0].concat(values));
			if (min === max) {
				max = min === 0 ? 1 : min + Math.abs(min * 0.1);
			}
			return { name: category, min: min, max: max };
		});
		return {
			tooltip: { trigger: 'item' },
			legend: {},
			radar: { indicator: indicators },
			series: [{
				type: 'radar',
				data: prepared.seriesSlugs.map(function(slug) {
					return { name: columnsMeta[slug] || slug, value: prepared.data[slug] };
				})
			}]
		};
	}

	function buildHeatmapOption(context) {
		var chartConfig = context.chartConfig;
		var helpers = context.helpers;
		var xIdx = helpers.slugIdx(context.slugToIndex, chartConfig.heatmap_x);
		var yIdx = helpers.slugIdx(context.slugToIndex, chartConfig.heatmap_y);
		var valueIdx = helpers.slugIdx(context.slugToIndex, chartConfig.heatmap_value);
		if (xIdx === null || yIdx === null || valueIdx === null) {
			return null;
		}

		var xCategories = [];
		var yCategories = [];
		var xIndexes = Object.create(null);
		var yIndexes = Object.create(null);
		var data = [];
		var min = null;
		var max = null;
		getRows(chartConfig, context.tableInstance).forEach(function(row) {
			var x = getRowText(row, xIdx, helpers);
			var y = getRowText(row, yIdx, helpers);
			var value = helpers.parseOptionalNumber(getRowValue(row, valueIdx));
			if (x === '' || y === '' || value === null) {
				return;
			}
			if (!Object.prototype.hasOwnProperty.call(xIndexes, x)) {
				xIndexes[x] = xCategories.length;
				xCategories.push(x);
			}
			if (!Object.prototype.hasOwnProperty.call(yIndexes, y)) {
				yIndexes[y] = yCategories.length;
				yCategories.push(y);
			}
			data.push([xIndexes[x], yIndexes[y], value]);
			min = min === null ? value : Math.min(min, value);
			max = max === null ? value : Math.max(max, value);
		});
		if (min === null) {
			min = 0;
			max = 1;
		} else if (min === max) {
			min = Math.min(0, min);
			max = max === 0 ? 1 : max;
		}

		var valueLabel = context.columnsMeta[chartConfig.heatmap_value] || chartConfig.heatmap_value;
		return {
			tooltip: {
				formatter: function(params) {
					var point = params.value || [];
					return helpers.escapeHtml(xCategories[point[0]] || '') + '<br>' +
						helpers.escapeHtml(yCategories[point[1]] || '') + '<br>' +
						helpers.escapeHtml(valueLabel) + ': ' + helpers.escapeHtml(point[2]);
				}
			},
			grid: { containLabel: true, left: '3%', right: '4%', bottom: 72 },
			xAxis: { type: 'category', data: xCategories, splitArea: { show: true } },
			yAxis: { type: 'category', data: yCategories, splitArea: { show: true } },
			visualMap: { min: min, max: max, calculable: true, orient: 'horizontal', left: 'center', bottom: 8 },
			series: [{
				type: 'heatmap',
				name: valueLabel,
				data: data,
				label: { show: true },
				emphasis: { itemStyle: { shadowBlur: 8, shadowColor: 'rgba(0,0,0,0.35)' } }
			}]
		};
	}

	function buildPointOption(type, prepared, columnsMeta, parseOptionalNumber) {
		function buildPointData(buildPoint) {
			var points = [];
			prepared.categories.forEach(function(category, index) {
				var xValue = parseOptionalNumber(category);
				if (xValue !== null) {
					points.push(buildPoint(xValue, index, category));
				}
			});
			return points;
		}

		var option = {
			tooltip: { trigger: 'item' },
			legend: {},
			xAxis: { type: 'value', name: columnsMeta[prepared.xSlug] || prepared.xSlug },
			yAxis: { type: 'value' }
		};
		if (type === 'bubble') {
			var ySlug = prepared.seriesSlugs[0];
			var sizeSlug = prepared.seriesSlugs[1] || '';
			var bubbleData = buildPointData(function(xValue, index, category) {
				return [
					xValue,
					prepared.data[ySlug][index],
					sizeSlug ? prepared.data[sizeSlug][index] : 0,
					category
				];
			});
			option.series = [{
				type: 'scatter',
				name: columnsMeta[ySlug] || ySlug,
				data: bubbleData,
				symbolSize: function(value) {
					var size = Array.isArray(value) ? Math.abs(Number(value[2]) || 0) : 0;
					return size > 0 ? Math.min(50, Math.max(8, Math.sqrt(size) * 4)) : 14;
				}
			}];
			return option;
		}

		option.series = prepared.seriesSlugs.map(function(slug) {
			var pointData = buildPointData(function(xValue, index, category) {
				return [xValue, prepared.data[slug][index], category];
			});
			return {
				type: 'scatter',
				name: columnsMeta[slug] || slug,
				data: pointData
			};
		});
		return option;
	}

	function buildCartesianOption(type, prepared, columnsMeta, chartConfig) {
		var isArea = type === 'area';
		var isHorizontalBar = type === 'horizontal_bar';
		var seriesType = isArea ? 'line' : (isHorizontalBar ? 'bar' : type);
		var option = {
			tooltip: { trigger: 'axis' },
			legend: {}
		};
		if (isHorizontalBar) {
			option.xAxis = { type: 'value' };
			option.yAxis = { type: 'category', data: prepared.categories };
		} else {
			option.xAxis = { type: 'category', data: prepared.categories };
			option.yAxis = { type: 'value' };
		}
		option.series = prepared.seriesSlugs.map(function(slug) {
			return {
				type: seriesType,
				name: columnsMeta[slug] || slug,
				data: prepared.data[slug],
				stack: chartConfig.stack ? 'total' : undefined,
				areaStyle: (seriesType === 'line' && (chartConfig.stack || isArea)) ? {} : undefined
			};
		});
		return option;
	}

	function buildChartOption(context) {
		var type = context.chartConfig.type || 'bar';
		// mode is authoritative for plugin-generated payloads. The type fallback keeps the public
		// BaraTablesCharts.init() API compatible for integrations still constructing old payloads.
		var legacyModes = {gantt: 'gantt', radar: 'radar', pie: 'single_series', donut: 'single_series', treemap: 'treemap', funnel: 'single_series', scatter: 'point', bubble: 'point', heatmap: 'heatmap'};
		var mode = context.chartConfig.mode || legacyModes[type] || 'standard';
		if (mode === 'gantt') {
			var ganttData = buildGanttData(context);
			return ganttData ? buildGanttOption(ganttData, context.helpers.escapeHtml, context.chartConfig.labels) : null;
		}
		if (mode === 'heatmap') {
			return buildHeatmapOption(context);
		}

		var prepared = buildSeriesData(context);
		if (!prepared) {
			return null;
		}
		if (mode === 'single_series') {
			return buildPieLikeOption(type, prepared, context.columnsMeta);
		}
		if (mode === 'treemap') {
			return buildTreemapOption(prepared, context.columnsMeta, context.helpers.escapeHtml);
		}
		if (mode === 'radar') {
			return buildRadarOption(prepared, context.columnsMeta);
		}
		if (mode === 'point') {
			return buildPointOption(type, prepared, context.columnsMeta, context.helpers.parseOptionalNumber);
		}
		return buildCartesianOption(type, prepared, context.columnsMeta, context.chartConfig);
	}

	function eachItem(value, callback) {
		var list = Array.isArray(value) ? value : (value ? [value] : []);
		list.forEach(callback);
	}

	// Split lines only show on value axes by default; creating them on category axes would
	// ADD grid lines the chart never had. Axis lines are the reverse, so category axes get
	// theirs colored while value axes keep theirs hidden. An axis that explicitly hides
	// either line (show: false, from a builder or a hand-built payload) is never re-shown.
	function applyAxisStyle(axis, textColor, gridColor, fontSize) {
		if (!axis || typeof axis !== 'object') { return; }
		if (textColor || fontSize) {
			axis.axisLabel = axis.axisLabel || {};
			if (textColor) { axis.axisLabel.color = textColor; }
			if (fontSize) { axis.axisLabel.fontSize = fontSize; }
			if (axis.name) {
				axis.nameTextStyle = axis.nameTextStyle || {};
				if (textColor) { axis.nameTextStyle.color = textColor; }
				if (fontSize) { axis.nameTextStyle.fontSize = fontSize; }
			}
		}
		if (gridColor) {
			var splitLineVisible = axis.splitLine ? axis.splitLine.show !== false : axis.type === 'value';
			if (splitLineVisible) {
				axis.splitLine = axis.splitLine || { show: true };
				axis.splitLine.lineStyle = axis.splitLine.lineStyle || {};
				axis.splitLine.lineStyle.color = gridColor;
			}
			var axisLineVisible = axis.axisLine ? axis.axisLine.show !== false : axis.type === 'category';
			if (axisLineVisible) {
				axis.axisLine = axis.axisLine || { show: true };
				axis.axisLine.lineStyle = axis.axisLine.lineStyle || {};
				axis.axisLine.lineStyle.color = gridColor;
			}
		}
	}

	/**
	 * Design tokens (2.0, extended in 2.1.0). The chart's own palette replaces the
	 * accent-led default series colors wherever ECharts has not chosen its own; a builder
	 * that sets option.color keeps it. Text, grid, and background then restyle the built
	 * option on top; the background also prints as --btbl-chart-bg on the container from
	 * PHP, so copy/image exports without a canvas background still show it. Colors arrive
	 * sanitized from PHP, but the runtime re-checks so a hand-built integration payload
	 * can never inject a malformed value.
	 *
	 * Every chart type is covered, including the ones whose drawing has no x/y axes: a
	 * radar's indicator names and web, a treemap's tile labels, a heatmap's visualMap
	 * gradient and legend, and the tooltip, which is the one piece of text that never
	 * touches the canvas.
	 */
	function applyChartStyle(option, chartConfig, container, palette) {
		var style = chartConfig || {};
		var textColor = validColor(style.styleTextColor);
		var gridColor = validColor(style.styleGridColor);
		var fontSize = validSize(style.styleFontSize);
		var background = validColor(style.styleBackground);
		var seriesPalette = palette || [];

		if (!option.color && seriesPalette.length) {
			option.color = seriesPalette;
		}
		if (background) {
			option.backgroundColor = background;
		}
		if (textColor || fontSize) {
			option.textStyle = option.textStyle || {};
			if (textColor) { option.textStyle.color = textColor; }
			if (fontSize) { option.textStyle.fontSize = fontSize; }
		}
		eachItem(option.legend, function(legend) {
			legend.textStyle = legend.textStyle || {};
			if (textColor) { legend.textStyle.color = textColor; }
			if (fontSize) { legend.textStyle.fontSize = fontSize; }
		});
		// The tooltip is the one piece of text that is not drawn on the canvas, so it
		// needs its own color: on a dark background ECharts' default tooltip text is
		// unreadable.
		if (textColor || fontSize) {
			option.tooltip = option.tooltip || {};
			option.tooltip.textStyle = option.tooltip.textStyle || {};
			if (textColor) { option.tooltip.textStyle.color = textColor; }
			if (fontSize) { option.tooltip.textStyle.fontSize = fontSize; }
		}
		if (gridColor || textColor || fontSize) {
			eachItem(option.xAxis, function(axis) { applyAxisStyle(axis, textColor, gridColor, fontSize); });
			eachItem(option.yAxis, function(axis) { applyAxisStyle(axis, textColor, gridColor, fontSize); });
		}
		// A radar has no x/y axes: its indicator names and its web live on the radar
		// component, so they need the same treatment the cartesian axes get.
		if (option.radar && (gridColor || textColor || fontSize)) {
			applyRadarStyle(option.radar, textColor, gridColor, fontSize);
		}
		// A treemap's tile labels carry their own default color, which the global text
		// style does not reach.
		if (textColor) {
			eachItem(option.series, function(series) {
				if (series && series.type === 'treemap') {
					series.label = series.label || {};
					series.label.color = textColor;
				}
			});
		}
		// A heatmap's cells are painted by the visualMap rather than by the series
		// palette, so the palette only reaches it through the visualMap's own gradient.
		if (option.visualMap) {
			if (seriesPalette.length) {
				option.visualMap.inRange = option.visualMap.inRange || {};
				option.visualMap.inRange.color = seriesPalette;
			}
			if (textColor || fontSize) {
				option.visualMap.textStyle = option.visualMap.textStyle || {};
				if (textColor) { option.visualMap.textStyle.color = textColor; }
				if (fontSize) { option.visualMap.textStyle.fontSize = fontSize; }
			}
		}
	}

	/**
	 * A radar's own chrome: the indicator names around the web, the web lines, and the
	 * outer axis line. The split areas keep their own background, because the grid color
	 * is a LINE color everywhere else in the plugin.
	 */
	function applyRadarStyle(radar, textColor, gridColor, fontSize) {
		if (!radar || typeof radar !== 'object') { return; }
		if (textColor || fontSize) {
			radar.axisName = radar.axisName || {};
			if (textColor) { radar.axisName.color = textColor; }
			if (fontSize) { radar.axisName.fontSize = fontSize; }
			radar.splitArea = radar.splitArea || {};
			radar.splitArea.axisName = radar.splitArea.axisName || {};
			if (textColor) { radar.splitArea.axisName.color = textColor; }
			if (fontSize) { radar.splitArea.axisName.fontSize = fontSize; }
		}
		if (gridColor) {
			radar.splitLine = radar.splitLine || {};
			radar.splitLine.lineStyle = radar.splitLine.lineStyle || {};
			radar.splitLine.lineStyle.color = gridColor;
			radar.axisLine = radar.axisLine || {};
			radar.axisLine.lineStyle = radar.axisLine.lineStyle || {};
			radar.axisLine.lineStyle.color = gridColor;
		}
	}

	function init(chartConfig, tableInstance, tableId, slugToIndex, helpers) {
		if (!chartConfig || !chartConfig.enabled || !window.echarts) {
			return null;
		}
		chartConfig = normalizeConfig(chartConfig);
		// Chart-only renders read chartConfig.rows, which the server narrows to just the plotted
		// columns and re-indexes. Its own slug=>index map has to win for those rows; when the chart
		// is attached to a table the rows come from DataTables in full width, so the table's map
		// applies. row_slug_index is null when nothing could be narrowed.
		if (!(tableInstance && tableInstance.rows) && chartConfig.row_slug_index) {
			slugToIndex = chartConfig.row_slug_index;
		}
		var container = document.getElementById('btbl-chart-' + tableId);
		if (!container) {
			return null;
		}

		// Dispose any prior instance on this container before re-initializing (e.g. a page builder
		// or AJAX swap re-runs the queue). Without this, chart-only renders -- which have no table
		// 'destroy' hook -- would stack ECharts instances and leak a window resize listener each time.
		var existingChart = window.echarts.getInstanceByDom(container);
		if (existingChart) {
			if (existingChart.__btblResize) {
				window.removeEventListener('resize', existingChart.__btblResize);
			}
			existingChart.dispose();
		}
		var chart = window.echarts.init(container);
		var baseSlugToIndex = slugToIndex;
		var context = {
			chartConfig: chartConfig,
			tableInstance: tableInstance,
			slugToIndex: baseSlugToIndex,
			helpers: helpers,
			columnsMeta: buildColumnsMeta(chartConfig.columns),
			// Resolved once, before any option is built: a Gantt bakes its group colors
			// into the data items while the option is assembled, so the palette has to
			// exist by then.
			palette: resolveSeriesPalette(chartConfig, container)
		};

		var rendered = false;
		function render() {
			if (tableInstance && tableInstance.rows) {
				context.slugToIndex = transposeSlugIndex(tableInstance, baseSlugToIndex);
			}
			var option = buildChartOption(context);
			if (option) {
				rendered = true;
				applyChartStyle(option, chartConfig, container, context.palette);
				chart.setOption(option, true);
			}
		}

		render();
		if (!rendered) {
			// buildChartOption only returns null when the configuration itself is broken (a
			// plotted column was renamed or deleted after publishing) -- never for merely empty
			// data. Report that to the caller instead of leaving a blank container: both
			// consumers (table bootstrap and chart-only bootstrap) surface their runtime error
			// on a null return.
			chart.dispose();
			return null;
		}
		// The Design tab's live restyling re-initializes from this stash (admin-chart.js):
		// config carries the style keys it rewrites, and helpers/slugToIndex are the exact
		// inputs this init() call received.
		container.__btblChart = {config: chartConfig, tableId: tableId, slugToIndex: slugToIndex, helpers: helpers};
		if (tableInstance && tableInstance.on) {
			tableInstance.on('draw', render);
		}

		// Named + stored so the destroy/re-init paths can remove it (see above and the table
		// 'destroy' handler). The disposed guard stops a resize that fires in the debounce window
		// after dispose from throwing "instance is disposed".
		var resizeTimer;
		function onResize() {
			clearTimeout(resizeTimer);
			resizeTimer = setTimeout(function() {
				if (!chart.isDisposed || !chart.isDisposed()) {
					chart.resize();
				}
			}, 150);
		}
		window.addEventListener('resize', onResize);
		chart.__btblResize = onResize;
		return chart;
	}

	window.BaraTablesCharts = {
		init: init
	};
})(window);
