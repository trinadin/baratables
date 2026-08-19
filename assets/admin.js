jQuery(function($) {
	var columnOrderController;
	var filterOrderController;
	var adminCore = window.BaraTablesAdminCore;
	var requestFragment = adminCore.requestFragment;
	var redirectWithQuery = adminCore.redirectWithQuery;

	function updateSortVisibility($label) {
		var checked = $label.find('input[type="checkbox"][name="btbl_columns[]"]').is(':checked');
		var $sortToggle = $label.find('.btbl-sort-enabled');
		var sortEnabled = $sortToggle.is(':checked') && checked;
		var $sortPriority = $label.find('.btbl-sort-priority');
		var $row = $sortPriority.closest('.btbl-options-row');
		$row.toggleClass('is-hidden', !sortEnabled);
		// When sort is enabled but no priority is set yet, default it to 1 -- the same default the
		// server applies in ui.php so an enabled column always has a concrete priority.
		if (sortEnabled && $sortPriority.length) {
			var priorityVal = ($sortPriority.val() || '').trim();
			if (!priorityVal || parseInt(priorityVal, 10) < 1) {
				$sortPriority.val('1');
			}
		}
		$sortToggle.closest('.btbl-inline').toggleClass('is-hidden', !checked);
	}

	function setOptionsOpen($label, open) {
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

		var sortableEnabled = checked && !hideColumnChecked && orderingEnabled;
		var sortInputsEnabled = sortEnabled && checked;

		$optionsToggle.toggleClass('is-hidden', !checked);
		$fieldControls.toggle(checked);

		$customLabelRow.toggleClass('is-hidden', !headingInputEnabled);

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

		if (isDateCandidate) {
			$dateRow.toggleClass('is-hidden', !checked);
		} else {
			$dateRow.addClass('is-hidden');
		}

		$sortableRow.toggleClass('is-hidden', !sortableEnabled);
		// Keep sortable state; default handled by server-side state.

		$sortRow.toggleClass('is-hidden', !sortInputsEnabled);
		// updateSortVisibility() below fills a blank priority with 1 when sort is enabled.

		if (!filterEnabled || !checked) {
			$select.val('none');
			$sortSelect.val('asc');
		}
		if (!dropdownEnabled) {
			$dropdownInputs.prop('checked', false);
		}
		if (!checked) {
			$sortToggle.prop('checked', false);
			$sortRow.addClass('is-hidden');
			setOptionsOpen($label, false);
		}

		updateSortVisibility($label);
	}

	// Set while "Select all columns" is flipping every checkbox in turn. Each change would
	// otherwise trigger a full order-list rebuild plus a document-wide attribute sweep per
	// selected column -- O(N^2) for a result the bulk handler recomputes once at the end.
	// toggleFilterControls() deliberately still runs per row: that is
	// per-row state, not a whole-list rebuild.
	var bulkColumnToggle = false;

	// Bind a single column row's controls. Extracted so swapped-in rows (AJAX
	// post-type refresh) can be re-initialised without a page reload.
	function initColumnOption($label) {
		toggleFilterControls($label);
		$label.find('input[type="checkbox"][name="btbl_columns[]"]').on('change', function() {
			toggleFilterControls($label);
			if (bulkColumnToggle) {
				return;
			}
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
			setOptionsOpen($label, !isOpen);
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

	// The accent-color picker writes into the adjacent hex field; typing a hex updates the
	// swatch in return -- live on every keystroke, so the pair never visibly disagrees. An
	// empty field means "follow the theme", so clearing it parks the swatch back on the same
	// theme accent the placeholder names. Partial/invalid hexes are ignored until complete:
	// the native swatch can only ever show a full #rrggbb.
	$(document).on('input', '.btbl-color-picker', function() {
		var $value = $(this).closest('.btbl-color-field').find('.btbl-color-value');
		if ($value.length) {
			$value.val($(this).val()).trigger('change');
		}
	});
	$(document).on('input change', '.btbl-color-value', function() {
		var $field = $(this).closest('.btbl-color-field');
		var $picker = $field.find('.btbl-color-picker');
		// Masked as you type: only "#" and hex digits, seven characters at most. The value
		// is rewritten ONLY when something was stripped, so normal typing never disturbs the
		// caret. A non-empty value that is not yet a complete #rrggbb flags the field as
		// invalid -- the save pipeline drops such a value back to the theme color.
		var raw = String($(this).val() || '');
		var masked = raw.replace(/[^#0-9a-fA-F]/g, '').slice(0, 7);
		if (masked !== raw) {
			$(this).val(masked);
			this.setSelectionRange(masked.length, masked.length);
		}
		var complete = /^#[0-9a-fA-F]{6}$/.test(masked);
		var invalid = masked !== '' && !complete;
		$(this).toggleClass('is-invalid', invalid);
		if (invalid) {
			$(this).attr('aria-invalid', 'true');
		} else {
			$(this).removeAttr('aria-invalid');
		}
		if (complete) {
			$picker.val(masked);
		} else if (masked === '') {
			$picker.val($field.data('btbl-theme-accent') || '#2271b1');
		}
	});

	// #btbl_source_type is the one control applyFieldRefresh() never replaces, so it is safe to
	// hold. Everything else below is re-queried on each call instead of cached at DOM-ready:
	// applyFieldRefresh() swaps out the taxonomy select and the entire .btbl-taxonomy-filter
	// block, and a cached collection would keep toggling classes on the detached originals --
	// leaving the term picker permanently invisible until a full page reload.
	var $sourceSelect = $('#btbl_source_type');
	function syncTaxonomyTerms() {
		var source = $sourceSelect.length ? $sourceSelect.val() || 'wp_query' : 'wp_query';
		var isWpQuerySource = source === 'wp_query';
		var selected = $('#btbl_taxonomy').val() || [];
		if (!Array.isArray(selected)) {
			selected = selected ? [selected] : [];
		}
		var hasSelection = selected.length > 0 && isWpQuerySource;
		$('.btbl-taxonomy-filter').toggleClass('is-hidden', !hasSelection);
		$('.btbl-taxonomy-term-picker').toggleClass('is-hidden', !hasSelection);
		$('.btbl-tax-terms-group').each(function() {
			var $group = $(this);
			var matches = isWpQuerySource && selected.indexOf($group.data('taxonomy')) !== -1;
			$group.toggleClass('is-hidden', !matches);
		});
	}
	function syncSourceVisibility() {
		var selected = $sourceSelect.length ? $sourceSelect.val() || 'wp_query' : 'wp_query';
		$('[data-btbl-source]').each(function() {
			var $block = $(this);
			// data-btbl-source may list several sources, space separated (e.g. the row-token
			// field, which applies to both WP_Query-backed sources).
			var targets = String($block.data('btbl-source') || 'wp_query').split(' ');
			var show = targets.indexOf(selected) !== -1;
			$block.toggleClass('is-hidden', !show);
		});
		syncTaxonomyTerms();
	}
	// Declared ahead of the source-select handler below, which reads it. Both run only on user
	// interaction, so the hoisted `var` was never actually undefined at call time -- but keeping
	// the declaration above its first use is what lets no-use-before-define stay on, and that
	// rule is what caught the ext.search cleanup bug in baratables.js.
	var $postTypeSelect = $('#btbl_post_type');
	if ($sourceSelect.length) {
		$sourceSelect.on('change', function() {
			syncSourceVisibility();
			// Each source's control block is already in the DOM (toggled above), so switching only
			// needs to refresh the source-dependent columns panel -- done in place via
			// btbl_refresh_fields (the same AJAX swap the post-type switch uses), no full reload.
			// CSV column inference still runs through the CSV controls' own refresh (file upload /
			// delimiter / header), and custom-query/external columns load via their own actions.
			var typeParam = normalizeSelectValues($postTypeSelect.length ? $postTypeSelect.val() : '').join(',');
			refreshSourceFields(typeParam);
		});
		syncSourceVisibility();
	}

	if ($postTypeSelect.length) {
		$postTypeSelect.on('change', function() {
			var typeParam = normalizeSelectValues($(this).val()).join(',');
			refreshSourceFields(typeParam);
		});
	}

	// Legacy full-page reload -- used as a graceful fallback if the AJAX refresh fails.
	function legacyPostTypeReload(typeParam, source) {
		var values = {type: typeParam || ''};
		// Without the source param the reload falls back to the SAVED source, silently undoing
		// the switch the user just made; the CSV and custom-query reloads already carry theirs.
		if (source) {
			values.btbl_source = source;
		}
		redirectWithQuery(values);
	}

	// Fetch the post-type-dependent fields via admin-ajax and swap them in place
	// instead of reloading the whole editor.
	function refreshSourceFields(typeParam) {
		var source = $sourceSelect.length ? ($sourceSelect.val() || 'wp_query') : 'wp_query';
		// CSV columns come from the selected file (id/delimiter/header), not the post type. The
		// generic fields refresh doesn't carry those params, so it would blank the columns while
		// leaving a file in the uploader. Route CSV through the CSV-aware refresh instead, which
		// re-infers the columns of whatever file is still selected (or clears them if none is).
		if (source === 'csv') {
			triggerCsvPreviewRefresh();
			return;
		}
		requestFragment({
			key: 'fields',
			control: $postTypeSelect,
			data: {action: 'btbl_refresh_fields', type: typeParam, source: source},
			validate: function(response) { return !!(response && response.success && response.data); },
			success: applyFieldRefresh,
			fallback: function() { legacyPostTypeReload(typeParam, source); }
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
	}

	// Delegated, not bound directly: applyFieldRefresh() replaces #btbl_taxonomy, which would
	// discard a handler attached to the original node.
	$(document).on('change', '#btbl_taxonomy', syncTaxonomyTerms);
	if ($('#btbl_taxonomy').length) {
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
				// Cache the lowercased label on first use: without it every keystroke re-read and
				// re-lowercased the text node of every chip, which is O(terms) string work per
				// character typed on a taxonomy with hundreds of terms.
				var text = $chip.data('btblSearchText');
				if (text === undefined) {
					text = $chip.text().toLowerCase();
					$chip.data('btblSearchText', text);
				}
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

		// Debounced: the handler walks every chip in the group, so firing it on each keystroke
		// is the expensive part on a large taxonomy. 150ms still feels instant while typing.
		// The timer is stored on the input rather than in one shared variable, because a single
		// delegated handler serves every taxonomy's search box -- a shared timer let typing in
		// one box cancel a different box's pending filter and leave that group unfiltered.
		function flushTermSearch($group) {
			var $input = $group.find('.btbl-term-search');
			var timer = $input.data('btblSearchTimer');
			if (timer) {
				clearTimeout(timer);
				$input.removeData('btblSearchTimer');
				filterTermChips($group, $input.val());
			}
		}
		$(document).on('input', '.btbl-term-search', function() {
			var $input = $(this);
			var $group = $input.closest('.btbl-tax-terms-group');
			clearTimeout($input.data('btblSearchTimer'));
			$input.data('btblSearchTimer', setTimeout(function() {
				$input.removeData('btblSearchTimer');
				filterTermChips($group, $input.val());
			}, 150));
		});

		$(document).on('click', '.btbl-term-action', function() {
			var $button = $(this);
			var action = $button.data('action');
			var $group = $button.closest('.btbl-tax-terms-group');
			// Select all / Clear operate on what is currently *visible*. If a search is still
			// waiting out its debounce the visible set is the pre-search one, so a keyboard user
			// who reaches this button within 150ms of typing would select every term in the
			// taxonomy instead of the matches. Settle the filter first.
			flushTermSearch($group);
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

	var mediaFrames = {};
	var mediaTarget = null;
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
		// One frame per title/button pair, reused across clicks: every wp.media() call attaches
		// another modal to the DOM, so building one per click leaks them. The select handler
		// reads mediaTarget at pick time, so a reused frame still writes to the invoking field.
		var frameKey = frameTitle + '|' + frameButton;
		var mediaFrame = mediaFrames[frameKey];
		if (!mediaFrame) {
			mediaFrame = wp.media({
				title: frameTitle,
				button: { text: frameButton },
				library: { type: ['text/csv', 'text/plain', 'application/vnd.ms-excel'] },
				multiple: false,
			});
			mediaFrame.on('select', function() {
				var attachment = mediaFrame.state().get('selection').first().toJSON();
				if (mediaTarget) {
					mediaTarget.val(attachment.id);
					mediaTarget.siblings('.btbl-media-clear').show();
					triggerCsvPreviewRefresh();
				}
			});
			mediaFrames[frameKey] = mediaFrame;
		}
		mediaTarget = $target;
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
		triggerCsvPreviewRefresh({ clearCsv: true });
	});

	function triggerCsvPreviewRefresh(options) {
		var opts = options || {};
		var clearCsv = !!opts.clearCsv;
		var source = $sourceSelect.length ? $sourceSelect.val() || 'wp_query' : 'wp_query';
		if (source !== 'csv' && !clearCsv) {
			return;
		}
		var csvId = clearCsv ? '0' : ($('#btbl_csv_attachment_id').val() || '');
		var delim = $('#btbl_csv_delimiter').val() || ',';
		var hasHeader = $('#btbl_csv_has_header').is(':checked') ? '1' : '0';
		// Fallback: the original full-page reload (used if AJAX is unavailable or the request fails).
		function legacyCsvReload() {
			var values = {btbl_source: source};
			var removedKeys = [];
			if (clearCsv) {
				values.btbl_preview_csv_id = '0';
				removedKeys = ['btbl_preview_csv_delim', 'btbl_preview_csv_header'];
			} else if (csvId) {
				values.btbl_preview_csv_id = csvId;
				values.btbl_preview_csv_delim = delim;
				values.btbl_preview_csv_header = hasHeader;
			} else {
				removedKeys = ['btbl_preview_csv_id', 'btbl_preview_csv_delim', 'btbl_preview_csv_header'];
			}
			var activeTab = $('.btbl-tab-link.nav-tab-active').data('target') || '';
			if (activeTab) { values.tab = activeTab; }
			redirectWithQuery(values, removedKeys);
		}
		// Infer the CSV columns in place via the shared fields endpoint instead of reloading.
		var typeVal = $postTypeSelect.length ? $postTypeSelect.val() : '';
		var payload = {
			action: 'btbl_refresh_fields',
			type: normalizeSelectValues(typeVal).join(','),
			source: 'csv'
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
		requestFragment({
			key: 'fields',
			control: $('#btbl_csv_attachment_id'),
			data: payload,
			validate: function(response) { return !!(response && response.success && response.data); },
			success: applyFieldRefresh,
			fallback: legacyCsvReload
		});
	}

	$('#btbl_csv_delimiter, #btbl_csv_has_header').on('change input', function() {
		triggerCsvPreviewRefresh();
	});

	var $customQueryRefresh = $('#btbl_custom_query_refresh');
	var $customQueryInput = $('#btbl_custom_query_json');

	function customQueryPostTypeParam(raw) {
		try {
			var parsed = JSON.parse(raw);
			var pt = parsed && parsed.post_type;
			if (Array.isArray(pt)) { return pt.join(','); }
			if (typeof pt === 'string' && pt) { return pt; }
		} catch (e) {
			// Not valid JSON yet (user still typing) -- fall through to the empty default.
		}
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
		function legacyCustomQueryReload() {
			var values = {btbl_source: 'custom_query', btbl_preview_custom_query: raw};
			var activeTab = $('.btbl-tab-link.nav-tab-active').data('target') || '';
			if (activeTab) { values.tab = activeTab; }
			redirectWithQuery(values);
		}
		$customQueryRefresh.prop('disabled', true);
		requestFragment({
			key: 'fields',
			control: $customQueryInput,
			data: {action: 'btbl_refresh_fields', type: customQueryPostTypeParam(raw), source: 'custom_query', custom_query: raw},
			validate: function(response) { return !!(response && response.success && response.data); },
			success: function(data) {
				applyFieldRefresh(data);
				markCustomQueryLoaded(raw); // columns now match this query -> re-hide the button
			},
			unavailable: legacyCustomQueryReload,
			always: function() {
			// Re-enable even when another fields refresh superseded and aborted this request.
				$customQueryRefresh.prop('disabled', false);
			}
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
	var initialTabParam;
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
	// Enter acts as OK, Escape as Cancel -- and never submits the surrounding post form.
	$(document).on('keydown', '.btbl-id-input', function(e) {
		if (e.key === 'Enter') {
			e.preventDefault();
			$(this).closest('.btbl-id-editor').find('.btbl-id-edit-ok').trigger('click');
		} else if (e.key === 'Escape') {
			e.preventDefault();
			$(this).closest('.btbl-id-editor').find('.btbl-id-edit-cancel').trigger('click');
		}
	});

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
			// Fire change so listeners (e.g. the Refresh-preview reveal) notice a reorder --
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

		// Provide keyboard reordering alongside drag-and-drop.
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

	// Live-update a column's heading -- the label next to its checkbox and its order/filter pills --
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
		// Keep the checkbox's data-label (read by getSelectedColumnsMap) in sync -- both the
		// attribute and jQuery's cached .data() -- so any rebuilt pills use the new heading too.
		// getSelectedColumnsMap()'s value is written to the DOM by renderList() with jQuery
		// .html(), so whatever we store here must be HTML-safe. HTML-escape BOTH branches:
		// the typed value AND the default fallback. The fallback comes from data-default-label,
		// which the server emits with esc_attr() only (not wp_kses) -- so for an inferred CSV
		// column it holds the raw file header, and reading it back with .attr() returns live
		// markup. Escaping only the typed branch (as this did) let a header like
		// "<img src=x onerror=...>" reach the .html() sink and execute on the next pill rebuild.
		var labelForData = $('<div/>').text(label).html();
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

			bulkColumnToggle = true;
			try {
				$('#btbl-tab-columns input[type="checkbox"][name="btbl_columns[]"]').each(function() {
					var $cb = $(this);
					if (!$cb.is(':visible')) {
						return;
					}
					$cb.prop('checked', checked).trigger('change');
				});
			} finally {
				bulkColumnToggle = false;
			}
			
			if (!checked) {
				$('.btbl-filter-select').val('none');
			}
			syncOrderFromSelection();
			syncFilterOrderFromSelection();
			syncSelectAllState();
			// Suppressed per-column during the bulk loop above; run it once now that the
			// selection has settled, which is the only result that was ever visible.
			syncRefreshPreviewVisibility();
		});

		$(document).on('change', '#btbl-tab-columns input[type="checkbox"][name="btbl_columns[]"]', function() {
			if (bulkColumnToggle) {
				return;
			}
			syncSelectAllState();
		});
	}

	// Holds a queued external-DB column reset so a form submit can cancel it. See the comment at
	// the reset site below for why the reset cannot run synchronously.
	var pendingExternalReset = null;
	$('#post').on('submit', function() {
		if (pendingExternalReset) {
			clearTimeout(pendingExternalReset);
			pendingExternalReset = null;
		}
	});

	// True from the moment a save button is pressed until that press resolves.
	//
	// Cancelling the queued reset on 'submit' is not sufficient for a MOUSE click. Pressing a save
	// button blurs the focused field first, so the real order is:
	//     mousedown -> blur -> change   [task ends]   -> mouseup -> click -> submit
	// A setTimeout(0) queued during 'change' runs at the end of that first task -- before mouseup,
	// let alone submit -- so the reset fired and wiped the column config every time, and the submit
	// handler above then found nothing left to cancel. mousedown is the one event that lands ahead
	// of the blur, which is why the intent is recorded there rather than cancelled later.
	var SAVE_CONTROLS = '#publish, #save-post, #post input[type="submit"], #post button[type="submit"]';
	var saveIntent = false;
	$(document).on('mousedown', SAVE_CONTROLS, function() {
		saveIntent = true;
	});
	$(document).on('keydown', SAVE_CONTROLS, function(e) {
		// Enter or Space on a focused save button activates it.
		if (e.which === 13 || e.which === 32) {
			saveIntent = true;
		}
	});
	function releaseSaveIntent() {
		// If the press did not turn into a submit (pointer dragged off the button, or a validation
		// hook blocked it), drop the intent so a later schema edit still resets as it should.
		window.setTimeout(function() {
			saveIntent = false;
		}, 0);
	}
	// Keyboard activation needs its own release. With only mouseup listening, activating Publish
	// via Enter or Space while something blocked the submit left saveIntent latched true for the
	// rest of the page, silently suppressing every later external-DB column reset.
	$(document).on('mouseup keyup', releaseSaveIntent);

	$('#btbl-tab-general').on('change input', ':input', function(e) {
		var $target = $(e.target);
		if ($target.closest('.btbl-tax-terms-group').length) {
			return;
		}
		var source = $sourceSelect.length ? $sourceSelect.val() || 'wp_query' : 'wp_query';
		var isWpQueryControl = $target.closest('[data-btbl-source~="wp_query"]').length > 0
			|| $target.is('#btbl_post_type')
			|| $target.is('#btbl_taxonomy');
		var isCustomQueryControl = $target.closest('[data-btbl-source~="custom_query"]').length > 0;
		var isCustomDataControl = $target.closest('[data-btbl-source~="custom_data"]').length > 0;
		// External DB is the one source with no auto-refresh to rebuild the column list after a
		// reset, so wiping columns here is pure data loss. Credentials and charset never change
		// which columns exist; the fields that do (server, port, database, table) still reset --
		// but only once the value is committed, never on each keystroke while it is being typed.
		var isExternalDbControl = $target.closest('[data-btbl-source~="external_db"]').length > 0;
		var isExternalSchemaField = $target.is('#btbl_external_table, #btbl_external_name, #btbl_external_host, #btbl_external_port');
		if (source === 'wp_query' && isWpQueryControl) {
			return;
		}
		if (source === 'custom_query' && isCustomQueryControl) {
			return;
		}
		if (source === 'custom_data' && isCustomDataControl) {
			return;
		}
		if (source === 'external_db' && isExternalDbControl) {
			if (e.type === 'input' || !isExternalSchemaField) {
				return;
			}
			// 'change' fires on blur, and clicking "Update" blurs the focused field first, so a
			// synchronous reset here would wipe the column config and the emptied form is what
			// gets saved, with no chance to see it.
			//
			// The mouse path is caught by saveIntent (see its declaration above for the event
			// ordering): the press is already recorded by the time this runs, and the user's
			// column configuration must survive their own save.
			if (saveIntent) {
				return;
			}
			// Enter inside the field fires 'change' and 'submit' in the SAME task, so there the
			// queued reset below is still cancelled by the submit handler before it can run.
			// Tabbing away or clicking elsewhere leaves it to fire, which is what re-infers the
			// columns for the newly pointed-at table.
			if (pendingExternalReset) {
				clearTimeout(pendingExternalReset);
			}
			pendingExternalReset = setTimeout(function() {
				pendingExternalReset = null;
				resetColumnsAndFilters();
			}, 0);
			return;
		}
		resetColumnsAndFilters();
	});


	// Validate JSON textareas on blur so a typo does not silently discard what was typed.
	// Both JSON inputs in the editor fail the same way -- invalid JSON is discarded at save with
	// no visible sign -- so they share one validator instead of only Value overrides having it.
	// Opt in with class="btbl-json-check" + data-error-target="<id of the message element>".
	$(document).on('blur', '.btbl-json-check', function() {
		var $ta = $(this);
		var $err = $('#' + ($ta.data('error-target') || ''));
		var val = ($ta.val() || '').trim();
		var valid = true;
		if (val !== '') {
			try {
				JSON.parse(val);
			} catch (err) {
				valid = false;
			}
		}
		$err.prop('hidden', valid);
		$ta.toggleClass('btbl-invalid', !valid);
	});

	// Show the Refresh-preview button only while the builder differs from the
	// state the preview currently reflects; hide it again when edits are reverted.
	var $builderForm = $('#btbl-table-builder').closest('form');
	if (!$builderForm.length) { $builderForm = $('#post'); }
	var previewedState = $builderForm.length ? $builderForm.serialize() : '';
	function syncRefreshPreviewVisibility() {
		if (!$builderForm.length) { return; }
		// serialize() walks every control in the builder, and this is bound at document level to
		// every :input inside it -- so "Select all columns", which triggers one change per column,
		// otherwise serialized the whole form once per column. The bulk handler calls this once
		// when it is done, which is the only result that was ever visible.
		if (bulkColumnToggle) { return; }
		var dirty = $builderForm.serialize() !== previewedState;
		$('.btbl-preview-toolbar').prop('hidden', !dirty);
	}
	// Debounce the input-driven path only. A manual-data grid is thousands of text inputs inside
	// this form, and syncRefreshPreviewVisibility() serialize()s the whole #post form; running that
	// synchronously on every keystroke lags badly near the cell cap. The
	// Refresh-preview button only needs to appear shortly after edits settle, not on the exact
	// keystroke. Direct callers (post-refresh, and the reset at load) still invoke it synchronously.
	var refreshPreviewDebounce;
	$(document).on('change input', '#btbl-table-builder :input', function() {
		clearTimeout(refreshPreviewDebounce);
		refreshPreviewDebounce = setTimeout(syncRefreshPreviewVisibility, 200);
	});

	// Refresh the table preview against the current unsaved builder state.
	$(document).on('click', '#btbl-refresh-preview', function(e) {
		e.preventDefault();
		var $btn = $(this);
		var $target = $('#btbl-preview-target');
		if (!$target.length) { return; }
		var serialized = $builderForm.length ? $builderForm.serialize() : '';
		var data = serialized + '&action=btbl_refresh_preview';
		$btn.prop('disabled', true);
		requestFragment({
			key: 'preview',
			auth: false,
			data: data,
			validate: function(response) { return !!(response && response.success && response.data && typeof response.data.html === 'string'); },
			success: function(responseData) {
				$target.html(responseData.html);
				previewedState = serialized; // preview now reflects this state
				syncRefreshPreviewVisibility(); // -> hides the button until the next edit
			},
			always: function(isCurrent) {
				if (isCurrent) { $btn.prop('disabled', false); }
			}
		});
	});
});
