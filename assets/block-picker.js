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
	var config = window.BaraTablesBlockPicker || {};

	// The table and chart blocks are the same picker parameterized by a descriptor: the
	// two editors differ only in their action endpoint, icon, admin list link, and
	// wording. Everything translatable stays here in JS with its msgid, so the template
	// only tracks a reference change.
	var KINDS = [
		{
			name: 'baratables/table',
			configKey: 'table',
			icon: 'grid-view',
			listUrl: 'edit.php?post_type=btbl_table',
			keywords: ['table', 'baratables'],
			title: __('BaraTables table', 'baratables'),
			description: __('Embed an interactive, searchable BaraTables table.', 'baratables'),
			selectLabel: __('Select a table...', 'baratables'),
			emptyMessage: __('No tables yet. Create a table first, then pick it here.', 'baratables'),
			goToLabel: __('Go to tables', 'baratables'),
			controlLabel: __('Table', 'baratables'),
			rendersMessage: __('The interactive table renders on your site.', 'baratables'),
			staleMessage: __('The selected table no longer exists. Pick another table.', 'baratables'),
			/* translators: %d is the number of tables the picker loaded. */
			truncatedMessage: __('The picker lists only the first %d tables; the selected one may exist beyond them.', 'baratables')
		},
		{
			name: 'baratables/chart',
			configKey: 'chart',
			icon: 'chart-bar',
			listUrl: 'edit.php?post_type=btbl_chart',
			keywords: ['chart', 'baratables'],
			title: __('BaraTables chart', 'baratables'),
			description: __('Embed a BaraTables chart.', 'baratables'),
			selectLabel: __('Select a chart...', 'baratables'),
			emptyMessage: __('No charts yet. Create a chart first, then pick it here.', 'baratables'),
			goToLabel: __('Go to charts', 'baratables'),
			controlLabel: __('Chart', 'baratables'),
			rendersMessage: __('The chart renders on your site.', 'baratables'),
			staleMessage: __('The selected chart no longer exists. Pick another chart.', 'baratables'),
			/* translators: %d is the number of charts the picker loaded. */
			truncatedMessage: __('The picker lists only the first %d charts; the selected one may exist beyond them.', 'baratables')
		}
	];

	function kindConfig(kind) {
		var items = config.items || {};
		return items[kind.configKey] || {};
	}

	// Fetch the picker's list once per editor session, straight from admin-ajax. The
	// items themselves are deliberately hidden from REST, so there is no REST collection
	// to query; the nonce is injected server-side next to this script. check_ajax_referer()
	// only reads _ajax_nonce or _wpnonce -- a "nonce" param dies as 403 and the picker
	// shows "no items" even when items exist.
	// The endpoint pages at 20 items; pull follow-up pages while `more` holds so a site
	// with 21+ items can still embed any of them (cap: 5 pages / 100 items). When the cap
	// stops the walk early the list is truncated, and the caller must not report a
	// selection that simply sits beyond the cap as deleted.
	function fetchItemPage(kind, page, collected) {
		var endpoint = config.ajaxUrl + '?action=' + kindConfig(kind).action +
			'&_ajax_nonce=' + encodeURIComponent(kindConfig(kind).nonce || '') +
			'&page=' + page;
		return window.fetch(endpoint)
			.then(function (response) { return response.json(); })
			.then(function (json) {
				var data = json && json.success && json.data ? json.data : {};
				var results = collected.concat(data.results || []);
				var more = !!data.more;
				if (more && page < 5) {
					return fetchItemPage(kind, page + 1, results);
				}
				return { results: results, truncated: more };
			});
	}

	function useItems(kind) {
		var pair = useState(null);
		var items = pair[0];
		var setItems = pair[1];

		useEffect(function () {
			if (items !== null || !config.ajaxUrl || !kindConfig(kind).nonce) {
				return;
			}
			fetchItemPage(kind, 1, [])
				.then(function (list) { setItems(list); })
				.catch(function () {
					setItems({ results: [], truncated: false });
				});
		}, []);

		return items;
	}

	function registerPickerBlock(kind) {
		function PickerEdit(props) {
			var list = useItems(kind);
			var selectedId = props.attributes.id || '';

			if (list === null) {
				return createElement(
					Placeholder,
					{ icon: kind.icon, label: kind.title },
					createElement(Spinner, null)
				);
			}

			if (list.results.length === 0) {
				return createElement(
					Placeholder,
					{ icon: kind.icon, label: kind.title },
					createElement('p', null, kind.emptyMessage),
					createElement(ExternalLink, {
						href: kind.listUrl
					}, kind.goToLabel)
				);
			}

			var options = [{ value: '', label: kind.selectLabel }];
			var selectionExists = false;
			for (var i = 0; i < list.results.length; i++) {
				options.push({ value: list.results[i].id, label: list.results[i].text });
				if (list.results[i].id === selectedId) {
					selectionExists = true;
				}
			}

			// A saved item that was deleted would otherwise render as an empty, silent picker --
			// but only claim deletion when the walk actually reached the end of the list.
			var staleNotice = null;
			if (selectedId !== '' && !selectionExists) {
				staleNotice = createElement(
					Notice,
					{ status: 'warning', isDismissible: false },
					list.truncated
						? sprintf(kind.truncatedMessage, list.results.length)
						: kind.staleMessage
				);
			}

			return createElement(
				Placeholder,
				{ icon: kind.icon, label: kind.title },
				staleNotice,
				createElement(SelectControl, {
					label: kind.controlLabel,
					value: selectedId,
					options: options,
					onChange: function (value) {
						props.setAttributes({ id: value });
					}
				}),
				createElement('p', null, kind.rendersMessage)
			);
		}

		wp.blocks.registerBlockType(kind.name, {
			apiVersion: 2,
			title: kind.title,
			description: kind.description,
			icon: kind.icon,
			category: 'widgets',
			keywords: kind.keywords,
			attributes: {
				id: { type: 'string', default: '' }
			},
			supports: {
				html: false
			},
			edit: PickerEdit,
			// Dynamic block: the server render_callback (which runs the [bara_table] /
			// [bara_chart] shortcode pipeline) is the single source of rendered output.
			save: function () {
				return null;
			}
		});
	}

	for (var i = 0; i < KINDS.length; i++) {
		registerPickerBlock(KINDS[i]);
	}
})(window);
