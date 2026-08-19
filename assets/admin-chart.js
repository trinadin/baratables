jQuery(function($) {
	var adminCore = window.BaraTablesAdminCore;
	var requestFragment = adminCore.requestFragment;
	var isActivationEvent = adminCore.isActivationEvent;
	var redirectWithQuery = adminCore.redirectWithQuery;

	var $chartTableSelect = $('#btbl_chart_table');
	if ($chartTableSelect.length) {
		var auth = adminCore.formAuth($chartTableSelect);
		var chartTableElement = $chartTableSelect[0];

		function tableChangeHasChoices() {
			// Gantt picks live in their own five selects; leaving them out meant switching the
			// table wiped a Gantt chart's column choices with no confirmation prompt.
			return ($('#btbl_chart_x_axis').val() || '') !== '' ||
				$('#btbl_chart_series input[type="checkbox"]:checked').length > 0 ||
				['#btbl_chart_gantt_label', '#btbl_chart_gantt_start', '#btbl_chart_gantt_end', '#btbl_chart_gantt_group', '#btbl_chart_gantt_progress', '#btbl_chart_heatmap_x', '#btbl_chart_heatmap_y', '#btbl_chart_heatmap_value'].some(function(sel) {
					return ($(sel).val() || '') !== '';
				});
		}

		// Confirm before rebuilding the column pickers and clearing their current choices.
		function handleTableChange(value, revert) {
			var confirmMsg = $chartTableSelect.data('switch-confirm');
			if (tableChangeHasChoices() && confirmMsg && !window.confirm(confirmMsg)) {
				revert();
				return;
			}
			$chartTableSelect.data('previous', value); // keep the revert target in sync after a switch
			refreshChartFields(value);
		}

		var TomSelectCtor = window.TomSelect;
		if (typeof TomSelectCtor === 'function' && !chartTableElement.tomselect) {
			// Search-as-you-type across every table, on the same dependency-free picker the
			// front-end dropdown filters use; Select2 is not shipped anywhere.
			var tablePicker = new TomSelectCtor(chartTableElement, {
				valueField: 'id',
				labelField: 'text',
				searchField: ['text'],
				maxOptions: null,
				placeholder: $chartTableSelect.find('option[value=""]').first().text() || '',
				loadThrottle: 250,
				preload: 'focus',
				load: function(query, callback) {
					var base = {
						action: 'btbl_search_chart_tables',
						_baratables_nonce: auth.nonce,
						post_id: auth.postId,
						search: query
					};
					var collected = [];
					// The endpoint pages at 20 tables; pull follow-up pages (up to 100 choices)
					// so one broad search still lists everything.
					var fetchPage = function(page) {
						$.post(window.ajaxurl, $.extend({}, base, {page: page})).then(function(response) {
							var data = response && response.success && response.data ? response.data : {};
							collected = collected.concat(Array.isArray(data.results) ? data.results : []);
							if (data.more && page < 5) {
								fetchPage(page + 1);
							} else {
								callback(collected);
							}
						}, function() {
							callback(collected);
						});
					};
					fetchPage(1);
				},
				render: {
					no_results: function(data, escape) {
						return '<div class="no-results">' + escape($chartTableSelect.data('no-results-label') || 'No tables found.') + '</div>';
					},
					loading: function(data, escape) {
						return '<div class="loading">' + escape($chartTableSelect.data('searching-label') || 'Searching...') + '</div>';
					}
				}
			});

			tablePicker.on('focus', function() {
				$chartTableSelect.data('previous', tablePicker.getValue() || '');
			});
			tablePicker.on('change', function(value) {
				handleTableChange(value || '', function() {
					// Silent: restore the value at open time without re-running this handler.
					tablePicker.setValue($chartTableSelect.data('previous') || '', true);
				});
			});
		} else {
			// No enhanced picker (its script failed to load): the plain select keeps the flow working.
			$chartTableSelect.on('change', function() {
				var previous = $chartTableSelect.data('previous') || '';
				handleTableChange($(this).val() || '', function() {
					$chartTableSelect.val(previous);
				});
			});
		}
		$chartTableSelect.data('previous', $chartTableSelect.val() || '');
	}

	// Switching a chart's source table rebuilds its column pickers in place via
	// admin-ajax (btbl_refresh_chart_fields) instead of a full page reload.
	function legacyChartReload(tableId) {
		redirectWithQuery(tableId ? {table: tableId} : {}, tableId ? [] : ['table']);
	}
	function refreshChartFields(tableId) {
		requestFragment({
			key: 'chart-fields',
			control: $chartTableSelect,
			data: {action: 'btbl_refresh_chart_fields', table_id: tableId},
			validate: function(response) { return !!(response && response.success && response.data && typeof response.data.panel === 'string'); },
			success: function(data) { applyChartFieldRefresh(data.panel); },
			fallback: function() { legacyChartReload(tableId); }
		});
	}
	function applyChartFieldRefresh(panelHtml) {
		var $new = $('<div>').html(panelHtml);
		// Swap only the INNER content of the live, handler-bound nodes -- never replace the
		// <select>/<div> elements themselves, or their directly-bound change handlers are orphaned.
		['#btbl_chart_x_axis', '#btbl_chart_gantt_label', '#btbl_chart_gantt_start', '#btbl_chart_gantt_end', '#btbl_chart_gantt_group', '#btbl_chart_gantt_progress', '#btbl_chart_heatmap_x', '#btbl_chart_heatmap_y', '#btbl_chart_heatmap_value'].forEach(function(sel) {
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
		toggleChartControlsUI();
		syncChartSeriesOptions();
	}

	$('#btbl_chart_type').on('change', function() {
		toggleChartControlsUI();
		syncChartSeriesOptions();
	});

	function updateChartStackToggle() {
		var $selectedType = $('#btbl_chart_type option:selected');
		var $stack = $('input[name="btbl_chart_stack"]');
		var disableStack = String($selectedType.attr('data-stackable')) === '0';
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
		// A column cannot be both the X-axis and a data series.
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
		var mode = $('#btbl_chart_type option:selected').attr('data-mode') || 'standard';
		var isGantt = mode === 'gantt';
		var isHeatmap = mode === 'heatmap';
		var $standardBlock = $('.btbl-chart-standard');
		var $ganttBlock = $('.btbl-chart-gantt');
		var $heatmapBlock = $('.btbl-chart-heatmap');
		$standardBlock.toggleClass('is-hidden', isGantt || isHeatmap);
		$ganttBlock.toggleClass('is-hidden', !isGantt);
		$heatmapBlock.toggleClass('is-hidden', !isHeatmap);

		// Series is now a checkbox group (no native required); the save guard warns on empty series.
		var $requiredStandard = $('#btbl_chart_x_axis');
		var $requiredGantt = $('#btbl_chart_gantt_label, #btbl_chart_gantt_start, #btbl_chart_gantt_end');
		var $requiredHeatmap = $('#btbl_chart_heatmap_x, #btbl_chart_heatmap_y, #btbl_chart_heatmap_value');
		$requiredStandard.removeAttr('required');
		$requiredGantt.removeAttr('required');
		$requiredHeatmap.removeAttr('required');
		if (isGantt) {
			$requiredGantt.attr('required', 'required');
		} else if (isHeatmap) {
			$requiredHeatmap.attr('required', 'required');
		} else {
			$requiredStandard.attr('required', 'required');
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
			// Return focus to the control that opened the modal.
			if (lastFocus && typeof lastFocus.focus === 'function') {
				lastFocus.focus();
			}
		}

		function openModal(trigger) {
			lastFocus = trigger || document.activeElement;
			$modal.addClass('is-open');
			// Move focus into the dialog (active card, else first card/close).
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

		function bindActivation($elements, activate) {
			$elements.on('click keydown', function(e) {
				if (!isActivationEvent(e)) { return; }
				e.preventDefault();
				activate(this);
			});
		}

		// Trap Tab within the open dialog.
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

		bindActivation($openers, openModal);
		bindActivation($closers, closeModal);

		$(document).on('keydown', function(e) {
			if (e.key === 'Escape' && $modal.hasClass('is-open')) {
				closeModal();
			}
		});
	}

	initChartTypeChooser();
});
