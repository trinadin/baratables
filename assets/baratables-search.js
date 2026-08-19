(function() {
	var utils = window.BaraTablesUtils;
	var featureRegistered = false;

	// The search control registers as a first-class DataTables feature (the library's own
	// extension point), placed in the toolbar like the stock search but driving native
	// column-group searches. Registered lazily so this module never depends on DataTables
	// having evaluated first; the caller registers before building the table configuration.
	function registerFeature() {
		var dt = window.DataTable;
		if (featureRegistered || !dt || !dt.feature || typeof dt.feature.register !== 'function') {
			return featureRegistered;
		}
		dt.feature.register('btblSearch', function(settings, opts) {
			// opts is the controller instance whose layout item selected this feature.
			return opts && typeof opts.buildControl === 'function' ? opts.buildControl(settings) : null;
		});
		featureRegistered = true;
		return true;
	}
	var instanceCounter = 0;

	// Integer column selectors address a column's CURRENT position, which a ColReorder drag
	// changes; the picker works in original data indexes (stable), so transpose on write.
	function transposeIndex(api, index) {
		if (api.colReorder && typeof api.colReorder.transpose === 'function') {
			try {
				return api.colReorder.transpose(index, 'toCurrent');
			} catch (e) {
				return index;
			}
		}
		return index;
	}

	function create(options) {
		var resolvedOptions = options.resolvedOptions || {};
		var presetColumns = Array.isArray(options.presetColumns) ? options.presetColumns : [];
		var presetTerm = typeof options.presetTerm === 'string' ? options.presetTerm : '';
		var enableColumnPicker = resolvedOptions.searchColumns !== false;

		var searchableColumns = [];
		var searchableColumnIndices = [];
		var searchState = { term: presetTerm, columns: [] };
		var api = null;
		var settingsObj = null;
		var inputEl = null;
		var dropdownEl = null;
		var searchColumnsEl = null;
		var toggleEl = null;
		// What this controller last wrote to DataTables: null indexes = the global search,
		// an array = the column group. Tracked so retargeting clears exactly the previous
		// entry, whatever a drag has done to positions since.
		var applied = { indexes: null, term: '' };
		var lastKnownGlobalTerm = '';
		// Set while this controller is (re)writing searches itself: the search.dt listener must
		// not mistake the transiently emptied global term during a retarget for an external
		// writer and adopt it (which would blank the input and the shareable URL).
		var writingInternally = false;
		var debounceTimer = null;
		var documentClickHandler = null;

		function changed() {
			if (typeof options.onChange === 'function') {
				options.onChange(searchState);
			}
		}

		function allColumnIndices() {
			return searchableColumnIndices.slice();
		}

		function clearAppliedSearch() {
			if (!api) {
				return;
			}
			writingInternally = true;
			try {
				if (applied.indexes === null) {
					if (applied.term !== '') {
						api.search('');
					}
				} else if (applied.indexes.length) {
					api.columns(applied.indexes).search('');
				}
			} finally {
				writingInternally = false;
			}
			applied = { indexes: null, term: '' };
		}

		// All matching is DataTables' own: the unscoped term goes to the global search and a
		// column-subset term becomes a native multi-column group search (OR across the subset).
		// There is no plugin and no per-row scanning on our side.
		function writeSearch(term, indexes, skipDraw) {
			if (!api) {
				return;
			}
			clearAppliedSearch();
			var subset = null;
			if (indexes !== null && indexes.length && indexes.length !== searchableColumnIndices.length) {
				subset = indexes.map(function(index) {
					return transposeIndex(api, index);
				});
			}
			if (term !== '') {
				if (subset) {
					api.columns(subset).search(term);
					applied = { indexes: subset, term: term };
				} else {
					api.search(term);
					applied = { indexes: null, term: term };
				}
			}
			if (!skipDraw) {
				api.draw(false);
			}
		}

		function currentTermFromState() {
			return searchState.term || '';
		}

		function applySearchFromInput() {
			var term = inputEl ? inputEl.value : '';
			searchState.term = term;
			writeSearch(term, searchState.columns.length ? searchState.columns : null);
			changed();
		}

		function syncFilledState() {
			var wrapper = inputEl && inputEl.closest('.' + ((settingsObj && settingsObj.classes && settingsObj.classes.search && settingsObj.classes.search.container) || 'dt-search'));
			if (wrapper) {
				wrapper.classList.toggle('btbl-search-filled', !!(inputEl && inputEl.value));
			}
		}

		function buildColumnEnumeration() {
			api.columns().every(function(index) {
				if ((options.nonSearchable || {})[index]) {
					return;
				}
				var headerHtml = (this.header() ? (this.header().innerHTML || this.title() || '') : '').trim();
				var defaultLabel = 'Column ' + (index + 1);
				if (!headerHtml) {
					headerHtml = defaultLabel;
				}
				var headerText = utils.labelToPlainText(headerHtml, defaultLabel) || defaultLabel;
				searchableColumns.push({
					index: index,
					labelHtml: headerHtml,
					labelText: headerText
				});
				searchableColumnIndices.push(index);
			});
		}

		function applyPresetColumns() {
			searchState.columns = allColumnIndices();
			if (!enableColumnPicker || !presetColumns.length) {
				return;
			}
			var searchableIndexSet = {};
			searchableColumns.forEach(function(column) {
				searchableIndexSet[column.index] = true;
			});
			var presetIndices = presetColumns.map(function(slug) {
				return utils.slugIndex(options.slugToIndex, slug);
			}).filter(function(value) {
				return value !== null && value !== undefined && searchableIndexSet[parseInt(value, 10)];
			});
			if (presetIndices.length) {
				searchState.columns = presetIndices;
			}
		}

		function buildControl(settings) {
			api = settings.api || api;
			settingsObj = settings;
			if (!api) {
				return null;
			}
			var searchClasses = (settings.classes && settings.classes.search) || {};
			var containerClass = searchClasses.container || 'dt-search';
			var inputClass = searchClasses.input || 'dt-input';
			var uid = 'btbl-search-' + (++instanceCounter);

			var wrapper = document.createElement('div');
			wrapper.className = containerClass;

			var label = document.createElement('label');
			label.setAttribute('for', uid);
			var usesVisualLabel = options.labelHtml && options.labelHtml !== options.labelPlain;
			if (options.labelHtml && !usesVisualLabel) {
				label.textContent = options.labelPlain;
			} else {
				wrapper.classList.add('btbl-search-label-empty');
			}
			inputEl = document.createElement('input');
			inputEl.type = 'search';
			inputEl.className = inputClass;
			inputEl.id = uid;
			inputEl.autocomplete = 'off';
			inputEl.setAttribute('aria-controls', settings.tableId || '');
			if (options.placeholder) {
				inputEl.placeholder = options.placeholder;
			}
			if (presetTerm) {
				inputEl.value = presetTerm;
			}
			label.appendChild(inputEl);
			wrapper.appendChild(label);

			if (usesVisualLabel) {
				var visual = document.createElement('span');
				visual.className = 'btbl-search-placeholder-visual';
				visual.setAttribute('aria-hidden', 'true');
				visual.innerHTML = options.labelHtml;
				label.insertBefore(visual, inputEl);
				wrapper.classList.add('btbl-has-placeholder-visual');
				syncFilledState();
			}

			inputEl.addEventListener('input', function() {
				syncFilledState();
				if (debounceTimer) {
					window.clearTimeout(debounceTimer);
				}
				debounceTimer = window.setTimeout(applySearchFromInput, 250);
			});
			inputEl.addEventListener('keypress', function(event) {
				if (event.key === 'Enter') {
					event.preventDefault();
				}
			});

			buildColumnEnumeration();
			applyPresetColumns();

			if (enableColumnPicker && searchableColumns.length) {
				buildPicker(wrapper);
			}

			if (searchableColumns.length && presetTerm) {
				// Written during construction so the table's own initial draw is already
				// filtered -- no second draw for the preset.
				writeSearch(presetTerm, searchState.columns.length !== searchableColumnIndices.length ? searchState.columns : null, true);
			}
			return wrapper;
		}

		function buildPicker(wrapper) {
			var dropdownId = 'btbl-search-columns-' + options.tableId;
			var toggleLabelHtml = utils.resolveLabelHtml(resolvedOptions.searchColumnsLabel, 'Columns');
			var toggleLabelText = utils.labelToPlainText(toggleLabelHtml, 'Columns');
			var headingLabelHtml = utils.resolveLabelHtml(resolvedOptions.searchColumnsHeading, 'Search in');

			searchColumnsEl = document.createElement('div');
			searchColumnsEl.className = 'btbl-search-columns';
			toggleEl = document.createElement('button');
			toggleEl.type = 'button';
			toggleEl.className = 'btbl-search-columns-toggle';
			toggleEl.setAttribute('aria-expanded', 'false');
			toggleEl.setAttribute('aria-controls', dropdownId);
			toggleEl.innerHTML = toggleLabelHtml;
			toggleEl.setAttribute('aria-label', toggleLabelText);
			dropdownEl = document.createElement('div');
			dropdownEl.className = 'btbl-search-columns-dropdown';
			dropdownEl.id = dropdownId;
			dropdownEl.setAttribute('role', 'group');
			dropdownEl.setAttribute('aria-labelledby', dropdownId + '-heading');
			var heading = document.createElement('div');
			heading.className = 'btbl-search-columns-heading';
			heading.id = dropdownId + '-heading';
			heading.innerHTML = headingLabelHtml;
			var list = document.createElement('div');
			list.className = 'btbl-search-columns-list';

			searchableColumns.forEach(function(column) {
				var checkboxId = dropdownId + '-' + column.index;
				var item = document.createElement('label');
				item.className = 'btbl-search-columns-option';
				item.setAttribute('for', checkboxId);
				var checkbox = document.createElement('input');
				checkbox.type = 'checkbox';
				checkbox.checked = true;
				checkbox.id = checkboxId;
				checkbox.value = String(column.index);
				checkbox.setAttribute('aria-label', column.labelText);
				if (searchState.columns.indexOf(column.index) === -1) {
					checkbox.checked = false;
				}
				var caption = document.createElement('span');
				caption.innerHTML = column.labelHtml || column.labelText;
				item.appendChild(checkbox);
				item.appendChild(caption);
				list.appendChild(item);
			});

			dropdownEl.appendChild(heading);
			dropdownEl.appendChild(list);
			searchColumnsEl.appendChild(toggleEl);
			searchColumnsEl.appendChild(dropdownEl);
			wrapper.appendChild(searchColumnsEl);

			function openDropdown() {
				dropdownEl.classList.add('is-open');
				toggleEl.setAttribute('aria-expanded', 'true');
			}
			function closeDropdown() {
				dropdownEl.classList.remove('is-open');
				toggleEl.setAttribute('aria-expanded', 'false');
			}

			toggleEl.addEventListener('click', function() {
				if (dropdownEl.classList.contains('is-open')) {
					closeDropdown();
				} else {
					openDropdown();
				}
			});
			dropdownEl.addEventListener('change', function(event) {
				if (event.target && event.target.type === 'checkbox') {
					applyColumnSelection();
				}
			});
			documentClickHandler = function(event) {
				if (searchColumnsEl && !searchColumnsEl.contains(event.target) && event.target !== inputEl) {
					closeDropdown();
				}
			};
			document.addEventListener('click', documentClickHandler);
		}

		function applyColumnSelection() {
			var selected = Array.prototype.slice.call(dropdownEl.querySelectorAll('input[type="checkbox"]:checked')).map(function(checkbox) {
				return parseInt(checkbox.value, 10);
			});
			if (!selected.length) {
				selected = allColumnIndices();
				Array.prototype.forEach.call(dropdownEl.querySelectorAll('input[type="checkbox"]'), function(checkbox) {
					checkbox.checked = true;
				});
			}
			searchState.columns = selected;
			if (inputEl) {
				searchState.term = inputEl.value || '';
			}
			writeSearch(currentTermFromState(), selected.length !== searchableColumnIndices.length ? selected : null);
			changed();
		}

		function attach(tableApi) {
			api = tableApi || api;
			if (!api) {
				return;
			}
			lastKnownGlobalTerm = api.search() || '';
			api.on('search.dt', function(event, settings) {
				if (settings !== settingsObj && settingsObj) {
					settingsObj = settings;
				}
				var term = api.search() || '';
				if (writingInternally) {
					lastKnownGlobalTerm = term;
					return;
				}
				if (term !== lastKnownGlobalTerm) {
					lastKnownGlobalTerm = term;
					if (term !== searchState.term) {
						// An external writer changed the global term; adopt it.
						searchState.term = term;
						if (inputEl && inputEl.value !== term) {
							inputEl.value = term;
							syncFilledState();
						}
						changed();
					}
				}
			});
			api.on('destroy', function() {
				if (debounceTimer) {
					window.clearTimeout(debounceTimer);
				}
				if (documentClickHandler) {
					document.removeEventListener('click', documentClickHandler);
				}
			});
		}

		return {
			// Invoked by the registered DataTables feature while the toolbar is built; the
			// settings argument is what supplies the API instance and the table's own classes.
			buildControl: function(settings) {
				var element = buildControl(settings);
				lastKnownGlobalTerm = '';
				return element;
			},
			inputEl: function() {
				return inputEl;
			},
			getState: function() {
				return searchState;
			},
			getColumns: function() {
				return searchableColumns;
			},
			needsInitialDraw: function() {
				// Presets are applied during construction; nothing extra to draw here.
				return false;
			},
			attach: attach,
			reset: function() {
				if (!inputEl) {
					return;
				}
				inputEl.value = '';
				syncFilledState();
				searchState.term = '';
				searchState.columns = allColumnIndices();
				if (dropdownEl) {
					Array.prototype.forEach.call(dropdownEl.querySelectorAll('input[type="checkbox"]'), function(checkbox) {
						checkbox.checked = true;
					});
				}
				writeSearch('', null);
			}
		};
	}

	window.BaraTablesSearch = { create: create, registerFeature: registerFeature };
})();
