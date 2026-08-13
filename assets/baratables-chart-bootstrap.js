(function(window, document) {
	'use strict';

	var utils = window.BaraTablesUtils;

	function wrapperFor(config) {
		if (!config || !config.tableId) { return null; }
		var chartElement = document.getElementById('btbl-chart-' + config.tableId);
		return chartElement && chartElement.closest ? chartElement.closest('.btbl-table-wrapper') : null;
	}

	function fail(config, error) {
		var wrapper = wrapperFor(config);
		if (wrapper) {
			wrapper.classList.remove('is-loading');
			wrapper.classList.add('is-init-failed');
			var message = wrapper.querySelector('.btbl-runtime-error');
			if (message) { message.hidden = false; }
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
