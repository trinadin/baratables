(function(window, document) {
	'use strict';

	var utils = window.BaraTablesUtils;

	function wrapperFor(config) {
		if (!config || !config.tableId) { return null; }
		var chartElement = document.getElementById('btbl-chart-' + config.tableId);
		return chartElement && chartElement.closest ? chartElement.closest('.btbl-table-wrapper') : null;
	}

	// The failure reveal itself lives in baratables-utils, shared with the table runtime.
	// The guard keeps the no-utils path (the runtime never loaded) able to say so.
	function fail(config, error) {
		if (utils && utils.revealFailure) {
			utils.revealFailure(wrapperFor(config), '[BaraTables] Chart initialization failed.', error);
			return;
		}
		if (window.console && console.error) {
			console.error('[BaraTables] Chart initialization failed.', error || 'Required runtime unavailable.');
		}
	}

	function init(config) {
		if (!config || !config.tableId || !config.chart || !config.chart.enabled || !window.BaraTablesCharts || !utils) {
			fail(config, 'Chart configuration or runtime unavailable.');
			return;
		}
		try {
			var chart = window.BaraTablesCharts.init(config.chart, null, config.tableId, config.slugToIndex || {}, {
				extractText: utils.extractText,
				escapeHtml: utils.escapeHtml,
				parseDate: utils.parseDate,
				parseNumber: utils.parseNumber,
				parseOptionalNumber: utils.parseOptionalNumber,
				slugIdx: utils.slugIndex
			});
			if (!chart) {
				fail(config, 'ECharts could not create the chart.');
				return;
			}
			var wrapper = wrapperFor(config);
			if (wrapper) { wrapper.classList.remove('is-loading'); }
		} catch (error) {
			fail(config, error);
		}
	}

	function drain() {
		var queue = Array.isArray(window.BaraTablesChartQueue) ? window.BaraTablesChartQueue : [];
		while (queue.length) {
			init(queue.shift());
		}
		window.BaraTablesChartQueue = {push: init};
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', drain, {once: true});
	} else {
		drain();
	}
})(window, document);
