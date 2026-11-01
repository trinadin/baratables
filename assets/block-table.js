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
	var config = window.BaraTablesBlockConfig || {};

	// Fetch the picker's table list once per editor session, straight from admin-ajax. The
	// tables themselves are deliberately hidden from REST, so there is no REST collection to
	// query; the nonce is injected server-side next to this script. check_ajax_referer()
	// only reads _ajax_nonce or _wpnonce -- a "nonce" param dies as 403 and the picker
	// shows "no tables" even when tables exist.
	// The endpoint pages at 20 tables; pull follow-up pages while `more` holds so a site with
	// 21+ tables can still embed any of them (cap: 5 pages / 100 tables). When the cap stops
	// the walk early the list is truncated, and the caller must not report a selection that
	// simply sits beyond the cap as deleted.
	function fetchTablePage(page, collected) {
		return window.fetch(config.ajaxUrl + '?action=btbl_block_tables&_ajax_nonce=' + encodeURIComponent(config.nonce) + '&page=' + page)
			.then(function (response) { return response.json(); })
			.then(function (json) {
				var data = json && json.success && json.data ? json.data : {};
				var results = collected.concat(data.results || []);
				var more = !!data.more;
				if (more && page < 5) {
					return fetchTablePage(page + 1, results);
				}
				return { results: results, truncated: more };
			});
	}

	function useTables() {
		var pair = useState(null);
		var tables = pair[0];
		var setTables = pair[1];

		useEffect(function() {
			if (tables !== null || !config.ajaxUrl || !config.nonce) {
				return;
			}
			fetchTablePage(1, [])
				.then(function (list) { setTables(list); })
				.catch(function () {
					setTables({ results: [], truncated: false });
				});
		}, []);

		return tables;
	}

	function TableEdit(props) {
		var list = useTables();
		var selectedId = props.attributes.id || '';

		if (list === null) {
			return createElement(
				Placeholder,
				{ icon: 'grid-view', label: __('BaraTables table', 'baratables') },
				createElement(Spinner, null)
			);
		}

		if (list.results.length === 0) {
			return createElement(
				Placeholder,
				{ icon: 'grid-view', label: __('BaraTables table', 'baratables') },
				createElement('p', null, __('No tables yet. Create a table first, then pick it here.', 'baratables')),
				createElement(ExternalLink, {
					href: 'edit.php?post_type=btbl_table'
				}, __('Go to tables', 'baratables'))
			);
		}

		var options = [{ value: '', label: __('Select a table...', 'baratables') }];
		var selectionExists = false;
		for (var i = 0; i < list.results.length; i++) {
			options.push({ value: list.results[i].id, label: list.results[i].text });
			if (list.results[i].id === selectedId) {
				selectionExists = true;
			}
		}

		// A saved table that was deleted would otherwise render as an empty, silent picker --
		// but only claim deletion when the walk actually reached the end of the list.
		var staleNotice = null;
		if (selectedId !== '' && !selectionExists) {
			staleNotice = createElement(
				Notice,
				{ status: 'warning', isDismissible: false },
				list.truncated
					? sprintf(
						/* translators: %d is the number of tables the picker loaded. */
						__('The picker lists only the first %d tables; the selected one may exist beyond them.', 'baratables'),
						list.results.length
					)
					: __('The selected table no longer exists. Pick another table.', 'baratables')
			);
		}

		return createElement(
			Placeholder,
			{ icon: 'grid-view', label: __('BaraTables table', 'baratables') },
			staleNotice,
			createElement(SelectControl, {
				label: __('Table', 'baratables'),
				value: selectedId,
				options: options,
				onChange: function (value) {
					props.setAttributes({ id: value });
				}
			}),
			createElement('p', null, __('The interactive table renders on your site.', 'baratables'))
		);
	}

	wp.blocks.registerBlockType('baratables/table', {
		apiVersion: 2,
		title: __('BaraTables table', 'baratables'),
		description: __('Embed an interactive, searchable BaraTables table.', 'baratables'),
		icon: 'grid-view',
		category: 'widgets',
		keywords: ['table', 'baratables'],
		attributes: {
			id: { type: 'string', default: '' }
		},
		supports: {
			html: false
		},
		edit: TableEdit,
		// Dynamic block: the server render_callback (which runs the [bara_table] shortcode
		// pipeline) is the single source of rendered output.
		save: function () {
			return null;
		}
	});
})(window);
