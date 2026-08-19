jQuery(function($) {
	var applyDefaultIfUntouched = window.BaraTablesAdminCore.applyDefaultIfUntouched;

	function tableOptionEnabled(key) {
		if (key === 'buttons') {
			return $('input[name="btbl_table_options[buttons][]"]:checked').length > 0;
		}
		return $('input[type="checkbox"][name="btbl_table_options[' + key + ']"]').is(':checked');
	}

	function optionDependenciesMet($row) {
		var conditions = $row.data('btblConditions');
		if (!conditions) {
			var raw = $row.attr('data-btbl-visible-when');
			try { conditions = raw ? JSON.parse(raw) : {}; } catch (e) { conditions = {}; }
			$row.data('btblConditions', conditions);
		}
		return Object.keys(conditions).every(function(key) {
			return tableOptionEnabled(key) === !!conditions[key];
		});
	}

	function toggleTableOptionDependencies() {
		for (var pass = 0; pass < 2; pass++) {
			$('[data-btbl-visible-when]').each(function() {
				var $row = $(this);
				var visible = optionDependenciesMet($row);
				var $checkbox = $row.find('input[type="checkbox"][name^="btbl_table_options"]').first();
				if (!visible && $checkbox.attr('data-btbl-reset-when-hidden') === '1') {
					$checkbox.prop('checked', false);
				} else if (visible && $checkbox.attr('data-btbl-restore-default') === '1') {
					applyDefaultIfUntouched($checkbox);
				}
				$row.toggleClass('is-hidden', !visible);
			});
		}
	}

	function toggleTableFlagOptions() {
		$('.btbl-table-flags .btbl-checkbox, .btbl-table-flags .btbl-flag-card').each(function() {
			var $card = $(this);
			// The FIRST checkbox is the card's own toggle -- the card body may hold further
			// checkboxes ("Collapse when shorter", "Show per page selector") whose defaults are
			// checked. is(':checked') over the whole collection means "any is checked", which
			// kept the gear visible on disabled cards like "Fixed scroll height".
			var $checkbox = $card.find('input[type="checkbox"][name^="btbl_table_options"]').first();
			if (!$checkbox.length) { return; }
			var checked = $checkbox.is(':checked');
			var $toggle = $card.find('.btbl-flag-options-toggle');
			var $body = $card.find('.btbl-field-options-body');
			$toggle.toggleClass('is-hidden', !checked).attr('aria-expanded', checked && $body.hasClass('is-open') ? 'true' : 'false');
			if (!checked) { $body.removeClass('is-open').addClass('is-hidden'); }
			else { $body.removeClass('is-hidden'); }
		});
	}

	var defaultFlagSelector = 'input[type="checkbox"][data-btbl-restore-default="1"]';
	$(document).on('pointerdown keydown change', defaultFlagSelector, function() { $(this).data('touched', true); });
	$(document).on('change', 'input[type="checkbox"][name^="btbl_table_options"]', toggleTableOptionDependencies);
	$(document).on('change', '.btbl-table-flags input[type="checkbox"][name^="btbl_table_options"]', function() {
		toggleTableFlagOptions();
		toggleTableOptionDependencies();
	});
	$(document).on('click', '.btbl-flag-options-toggle', function(event) {
		event.preventDefault();
		var $toggle = $(this);
		var $body = $toggle.closest('.btbl-checkbox, .btbl-flag-card').find('.btbl-field-options-body');
		var open = !$body.hasClass('is-open');
		$body.toggleClass('is-open', open);
		$toggle.attr('aria-expanded', open ? 'true' : 'false');
	});

	// "Table overrides" cards. The checkbox state is derived from the stored value, so an
	// override switched off must submit the default again -- otherwise the saved value flips
	// the toggle right back on at the next render. Visibility follows the same contract as the
	// flag cards in toggleTableFlagOptions(): checking reveals the gear only (the body opens
	// via the gear), and unchecking closes and hides both. The accent hex deliberately stays
	// empty when enabled -- empty means "follow the theme", so the override only takes hold
	// once a color is actually entered.
	$(document).on('change', '.btbl-override-flags [data-btbl-override-toggle]', function() {
		var $card = $(this).closest('[data-btbl-override]');
		var enabled = $(this).is(':checked');
		var $toggle = $card.find('.btbl-flag-options-toggle');
		var $body = $card.find('.btbl-field-options-body');
		$toggle.toggleClass('is-hidden', !enabled).attr('aria-expanded', enabled && $body.hasClass('is-open') ? 'true' : 'false');
		if (!enabled) {
			// Empty IS "use the default" (the placeholder carries the default; the save
			// pipeline restores it), so clearing the fields keeps the derived state honest.
			$card.find('[data-btbl-override-field]').val('');
			// The swatch cannot be blank: park it back on the theme accent the field renders.
			var $colorField = $card.find('.btbl-color-field');
			$card.find('.btbl-color-picker').val(($colorField.data('btbl-theme-accent') || '#2271b1'));
			$body.removeClass('is-open').addClass('is-hidden');
		} else {
			$body.removeClass('is-hidden');
		}
	});

	function initLayoutBuilder($builder) {
		var dragItem = null;
		var $palette = $builder.find('.btbl-layout-palette-drop');
		var defaults = $builder.data('defaults') || {};
		if (typeof defaults === 'string') {
			try { defaults = JSON.parse(defaults); } catch (e) { defaults = {}; }
		}

		function syncLayoutInputs() {
			$builder.find('.btbl-layout-inputs').each(function() {
				var $inputs = $(this);
				var zoneKey = $inputs.data('zone-inputs');
				if (!zoneKey) { return; }
				var $zone = $builder.find('.btbl-layout-drop[data-zone="' + zoneKey + '"]');
				$inputs.empty().append('<input type="hidden" name="btbl_table_options[' + zoneKey + '][]" value="" />');
				$zone.find('.btbl-layout-chip').each(function() {
					var feature = $(this).data('feature');
					if (feature) { $inputs.append('<input type="hidden" name="btbl_table_options[' + zoneKey + '][]" value="' + feature + '" />'); }
				});
			});
		}

		function updateLayoutAvailability() {
			var disabledHint = $builder.find('.btbl-layout-grid').data('disabled-hint') || '';
			$builder.find('.btbl-layout-chip').each(function() {
				var $chip = $(this);
				var optionKey = $chip.attr('data-btbl-option-key');
				var enabled = !optionKey || tableOptionEnabled(optionKey);
				$chip.toggleClass('is-disabled', !enabled)
					.attr('aria-disabled', enabled ? 'false' : 'true')
					.attr('title', enabled ? null : disabledHint);
			});
		}

		function getCurrentLayout() {
			var map = {};
			$builder.find('.btbl-layout-drop[data-zone]').each(function() {
				var zone = $(this).data('zone');
				if (zone === 'palette') { return; }
				map[zone] = $(this).find('.btbl-layout-chip').map(function() { return $(this).data('feature'); }).get();
			});
			return map;
		}

		function layoutMatchesDefaults() {
			var current = getCurrentLayout();
			var keys = {};
			Object.keys(defaults).forEach(function(key) { keys[key] = true; });
			Object.keys(current).forEach(function(key) { keys[key] = true; });
			return Object.keys(keys).every(function(key) {
				return JSON.stringify(defaults[key] || []) === JSON.stringify(current[key] || []);
			});
		}

		function syncLayoutResetVisibility() {
			$builder.find('.btbl-layout-reset').prop('hidden', layoutMatchesDefaults());
		}

		function syncLayoutState() {
			syncLayoutInputs();
			updateLayoutAvailability();
			syncLayoutResetVisibility();
		}

		function resetLayout() {
			$palette.append($builder.find('.btbl-layout-chip'));
			Object.keys(defaults).forEach(function(zoneKey) {
				var $zone = $builder.find('.btbl-layout-drop[data-zone="' + zoneKey + '"]');
				(Array.isArray(defaults[zoneKey]) ? defaults[zoneKey] : []).forEach(function(feature) {
					var $chip = $builder.find('.btbl-layout-chip[data-feature="' + feature + '"]').first();
					if ($chip.length) { $zone.append($chip); }
				});
			});
			syncLayoutState();
		}

		$builder.on('keydown', '.btbl-layout-chip', function(event) {
			var horizontal = event.key === 'ArrowLeft' || event.key === 'ArrowRight';
			var vertical = event.key === 'ArrowUp' || event.key === 'ArrowDown';
			if (!horizontal && !vertical) { return; }
			event.preventDefault();
			var $chip = $(this);
			var $zones = $builder.find('.btbl-layout-drop');
			var $currentZone = $chip.closest('.btbl-layout-drop');
			if (vertical) {
				var $siblings = $currentZone.find('.btbl-layout-chip');
				var index = $siblings.index($chip);
				var nextIndex = event.key === 'ArrowUp' ? index - 1 : index + 1;
				if (nextIndex < 0 || nextIndex >= $siblings.length) { return; }
				if (event.key === 'ArrowUp') { $chip.insertBefore($siblings.eq(nextIndex)); }
				else { $chip.insertAfter($siblings.eq(nextIndex)); }
			} else {
				var zoneIndex = $zones.index($currentZone);
				var targetIndex = event.key === 'ArrowLeft' ? zoneIndex - 1 : zoneIndex + 1;
				if (targetIndex < 0 || targetIndex >= $zones.length) { return; }
				$zones.eq(targetIndex).append($chip);
			}
			syncLayoutState();
			$chip.trigger('focus');
		});

		$builder.on('dragstart', '.btbl-layout-chip', function(event) {
			dragItem = this;
			event.originalEvent.dataTransfer.effectAllowed = 'move';
			event.originalEvent.dataTransfer.setData('text/plain', $(this).data('feature'));
			$(this).addClass('is-dragging');
		});
		$builder.on('dragend', '.btbl-layout-chip', function() {
			$(this).removeClass('is-dragging');
			dragItem = null;
			$builder.find('.btbl-layout-drop').removeClass('is-dragover');
			// Hovering chips reorders the live DOM mid-drag, so a drag aborted with Esc or an
			// outside release leaves the chips visually moved. Re-sync so the hidden inputs (and
			// the next save) match what the admin sees; idempotent for completed drops.
			syncLayoutState();
		});
		$builder.on('dragover', '.btbl-layout-drop', function(event) {
			event.preventDefault();
			event.originalEvent.dataTransfer.dropEffect = 'move';
			$(this).addClass('is-dragover');
		});
		$builder.on('dragleave', '.btbl-layout-drop', function() { $(this).removeClass('is-dragover'); });
		$builder.on('drop', '.btbl-layout-drop', function(event) {
			event.preventDefault();
			$(this).removeClass('is-dragover');
			if (!dragItem) { return; }
			if ($(event.target).closest('.btbl-layout-chip').length === 0) { $(this).append(dragItem); }
			syncLayoutState();
		});
		$builder.on('dragover', '.btbl-layout-chip', function(event) {
			if (!dragItem || dragItem === this) { return; }
			event.preventDefault();
			var rectangle = this.getBoundingClientRect();
			if ((event.originalEvent.clientX - rectangle.left) < rectangle.width / 2) { $(this).before(dragItem); }
			else { $(this).after(dragItem); }
		});
		$builder.on('click', '.btbl-layout-reset', resetLayout);
		$builder.data('btblSyncLayoutState', syncLayoutState);
		syncLayoutState();
	}

	function syncAllLayoutBuilders() {
		$('.btbl-layout-builder').each(function() {
			var syncLayoutState = $(this).data('btblSyncLayoutState');
			if (syncLayoutState) { syncLayoutState(); }
		});
	}

	toggleTableFlagOptions();
	toggleTableOptionDependencies();
	$('.btbl-layout-builder').each(function() { initLayoutBuilder($(this)); });
	$(document).on('change', 'input[name^="btbl_table_options"]', syncAllLayoutBuilders);
});
