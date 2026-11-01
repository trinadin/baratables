(function (window) {
	var wp = window.wp || {};
	if (!wp.blocks || !wp.element || !wp.components || !wp.i18n) {
		return;
	}

	var createElement = wp.element.createElement;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var __ = wp.i18n.__;
	var sprintf = wp.i18n.sprintf;
	var Placeholder = wp.components.Placeholder;
	var SelectControl = wp.components.SelectControl;
	var Spinner = wp.components.Spinner;
	var ExternalLink = wp.components.ExternalLink;
var Notice = wp.components.Notice;
	var config = window.BaraTablesChartBlockConfig || {};

	// Fetch the picker's chart list once per editor session, straight from admin-ajax. The
	// charts themselves are deliberately hidden from REST, so there is no REST collection to
	// query; the nonce is injected server-side next to this script. check_ajax_referer() only
	// reads _ajax_nonce or _wpnonce -- anything else dies as 403 and the picker shows "no
	// charts" even when charts exist.
	// The endpoint pages at 20 charts; pull follow-up pages while `more` holds so a site with
	// 21+ charts can still embed any of them (cap: 5 pages / 100 charts). When the cap stops
	// the walk early the list is truncated, and the caller must not report a selection that
	// simply sits beyond the cap as deleted.
	function fetchChartPage(page, collected) {
		return window.fetch(config.ajaxUrl + '?action=btbl_block_charts&_ajax_nonce=' + encodeURIComponent(config.nonce) + '&page=' + page)
			.then(function (response) { return response.json(); })
			.then(function (json) {
				var data = json && json.success && json.data ? json.data : {};
				var results = collected.concat(data.results || []);
				var more = !!data.more;
				if (more && page < 5) {
					return fetchChartPage(page + 1, results);
				}
				return { results: results, truncated: more };
			});
	}

	function useCharts() {
		var pair = useState(null);
		var charts = pair[0];
		var setCharts = pair[1];

		useEffect(function() {
			if (charts !== null || !config.ajaxUrl || !config.nonce) {
				return;
			}
			fetchChartPage(1, [])
				.then(function (list) { setCharts(list); })
				.catch(function () {
					setCharts({ results: [], truncated: false });
				});
		}, []);

		return charts;
	}

	function ChartEdit(props) {
		var list = useCharts();
		var selectedId = props.attributes.id || '';

		if (list === null) {
			return createElement(
				Placeholder,
				{ icon: 'chart-bar', label: __('BaraTables chart', 'baratables') },
				createElement(Spinner, null)
			);
		}

		if (list.results.length === 0) {
			return createElement(
				Placeholder,
				{ icon: 'chart-bar', label: __('BaraTables chart', 'baratables') },
				createElement('p', null, __('No charts yet. Create a chart first, then pick it here.', 'baratables')),
				createElement(ExternalLink, {
					href: 'edit.php?post_type=btbl_chart'
				}, __('Go to charts', 'baratables'))
			);
		}

		var options = [{ value: '', label: __('Select a chart...', 'baratables') }];
		var selectionExists = false;
		for (var i = 0; i < list.results.length; i++) {
			options.push({ value: list.results[i].id, label: list.results[i].text });
			if (list.results[i].id === selectedId) {
				selectionExists = true;
			}
		}

		// A saved chart that was deleted would otherwise render as an empty, silent picker --
		// but only claim deletion when the walk actually reached the end of the list.
		var staleNotice = null;
		if (selectedId !== '' && !selectionExists) {
			staleNotice = createElement(
				Notice,
				{ status: 'warning', isDismissible: false },
				list.truncated
					? sprintf(
						/* translators: %d is the number of charts the picker loaded. */
						__('The picker lists only the first %d charts; the selected one may exist beyond them.', 'baratables'),
						list.results.length
					)
					: __('The selected chart no longer exists. Pick another chart.', 'baratables')
			);
		}

		return createElement(
			Placeholder,
			{ icon: 'chart-bar', label: __('BaraTables chart', 'baratables') },
			staleNotice,
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
		title: __('BaraTables chart', 'baratables'),
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
