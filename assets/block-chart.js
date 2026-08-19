(function (window) {
	var wp = window.wp || {};
	if (!wp.blocks || !wp.element || !wp.components || !wp.i18n) {
		return;
	}

	var createElement = wp.element.createElement;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var __ = wp.i18n.__;
	var Placeholder = wp.components.Placeholder;
	var SelectControl = wp.components.SelectControl;
	var Spinner = wp.components.Spinner;
	var ExternalLink = wp.components.ExternalLink;
	var config = window.BaraTablesChartBlockConfig || {};

	// Fetch the picker's chart list once per editor session, straight from admin-ajax. The
	// charts themselves are deliberately hidden from REST, so there is no REST collection to
	// query; the nonce is injected server-side next to this script. check_ajax_referer() only
	// reads _ajax_nonce or _wpnonce -- anything else dies as 403 and the picker shows "no
	// charts" even when charts exist.
	function useCharts() {
		var pair = useState(null);
		var charts = pair[0];
		var setCharts = pair[1];

		useEffect(function() {
			if (charts !== null || !config.ajaxUrl || !config.nonce) {
				return;
			}
			window.fetch(config.ajaxUrl + '?action=btbl_block_charts&_ajax_nonce=' + encodeURIComponent(config.nonce))
				.then(function (response) { return response.json(); })
				.then(function (json) {
					setCharts(json && json.success && json.data ? (json.data.results || []) : []);
				})
				.catch(function () {
					setCharts([]);
				});
		}, []);

		return charts;
	}

	function ChartEdit(props) {
		var charts = useCharts();
		var selectedId = props.attributes.id || '';

		if (charts === null) {
			return createElement(
				Placeholder,
				{ icon: 'chart-bar', label: __('BaraTables Chart', 'baratables') },
				createElement(Spinner, null)
			);
		}

		if (charts.length === 0) {
			return createElement(
				Placeholder,
				{ icon: 'chart-bar', label: __('BaraTables Chart', 'baratables') },
				createElement('p', null, __('No charts yet. Create a chart first, then pick it here.', 'baratables')),
				createElement(ExternalLink, {
					href: 'edit.php?post_type=btbl_chart'
				}, __('Go to Charts', 'baratables'))
			);
		}

		var options = [{ value: '', label: __('Select a chart...', 'baratables') }];
		for (var i = 0; i < charts.length; i++) {
			options.push({ value: charts[i].id, label: charts[i].text });
		}

		return createElement(
			Placeholder,
			{ icon: 'chart-bar', label: __('BaraTables Chart', 'baratables') },
			createElement(SelectControl, {
				label: __('Chart', 'baratables'),
				value: selectedId,
				options: options,
				onChange: function (value) {
					props.setAttributes({ id: value });
				}
			}),
			createElement('p', null, __('The chart renders on your site.', 'baratables'))
		);
	}

	wp.blocks.registerBlockType('baratables/chart', {
		apiVersion: 2,
		title: __('BaraTables Chart', 'baratables'),
		description: __('Embed a BaraTables chart.', 'baratables'),
		icon: 'chart-bar',
		category: 'widgets',
		keywords: ['chart', 'baratables'],
		attributes: {
			id: { type: 'string', default: '' }
		},
		supports: {
			html: false
		},
		edit: ChartEdit,
		// Dynamic block: the server render_callback (which runs the [bara_chart] shortcode
		// pipeline) is the single source of rendered output.
		save: function () {
			return null;
		}
	});
})(window);
