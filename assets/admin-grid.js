jQuery(function($) {
	var $customGrid = $('#btbl_custom_grid');
	var $customColsInput = $('#btbl_custom_columns_count');
	var $customRowsInput = $('#btbl_custom_rows_count');
	var $customLimitMessage = $('.btbl-custom-grid-limit');
	var $customRefresh = $('#btbl_custom_grid_refresh');

	function clampNumber(val, min, max) {
		var num = parseInt(val, 10);
		if (isNaN(num)) {
			num = min;
		}
		return Math.min(Math.max(num, min), max);
	}

	// Grid caps come from PHP (data-max-* attributes = the MAX_CUSTOM_* constants). Every path
	// that resizes the grid must read them here, not hardcode a number, or the grid ends up with
	// two disagreeing limits and legal rows get silently dropped on a row action or paste.
	function getGridCaps() {
		return {
			cols: parseInt($customGrid.data('maxCols'), 10) || 100,
			rows: parseInt($customGrid.data('maxRows'), 10) || 1000,
			cells: parseInt($customGrid.data('maxCells'), 10) || 25000
		};
	}

	function getCustomCounts() {
		var cols = $customGrid.data('cols') || 1;
		var rows = $customGrid.data('rows') || 1;
		var caps = getGridCaps();
		var maxCols = caps.cols;
		var maxRows = caps.rows;
		var maxCells = caps.cells;
		var requestedCols = clampNumber($customColsInput.val() || cols, 1, maxCols);
		var requestedRows = clampNumber($customRowsInput.val() || rows, 1, maxRows);
		var cappedRows = Math.min(requestedRows, Math.max(1, Math.floor(maxCells / requestedCols)));
		var wasAdjusted = cappedRows !== requestedRows;
		if ($customLimitMessage.length) {
			var template = $customLimitMessage.data('message') || '';
			var message = template
				.replace('%1$d', requestedCols)
				.replace('%2$d', cappedRows)
				.replace('%3$d', maxCells);
			$customLimitMessage.text(wasAdjusted ? message : '').toggleClass('is-hidden', !wasAdjusted);
		}
		return {
			cols: requestedCols,
			rows: cappedRows,
		};
	}

	function getRenderedCounts(caps) {
		caps = caps || getGridCaps();
		return {
			cols: Math.min(parseInt($customGrid.attr('data-cols'), 10) || 1, caps.cols),
			rows: Math.min(parseInt($customGrid.attr('data-rows'), 10) || 1, caps.rows)
		};
	}

	var gridLabels = {
		moveUp: $customGrid.data('label-move-up') || 'Move row up',
		moveDown: $customGrid.data('label-move-down') || 'Move row down',
		insert: $customGrid.data('label-insert') || 'Insert row below',
		duplicate: $customGrid.data('label-duplicate') || 'Duplicate row',
		remove: $customGrid.data('label-delete') || 'Delete row'
	};
	var gridConfirmShrinkOne = $customGrid.data('confirm-shrink-one') || 'Reducing the grid will remove %d filled cell. Continue?';
	var gridConfirmShrinkMany = $customGrid.data('confirm-shrink-many') || 'Reducing the grid will remove %d filled cells. Continue?';

	// Read the current header labels from the DOM so resizes preserve them
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
		return rows;
	}

	// Count how many filled cells would be discarded if the grid shrinks.
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

	// Per-row delete, insert-below and duplicate controls.
	// Uniform dashicons (shared 20x20 metrics) so the three actions align and size
	// identically, instead of three mismatched text glyphs.
	// Build the grid as one HTML string and parse it once. Constructing jQuery objects per row and
	// cell scales poorly and also resets the scroll position during row actions.
	var escGridHtml = window.BaraTablesUtils.escapeHtml;
	function rowActionHtml(cls, dashicon, label, disabled) {
		return '<button type="button" class="button-link ' + cls + '"'
			+ ' title="' + escGridHtml(label) + '" aria-label="' + escGridHtml(label) + '"'
			+ (disabled ? ' disabled="disabled"' : '') + '>'
			+ '<span class="dashicons ' + dashicon + '" aria-hidden="true"></span>'
			+ '</button>';
	}
	function buildRowActionsHtml(rowIndex, rowCount) {
		// Reorder controls (disabled at the boundaries), then the edit controls.
		return '<td class="btbl-row-actions">'
			+ rowActionHtml('btbl-row-move-up', 'dashicons-arrow-up-alt2', gridLabels.moveUp, rowIndex === 0)
			+ rowActionHtml('btbl-row-move-down', 'dashicons-arrow-down-alt2', gridLabels.moveDown, rowIndex === rowCount - 1)
			+ rowActionHtml('btbl-row-insert', 'dashicons-plus-alt2', gridLabels.insert, false)
			+ rowActionHtml('btbl-row-duplicate', 'dashicons-admin-page', gridLabels.duplicate, false)
			+ rowActionHtml('btbl-row-delete', 'dashicons-no-alt', gridLabels.remove, false)
			+ '</td>';
	}

	function renderCustomGrid(headers, rows, counts) {
		var headingLabel = $customGrid.data('heading-label') || 'Column';
		var colTemplate = $customGrid.data('column-label') || 'Column %d';
		var rowTemplate = $customGrid.data('row-label') || 'Row %d';
		var html = ['<table class="widefat fixed striped"><thead><tr>'];
		html.push('<th scope="col" class="btbl-grid-corner">' + escGridHtml(headingLabel) + '</th>');
		for (var c = 0; c < counts.cols; c++) {
			var placeholder = colTemplate.replace('%d', (c + 1));
			var headerVal = (headers && headers[c]) ? headers[c] : placeholder;
			html.push('<th scope="col">' + escGridHtml(headerVal) + '</th>');
		}
		html.push('<th scope="col" class="btbl-row-actions-head"><span class="screen-reader-text">' + escGridHtml(gridLabels.remove) + '</span></th>');
		html.push('</tr></thead><tbody>');
		for (var r = 0; r < counts.rows; r++) {
			var rowLabel = rowTemplate.replace('%d', (r + 1));
			// Visible gutter shows just the number; the descriptive "Row N" stays as an
			// aria-label so screen readers still announce it.
			html.push('<tr><th scope="row" class="btbl-grid-rownum" aria-label="' + escGridHtml(rowLabel) + '">' + (r + 1) + '</th>');
			var rowValues = rows[r] || [];
			for (var c2 = 0; c2 < counts.cols; c2++) {
				var cellVal = rowValues[c2] || '';
				// Mirror the value in the title so truncated cells reveal on hover.
				html.push('<td><input type="text" name="btbl_custom_data[' + r + '][' + c2 + ']"'
					+ ' title="' + escGridHtml(cellVal) + '" value="' + escGridHtml(cellVal) + '" /></td>');
			}
			html.push(buildRowActionsHtml(r, counts.rows));
			html.push('</tr>');
		}
		html.push('</tbody></table>');
		$customGrid.html(html.join(''));
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
			var dropped = countDroppedCells(values, counts);
			var gridConfirmShrink = dropped === 1 ? gridConfirmShrinkOne : gridConfirmShrinkMany;
			if (dropped > 0 && !window.confirm(gridConfirmShrink.replace('%d', dropped))) {
				return false;
			}
		}
		renderCustomGrid(headers.slice(0, counts.cols), values, counts);
		return true;
	}

	// Re-render from a mutated rows array (used by the row-action buttons).
	function renderRows(rows) {
		var caps = getGridCaps();
		var cols = getRenderedCounts(caps).cols;
		var rowsForCells = Math.max(1, Math.floor(caps.cells / cols));
		var rowCount = Math.max(1, Math.min(rows.length, caps.rows, rowsForCells));
		renderCustomGrid(readCustomGridHeaders().slice(0, cols), rows, { cols: cols, rows: rowCount });
	}

	if ($customGrid.length) {
		// The "Update grid size" button appears only while the column/row counts
		// differ from the grid that's actually rendered, and hides again on revert.
		function syncGridRefreshVisibility() {
			var counts = getCustomCounts();
			var rendered = getRenderedCounts();
			var changed = (counts.cols !== rendered.cols) || (counts.rows !== rendered.rows);
			// The button simply appearing when counts change is signal enough -- no extra
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
		// Keep the hover title in sync as the user types.
		$customGrid.on('input', 'input[name^="btbl_custom_data"]', function() {
			$(this).attr('title', $(this).val());
		});
		// Move the focused row up or down one position.
		function focusMovedRow(newIdx, dir) {
			var primary = dir === 'up' ? '.btbl-row-move-up' : '.btbl-row-move-down';
			var fallback = dir === 'up' ? '.btbl-row-move-down' : '.btbl-row-move-up';
			var $row = $customGrid.find('tbody tr').eq(newIdx);
			var $btn = $row.find(primary);
			if (!$btn.length || $btn.is('[disabled]')) {
				$btn = $row.find(fallback); // hit the boundary -- keep focus on the row
			}
			$btn.trigger('focus');
		}
		$customGrid.on('click', '.btbl-row-move-up, .btbl-row-move-down', function() {
			var idx = $(this).closest('tr').index();
			var rows = readCustomGridValues();
			var direction = $(this).hasClass('btbl-row-move-up') ? -1 : 1;
			var newIdx = idx + direction;
			if (newIdx < 0 || newIdx >= rows.length) { return; }
			var moved = rows.splice(idx, 1)[0];
			rows.splice(newIdx, 0, moved);
			renderRows(rows);
			focusMovedRow(newIdx, direction < 0 ? 'up' : 'down');
		});
		// Row actions.
		$customGrid.on('click', '.btbl-row-delete, .btbl-row-insert, .btbl-row-duplicate', function() {
			var $button = $(this);
			var idx = $(this).closest('tr').index();
			var rows = readCustomGridValues();
			if ($button.hasClass('btbl-row-delete')) {
				if (rows.length <= 1) {
					rows = [[]];
				} else {
					rows.splice(idx, 1);
				}
			} else {
				var inserted = $button.hasClass('btbl-row-duplicate') ? (rows[idx] || []).slice() : [];
				rows.splice(idx + 1, 0, inserted);
			}
			renderRows(rows);
		});
		// Paste tab/newline-delimited data from a spreadsheet.
		$customGrid.on('paste', 'input[name^="btbl_custom_data"]', function(e) {
			var clip = (e.originalEvent || e).clipboardData || window.clipboardData;
			if (!clip) { return; }
			var text = clip.getData('text/plain') || clip.getData('Text') || '';
			var hasTab = text.indexOf('\t') !== -1;
			var hasNewline = /\r|\n/.test(text);
			if (!hasTab && !hasNewline) { return; } // single cell -- default paste
			e.preventDefault();
			var lines = text.replace(/\r\n?/g, '\n').replace(/\n$/, '').split('\n');
			var $cell = $(this);
			var $row = $cell.closest('tr');
			var startRow = $row.index();
			var startCol = $row.find('input[name^="btbl_custom_data"]').index($cell);
			var values = readCustomGridValues();
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
			var caps = getGridCaps();
			var rendered = getRenderedCounts(caps);
			var newCols = Math.min(caps.cols, Math.max(rendered.cols, maxCol + 1));
			var rowsForCells = Math.max(1, Math.floor(caps.cells / newCols));
			var newRows = Math.min(caps.rows, rowsForCells, Math.max(rendered.rows, values.length));
			renderCustomGrid(readCustomGridHeaders().slice(0, newCols), values, { cols: newCols, rows: newRows });
		});
		rebuildCustomGrid(false);
	}
});
