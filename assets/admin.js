jQuery(function($) {
	var columnOrderController;
	var filterOrderController;
	var isBuilderPage = $('.btbl-admin').length > 0 && !$('.btbl-admin-embed').length;

	function updateSortVisibility($label) {
		var checked = $label.find('input[type="checkbox"][name="btbl_columns[]"]').is(':checked');
		var $sortToggle = $label.find('.btbl-sort-enabled');
		var sortEnabled = $sortToggle.is(':checked') && checked;
		var $sortPriority = $label.find('.btbl-sort-priority');
		var $row = $label.find('.btbl-sort-priority').closest('.btbl-options-row');
		var wasEnabled = $row.data('sort-enabled') === true;
		$row.data('sort-enabled', sortEnabled);
		$row.toggleClass('is-hidden', !sortEnabled);
		// Keep priority blank unless the user explicitly sets it.
		if (sortEnabled && $sortPriority.length) {
			var priorityVal = ($sortPriority.val() || '').trim();
			if (!priorityVal || parseInt(priorityVal, 10) < 1) {
				$sortPriority.val('1');
			}
		}
		$sortToggle.closest('.btbl-inline').toggleClass('is-hidden', !checked);
	}

	function isActivationEvent(e) {
		return e.type === 'click' || (e.type === 'keydown' && (e.key === 'Enter' || e.key === ' '));
	}

	function isDefaultChecked($checkbox) {
		if (!$checkbox || !$checkbox.length) {
			return false;
		}
		var raw = $checkbox.data('default');
		if (raw === undefined) {
			raw = $checkbox.attr('data-default');
		}
		if (typeof raw === 'string') {
			raw = raw.toLowerCase();
			return raw === '1' || raw === 'true';
		}
		return !!raw;
	}

	function applyDefaultIfUntouched($checkbox) {
		if (!$checkbox || !$checkbox.length) {
			return;
		}
		if ($checkbox.data('touched')) {
			return;
		}
		if (isDefaultChecked($checkbox)) {
			$checkbox.prop('checked', true);
		}
	}

	function setOptionsOpen($label, open, fromUser) {
		var $body = $label.find('.btbl-field-options-body');
		var $toggle = $label.find('.btbl-options-toggle');
		$body.toggleClass('is-open', open);
		if ($toggle.length) {
			$toggle.attr('aria-expanded', open ? 'true' : 'false');
		}
	}

	function toggleFilterControls($label) {
		var $columnCheckbox = $label.find('input[type="checkbox"][name="btbl_columns[]"]');
		var $select = $label.find('.btbl-filter-select');
		var $sortSelect = $label.find('.btbl-filter-sort');
		var $dropdownInputs = $label.find('input[type="checkbox"][name^="btbl_dropdown_"]');
		var $dropdownRows = $dropdownInputs.closest('.btbl-options-row');
		var $customLabelRow = $label.find('input[name^="btbl_custom_labels"]').closest('.btbl-options-row');
		var $customLabelInput = $customLabelRow.find('input[type="text"]');
		var $hideColumnToggle = $label.find('.btbl-hide-column');
		var $sortRow = $label.find('.btbl-sort-priority').closest('.btbl-options-row');
		var $sortToggle = $label.find('.btbl-sort-enabled');
		var $sortableToggle = $label.find('.btbl-sortable-toggle');
		var $sortableRow = $sortableToggle.closest('.btbl-options-row');
		var $filterSortRow = $label.find('.btbl-filter-sort-row');
		var $filterValuesRow = $label.find('.btbl-filter-values-row');
		var $filterLabelRow = $label.find('.btbl-filter-label-row');
		var $filterStrictRow = $label.find('.btbl-filter-strict-row');
		var $dateRow = $label.find('.btbl-date-format-row');
		var isDateCandidate = $dateRow.data('date-candidate') === 1 || $dateRow.data('date-candidate') === '1' || $dateRow.data('date-candidate') === true;
		var $fieldControls = $label.find('.btbl-field-controls');
		var $optionsToggle = $label.find('.btbl-options-toggle');
		var checked = $columnCheckbox.is(':checked');
		var orderingEnabled = $('input[name="btbl_table_options[ordering]"]').is(':checked');

		var hideColumnChecked = $hideColumnToggle.is(':checked');
		var filterType = $select.val() || 'none';
		var filterSort = $sortSelect.val() || 'asc';
		var customFilterSort = filterSort === 'custom' || filterSort === 'none';
		var sortEnabled = $sortToggle.is(':checked');

		var headingInputEnabled = checked && !hideColumnChecked;

		var filterEnabled = filterType !== 'none';
		var dropdownEnabled = checked && filterType === 'dropdown';
		var dateRowEnabled = checked;

		var sortableEnabled = checked && !hideColumnChecked && orderingEnabled;
		var sortToggleEnabled = checked;
		var sortInputsEnabled = sortEnabled && checked;

		$optionsToggle.toggleClass('is-hidden', !checked);
		$fieldControls.toggle(checked);

		$customLabelRow.removeClass('is-hidden').toggleClass('is-hidden', !headingInputEnabled);

		$hideColumnToggle.closest('.btbl-inline').toggleClass('is-hidden', !checked);
		$customLabelInput.closest('.btbl-inline').toggleClass('is-hidden', !headingInputEnabled);

		$select.closest('.btbl-inline').toggleClass('is-hidden', !checked);
		$sortSelect.closest('.btbl-inline').toggleClass('is-hidden', !filterEnabled);

		if ($dropdownRows.length) {
			$dropdownRows.toggleClass('is-hidden', !dropdownEnabled);
		}

		$filterSortRow.toggleClass('is-hidden', !filterEnabled || !customFilterSort);
		$filterValuesRow.toggleClass('is-hidden', !filterEnabled);
		$filterLabelRow.toggleClass('is-hidden', !filterEnabled);
		$filterStrictRow.toggleClass('is-hidden', !filterEnabled);

		if (isDateCandidate) {
			$dateRow.toggleClass('is-hidden', !dateRowEnabled);
		} else {
			$dateRow.addClass('is-hidden');
		}

		$sortableRow.toggleClass('is-hidden', !sortableEnabled);
		// Keep sortable state; default handled by server-side state.

		$sortRow.toggleClass('is-hidden', !sortInputsEnabled);
		// Leave sort priority empty until the user sets a value.

		if (!filterEnabled) {
			$select.val('none');
			$sortSelect.val('asc');
		}
		if (!dropdownEnabled) {
			$dropdownInputs.prop('checked', false);
		}
		if (!sortToggleEnabled) {
			$sortToggle.prop('checked', false);
			$sortRow.addClass('is-hidden');
		}

		if (!checked) {
			setOptionsOpen($label, false, false);
			$select.val('none');
			$sortSelect.val('asc');
			$dropdownInputs.prop('checked', false);
		}

		updateSortVisibility($label);
	}

	// Bind a single column row's controls. Extracted so swapped-in rows (AJAX
	// post-type refresh) can be re-initialised without a page reload.
	function initColumnOption($label) {
		toggleFilterControls($label);
		$label.find('input[type="checkbox"][name="btbl_columns[]"]').on('change', function() {
			toggleFilterControls($label);
			syncOrderFromSelection();
			syncFilterOrderFromSelection();
		});
		$label.find('.btbl-hide-column').on('change', function() {
			toggleFilterControls($label);
		});
		$label.find('.btbl-filter-select').on('change', function() {
			toggleFilterControls($label);
			syncFilterOrderFromSelection();
		});
		$label.find('.btbl-filter-sort').on('change', function() {
			toggleFilterControls($label);
		});
		$label.find('.btbl-sort-enabled, .btbl-sort-priority, .btbl-sort-direction').on('change input', function() {
			updateSortVisibility($label);
		});
		$label.find('.btbl-sortable-toggle').on('change', function() {
			$(this).data('touched', true);
		});
		$label.find('.btbl-options-toggle').on('click', function(e) {
			e.preventDefault();
			var isOpen = $label.find('.btbl-field-options-body').hasClass('is-open');
			setOptionsOpen($label, !isOpen, true);
		});
	}

	$('#btbl-tab-columns .btbl-checkbox').each(function() {
		initColumnOption($(this));
	});

	$(document).on('change', 'input[name="btbl_table_options[ordering]"]', function() {
		$('#btbl-tab-columns .btbl-checkbox').each(function() {
			toggleFilterControls($(this));
		});
	});

	var $sourceSelect = $('#btbl_source_type');
	var $sourceBlocks = $('[data-btbl-source]');
	var $taxonomySelect = $('#btbl_taxonomy');
	var $taxGroups = $('.btbl-tax-terms-group');
	var $taxFilterRows = $('.btbl-taxonomy-filter');
	var $taxTermPickers = $('.btbl-taxonomy-term-picker');
	function syncTaxonomyTerms() {
		var source = $sourceSelect.length ? $sourceSelect.val() || 'wp_query' : 'wp_query';
		var isWpQuerySource = source === 'wp_query';
		var selected = $taxonomySelect.val() || [];
		if (!Array.isArray(selected)) {
			selected = selected ? [selected] : [];
		}
		var hasSelection = selected.length > 0 && isWpQuerySource;
		$taxFilterRows.toggleClass('is-hidden', !hasSelection);
		$taxTermPickers.toggleClass('is-hidden', !hasSelection);
		$taxGroups.each(function() {
			var $group = $(this);
			var matches = isWpQuerySource && selected.indexOf($group.data('taxonomy')) !== -1;
			$group.toggleClass('is-hidden', !matches);
		});
	}
	function syncSourceVisibility() {
		var selected = $sourceSelect.length ? $sourceSelect.val() || 'wp_query' : 'wp_query';
		$sourceBlocks.each(function() {
			var $block = $(this);
			var target = $block.data('btbl-source') || 'wp_query';
			var show = target === selected;
			$block.toggleClass('is-hidden', !show);
		});
		syncTaxonomyTerms();
	}
	if ($sourceSelect.length) {
		$sourceSelect.on('change', function() {
			syncSourceVisibility();
			// Each source's control block is already in the DOM (toggled above), so switching only
			// needs to refresh the source-dependent columns panel — done in place via
			// btbl_refresh_fields (the same AJAX swap the post-type switch uses), no full reload.
			// CSV column inference still runs through the CSV controls' own refresh (file upload /
			// delimiter / header), and custom-query/external columns load via their own actions.
			var postTypeVal = $postTypeSelect.length ? $postTypeSelect.val() : '';
			var typeParam = Array.isArray(postTypeVal) ? postTypeVal.join(',') : (postTypeVal || '');
			refreshSourceFields(typeParam);
		});
		syncSourceVisibility();
	}

	var $postTypeSelect = $('#btbl_post_type');
	if ($postTypeSelect.length) {
		$postTypeSelect.on('change', function() {
			var selected = $(this).val();
			var typeParam = Array.isArray(selected) ? selected.join(',') : (selected || '');
			refreshSourceFields(typeParam);
		});
	}

	// Legacy full-page reload — used as a graceful fallback if the AJAX refresh fails.
	function legacyPostTypeReload(typeParam) {
		var url = new URL(window.location.href);
		if (isBuilderPage) {
			var editId = $postTypeSelect.data('edit-id');
			var pageSlug = $postTypeSelect.data('page') || 'baratables';
			url.searchParams.set('page', pageSlug);
			if (editId) {
				url.searchParams.set('id', editId);
			} else {
				url.searchParams.delete('id');
			}
		}
		url.searchParams.set('type', typeParam || '');
		window.location.href = url.toString();
	}

	// Fetch the post-type-dependent fields via admin-ajax and swap them in place
	// instead of reloading the whole editor.
	var fieldRefreshSeq = 0;
	function refreshSourceFields(typeParam) {
		var $form = $postTypeSelect.closest('form');
		if (!$form.length) { $form = $('#post'); }
		var nonce = $form.find('input[name="_baratables_nonce"]').val() || '';
		var postId = $('#post_ID').val() || $form.find('input[name="post_ID"]').val() || 0;
		var source = $sourceSelect.length ? ($sourceSelect.val() || 'wp_query') : 'wp_query';
		// CSV columns come from the selected file (id/delimiter/header), not the post type. The
		// generic fields refresh doesn't carry those params, so it would blank the columns while
		// leaving a file in the uploader. Route CSV through the CSV-aware refresh instead, which
		// re-infers the columns of whatever file is still selected (or clears them if none is).
		if (source === 'csv') {
			triggerCsvPreviewRefresh();
			return;
		}
		if (!nonce || !window.ajaxurl) {
			legacyPostTypeReload(typeParam);
			return;
		}
		var seq = ++fieldRefreshSeq;
		$.post(window.ajaxurl, {
			action: 'btbl_refresh_fields',
			post_id: postId,
			type: typeParam,
			source: source,
			_baratables_nonce: nonce
		}).done(function(resp) {
			if (seq !== fieldRefreshSeq) { return; } // a newer toggle superseded this response
			if (resp && resp.success && resp.data) {
				applyFieldRefresh(resp.data);
			} else {
				legacyPostTypeReload(typeParam);
			}
		}).fail(function() {
			if (seq === fieldRefreshSeq) {
				legacyPostTypeReload(typeParam);
			}
		});
	}

	function applyFieldRefresh(data) {
		if (typeof data.columns === 'string') {
			var $newCols = $('<div>').html(data.columns);
			var $newFieldset = $newCols.find('.btbl-columns').first();
			if ($newFieldset.length) {
				$('#btbl-tab-columns .btbl-columns').first().replaceWith($newFieldset);
				$('#btbl-tab-columns .btbl-checkbox').each(function() {
					initColumnOption($(this));
				});
			}
		}
		if (typeof data.source === 'string') {
			var $newSrc = $('<div>').html(data.source);
			['.btbl-taxonomy-select', '.btbl-taxonomy-filter'].forEach(function(sel) {
				var $frag = $newSrc.find(sel).first();
				var $old = $(sel).first();
				if ($frag.length && $old.length) {
					$old.replaceWith($frag);
				}
			});
			$('.btbl-taxonomy-select .btbl-chip-picker').each(function() {
				initChipPicker($(this));
			});
			$('.btbl-tax-terms-group').each(function() {
				updateTermCount($(this));
			});
		}
		syncSourceVisibility();
		syncOrderFromSelection();
		syncFilterOrderFromSelection();
		if (typeof syncTaxonomyTerms === 'function') {
			syncTaxonomyTerms();
		}
	}

	var $chartTableSelect = $('#btbl_chart_table');
	if ($chartTableSelect.length) {
		$chartTableSelect.on('focus', function() {
			$(this).data('previous', $(this).val());
		});
		$chartTableSelect.on('change', function() {
			// R29: warn before the reload discards the current column choices.
			var hasChoices = ($('#btbl_chart_x_axis').val() || '') !== '' ||
				$('#btbl_chart_series input[type="checkbox"]:checked').length > 0;
			var confirmMsg = $(this).data('switch-confirm');
			if (hasChoices && confirmMsg && !window.confirm(confirmMsg)) {
				$(this).val($(this).data('previous') || '');
				return;
			}
			var selected = $(this).val() || '';
			$(this).data('previous', selected); // keep the revert target in sync after a switch
			refreshChartFields(selected);
		});
	}

	// Switching a chart's source table rebuilds its X-axis/series/gantt pickers in place via
	// admin-ajax (btbl_refresh_chart_fields) instead of a full page reload.
	var chartRefreshSeq = 0;
	function legacyChartReload(tableId) {
		var url = new URL(window.location.href);
		if (tableId) { url.searchParams.set('table', tableId); } else { url.searchParams.delete('table'); }
		if (isBuilderPage) {
			var editId = $('#btbl_chart_id').val() || '';
			var pageSlug = $chartTableSelect.data('page') || 'wp-btbl-charts-add';
			url.searchParams.set('page', pageSlug);
			if (editId) { url.searchParams.set('id', editId); }
		}
		window.location.href = url.toString();
	}
	function refreshChartFields(tableId) {
		var $form = $chartTableSelect.closest('form');
		if (!$form.length) { $form = $('#post'); }
		var nonce = $form.find('input[name="_baratables_nonce"]').val() || '';
		var postId = $('#post_ID').val() || $form.find('input[name="post_ID"]').val() || 0;
		if (!nonce || !window.ajaxurl) { legacyChartReload(tableId); return; }
		var seq = ++chartRefreshSeq;
		$.post(window.ajaxurl, {
			action: 'btbl_refresh_chart_fields',
			post_id: postId,
			table_id: tableId,
			_baratables_nonce: nonce
		}).done(function(resp) {
			if (seq !== chartRefreshSeq) { return; }
			if (resp && resp.success && resp.data && typeof resp.data.panel === 'string') {
				applyChartFieldRefresh(resp.data.panel);
			} else {
				legacyChartReload(tableId);
			}
		}).fail(function() {
			if (seq === chartRefreshSeq) { legacyChartReload(tableId); }
		});
	}
	function applyChartFieldRefresh(panelHtml) {
		var $new = $('<div>').html(panelHtml);
		// Swap only the INNER content of the live, handler-bound nodes — never replace the
		// <select>/<div> elements themselves, or their directly-bound change handlers are orphaned.
		['#btbl_chart_x_axis', '#btbl_chart_gantt_label', '#btbl_chart_gantt_start', '#btbl_chart_gantt_end', '#btbl_chart_gantt_group', '#btbl_chart_gantt_progress'].forEach(function(sel) {
			var $frag = $new.find(sel).first();
			var $old = $(sel).first();
			if ($frag.length && $old.length) { $old.html($frag.html()); }
		});
		var $seriesFrag = $new.find('#btbl_chart_series').first();
		if ($seriesFrag.length) { $('#btbl_chart_series').first().html($seriesFrag.html()); }
		var $oldNotice = $('#btbl-tab-chart .btbl-dropped-columns').first();
		var $newNotice = $new.find('.btbl-dropped-columns').first();
		if ($newNotice.length) {
			if ($oldNotice.length) { $oldNotice.replaceWith($newNotice); } else { $('#btbl-tab-chart').first().prepend($newNotice); }
		} else if ($oldNotice.length) {
			$oldNotice.remove();
		}
		if (typeof toggleChartControlsUI === 'function') { toggleChartControlsUI(); }
		if (typeof syncChartSeriesOptions === 'function') { syncChartSeriesOptions(); }
	}
	if ($taxonomySelect.length) {
		$taxonomySelect.on('change', syncTaxonomyTerms);
		syncTaxonomyTerms();
	}

	function normalizeSelectValues(values) {
		if (!values) {
			return [];
		}
		if (Array.isArray(values)) {
			return values.map(function(item) {
				return String(item);
			});
		}
		return [String(values)];
	}

	function initChipPicker($picker) {
		var target = $picker.data('btbl-target');
		if (!target) {
			return;
		}
		var $select = $(target);
		if (!$select.length) {
			return;
		}

		function syncChips() {
			var selected = normalizeSelectValues($select.val());
			$picker.find('.btbl-chip').each(function() {
				var $chip = $(this);
				var value = String($chip.data('value'));
				var isSelected = selected.indexOf(value) !== -1;
				$chip.toggleClass('is-selected', isSelected);
				$chip.attr('aria-pressed', isSelected ? 'true' : 'false');
			});
		}

		$picker.on('click', '.btbl-chip', function() {
			var $chip = $(this);
			if ($chip.hasClass('is-disabled') || $chip.attr('aria-disabled') === 'true') {
				return;
			}
			var value = String($chip.data('value'));
			var selected = normalizeSelectValues($select.val());
			var index = selected.indexOf(value);
			if (index === -1) {
				selected.push(value);
			} else {
				selected.splice(index, 1);
			}
			$select.val(selected);
			syncChips();
			$select.trigger('change');
		});

		syncChips();
	}

	function initChipPickers() {
		$('.btbl-chip-picker').each(function() {
			initChipPicker($(this));
		});
	}

	function formatTermCount($count, count) {
		var emptyText = $count.data('empty') || 'No terms selected';
		var singular = $count.data('singular') || '%d term selected';
		var plural = $count.data('plural') || '%d terms selected';
		if (!count) {
			return emptyText;
		}
		var template = count === 1 ? singular : plural;
		return template.replace('%d', count);
	}

	function updateTermCount($group) {
		var $count = $group.find('.btbl-term-count');
		if (!$count.length) {
			return;
		}
		var total = $group.find('.btbl-term-chip input:checked').length;
		$count.text(formatTermCount($count, total));
	}

	function filterTermChips($group, query) {
		var search = (query || '').toLowerCase().trim();
		var $chips = $group.find('.btbl-term-chip');
		var visible = 0;
		if (!search) {
			$chips.removeClass('is-hidden');
			visible = $chips.length;
		} else {
			$chips.each(function() {
				var $chip = $(this);
				var text = $chip.text().toLowerCase();
				var matches = text.indexOf(search) !== -1;
				$chip.toggleClass('is-hidden', !matches);
				if (matches) {
					visible += 1;
				}
			});
		}
		var $empty = $group.find('.btbl-term-empty');
		$empty.toggleClass('is-hidden', visible > 0 || !search);
	}

	function initTermPickers() {
		$('.btbl-tax-terms-group').each(function() {
			var $group = $(this);
			updateTermCount($group);
		});

		$(document).on('input', '.btbl-term-search', function() {
			var $input = $(this);
			var $group = $input.closest('.btbl-tax-terms-group');
			filterTermChips($group, $input.val());
		});

		$(document).on('click', '.btbl-term-action', function() {
			var $button = $(this);
			var action = $button.data('action');
			var $group = $button.closest('.btbl-tax-terms-group');
			var $targets = $group.find('.btbl-term-chip').not('.is-hidden').find('input[type="checkbox"]');
			var shouldCheck = action === 'select-all';
			$targets.prop('checked', shouldCheck);
			updateTermCount($group);
		});

		$(document).on('change', '.btbl-term-chip input[type="checkbox"]', function() {
			var $group = $(this).closest('.btbl-tax-terms-group');
			updateTermCount($group);
		});
	}

	initChipPickers();
	initTermPickers();

	var mediaFrame;
	$(document).on('click', '.btbl-media-select', function(e) {
		e.preventDefault();
		var targetSelector = $(this).data('target');
		var frameTitle = $(this).data('frame-title') || 'Select CSV file';
		var frameButton = $(this).data('frame-button') || 'Use CSV';
		var $target = $(targetSelector);
		if (!$target.length) {
			return;
		}
		if (typeof wp === 'undefined' || !wp.media) {
			if (window.console && console.warn) {
				console.warn('Media library unavailable; cannot pick CSV file.');
			}
			return;
		}
		if (mediaFrame) {
			mediaFrame.off('select');
		}
		mediaFrame = wp.media({
			title: frameTitle,
			button: { text: frameButton },
			library: { type: ['text/csv', 'text/plain', 'application/vnd.ms-excel'] },
			multiple: false,
		});
		mediaFrame.on('select', function() {
			var attachment = mediaFrame.state().get('selection').first().toJSON();
			$target.val(attachment.id);
			$(targetSelector).siblings('.btbl-media-clear').show();
			triggerCsvPreviewRefresh();
		});
		mediaFrame.open();
	});

	$(document).on('click', '.btbl-media-clear', function(e) {
		e.preventDefault();
		var targetSelector = $(this).data('target');
		var $target = $(targetSelector);
		if ($target.length) {
			$target.val('');
		}
		$(this).hide();
		triggerCsvPreviewRefresh(false, { clearCsv: true });
	});

	function triggerCsvPreviewRefresh(forceWpQuery, options) {
		var opts = options || {};
		var clearCsv = !!opts.clearCsv;
		var source = $sourceSelect.length ? $sourceSelect.val() || 'wp_query' : 'wp_query';
		if (!forceWpQuery && source !== 'csv' && !clearCsv) {
			return;
		}
		var csvId = clearCsv ? '0' : ($('#btbl_csv_attachment_id').val() || '');
		var delim = $('#btbl_csv_delimiter').val() || ',';
		var hasHeader = $('#btbl_csv_has_header').is(':checked') ? '1' : '0';
		// Fallback: the original full-page reload (used if AJAX is unavailable or the request fails).
		function legacyCsvReload() {
			var url = new URL(window.location.href);
			if (clearCsv) {
				url.searchParams.set('btbl_preview_csv_id', '0');
				url.searchParams.delete('btbl_preview_csv_delim');
				url.searchParams.delete('btbl_preview_csv_header');
			} else if (csvId) {
				url.searchParams.set('btbl_preview_csv_id', csvId);
				url.searchParams.set('btbl_preview_csv_delim', delim);
				url.searchParams.set('btbl_preview_csv_header', hasHeader);
			} else {
				url.searchParams.delete('btbl_preview_csv_id');
				url.searchParams.delete('btbl_preview_csv_delim');
				url.searchParams.delete('btbl_preview_csv_header');
			}
			url.searchParams.set('btbl_source', source);
			var activeTab = $('.btbl-tab-link.nav-tab-active').data('target') || '';
			if (activeTab) { url.searchParams.set('tab', activeTab); }
			window.location.href = url.toString();
		}
		// Infer the CSV columns in place via the shared fields endpoint instead of reloading.
		var $form = $('#btbl_csv_attachment_id').closest('form');
		if (!$form.length) { $form = $('#post'); }
		var nonce = $form.find('input[name="_baratables_nonce"]').val() || '';
		var postId = $('#post_ID').val() || $form.find('input[name="post_ID"]').val() || 0;
		if (!nonce || !window.ajaxurl) { legacyCsvReload(); return; }
		var typeVal = $postTypeSelect.length ? $postTypeSelect.val() : '';
		var payload = {
			action: 'btbl_refresh_fields',
			post_id: postId,
			type: Array.isArray(typeVal) ? typeVal.join(',') : (typeVal || ''),
			source: 'csv',
			_baratables_nonce: nonce
		};
		if (clearCsv) {
			payload.csv_id = '0';
		} else if (csvId) {
			payload.csv_id = csvId;
			payload.csv_delim = delim;
			payload.csv_header = hasHeader;
		} else {
			payload.csv_id = '0';
		}
		var seq = ++fieldRefreshSeq;
		$.post(window.ajaxurl, payload).done(function(resp) {
			if (seq !== fieldRefreshSeq) { return; }
			if (resp && resp.success && resp.data) {
				applyFieldRefresh(resp.data);
			} else {
				legacyCsvReload();
			}
		}).fail(function() {
			if (seq === fieldRefreshSeq) { legacyCsvReload(); }
		});
	}

	$('#btbl_csv_delimiter, #btbl_csv_has_header').on('change input', function() {
		triggerCsvPreviewRefresh();
	});

	var $customGrid = $('#btbl_custom_grid');
	var $customColsInput = $('#btbl_custom_columns_count');
	var $customRowsInput = $('#btbl_custom_rows_count');
	var $customRefresh = $('#btbl_custom_grid_refresh');
	var $customQueryRefresh = $('#btbl_custom_query_refresh');
	var $customQueryInput = $('#btbl_custom_query_json');

	function clampNumber(val, min, max) {
		var num = parseInt(val, 10);
		if (isNaN(num)) {
			num = min;
		}
		return Math.min(Math.max(num, min), max);
	}

	function getCustomCounts() {
		var cols = $customGrid.data('cols') || 1;
		var rows = $customGrid.data('rows') || 1;
		return {
			cols: clampNumber($customColsInput.val() || cols, 1, 50),
			rows: clampNumber($customRowsInput.val() || rows, 1, 500),
		};
	}

	var gridLabels = {
		moveUp: $customGrid.data('label-move-up') || 'Move row up',
		moveDown: $customGrid.data('label-move-down') || 'Move row down',
		insert: $customGrid.data('label-insert') || 'Insert row below',
		duplicate: $customGrid.data('label-duplicate') || 'Duplicate row',
		remove: $customGrid.data('label-delete') || 'Delete row'
	};
	var gridConfirmShrink = $customGrid.data('confirm-shrink') || 'Reducing the grid will remove %d filled cell(s). Continue?';

	// R17: read the current header labels from the DOM so resizes preserve them
	// instead of resetting to the generic "Column N" placeholders.
	function readCustomGridHeaders() {
		var headers = [];
		$customGrid.find('thead th').each(function(idx) {
			if (idx === 0) { return; } // corner "Column" cell
			if ($(this).hasClass('btbl-row-actions-head')) { return; }
			headers.push($(this).text());
		});
		return headers;
	}

	function readCustomGridValues() {
		var rows = [];
		$customGrid.find('tbody tr').each(function(rowIdx) {
			var row = [];
			$(this).find('input[name^="btbl_custom_data"]').each(function(cellIdx) {
				row[cellIdx] = $(this).val();
			});
			rows[rowIdx] = row;
		});
		return { rows: rows };
	}

	// R11: how many filled cells would be discarded if we shrink to these counts.
	function countDroppedCells(rows, counts) {
		var dropped = 0;
		for (var r = 0; r < rows.length; r++) {
			var row = rows[r] || [];
			for (var c = 0; c < row.length; c++) {
				if ((r >= counts.rows || c >= counts.cols) && String(row[c] || '') !== '') {
					dropped++;
				}
			}
		}
		return dropped;
	}

	// R12: per-row delete / insert-below / duplicate controls.
	// Uniform dashicons (shared 20x20 metrics) so the three actions align and size
	// identically, instead of three mismatched text glyphs.
	function buildRowAction(cls, dashicon, label, disabled) {
		var $btn = $('<button type="button"/>')
			.addClass('button-link ' + cls)
			.attr({ title: label, 'aria-label': label })
			.append($('<span class="dashicons ' + dashicon + '" aria-hidden="true"/>'));
		if (disabled) { $btn.attr('disabled', 'disabled'); }
		return $btn;
	}
	function buildRowActions(rowIndex, rowCount) {
		var $td = $('<td class="btbl-row-actions"/>');
		// Reorder controls (disabled at the boundaries), then the edit controls.
		buildRowAction('btbl-row-move-up', 'dashicons-arrow-up-alt2', gridLabels.moveUp, rowIndex === 0).appendTo($td);
		buildRowAction('btbl-row-move-down', 'dashicons-arrow-down-alt2', gridLabels.moveDown, rowIndex === rowCount - 1).appendTo($td);
		buildRowAction('btbl-row-insert', 'dashicons-plus-alt2', gridLabels.insert).appendTo($td);
		buildRowAction('btbl-row-duplicate', 'dashicons-admin-page', gridLabels.duplicate).appendTo($td);
		buildRowAction('btbl-row-delete', 'dashicons-no-alt', gridLabels.remove).appendTo($td);
		return $td;
	}

	function renderCustomGrid(headers, rows, counts) {
		var headingLabel = $customGrid.data('heading-label') || 'Column';
		var colTemplate = $customGrid.data('column-label') || 'Column %d';
		var rowTemplate = $customGrid.data('row-label') || 'Row %d';
		var $table = $('<table class="widefat fixed striped"/>');
		var $thead = $('<thead/>').appendTo($table);
		var $headRow = $('<tr/>').appendTo($thead);
		$('<th scope="col" class="btbl-grid-corner"/>').text(headingLabel).appendTo($headRow);
		for (var c = 0; c < counts.cols; c++) {
			var placeholder = (colTemplate || '').replace('%d', (c + 1));
			var headerVal = (headers && headers[c]) ? headers[c] : placeholder;
			$('<th scope="col"/>').text(headerVal).appendTo($headRow);
		}
		$('<th scope="col" class="btbl-row-actions-head"><span class="screen-reader-text">' + gridLabels.remove + '</span></th>').appendTo($headRow);
		var $tbody = $('<tbody/>').appendTo($table);
		for (var r = 0; r < counts.rows; r++) {
			var rowLabel = (rowTemplate || 'Row %d').replace('%d', (r + 1));
			var $tr = $('<tr/>');
			// Visible gutter shows just the number; the descriptive "Row N" stays as an
			// aria-label so screen readers still announce it.
			$tr.append($('<th scope="row" class="btbl-grid-rownum"/>').attr('aria-label', rowLabel).text(r + 1));
			var rowValues = rows[r] || [];
			for (var c2 = 0; c2 < counts.cols; c2++) {
				var cellVal = rowValues[c2] || '';
				// R39: title mirrors the value so truncated cells reveal on hover.
				var $cellInput = $('<input type="text"/>')
					.attr('name', 'btbl_custom_data[' + r + '][' + c2 + ']')
					.attr('title', cellVal)
					.val(cellVal);
				$tr.append($('<td/>').append($cellInput));
			}
			$tr.append(buildRowActions(r, counts.rows));
			$tbody.append($tr);
		}
		$customGrid.empty().append($table);
		$customGrid.attr('data-cols', counts.cols).attr('data-rows', counts.rows);
		$customColsInput.val(counts.cols);
		$customRowsInput.val(counts.rows);
	}

	function rebuildCustomGrid(confirmLoss) {
		if (!$customGrid.length) {
			return false;
		}
		var counts = getCustomCounts();
		var headers = readCustomGridHeaders();
		var values = readCustomGridValues();
		if (confirmLoss) {
			var dropped = countDroppedCells(values.rows, counts);
			if (dropped > 0 && !window.confirm(gridConfirmShrink.replace('%d', dropped))) {
				return false;
			}
		}
		var rows = [];
		for (var r = 0; r < counts.rows; r++) {
			var sourceRow = values.rows[r] || [];
			var normalized = [];
			for (var c2 = 0; c2 < counts.cols; c2++) {
				normalized[c2] = sourceRow[c2] || '';
			}
			rows[r] = normalized;
		}
		renderCustomGrid(headers.slice(0, counts.cols), rows, counts);
		return true;
	}

	// Re-render from a mutated rows array (used by the row-action buttons).
	function renderRows(rows) {
		var cols = parseInt($customGrid.attr('data-cols'), 10) || 1;
		var rowCount = Math.max(1, Math.min(500, rows.length));
		renderCustomGrid(readCustomGridHeaders().slice(0, cols), rows, { cols: cols, rows: rowCount });
	}

	if ($customGrid.length) {
		// R26/R45: the "Update grid size" button only appears while the column/row counts
		// differ from the grid that's actually rendered, and hides again on revert.
		function syncGridRefreshVisibility() {
			var counts = getCustomCounts();
			var gridCols = parseInt($customGrid.attr('data-cols'), 10) || counts.cols;
			var gridRows = parseInt($customGrid.attr('data-rows'), 10) || counts.rows;
			var changed = (counts.cols !== gridCols) || (counts.rows !== gridRows);
			// The button simply appearing when counts change is signal enough — no extra
			// highlight on the button or the grid cells.
			$customRefresh.prop('hidden', !changed);
		}
		$customRefresh.on('click', function(e) {
			e.preventDefault();
			if (rebuildCustomGrid(true) !== false) {
				syncGridRefreshVisibility(); // counts now match the grid -> button hides
			}
		});
		$customColsInput.add($customRowsInput).on('change input', syncGridRefreshVisibility);
		syncGridRefreshVisibility(); // hidden on load (counts match the rendered grid)
		// R39: keep the hover title in sync as the user types.
		$customGrid.on('input', 'input[name^="btbl_custom_data"]', function() {
			$(this).attr('title', $(this).val());
		});
		// Reorder rows (R44): move the focused row up/down one position.
		function focusMovedRow(newIdx, dir) {
			var primary = dir === 'up' ? '.btbl-row-move-up' : '.btbl-row-move-down';
			var fallback = dir === 'up' ? '.btbl-row-move-down' : '.btbl-row-move-up';
			var $row = $customGrid.find('tbody tr').eq(newIdx);
			var $btn = $row.find(primary);
			if (!$btn.length || $btn.is('[disabled]')) {
				$btn = $row.find(fallback); // hit the boundary — keep focus on the row
			}
			$btn.trigger('focus');
		}
		$customGrid.on('click', '.btbl-row-move-up', function() {
			var idx = $(this).closest('tr').index();
			if (idx <= 0) { return; }
			var rows = readCustomGridValues().rows;
			var moved = rows.splice(idx, 1)[0];
			rows.splice(idx - 1, 0, moved);
			renderRows(rows);
			focusMovedRow(idx - 1, 'up');
		});
		$customGrid.on('click', '.btbl-row-move-down', function() {
			var idx = $(this).closest('tr').index();
			var rows = readCustomGridValues().rows;
			if (idx >= rows.length - 1) { return; }
			var moved = rows.splice(idx, 1)[0];
			rows.splice(idx + 1, 0, moved);
			renderRows(rows);
			focusMovedRow(idx + 1, 'down');
		});
		// R12: row actions.
		$customGrid.on('click', '.btbl-row-delete', function() {
			var idx = $(this).closest('tr').index();
			var rows = readCustomGridValues().rows;
			if (rows.length <= 1) {
				rows = [[]];
			} else {
				rows.splice(idx, 1);
			}
			renderRows(rows);
		});
		$customGrid.on('click', '.btbl-row-insert', function() {
			var idx = $(this).closest('tr').index();
			var rows = readCustomGridValues().rows;
			rows.splice(idx + 1, 0, []);
			renderRows(rows);
		});
		$customGrid.on('click', '.btbl-row-duplicate', function() {
			var idx = $(this).closest('tr').index();
			var rows = readCustomGridValues().rows;
			var copy = (rows[idx] || []).slice();
			rows.splice(idx + 1, 0, copy);
			renderRows(rows);
		});
		// R13: paste tab/newline-delimited data from a spreadsheet.
		$customGrid.on('paste', 'input[name^="btbl_custom_data"]', function(e) {
			var clip = (e.originalEvent || e).clipboardData || window.clipboardData;
			if (!clip) { return; }
			var text = clip.getData('text/plain') || clip.getData('Text') || '';
			var hasTab = text.indexOf('\t') !== -1;
			var hasNewline = /\r|\n/.test(text);
			if (!hasTab && !hasNewline) { return; } // single cell — default paste
			e.preventDefault();
			var lines = text.replace(/\r\n?/g, '\n').replace(/\n$/, '').split('\n');
			var $cell = $(this);
			var $row = $cell.closest('tr');
			var startRow = $row.index();
			var startCol = $row.find('input[name^="btbl_custom_data"]').index($cell);
			var values = readCustomGridValues().rows;
			var maxCol = startCol;
			for (var i = 0; i < lines.length; i++) {
				var cells = lines[i].split('\t');
				var rIdx = startRow + i;
				if (!values[rIdx]) { values[rIdx] = []; }
				for (var j = 0; j < cells.length; j++) {
					values[rIdx][startCol + j] = cells[j];
					if (startCol + j > maxCol) { maxCol = startCol + j; }
				}
			}
			var newCols = Math.min(50, Math.max(parseInt($customGrid.attr('data-cols'), 10) || 1, maxCol + 1));
			var newRows = Math.min(500, Math.max(parseInt($customGrid.attr('data-rows'), 10) || 1, values.length));
			renderCustomGrid(readCustomGridHeaders().slice(0, newCols), values, { cols: newCols, rows: newRows });
		});
		rebuildCustomGrid(false);
	}

	function customQueryPostTypeParam(raw) {
		try {
			var parsed = JSON.parse(raw);
			var pt = parsed && parsed.post_type;
			if (Array.isArray(pt)) { return pt.join(','); }
			if (typeof pt === 'string' && pt) { return pt; }
		} catch (e) {}
		return '';
	}

	// Gate "Load columns": show it only once the JSON differs from the query whose columns are
	// currently loaded. With the AJAX load (no reload), markCustomQueryLoaded() advances
	// loadedQuery after a successful swap so the button re-hides.
	var loadedQuery = $customQueryInput.length ? ($customQueryInput.val() || '') : '';
	function syncQueryRefreshVisibility() {
		if (!$customQueryRefresh.length) { return; }
		$customQueryRefresh.prop('hidden', ($customQueryInput.val() || '') === loadedQuery);
	}
	function markCustomQueryLoaded(raw) {
		loadedQuery = raw;
		syncQueryRefreshVisibility();
	}

	function triggerCustomQueryPreview() {
		if (!$customQueryInput.length) {
			return;
		}
		var raw = $customQueryInput.val() || '';
		var $form = $customQueryInput.closest('form');
		if (!$form.length) { $form = $('#post'); }
		var nonce = $form.find('input[name="_baratables_nonce"]').val() || '';
		var postId = $('#post_ID').val() || $form.find('input[name="post_ID"]').val() || 0;
		if (!nonce || !window.ajaxurl) {
			// Fallback: legacy full-page reload.
			var url = new URL(window.location.href);
			url.searchParams.set('btbl_source', 'custom_query');
			url.searchParams.set('btbl_preview_custom_query', raw);
			var activeTab = $('.btbl-tab-link.nav-tab-active').data('target') || '';
			if (activeTab) { url.searchParams.set('tab', activeTab); }
			window.location.href = url.toString();
			return;
		}
		$customQueryRefresh.prop('disabled', true);
		var seq = ++fieldRefreshSeq;
		$.post(window.ajaxurl, {
			action: 'btbl_refresh_fields',
			post_id: postId,
			type: customQueryPostTypeParam(raw),
			source: 'custom_query',
			custom_query: raw,
			_baratables_nonce: nonce
		}).done(function(resp) {
			if (seq !== fieldRefreshSeq) { return; }
			if (resp && resp.success && resp.data) {
				applyFieldRefresh(resp.data);
				markCustomQueryLoaded(raw); // columns now match this query -> re-hide the button
			}
		}).always(function() {
			// Always re-enable the button — fieldRefreshSeq is shared with the source/CSV refreshes,
			// so a sibling refresh started while this request was in flight would otherwise leave
			// seq !== fieldRefreshSeq and strand the button disabled until a full page reload. The
			// stale-response guard stays on the .done() fragment swap above (line ~1010); only the
			// button-enable is unconditional.
			$customQueryRefresh.prop('disabled', false);
		});
	}

	if ($customQueryRefresh.length) {
		$customQueryInput.on('change input', syncQueryRefreshVisibility);
		syncQueryRefreshVisibility();
		$customQueryRefresh.on('click', function(e) {
			e.preventDefault();
			triggerCustomQueryPreview();
		});
	}

	function copyShortcode(text, $el) {
		if (!text) {
			return;
		}
		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(text).then(function() {
				showCopiedState($el);
			}).catch(function() {
				fallbackCopy(text, $el);
			});
		} else {
			fallbackCopy(text, $el);
		}
	}

	function fallbackCopy(text, $el) {
		var $temp = $('<textarea>');
		$temp.css({position: 'absolute', left: '-9999px', top: '0'});
		$temp.val(text);
		$('body').append($temp);
		$temp.select();
		try {
			document.execCommand('copy');
			showCopiedState($el);
		} catch (e) {
			if (window.console && console.warn) {
				console.warn('Copy failed', e);
			}
		}
		$temp.remove();
	}

	function showCopiedState($el) {
		if (!$el || !$el.length) {
			return;
		}
		$el.addClass('is-copied');
		// R9: announce the copy to screen readers.
		var label = $el.data('copied-label') || 'Copied';
		var $live = $('#btbl-copy-live');
		if (!$live.length) {
			$live = $('<span id="btbl-copy-live" class="screen-reader-text" aria-live="polite"></span>').appendTo('body');
		}
		$live.text('');
		setTimeout(function() { $live.text(label); }, 50);
		setTimeout(function() {
			$el.removeClass('is-copied');
		}, 1500);
	}

	// Re-sync hook (assigned by the select-all block below). The master "select all" checkbox
	// can only be counted correctly once the Columns tab is visible, so re-run it on activation.
	var resyncSelectAllColumns = function () {};

	function activateTab(targetId) {
		var $targetPanel = $('#' + targetId);
		if (!$targetPanel.length) {
			return;
		}
		$('.btbl-tab-link').removeClass('nav-tab-active').attr('aria-selected', 'false');
		$('.btbl-tab-link[data-target="' + targetId + '"]').addClass('nav-tab-active').attr('aria-selected', 'true');
		$('.btbl-tab-panel').removeClass('is-active');
		$targetPanel.addClass('is-active');
		$('#btbl_active_tab').val(targetId);
		if (targetId === 'btbl-tab-columns') {
			resyncSelectAllColumns();
		}
		if (window.history && window.history.replaceState) {
			var url = new URL(window.location.href);
			url.searchParams.set('tab', targetId);
			window.history.replaceState({}, '', url.toString());
		}
	}

	$('.btbl-tab-link').on('click', function(e) {
		e.preventDefault();
		var target = $(this).data('target');
		activateTab(target);
	});

	var initialHash = window.location.hash.replace('#', '');
	var initialTabParam = '';
	try {
		initialTabParam = new URL(window.location.href).searchParams.get('tab') || '';
	} catch (e) {
		initialTabParam = '';
	}
	var initialTarget = initialHash || initialTabParam || $('#btbl_active_tab').val() || '';
	if (initialTarget && $('#' + initialTarget).length) {
		activateTab(initialTarget);
	} else {
		activateTab('btbl-tab-general');
	}

	$(document).on('click keydown', '.btbl-shortcode', function(e) {
		if (!isActivationEvent(e)) {
			return;
		}
		e.preventDefault();
		var $el = $(this);
		var shortcode = $el.data('shortcode') || $el.text();
		copyShortcode(shortcode, $el);
	});

	// Collapsible shortcode-ID editor (WordPress slug-editor pattern): "Edit ID" reveals the
	// input; OK collapses and optimistically updates the shortcode display; Cancel reverts.
	function collapseIdEditor($editor) {
		$editor.closest('.btbl-shortcode-row').removeClass('is-editing-id');
		$editor.find('.btbl-id-edit-panel').prop('hidden', true);
		$editor.find('.btbl-id-edit-toggle').prop('hidden', false).trigger('focus');
	}
	function reflectShortcodeId(newId) {
		var clean = String(newId).toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
		var $code = $('.btbl-shortcode-permalink .btbl-shortcode').first();
		if (!$code.length) { return; }
		var updated = $code.text().replace(/id="[^"]*"/, 'id="' + clean + '"');
		$code.text(updated).attr('data-shortcode', updated);
	}
	$(document).on('click', '.btbl-id-edit-toggle', function() {
		var $editor = $(this).closest('.btbl-id-editor');
		$editor.closest('.btbl-shortcode-row').addClass('is-editing-id');
		$(this).prop('hidden', true);
		var $input = $editor.find('.btbl-id-edit-panel').prop('hidden', false).find('.btbl-id-input');
		$input.data('original', $input.val()).trigger('focus');
		if ($input[0] && $input[0].select) { $input[0].select(); }
	});
	$(document).on('click', '.btbl-id-edit-ok', function() {
		var $editor = $(this).closest('.btbl-id-editor');
		reflectShortcodeId($editor.find('.btbl-id-input').val());
		collapseIdEditor($editor);
	});
	$(document).on('click', '.btbl-id-edit-cancel', function() {
		var $editor = $(this).closest('.btbl-id-editor');
		var $input = $editor.find('.btbl-id-input');
		if (typeof $input.data('original') !== 'undefined') { $input.val($input.data('original')); }
		collapseIdEditor($editor);
	});
	// Enter acts as OK, Escape as Cancel — and never submits the surrounding post form.
	$(document).on('keydown', '.btbl-id-input', function(e) {
		if (e.key === 'Enter') {
			e.preventDefault();
			$(this).closest('.btbl-id-editor').find('.btbl-id-edit-ok').trigger('click');
		} else if (e.key === 'Escape') {
			e.preventDefault();
			$(this).closest('.btbl-id-editor').find('.btbl-id-edit-cancel').trigger('click');
		}
	});

	function togglePaginationControls() {
		var $pagingToggle = $('input[name="btbl_table_options[paging]"]');
		var $pageLengthRow = $('.btbl-page-length-row');
		var $lengthChangeToggle = $('input[name="btbl_table_options[lengthChange]"]');
		var $lengthChangeFlag = $lengthChangeToggle.closest('.btbl-length-change-flag');
		var $lengthMenuRows = $('.btbl-length-menu-row');
		if (!$pagingToggle.length || !$pageLengthRow.length) {
			return;
		}
		var enabled = $pagingToggle.is(':checked');
		var lengthChangeChecked = $lengthChangeToggle.length ? $lengthChangeToggle.is(':checked') : false;
		$pageLengthRow.toggleClass('is-hidden', !enabled);
		if ($lengthChangeToggle.length) {
			if (!enabled) {
				$lengthChangeToggle.prop('checked', false);
			} else if (!$lengthChangeToggle.data('touched')) {
				applyDefaultIfUntouched($lengthChangeToggle);
			}
			lengthChangeChecked = $lengthChangeToggle.is(':checked');
			$lengthChangeFlag.toggleClass('is-hidden', !enabled);
		}
		$lengthMenuRows.toggleClass('is-hidden', !(enabled && lengthChangeChecked));
	}

	$(document).on('change', 'input[name="btbl_table_options[paging]"]', togglePaginationControls);
	$(document).on('change', 'input[name="btbl_table_options[lengthChange]"]', togglePaginationControls);
	togglePaginationControls();

	function resetColumnsAndFilters() {
		$('input[name="btbl_columns[]"]').prop('checked', false);
		$('.btbl-filter-select').val('none');
		$('.btbl-filter-sort').val('asc');
		$('input[name^="btbl_dropdown_"]').prop('checked', false);
		$('.btbl-date-format-input').val('');
		$('.btbl-format-date-toggle').prop('checked', false);
		$('textarea[name^="btbl_filter_values"]').val('');
		$('.btbl-hide-column, .btbl-sort-enabled, .btbl-sortable-toggle').prop('checked', false);
		$('.btbl-sort-priority').val('');
		$('.btbl-sort-direction').val('asc');
		$('input[name^="btbl_custom_labels"]').val('');
		if (columnOrderController && typeof columnOrderController.clear === 'function') {
			columnOrderController.clear();
		}
		if (filterOrderController && typeof filterOrderController.clear === 'function') {
			filterOrderController.clear();
		}
		$('#btbl-tab-columns .btbl-checkbox').each(function() {
			var $label = $(this);
			toggleFilterControls($label);
		});
	}

	function toggleSearchSettings(event) {
		var $searchToggle = $('input[type="checkbox"][name="btbl_table_options[searchBox]"]');
		var $searchColumnsToggle = $('input[type="checkbox"][name="btbl_table_options[searchColumns]"]');
		var $settingRows = $('.btbl-search-setting-row');
		var $searchColumnsRows = $('.btbl-search-columns-setting');
		var $searchColumnsFlag = $('.btbl-search-columns-flag');
		if (!$searchToggle.length || !$settingRows.length) {
			return;
		}

		var searchEnabled = $searchToggle.is(':checked');
		$settingRows.toggleClass('is-hidden', !searchEnabled);

		if ($searchColumnsToggle.length) {
			if (!searchEnabled) {
				$searchColumnsToggle.prop('checked', false);
			} else if (!$searchColumnsToggle.data('touched')) {
				applyDefaultIfUntouched($searchColumnsToggle);
			}
		}

		var searchColumnsEnabled = searchEnabled && ($searchColumnsToggle.length ? $searchColumnsToggle.is(':checked') : true);
		$searchColumnsRows.toggleClass('is-hidden', !searchColumnsEnabled);
		$searchColumnsFlag.toggleClass('is-hidden', !searchEnabled);
	}

	var defaultFlagSelector = [
		'input[type="checkbox"][name="btbl_table_options[searchColumns]"]',
		'input[type="checkbox"][name="btbl_table_options[lengthChange]"]',
		'input[type="checkbox"][name="btbl_table_options[pagingNumbers]"]',
		'input[type="checkbox"][name="btbl_table_options[pagingFirstLast]"]',
		'input[type="checkbox"][name="btbl_table_options[pagingPreviousNext]"]'
	].join(', ');

	$(document).on('pointerdown keydown', defaultFlagSelector, function() {
		$(this).data('touched', true);
	});
	$(document).on('change', 'input[type="checkbox"][name="btbl_table_options[searchBox]"], input[type="checkbox"][name="btbl_table_options[searchColumns]"]', toggleSearchSettings);
	$(document).on('change', defaultFlagSelector, function() {
		$(this).data('touched', true);
	});
	toggleSearchSettings();

	function toggleTableFlagOptions() {
		$('.btbl-table-flags .btbl-checkbox, .btbl-table-flags .btbl-flag-card').each(function() {
			var $card = $(this);
			var $checkbox = $card.find('input[type="checkbox"][name^="btbl_table_options"]');
			if (!$checkbox.length) {
				return;
			}
			var checked = $checkbox.is(':checked');
			var $toggle = $card.find('.btbl-flag-options-toggle');
			var $body = $card.find('.btbl-field-options-body');
			$toggle.toggleClass('is-hidden', !checked).attr('aria-expanded', checked && $body.hasClass('is-open') ? 'true' : 'false');
			if (!checked) {
				$body.removeClass('is-open').addClass('is-hidden');
			} else {
				$body.removeClass('is-hidden');
			}
		});
	}

	$(document).on('change', '.btbl-table-flags input[type="checkbox"][name^="btbl_table_options"]', function() {
		toggleTableFlagOptions();
	});

	$(document).on('click keydown', '.btbl-flag-options-toggle', function(e) {
		if (!isActivationEvent(e)) {
			return;
		}
		e.preventDefault();
		var $toggle = $(this);
		var $card = $toggle.closest('.btbl-checkbox, .btbl-flag-card');
		var $body = $card.find('.btbl-field-options-body');
		var isOpen = $body.hasClass('is-open');
		$body.toggleClass('is-open', !isOpen);
		$toggle.attr('aria-expanded', !isOpen ? 'true' : 'false');
	});

	toggleTableFlagOptions();

	function togglePaginationSettings() {
		var $pagingToggle = $('input[name="btbl_table_options[paging]"]');
		var $firstLastToggle = $('input[name="btbl_table_options[pagingFirstLast]"]');
		var $previousNextToggle = $('input[name="btbl_table_options[pagingPreviousNext]"]');
		var $numbersToggle = $('input[name="btbl_table_options[pagingNumbers]"]');
		var pagingEnabled = $pagingToggle.length ? $pagingToggle.is(':checked') : false;

		if (pagingEnabled) {
			applyDefaultIfUntouched($firstLastToggle);
			applyDefaultIfUntouched($previousNextToggle);
			applyDefaultIfUntouched($numbersToggle);
		}

		var showFirstLast = pagingEnabled && ($firstLastToggle.length ? $firstLastToggle.is(':checked') : true);
		var showPreviousNext = pagingEnabled && ($previousNextToggle.length ? $previousNextToggle.is(':checked') : true);
		$('input[name="btbl_table_options[paginateFirst]"]').closest('.btbl-options-row').toggleClass('is-hidden', !showFirstLast);
		$('input[name="btbl_table_options[paginateLast]"]').closest('.btbl-options-row').toggleClass('is-hidden', !showFirstLast);
		$('input[name="btbl_table_options[paginatePrevious]"]').closest('.btbl-options-row').toggleClass('is-hidden', !showPreviousNext);
		$('input[name="btbl_table_options[paginateNext]"]').closest('.btbl-options-row').toggleClass('is-hidden', !showPreviousNext);
	}

	$(document).on('change', 'input[name="btbl_table_options[paging]"], input[name="btbl_table_options[pagingFirstLast]"], input[name="btbl_table_options[pagingPreviousNext]"]', togglePaginationSettings);
	togglePaginationSettings();

	function toggleFiltersTitleSettings() {
		var $flag = $('input[name="btbl_table_options[filtersTitle]"]');
		var enabled = $flag.length ? $flag.is(':checked') : false;
		$('.btbl-filters-title-setting').toggleClass('is-hidden', !enabled);
	}

	$(document).on('change', 'input[name="btbl_table_options[filtersTitle]"]', toggleFiltersTitleSettings);
	toggleFiltersTitleSettings();

	function toggleSummarySettings() {
		var $flag = $('input[name="btbl_table_options[info]"]');
		var enabled = $flag.length ? $flag.is(':checked') : false;
		$('.btbl-info-setting').toggleClass('is-hidden', !enabled);
	}

	$(document).on('change', 'input[name="btbl_table_options[info]"]', toggleSummarySettings);
	toggleSummarySettings();

	function initLayoutBuilder($builder) {
		var dragItem = null;
		var $palette = $builder.find('.btbl-layout-palette-drop');

		function syncLayoutInputs() {
			$builder.find('.btbl-layout-inputs').each(function() {
				var $inputs = $(this);
				var zoneKey = $inputs.data('zone-inputs');
				if (!zoneKey) {
					return;
				}
				var $zone = $builder.find('.btbl-layout-drop[data-zone="' + zoneKey + '"]');
				$inputs.empty();
				$inputs.append('<input type="hidden" name="btbl_table_options[' + zoneKey + '][]" value="" />');
				$zone.find('.btbl-layout-chip').each(function() {
					var feature = $(this).data('feature');
					if (!feature) {
						return;
					}
					$inputs.append('<input type="hidden" name="btbl_table_options[' + zoneKey + '][]" value="' + feature + '" />');
				});
			});
		}

		function updateLayoutAvailability() {
			var isSearchEnabled = $('input[name="btbl_table_options[searchBox]"]').is(':checked');
			var isLengthEnabled = $('input[name="btbl_table_options[lengthChange]"]').is(':checked');
			var isInfoEnabled = $('input[name="btbl_table_options[info]"]').is(':checked');
			var isPagingEnabled = $('input[name="btbl_table_options[paging]"]').is(':checked');
			var hasButtons = $('input[name="btbl_table_options[buttons][]"]:checked').length > 0;
			var availability = {
				search: isSearchEnabled,
				pagelength: isLengthEnabled,
				info: isInfoEnabled,
				paging: isPagingEnabled,
				buttons: hasButtons
			};
			var disabledHint = $builder.find('.btbl-layout-grid').data('disabled-hint') || '';
			Object.keys(availability).forEach(function(key) {
				var enabled = availability[key];
				$builder.find('.btbl-layout-chip[data-feature="' + key + '"]')
					.toggleClass('is-disabled', !enabled)
					.attr('aria-disabled', enabled ? 'false' : 'true')
					.attr('title', enabled ? null : disabledHint);
			});
		}

		function getCurrentLayout() {
			var map = {};
			$builder.find('.btbl-layout-drop[data-zone]').each(function() {
				var zone = $(this).data('zone');
				if (zone === 'palette') { return; }
				map[zone] = $(this).find('.btbl-layout-chip').map(function() {
					return $(this).data('feature');
				}).get();
			});
			return map;
		}
		function layoutMatchesDefaults() {
			var d = $builder.data('defaults') || {};
			if (typeof d === 'string') { try { d = JSON.parse(d); } catch (e) { d = {}; } }
			var cur = getCurrentLayout();
			var keys = {};
			Object.keys(d).forEach(function(k) { keys[k] = 1; });
			Object.keys(cur).forEach(function(k) { keys[k] = 1; });
			return Object.keys(keys).every(function(k) {
				return JSON.stringify(d[k] || []) === JSON.stringify(cur[k] || []);
			});
		}
		function syncLayoutResetVisibility() {
			$builder.find('.btbl-layout-reset').prop('hidden', layoutMatchesDefaults());
		}
		function resetLayout() {
			var defaults = $builder.data('defaults') || {};
			if (typeof defaults === 'string') {
				try {
					defaults = JSON.parse(defaults);
				} catch (e) {
					defaults = {};
				}
			}
			$palette.append($builder.find('.btbl-layout-chip'));
			Object.keys(defaults).forEach(function(zoneKey) {
				var items = Array.isArray(defaults[zoneKey]) ? defaults[zoneKey] : [];
				var $zone = $builder.find('.btbl-layout-drop[data-zone="' + zoneKey + '"]');
				items.forEach(function(feature) {
					var $chip = $builder.find('.btbl-layout-chip[data-feature="' + feature + '"]').first();
					if ($chip.length) {
						$zone.append($chip);
					}
				});
			});
			syncLayoutInputs();
			updateLayoutAvailability(); syncLayoutResetVisibility();
		}

		$builder.on('dragstart', '.btbl-layout-chip', function(e) {
			dragItem = this;
			e.originalEvent.dataTransfer.effectAllowed = 'move';
			e.originalEvent.dataTransfer.setData('text/plain', $(this).data('feature'));
			$(this).addClass('is-dragging');
		});

		$builder.on('dragend', '.btbl-layout-chip', function() {
			$(this).removeClass('is-dragging');
			dragItem = null;
			$builder.find('.btbl-layout-drop').removeClass('is-dragover');
		});

		$builder.on('dragover', '.btbl-layout-drop', function(e) {
			e.preventDefault();
			e.originalEvent.dataTransfer.dropEffect = 'move';
			$(this).addClass('is-dragover');
		});

		$builder.on('dragleave', '.btbl-layout-drop', function() {
			$(this).removeClass('is-dragover');
		});

		$builder.on('drop', '.btbl-layout-drop', function(e) {
			e.preventDefault();
			$(this).removeClass('is-dragover');
			if (!dragItem) {
				return;
			}
			if ($(e.target).closest('.btbl-layout-chip').length === 0) {
				$(this).append(dragItem);
			}
			syncLayoutInputs();
			updateLayoutAvailability(); syncLayoutResetVisibility();
		});

		$builder.on('dragover', '.btbl-layout-chip', function(e) {
			if (!dragItem || dragItem === this) {
				return;
			}
			e.preventDefault();
			var rect = this.getBoundingClientRect();
			var before = (e.originalEvent.clientX - rect.left) < rect.width / 2;
			if (before) {
				$(this).before(dragItem);
			} else {
				$(this).after(dragItem);
			}
		});

		$builder.on('click', '.btbl-layout-reset', function() {
			resetLayout();
		});

		$(document).on('change', 'input[name="btbl_table_options[searchBox]"], input[name="btbl_table_options[lengthChange]"], input[name="btbl_table_options[info]"], input[name="btbl_table_options[paging]"], input[name="btbl_table_options[buttons][]"]', function() {
			updateLayoutAvailability(); syncLayoutResetVisibility();
		});

		syncLayoutInputs();
		updateLayoutAvailability(); syncLayoutResetVisibility();
	}

	$('.btbl-layout-builder').each(function() {
		initLayoutBuilder($(this));
	});

	function createSortableList(config) {
		var $list = $(config.listSelector);
		var $input = $(config.inputSelector);
		if (!$list.length || !$input.length || typeof config.getSelectedMap !== 'function') {
			return {
				sync: function() {},
				clear: function() {},
			};
		}

		var order = ($input.val() || '').split(',').filter(function(val) { return val; });
		var currentMap = {};
		var dragSlug = null;

		function updateInput() {
			// Fire change so listeners (e.g. the Refresh-preview reveal) notice a reorder —
			// jQuery .val() alone is silent. Safe during init: the reveal handler binds later,
			// so the initial sync's change is a no-op until a real user reorder.
			$input.val(order.join(',')).trigger('change');
		}

		function renderList() {
			$list.empty();
			order.forEach(function(slug) {
				if (!Object.prototype.hasOwnProperty.call(currentMap, slug)) {
					return;
				}
				var label = currentMap[slug];
				var $item = $('<li/>')
					.attr('draggable', true)
					.attr('data-slug', slug)
					.attr('tabindex', '0')
					.attr('role', 'listitem')
					.attr('title', config.keyboardHint || 'Use the up and down arrow keys to reorder.')
					.html(label);
				$list.append($item);
			});
		}

		function syncFromSelection() {
			currentMap = config.getSelectedMap();
			var selectedSlugs = Object.keys(currentMap);
			order = order.filter(function(slug) {
				return selectedSlugs.indexOf(slug) !== -1;
			});
			selectedSlugs.forEach(function(slug) {
				if (order.indexOf(slug) === -1) {
					order.push(slug);
				}
			});
			renderList();
			updateInput();
		}

		// R19: keyboard reordering (parity with drag-and-drop).
		$list.on('keydown', 'li', function(e) {
			if (e.key !== 'ArrowUp' && e.key !== 'ArrowDown') {
				return;
			}
			e.preventDefault();
			var slug = $(this).data('slug');
			var idx = order.indexOf(slug);
			if (idx === -1) {
				return;
			}
			var newIdx = e.key === 'ArrowUp' ? idx - 1 : idx + 1;
			if (newIdx < 0 || newIdx >= order.length) {
				return;
			}
			order.splice(idx, 1);
			order.splice(newIdx, 0, slug);
			renderList();
			updateInput();
			$list.find('li[data-slug="' + slug + '"]').trigger('focus');
		});

		$list.on('dragstart', 'li', function(e) {
			dragSlug = $(this).data('slug');
			e.originalEvent.dataTransfer.effectAllowed = 'move';
		});

		$list.on('dragover', 'li', function(e) {
			e.preventDefault();
			$(this).addClass('is-drag-over');
			e.originalEvent.dataTransfer.dropEffect = 'move';
		});

		$list.on('dragleave', 'li', function() {
			$(this).removeClass('is-drag-over');
		});

		$list.on('drop', 'li', function(e) {
			e.preventDefault();
			var targetSlug = $(this).data('slug');
			$list.find('li').removeClass('is-drag-over');
			if (!dragSlug || dragSlug === targetSlug) {
				return;
			}
			var from = order.indexOf(dragSlug);
			var to = order.indexOf(targetSlug);
			if (from === -1 || to === -1) {
				return;
			}
			order.splice(from, 1);
			order.splice(to, 0, dragSlug);
			renderList();
			updateInput();
		});

		$list.on('dragend', 'li', function() {
			$list.find('li').removeClass('is-drag-over');
			dragSlug = null;
		});

		syncFromSelection();

		return {
			sync: syncFromSelection,
			clear: function() {
				order = [];
				renderList();
				updateInput();
			},
		};
	}

	function getSelectedColumnsMap() {
		var selected = {};
		$('#btbl-tab-columns input[name="btbl_columns[]"]:checked').each(function() {
			var slug = $(this).val();
			var label = $(this).data('label') || slug;
			selected[slug] = label;
		});
		return selected;
	}

	function getSelectedFiltersMap() {
		var selected = {};
		$('#btbl-tab-columns input[name="btbl_columns[]"]:checked').each(function() {
			var slug = $(this).val();
			var label = $(this).data('label') || slug;
			var $filterSelect = $('.btbl-filter-select[name="btbl_filters[' + slug + ']"]');
			if ($filterSelect.length && $filterSelect.val() && $filterSelect.val() !== 'none') {
				selected[slug] = label;
			}
		});
		return selected;
	}

	columnOrderController = createSortableList({
		listSelector: '#btbl-column-order-list',
		inputSelector: '#btbl_column_order',
		getSelectedMap: getSelectedColumnsMap,
	});

	filterOrderController = createSortableList({
		listSelector: '#btbl-filter-order-list',
		inputSelector: '#btbl_filter_order',
		getSelectedMap: getSelectedFiltersMap,
	});

	function syncOrderFromSelection() {
		columnOrderController.sync();
	}

	function syncFilterOrderFromSelection() {
		filterOrderController.sync();
	}

	// Live-update a column's heading — the label next to its checkbox and its order/filter pills —
	// as the gear's "Column heading" field is typed, instead of waiting for a Refresh.
	$(document).on('input', '#btbl-tab-columns input[name^="btbl_custom_labels"]', function() {
		var $input = $(this);
		var $box = $input.closest('.btbl-checkbox');
		var $checkbox = $box.find('input[name="btbl_columns[]"]').first();
		var slug = $checkbox.val();
		if (!slug) {
			return;
		}
		var typed = ($input.val() || '').trim();
		var label = typed !== '' ? typed : ($input.attr('data-default-label') || slug);
		$box.find('.btbl-field-name').first().text(label);
		// Keep the checkbox's data-label (read by getSelectedColumnsMap) in sync — both the
		// attribute and jQuery's cached .data() — so any rebuilt pills use the new heading too.
		// The order/filter pill list is rebuilt with jQuery .html(), and the server emits this
		// attribute as wp_kses'd inline HTML; so HTML-escape USER-TYPED text here (the typed
		// branch) before storing it, otherwise typing markup into a heading would execute it on
		// the next pill rebuild. The empty-field fallback is a server default label — left as-is.
		var labelForData = typed !== '' ? $('<div/>').text(typed).html() : label;
		$checkbox.attr('data-label', labelForData).data('label', labelForData);
		$('#btbl-column-order-list li[data-slug="' + slug + '"], #btbl-filter-order-list li[data-slug="' + slug + '"]').text(label);
	});

	var $selectAllColumns = $('#btbl_select_all_columns');
	if ($selectAllColumns.length) {
		function syncSelectAllState() {
			var $checkboxes = $('#btbl-tab-columns input[type="checkbox"][name="btbl_columns[]"]:visible');
			var allChecked = $checkboxes.length > 0 && $checkboxes.filter(':checked').length === $checkboxes.length;
			$selectAllColumns.prop('checked', allChecked);
		}

		// Let activateTab() re-sync once the Columns tab is actually visible (on load the tab is
		// display:none, so a :visible count here would see zero checkboxes and read "unchecked").
		resyncSelectAllColumns = syncSelectAllState;
		syncSelectAllState();

		$selectAllColumns.on('change', function() {
			var checked = $(this).is(':checked');

			$('#btbl-tab-columns input[type="checkbox"][name="btbl_columns[]"]').each(function() {
				var $cb = $(this);
				if (!$cb.is(':visible')) {
					return;
				}
				$cb.prop('checked', checked).trigger('change');
			});
			
			if (!checked) {
				$('.btbl-filter-select').each(function() {
					$(this).val('none');
				});
			}
			syncOrderFromSelection();
			syncFilterOrderFromSelection();
			syncSelectAllState();
		});

		$(document).on('change', '#btbl-tab-columns input[type="checkbox"][name="btbl_columns[]"]', function() {
			syncSelectAllState();
		});
	}

	$('#btbl-tab-general').on('change input', ':input', function(e) {
		var $target = $(e.target);
		if ($target.closest('.btbl-tax-terms-group').length) {
			return;
		}
		var source = $sourceSelect.length ? $sourceSelect.val() || 'wp_query' : 'wp_query';
		var isWpQueryControl = $target.closest('[data-btbl-source="wp_query"]').length > 0
			|| $target.is('#btbl_post_type')
			|| $target.is('#btbl_taxonomy');
		var isCustomQueryControl = $target.closest('[data-btbl-source="custom_query"]').length > 0;
		var isCustomDataControl = $target.closest('[data-btbl-source="custom_data"]').length > 0;
		if (source === 'wp_query' && isWpQueryControl) {
			return;
		}
		if (source === 'custom_query' && isCustomQueryControl) {
			return;
		}
		if (source === 'custom_data' && isCustomDataControl) {
			return;
		}
		resetColumnsAndFilters();
	});

	$('#btbl_chart_type').on('change', function() {
		updateChartStackToggle();
		toggleChartControlsUI();
		syncChartSeriesOptions();
	});

	function updateChartStackToggle() {
		var type = $('#btbl_chart_type').val();
		var $stack = $('input[name="btbl_chart_stack"]');
		var disableStack = type === 'pie' || type === 'gantt';
		if (disableStack) {
			$stack.prop('checked', false);
		}
		$stack.closest('.btbl-flag').toggleClass('is-hidden', disableStack);
	}

	function syncChartSeriesOptions() {
		var xAxis = $('#btbl_chart_x_axis').val() || '';
		var $series = $('#btbl_chart_series');
		if (!$series.length) {
			return;
		}
		// R8: checkbox series list — hide and uncheck the column chosen as the X-axis.
		$series.find('.btbl-chart-series-option').each(function() {
			var $opt = $(this);
			var isXAxis = xAxis !== '' && String($opt.data('slug')) === xAxis;
			$opt.toggleClass('is-hidden', isXAxis);
			if (isXAxis) {
				$opt.find('input[type="checkbox"]').prop('checked', false);
			}
		});
	}

	function toggleChartControlsUI() {
		var type = $('#btbl_chart_type').val() || 'bar';
		var isGantt = type === 'gantt';
		var $standardBlock = $('.btbl-chart-standard');
		var $ganttBlock = $('.btbl-chart-gantt');
		$standardBlock.toggleClass('is-hidden', isGantt);
		$ganttBlock.toggleClass('is-hidden', !isGantt);

		// Series is now a checkbox group (no native required); the save guard warns on empty series.
		var $requiredStandard = $('#btbl_chart_x_axis');
		var $requiredGantt = $('#btbl_chart_gantt_label, #btbl_chart_gantt_start, #btbl_chart_gantt_end');
		if (!isGantt) {
			$requiredStandard.attr('required', 'required');
			$requiredGantt.removeAttr('required');
		} else {
			$requiredGantt.attr('required', 'required');
			$requiredStandard.removeAttr('required');
		}

		updateChartStackToggle();
	}

	$('#btbl_chart_x_axis').on('change', syncChartSeriesOptions);
	toggleChartControlsUI();
	syncChartSeriesOptions();

	function initChartTypeChooser() {
		var $select = $('#btbl_chart_type');
		var $modal = $('#btbl-chart-type-modal');
		var $chooser = $modal.find('.btbl-chart-type-chooser');
		var $openers = $('.btbl-chart-preview-trigger');
		var $closers = $modal.find('.btbl-chart-modal__close, .btbl-chart-modal__backdrop');
		if (!$select.length || !$modal.length || !$chooser.length) {
			return;
		}

		var lastFocus = null;

		function getFocusable() {
			return $modal.find('.btbl-chart-type-card, .btbl-chart-modal__close, a[href], button').filter(':visible');
		}

		function closeModal() {
			if (!$modal.hasClass('is-open')) {
				return;
			}
			$modal.removeClass('is-open');
			// R10: return focus to the control that opened the modal.
			if (lastFocus && typeof lastFocus.focus === 'function') {
				lastFocus.focus();
			}
		}

		function openModal(trigger) {
			lastFocus = trigger || document.activeElement;
			$modal.addClass('is-open');
			// R10: move focus into the dialog (active card, else first card/close).
			var $target = $chooser.find('.btbl-chart-type-card.is-active').first();
			if (!$target.length) {
				$target = $chooser.find('.btbl-chart-type-card').first();
			}
			if (!$target.length) {
				$target = $modal.find('.btbl-chart-modal__close').first();
			}
			if ($target.length) {
				$target.attr('tabindex', '0');
				$target[0].focus();
			}
		}

		// R10: trap Tab within the open dialog.
		$modal.on('keydown', function(e) {
			if (e.key !== 'Tab' || !$modal.hasClass('is-open')) {
				return;
			}
			var $focusable = getFocusable();
			if (!$focusable.length) {
				return;
			}
			var first = $focusable[0];
			var last = $focusable[$focusable.length - 1];
			if (e.shiftKey && document.activeElement === first) {
				e.preventDefault();
				last.focus();
			} else if (!e.shiftKey && document.activeElement === last) {
				e.preventDefault();
				first.focus();
			}
		});

		function syncFromSelect() {
			var val = $select.val() || '';
			$chooser.find('.btbl-chart-type-card').each(function() {
				var $card = $(this);
				var isMatch = $card.data('type') === val;
				$card.toggleClass('is-active', isMatch);
				$card.attr('aria-pressed', isMatch ? 'true' : 'false');
			});
		}

		$chooser.on('click keydown', '.btbl-chart-type-card', function(e) {
			if (!isActivationEvent(e)) {
				return;
			}
			e.preventDefault();
			var type = $(this).data('type');
			if (!type) {
				return;
			}
			$select.val(type).trigger('change');
			syncFromSelect();
			closeModal();
		});

		$select.on('change', syncFromSelect);
		syncFromSelect();

		$openers.on('click keydown', function(e) {
			if (!isActivationEvent(e)) {
				return;
			}
			e.preventDefault();
			openModal(this);
		});

		$closers.on('click keydown', function(e) {
			if (!isActivationEvent(e)) {
				return;
			}
			e.preventDefault();
			closeModal();
		});

		$(document).on('keydown', function(e) {
			if (e.key === 'Escape' && $modal.hasClass('is-open')) {
				closeModal();
			}
		});
	}

	initChartTypeChooser();

	// Audit follow-up: per-user "Show/Hide help text" toggle (governs .btbl-help-text).
	$(document).on('click', '#btbl-help-toggle', function(e) {
		e.preventDefault();
		var $t = $(this);
		var hide = !document.body.classList.contains('btbl-help-hidden');
		document.body.classList.toggle('btbl-help-hidden', hide);
		var label = hide ? ($t.data('show-label') || 'Show help text') : ($t.data('hide-label') || 'Hide help text');
		$t.attr({ 'aria-label': label, title: label });
		$.post(window.ajaxurl, { action: 'btbl_toggle_help', hide: hide ? '1' : '0', _wpnonce: $t.data('nonce') });
	});

	// R6: validate the Value overrides JSON on blur so a typo doesn't silently discard rules.
	$(document).on('blur', '#btbl_value_overrides_json', function() {
		var $ta = $(this);
		var $err = $('#btbl_value_overrides_error');
		var val = ($ta.val() || '').trim();
		if (val === '') {
			$err.prop('hidden', true);
			$ta.removeClass('btbl-invalid');
			return;
		}
		try {
			JSON.parse(val);
			$err.prop('hidden', true);
			$ta.removeClass('btbl-invalid');
		} catch (err) {
			$err.prop('hidden', false);
			$ta.addClass('btbl-invalid');
		}
	});

	// R15/R45: show the Refresh-preview button only while the builder differs from the
	// state the preview currently reflects; hide it again when edits are reverted.
	var $builderForm = $('#btbl-table-builder').closest('form');
	if (!$builderForm.length) { $builderForm = $('#post'); }
	var previewedState = $builderForm.length ? $builderForm.serialize() : '';
	function syncRefreshPreviewVisibility() {
		if (!$builderForm.length) { return; }
		var dirty = $builderForm.serialize() !== previewedState;
		$('.btbl-preview-toolbar').prop('hidden', !dirty);
	}
	$(document).on('change input', '#btbl-table-builder :input', syncRefreshPreviewVisibility);

	// R15: refresh the table preview against the current (unsaved) builder state.
	$(document).on('click', '#btbl-refresh-preview', function(e) {
		e.preventDefault();
		var $btn = $(this);
		var $target = $('#btbl-preview-target');
		if (!$target.length) { return; }
		var serialized = $builderForm.length ? $builderForm.serialize() : '';
		var data = serialized + '&action=btbl_refresh_preview';
		$btn.prop('disabled', true);
		$.post(window.ajaxurl, data).done(function(resp) {
			if (resp && resp.success && resp.data && typeof resp.data.html === 'string') {
				$target.html(resp.data.html);
				previewedState = serialized; // preview now reflects this state
				syncRefreshPreviewVisibility(); // -> hides the button until the next edit
			}
		}).always(function() {
			$btn.prop('disabled', false);
		});
	});
});
