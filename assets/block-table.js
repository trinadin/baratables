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
	var config = window.BaraTablesBlockConfig || {};

		// Fetch the picker's table list once per editor session, straight from admin-ajax. The
		// tables themselves are deliberately hidden from REST, so there is no REST collection to
		// query; the nonce is injected server-side next to this script. check_ajax_referer()
		// only reads _ajax_nonce or _wpnonce -- a "nonce" param dies as 403 and the picker
		// shows "no tables" even when tables exist.
		function useTables() {
			var pair = useState(null);
			var tables = pair[0];
			var setTables = pair[1];

			useEffect(function() {
				if (tables !== null || !config.ajaxUrl || !config.nonce) {
					return;
				}
				window.fetch(config.ajaxUrl + '?action=btbl_block_tables&_ajax_nonce=' + encodeURIComponent(config.nonce))
				.then(function (response) { return response.json(); })
				.then(function (json) {
					setTables(json && json.success && json.data ? (json.data.results || []) : []);
				})
				.catch(function () {
					setTables([]);
				});
		}, []);

		return tables;
	}

	function TableEdit(props) {
		var tables = useTables();
		var selectedId = props.attributes.id || '';

		if (tables === null) {
			return createElement(
				Placeholder,
				{ icon: 'grid-view', label: __('BaraTables Table', 'baratables') },
				createElement(Spinner, null)
			);
		}

		if (tables.length === 0) {
			return createElement(
				Placeholder,
				{ icon: 'grid-view', label: __('BaraTables Table', 'baratables') },
				createElement('p', null, __('No tables yet. Create a table first, then pick it here.', 'baratables')),
				createElement(ExternalLink, {
					href: 'edit.php?post_type=btbl_table'
				}, __('Go to Tables', 'baratables'))
			);
		}

		var options = [{ value: '', label: __('Select a table...', 'baratables') }];
		for (var i = 0; i < tables.length; i++) {
			options.push({ value: tables[i].id, label: tables[i].text });
		}

		return createElement(
			Placeholder,
			{ icon: 'grid-view', label: __('BaraTables Table', 'baratables') },
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
		title: __('BaraTables Table', 'baratables'),
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
