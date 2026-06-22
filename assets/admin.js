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
			var priorityVal = $.trim($sortPriority.val());
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

	$('#btbl-tab-columns .btbl-checkbox').each(function() {
		var $label = $(this);
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
			triggerCsvPreviewRefresh(true);
			var selected = $(this).val();
			var url = new URL(window.location.href);
			url.searchParams.set('btbl_source', selected);
			if (selected === 'custom_query') {
				var customQuery = $('#btbl_custom_query_json').val() || '';
				url.searchParams.set('btbl_preview_custom_query', customQuery);
			} else {
				url.searchParams.delete('btbl_preview_custom_query');
			}
			if (isBuilderPage) {
				var editId = $('#btbl_post_type').data('edit-id');
				var pageSlug = $('#btbl_post_type').data('page') || 'baratables';
				url.searchParams.set('page', pageSlug);
				if (editId) {
					url.searchParams.set('id', editId);
				}
			}
			window.location.href = url.toString();
		});
		syncSourceVisibility();
	}

	var $postTypeSelect = $('#btbl_post_type');
	if ($postTypeSelect.length) {
		$postTypeSelect.on('change', function() {
			var selected = $(this).val();
			var typeParam = Array.isArray(selected) ? selected.join(',') : selected;
			var url = new URL(window.location.href);
			if (isBuilderPage) {
				var editId = $(this).data('edit-id');
				var pageSlug = $(this).data('page') || 'baratables';
				url.searchParams.set('page', pageSlug);
				if (editId) {
					url.searchParams.set('id', editId);
				} else {
					url.searchParams.delete('id');
				}
			}
			url.searchParams.set('type', typeParam || '');
			window.location.href = url.toString();
		});
	}

	var $chartTableSelect = $('#btbl_chart_table');
	if ($chartTableSelect.length) {
		$chartTableSelect.on('change', function() {
			var selected = $(this).val();
			var url = new URL(window.location.href);
			if (selected) {
				url.searchParams.set('table', selected);
			} else {
				url.searchParams.delete('table');
			}
			if (isBuilderPage) {
				var editId = $('#btbl_chart_id').val() || '';
				var pageSlug = $(this).data('page') || 'wp-btbl-charts-add';
				url.searchParams.set('page', pageSlug);
				if (editId) {
					url.searchParams.set('id', editId);
				}
			}
			window.location.href = url.toString();
		});
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

	function initChipPickers() {
		$('.btbl-chip-picker').each(function() {
			var $picker = $(this);
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
			title: 'Select CSV file',
			button: { text: 'Use CSV' },
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
		var url = new URL(window.location.href);
		var csvId = clearCsv ? '0' : ($('#btbl_csv_attachment_id').val() || '');
		var delim = $('#btbl_csv_delimiter').val() || ',';
		var hasHeader = $('#btbl_csv_has_header').is(':checked') ? '1' : '0';
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
		if (activeTab) {
			url.searchParams.set('tab', activeTab);
		}
		window.location.href = url.toString();
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

	function renderCustomGrid(headers, rows, counts) {
		var headingLabel = $customGrid.data('heading-label') || 'Column';
		var colTemplate = $customGrid.data('column-label') || 'Column %d';
		var rowTemplate = $customGrid.data('row-label') || 'Row %d';
		var $table = $('<table class="widefat fixed striped"/>');
		var $thead = $('<thead/>').appendTo($table);
		var $headRow = $('<tr/>').appendTo($thead);
		$('<th scope="col"/>').text(headingLabel).appendTo($headRow);
		for (var c = 0; c < counts.cols; c++) {
			var placeholder = (colTemplate || '').replace('%d', (c + 1));
			var headerVal = (headers && headers[c]) ? headers[c] : placeholder;
			var $th = $('<th scope="col"/>').text(headerVal);
			$headRow.append($th);
		}
		var $tbody = $('<tbody/>').appendTo($table);
		for (var r = 0; r < counts.rows; r++) {
			var rowLabel = (rowTemplate || 'Row %d').replace('%d', (r + 1));
			var $tr = $('<tr/>');
			$tr.append($('<th scope="row"/>').text(rowLabel));
			var rowValues = rows[r] || [];
			for (var c2 = 0; c2 < counts.cols; c2++) {
				var cellVal = rowValues[c2] || '';
				var $cellInput = $('<input type="text"/>')
					.attr('name', 'btbl_custom_data[' + r + '][' + c2 + ']')
					.val(cellVal);
				$tr.append($('<td/>').append($cellInput));
			}
			$tbody.append($tr);
		}
		$customGrid.empty().append($table);
		$customGrid.attr('data-cols', counts.cols).attr('data-rows', counts.rows);
		$customColsInput.val(counts.cols);
		$customRowsInput.val(counts.rows);
	}

	function rebuildCustomGrid() {
		if (!$customGrid.length) {
			return;
		}
		var counts = getCustomCounts();
		var values = readCustomGridValues();
		var rows = [];
		for (var r = 0; r < counts.rows; r++) {
			var sourceRow = values.rows[r] || [];
			var normalized = [];
			for (var c2 = 0; c2 < counts.cols; c2++) {
				normalized[c2] = sourceRow[c2] || '';
			}
			rows[r] = normalized;
		}
		renderCustomGrid([], rows, counts);
	}

	if ($customGrid.length) {
		$customRefresh.on('click', function(e) {
			e.preventDefault();
			rebuildCustomGrid();
		});
		$customColsInput.add($customRowsInput).on('change input', function() {
			$(this).closest('.btbl-custom-grid-control').addClass('is-dirty');
		});
		rebuildCustomGrid();
	}

	function triggerCustomQueryPreview() {
		if (!$customQueryInput.length) {
			return;
		}
		var raw = $customQueryInput.val() || '';
		var url = new URL(window.location.href);
		url.searchParams.set('btbl_source', 'custom_query');
		url.searchParams.set('btbl_preview_custom_query', raw);
		var activeTab = $('.btbl-tab-link.nav-tab-active').data('target') || '';
		if (activeTab) {
			url.searchParams.set('tab', activeTab);
		}
		window.location.href = url.toString();
	}

	if ($customQueryRefresh.length) {
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
		setTimeout(function() {
			$el.removeClass('is-copied');
		}, 1500);
	}

	function activateTab(targetId) {
		var $targetPanel = $('#' + targetId);
		if (!$targetPanel.length) {
			return;
		}
		$('.btbl-tab-link').removeClass('nav-tab-active');
		$('.btbl-tab-link[data-target="' + targetId + '"]').addClass('nav-tab-active');
		$('.btbl-tab-panel').removeClass('is-active');
		$targetPanel.addClass('is-active');
		$('#btbl_active_tab').val(targetId);
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
			Object.keys(availability).forEach(function(key) {
				var enabled = availability[key];
				$builder.find('.btbl-layout-chip[data-feature="' + key + '"]')
					.toggleClass('is-disabled', !enabled)
					.attr('aria-disabled', enabled ? 'false' : 'true');
			});
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
			updateLayoutAvailability();
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
			updateLayoutAvailability();
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
			updateLayoutAvailability();
		});

		syncLayoutInputs();
		updateLayoutAvailability();
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
			$input.val(order.join(','));
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

	var $selectAllColumns = $('#btbl_select_all_columns');
	if ($selectAllColumns.length) {
		function syncSelectAllState() {
			var $checkboxes = $('#btbl-tab-columns input[type="checkbox"][name="btbl_columns[]"]:visible');
			var allChecked = $checkboxes.length > 0 && $checkboxes.filter(':checked').length === $checkboxes.length;
			$selectAllColumns.prop('checked', allChecked);
		}

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
		$series.find('option').each(function() {
			var $opt = $(this);
			var isXAxis = xAxis !== '' && $opt.val() === xAxis;
			$opt.prop('hidden', isXAxis);
			if (isXAxis && $opt.is(':selected')) {
				$opt.prop('selected', false);
			}
		});
		$series.trigger('change.select2');
	}

	function toggleChartControlsUI() {
		var type = $('#btbl_chart_type').val() || 'bar';
		var isGantt = type === 'gantt';
		var $standardBlock = $('.btbl-chart-standard');
		var $ganttBlock = $('.btbl-chart-gantt');
		$standardBlock.toggleClass('is-hidden', isGantt);
		$ganttBlock.toggleClass('is-hidden', !isGantt);

		var $requiredStandard = $('#btbl_chart_x_axis, #btbl_chart_series');
		var $requiredGantt = $('#btbl_chart_gantt_label, #btbl_chart_gantt_start, #btbl_chart_gantt_end');
		if (!isGantt) {
			$requiredStandard.attr('required', 'required');
			$requiredGantt.removeAttr('required');
		} else {
			$requiredGantt.attr('required', 'required');
			$requiredStandard.removeAttr('required');
		}

		updateChartStackToggle();
		$('#btbl_chart_series').trigger('change.select2');
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

		function closeModal() {
			$modal.removeClass('is-open');
		}

		function openModal() {
			$modal.addClass('is-open');
		}

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
			openModal();
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
});
