<?php

if (!defined('ABSPATH')) {
	exit;
}

class BaraTables_Service {
	// Concern clusters split into their own files to keep this class from being a single 4,000-line
	// god-object. Traits run in this class's scope, so every $this->, self:: constant and private
	// property below is shared with them exactly as if the methods were still declared inline.
	use BaraTables_Filter_Options_Trait;
	use BaraTables_Value_Format_Trait;
	use BaraTables_Column_State_Trait;

	/** Compact declarations expanded by build_table_option_schema(). Tuple shapes:
	 *  checkbox [type, default], text [type, default], number [type, default, min, max],
	 *  checkbox_multi [type, default, choices]. The order is the editor's canonical option order.
	 */
	private const TABLE_OPTION_DEFINITIONS = [
		'paging'                  => ['checkbox', true],
		'pagingNumbers'           => ['checkbox', true],
		'pagingFirstLast'         => ['checkbox', true],
		'pagingPreviousNext'      => ['checkbox', true],
		'lengthChange'            => ['checkbox', true],
		'searchBox'               => ['checkbox', true],
		'searchColumns'           => ['checkbox', true],
		'info'                    => ['checkbox', true],
		'infoText'                => ['text_html', ''],
		'infoEmpty'               => ['text_html', ''],
		'infoFiltered'            => ['text_html', ''],
		'layoutTopStart'          => ['checkbox_multi', ['pagelength', 'buttons'], []],
		'layoutTopEnd'            => ['checkbox_multi', ['search'], []],
		'layoutBottomStart'       => ['checkbox_multi', ['info'], []],
		'layoutBottomEnd'         => ['checkbox_multi', ['paging'], []],
		'filtersTitle'            => ['checkbox', false],
		'filtersTitleText'        => ['text_html', ''],
		'ordering'                => ['checkbox', true],
		'colReorder'              => ['checkbox', false],
		'stateSave'               => ['checkbox', false],
		'autoWidth'               => ['checkbox', true],
		'scrollX'                 => ['checkbox', false],
		'scrollYEnabled'          => ['checkbox', false],
		'scrollY'                 => ['number', 300, 1, 2000],
		'scrollCollapse'          => ['checkbox', true],
		'stripe'                  => ['checkbox', true],
		'rowBorder'               => ['checkbox', true],
		'cellBorder'              => ['checkbox', false],
		'hover'                   => ['checkbox', true],
		'orderColumn'             => ['checkbox', true],
		'compact'                 => ['checkbox', false],
		'pageLength'              => ['number', 25, 1, 500],
		'rowLimit'                => ['number', 1000, 1, 10000],
		'lengthMenuPrefix'        => ['text_html', ''],
		'lengthMenuSuffix'        => ['text_html', ''],
		'paginateFirst'           => ['text_html', ''],
		'paginatePrevious'        => ['text_html', ''],
		'paginateNext'            => ['text_html', ''],
		'paginateLast'            => ['text_html', ''],
		'searchText'              => ['text_html', ''],
		'searchPlaceholder'       => ['text_html', ''],
		'searchColumnsLabel'      => ['text_html', ''],
		'searchColumnsHeading'    => ['text_html', ''],
		'buttons'                 => ['checkbox_multi', [], ['copy' => null, 'csv' => null, 'excel' => null, 'print' => null, 'colvis' => null, 'pagelength' => null]],
		'buttonTextCopy'          => ['text_html', ''],
		'buttonTextCsv'           => ['text_html', ''],
		'buttonTextExcel'         => ['text_html', ''],
		'buttonTextPrint'         => ['text_html', ''],
		'buttonTextColvis'        => ['text_html', ''],
		'buttonTextPagelength'    => ['text_html', ''],
	];
	private const ALLOWED_INLINE_HTML = [
		'span' => [
			'class' => [],
			'style' => [],
			'aria-hidden' => [],
			'aria-label' => [],
			'title' => [],
			'role' => [],
			'data-icon' => [],
		],
		'i' => [
			'class' => [],
			'style' => [],
			'aria-hidden' => [],
			'aria-label' => [],
			'title' => [],
			'role' => [],
		],
		'b' => [
			'class' => [],
			'aria-hidden' => [],
		],
		'strong' => [
			'class' => [],
			'aria-hidden' => [],
		],
		'em' => [
			'class' => [],
			'aria-hidden' => [],
		],
		'small' => [
			'class' => [],
		],
		'sup' => [
			'class' => [],
		],
		'sub' => [
			'class' => [],
		],
		'svg' => [
			'class' => [],
			'width' => [],
			'height' => [],
			'viewBox' => [],
			'fill' => [],
			'stroke' => [],
			'stroke-width' => [],
			'aria-hidden' => [],
			'aria-label' => [],
			'focusable' => [],
			'role' => [],
			'xmlns' => [],
		],
		'path' => [
			'd' => [],
			'fill' => [],
			'stroke' => [],
			'stroke-width' => [],
			'class' => [],
			'transform' => [],
		],
	];
	/**
	 * DataTables style classes for a table's options.
	 *
	 * One implementation for both the front end and the editor preview: they used to apply the
	 * same map with different predicates (`!empty()` vs `($opt ?? true) !== false`), which agree
	 * only while options arrive fully merged. If a key ever went missing the preview would show a
	 * style the front end did not.
	 */
	public static function table_style_classes(array $options): array {
		$classes = [];
		foreach (self::TABLE_STYLE_CLASS_MAP as $option_key => $class_name) {
			if (!empty($options[$option_key])) {
				$classes[] = $class_name;
			}
		}
		return $classes;
	}

	public const TABLE_STYLE_CLASS_MAP = [
		'stripe' => 'stripe',
		'rowBorder' => 'row-border',
		'cellBorder' => 'cell-border',
		'hover' => 'hover',
		'orderColumn' => 'order-column',
		'compact' => 'compact',
	];
	private const CHART_OPTION_DEFAULTS = [
		'type' => 'bar',
		'x_axis' => '',
		'series' => [],
		'stack' => false,
		'height' => 360,
		'position' => 'above',
		'gantt_label' => '',
		'gantt_start' => '',
		'gantt_end' => '',
		'gantt_group' => '',
		'gantt_progress' => '',
		'heatmap_x' => '',
		'heatmap_y' => '',
		'heatmap_value' => '',
	];
	private const MAX_CSV_BYTES = 5242880;
	private const MAX_CSV_LINE_LENGTH = 1048576;
	private const CSV_MIME_TYPES = [
		'text/csv',
		'text/plain',
		'application/csv',
		'application/vnd.ms-excel',
		'text/comma-separated-values',
	];
	public const MAX_CUSTOM_COLUMNS = 100;
	public const MAX_CUSTOM_ROWS = 1000;
	public const DEFAULT_ROW_LIMIT = 1000;
	public const MAX_ROW_LIMIT = 10000;
	// Cell budget matches the pre-1.2.1 maximum (50 cols x 500 rows) so no manual grid that was
	// legal in an earlier version is ever silently truncated when its editor is opened or saved;
	// the per-dimension caps above remain the real guard against pathologically large grids.
	public const MAX_CUSTOM_CELLS = 25000;
	// Nesting depth stringify_cell_value() will flatten before giving up. ACF Repeater/Group
	// fields nest arbitrarily; two levels covers Relationship and a flat Repeater row without
	// letting a pathological structure recurse.
	private const MAX_VALUE_FLATTEN_DEPTH = 2;
	// Ceiling on terms fetched per taxonomy for the editor's term picker. Already-selected terms
	// are always merged in on top of this, so the cap can never drop an existing selection.
	public const MAX_TERM_PICKER_TERMS = 200;
	public const LABEL_I18N_MIGRATION_OPTION = 'btbl_label_i18n_migrated';
	/**
	 * The English label defaults that the table-option schema carried before 1.2.3, and that the
	 * editor therefore baked into its input fields as a VALUE and persisted on every save. Any
	 * table saved under 1.0.0-1.2.2 has these literals stored, which is why those six controls
	 * rendered in English on localized sites no matter what the translation said.
	 *
	 * These are deliberately hardcoded English, never __(). The stored value is the untranslated
	 * literal, so the comparison must be too -- running these through __() on a German site would
	 * compare "Suchen:" against a stored "Search:" and match nothing.
	 *
	 * Every published tag (1.0.0 through 1.2.2) shipped exactly this set with exactly these
	 * strings -- verified against baratables-svn/tags/* -- so there is no second generation of
	 * literals to recognise. The other nine frontend_label_defaults() keys always defaulted to
	 * '' in the schema, were never baked in, and so need no migration.
	 */
	private const LEGACY_ENGLISH_LABEL_DEFAULTS = [
		'searchText'           => 'Search:',
		'lengthMenuPrefix'     => 'Show',
		'lengthMenuSuffix'     => 'entries',
		'filtersTitleText'     => 'Filters',
		'searchColumnsLabel'   => 'Columns',
		'searchColumnsHeading' => 'Search in',
	];
	/** @var array<string,BaraTables_Row_Result> Per-request atomic row results. */
	private array $row_cache = [];
	private BaraTables_Repository $repo;
	private BaraTables_Query_Sanitizer $query_sanitizer;
	private BaraTables_Fields_Discovery $fields_discovery;

	public function __construct(BaraTables_Repository $repo) {
		$this->repo = $repo;
		$this->query_sanitizer = new BaraTables_Query_Sanitizer();
		$this->fields_discovery = new BaraTables_Fields_Discovery($this->query_sanitizer);
	}

	public function sanitize_custom_query_json(string $raw_json): array {
		return $this->query_sanitizer->sanitize_custom_query_json($raw_json);
	}

	public function sanitize_value_overrides(string $raw_json): array {
		return $this->query_sanitizer->sanitize_value_overrides($raw_json);
	}

	public function get_supported_post_types(): array {
		return $this->fields_discovery->get_supported_post_types();
	}

	public function get_taxonomies_for_post_type(string $post_type, array $include_term_ids = []): array {
		return $this->fields_discovery->get_taxonomies_for_post_type($post_type, $include_term_ids);
	}

	public function get_taxonomies_for_post_types(array $post_types, array $include_term_ids = []): array {
		return $this->fields_discovery->get_taxonomies_for_post_types($post_types, $include_term_ids);
	}

	public function get_available_fields(string $post_type): array {
		return $this->fields_discovery->get_available_fields($post_type);
	}

	public function get_available_fields_for_post_types(array $post_types): array {
		return $this->fields_discovery->get_available_fields_for_post_types($post_types);
	}

	public static function allowed_inline_html(): array {
		return self::ALLOWED_INLINE_HTML;
	}

	private function sanitize_inline_html($value): string {
		if (!is_scalar($value)) {
			return '';
		}
		$value = (string) $value;
		if ($value === '') {
			return '';
		}
		$clean = wp_kses($value, self::ALLOWED_INLINE_HTML);
		return trim($clean);
	}

	private function sanitize_inline_label_map(array $labels_raw): array {
		$out = [];
		foreach ($labels_raw as $key => $label) {
			$clean_key = sanitize_text_field($key);
			$clean_label = $this->sanitize_inline_html($label);
			if ($clean_key === '' || $clean_label === '') {
				continue;
			}
			$out[$clean_key] = $clean_label;
		}
		return $out;
	}

	private function sanitize_bool_flags(array $raw, ?array $slugs = null, bool $default = false): array {
		$out = [];
		if ($slugs === null) {
			foreach ($raw as $slug => $value) {
				$clean_slug = sanitize_text_field($slug);
				if ($clean_slug === '') {
					continue;
				}
				$out[$clean_slug] = !empty($value);
			}
			return $out;
		}

		foreach ($slugs as $slug) {
			$clean_slug = sanitize_text_field($slug);
			if ($clean_slug === '') {
				continue;
			}
			$out[$clean_slug] = array_key_exists($clean_slug, $raw) ? (bool) $raw[$clean_slug] : $default;
		}
		return $out;
	}

	public function sanitize_filter_types(array $filters_raw, array $dropdown_multi_raw, array $dropdown_search_raw): array {
		$allowed = ['dropdown', 'checkbox', 'radio'];
		$out = [];
		foreach ($filters_raw as $key => $type) {
			$clean_key = sanitize_text_field($key);
			$clean_type = sanitize_key($type);
			if (in_array($clean_type, $allowed, true)) {
				if ($clean_type === 'dropdown') {
					$multi  = !empty($dropdown_multi_raw[$clean_key]);
					$search = !empty($dropdown_search_raw[$clean_key]);
					if ($search && $multi) {
						$out[$clean_key] = 'dropdown_multi';
					} elseif ($search) {
						$out[$clean_key] = 'dropdown';
					} elseif ($multi) {
						$out[$clean_key] = 'dropdown_plain_multi';
					} else {
						$out[$clean_key] = 'dropdown_plain';
					}
				} else {
					$out[$clean_key] = $clean_type;
				}
			}
		}
		return $out;
	}

	public function sanitize_filter_sorts(array $filter_sorts_raw): array {
		$allowed = ['asc', 'desc', 'custom'];
		$out = [];
		foreach ($filter_sorts_raw as $key => $sort) {
			$clean_key = sanitize_text_field($key);
			$clean_sort = sanitize_key($sort);
			if ($clean_sort === 'none') {
				$clean_sort = 'custom';
			}
			$out[$clean_key] = in_array($clean_sort, $allowed, true) ? $clean_sort : 'asc';
		}
		return $out;
	}

	public function sanitize_filter_type_priority(array $priority_raw): array {
		$out = [];
		foreach ($priority_raw as $key => $raw_value) {
			$clean_key = sanitize_text_field($key);
			if ($clean_key === '') {
				continue;
			}
			$priority = $this->parse_data_type_priority($raw_value);
			if (!empty($priority)) {
				$out[$clean_key] = $priority;
			}
		}
		return $out;
	}

	public function sanitize_custom_labels(array $labels_raw): array {
		return $this->sanitize_inline_label_map($labels_raw);
	}

	public function sanitize_filter_labels(array $labels_raw): array {
		$out = [];
		foreach ($labels_raw as $key => $label) {
			$clean_key = sanitize_text_field($key);
			if ($clean_key === '') {
				continue;
			}
			$out[$clean_key] = $this->sanitize_inline_html($label);
		}
		return $out;
	}

	public function sanitize_filter_values(array $filter_values_raw): array {
		$out = [];
		foreach ($filter_values_raw as $slug => $raw_values) {
			$clean_slug = sanitize_text_field($slug);
			if ($clean_slug === '') {
				continue;
			}
			$raw_string = is_array($raw_values) ? implode("\n", array_map('strval', $raw_values)) : (string) $raw_values;
			$lines = preg_split('/[\r\n]+/', $raw_string);
			$lines = $lines === false ? [] : $lines;
			$values = [];
			foreach ($lines as $line) {
				$line = trim($line);
				if ($line === '') {
					continue;
				}
				$label = $line;
				$search_source = $line;

				if (strpos($line, '=>') !== false) {
					[$label_part, $search_part] = explode('=>', $line, 2);
					$label = trim($label_part);
					$search_source = $search_part;
				} elseif (strpos($line, '|') !== false) {
					[$label_part, $search_part] = explode('|', $line, 2);
					$label = trim($label_part);
					$search_source = $search_part;
				}

				$search_chunks = array_map('trim', explode(',', $search_source));
				$search_terms = [];
				foreach ($search_chunks as $chunk) {
					if ($chunk === '') {
						$search_terms[] = '';
						continue;
					}
					$search_terms[] = sanitize_text_field($chunk);
				}
				$label = $label !== '' ? $label : (string) $search_terms[0];
				if ($label === '') {
					continue;
				}
				$first_term = (string) $search_terms[0];
				$value = $first_term !== '' ? $first_term : $label;
				$values[] = [
					'label' => $label,
					'value' => $value,
					'search_terms' => $search_terms,
				];
			}
			if (!empty($values)) {
				$out[$clean_slug] = $values;
			}
		}
		return $out;
	}

	public function sanitize_table_options($options_raw): array {
		$options_raw = is_array($options_raw) ? $options_raw : [];
		return $this->merge_table_options($options_raw);
	}

	public function sanitize_chart_options($options_raw, array $columns): array {
		$options_raw = is_array($options_raw) ? $options_raw : [];
		return $this->merge_chart_options($options_raw, $columns);
	}

	public function get_table_options(array $definition): array {
		$saved = isset($definition['table_options']) && is_array($definition['table_options'])
			? $definition['table_options']
			: [];
		return $this->merge_table_options($saved);
	}

	public function get_chart_options(array $definition): array {
		$saved = isset($definition['chart']) && is_array($definition['chart'])
			? $definition['chart']
			: [];
		return $this->merge_chart_options($saved, $definition['columns'] ?? []);
	}

	/**
	 * Translated defaults for every visitor-facing control label. Single source of truth: the
	 * front end substitutes these when the stored value is blank, and the editor shows the same
	 * string as the field's placeholder, so what an admin sees is what a visitor gets.
	 *
	 * These MUST stay blank in TABLE_OPTION_DEFINITIONS. A non-empty English default there is baked
	 * into the editor field as a value and persisted on save, which is why "Search:", "Show",
	 * "entries" and "Filters" used to render untranslated on non-English sites no matter what.
	 */
	public static function frontend_label_defaults(): array {
		return [
			'searchText'           => __('Search:', 'baratables'),
			'lengthMenuPrefix'     => __('Show', 'baratables'),
			'lengthMenuSuffix'     => __('entries', 'baratables'),
			'filtersTitleText'     => __('Filters', 'baratables'),
			// The result summary. These were the last visitor-facing labels still absent here, so
			// they shipped blank and DataTables rendered its OWN English -- while the editor
			// placeholder and the admin preview both showed the plugin's translated string. Same
			// screen, two answers, and English on every localized site.
			'infoText'             => __('Showing _START_ to _END_ of _TOTAL_ entries', 'baratables'),
			'infoEmpty'            => __('Showing 0 to 0 of 0 entries', 'baratables'),
			'infoFiltered'         => __('(filtered from _MAX_ total entries)', 'baratables'),
			'searchColumnsLabel'   => __('Columns', 'baratables'),
			'searchColumnsHeading' => __('Search in', 'baratables'),
			'buttonTextCopy'       => __('Copy', 'baratables'),
			'buttonTextCsv'        => __('Export CSV', 'baratables'),
			'buttonTextExcel'      => __('Export Excel', 'baratables'),
			'buttonTextPrint'      => __('Print', 'baratables'),
			'buttonTextColvis'     => __('Column visibility', 'baratables'),
			'buttonTextPagelength' => __('Page length', 'baratables'),
		];
	}

	/**
	 * Default pagination glyphs, keyed by their table_options key.
	 *
	 * Deliberately NOT part of frontend_label_defaults(): those get substituted into the front-end
	 * payload when blank, but a blank paginate* option must STAY blank so DataTables renders its
	 * own built-in arrows. These exist only so the two places that have to show the admin what a
	 * blank field will produce -- the editor's placeholder and the admin preview's buttons -- agree
	 * with each other. They were duplicated literals in both spots; a maintainer changing one and
	 * not the other made the editor promise one arrow while the preview drew another, which is the
	 * same "one screen, two answers" defect the label defaults were consolidated to prevent.
	 *
	 * Not translated: these are typographic glyphs, identical in every locale.
	 */
	public static function paginate_glyph_defaults(): array {
		return [
			'paginateFirst'    => '«',
			'paginatePrevious' => '‹',
			'paginateNext'     => '›',
			'paginateLast'     => '»',
		];
	}

	public function localize_frontend_table_labels(array $options): array {
		foreach (self::frontend_label_defaults() as $key => $default) {
			if (!isset($options[$key]) || $options[$key] === '') {
				$options[$key] = $default;
			}
		}
		return $options;
	}

	/**
	 * One-time upgrade: clear stored control labels that are byte-identical to the English
	 * defaults the pre-1.2.3 editor baked into its own fields, so localize_frontend_table_labels()
	 * can substitute the translated string instead.
	 *
	 * Blanking the schema defaults in 1.2.3 only fixes tables saved AFTER the upgrade; every table
	 * that already exists still holds the English literal, and a stored value always wins over the
	 * translation. Without this pass the i18n fix reaches no existing table on any site.
	 *
	 * On an English site this is output-identical: the value removed and the value substituted in
	 * its place are the same string. A translated site gets the translation, which is the point.
	 * The one behaviour change worth naming: an admin who deliberately typed the English word back
	 * in is indistinguishable from the baked default, so their table starts following the locale
	 * too -- correct for the overwhelming majority, and re-typing it is not how you pin a label
	 * anyway (a value that differs by even one character is left untouched).
	 *
	 * Idempotent and gated by an option; safe to call on every admin load.
	 */
	public function migrate_legacy_english_labels(): void {
		if (get_option(self::LABEL_I18N_MIGRATION_OPTION)) {
			return;
		}
		// Include 'trash' (which get_posts excludes under 'any') for the same reason
		// rewrite_chart_table_id() does: a trashed table that is later restored must come back
		// migrated, not carrying literals this pass will never run again to clear.
		$ids = get_posts([
			'post_type' => BaraTables_Repository::CPT,
			'post_status' => ['publish', 'draft', 'pending', 'future', 'private', 'trash'],
			'numberposts' => -1, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- One-time migration must visit every table once.
			'fields' => 'ids',
			'no_found_rows' => true,
		]);
		if (!empty($ids)) {
			_prime_post_caches($ids, false, true);
		}
		foreach ($ids as $id) {
			$defn = get_post_meta((int) $id, BaraTables_Repository::META_KEY, true);
			if (!is_array($defn) || empty($defn['table_options']) || !is_array($defn['table_options'])) {
				continue;
			}
			$changed = false;
			foreach (self::LEGACY_ENGLISH_LABEL_DEFAULTS as $key => $legacy_english) {
				if (!array_key_exists($key, $defn['table_options'])) {
					continue;
				}
				// Compare the raw stored scalar. No trim() and no case folding: a label the admin
				// altered even by a space is theirs, and clearing it would silently discard their
				// wording rather than fix a translation.
				$stored = $defn['table_options'][$key];
				if (!is_string($stored) || $stored !== $legacy_english) {
					continue;
				}
				$defn['table_options'][$key] = '';
				$changed = true;
			}
			if ($changed) {
				update_post_meta((int) $id, BaraTables_Repository::META_KEY, $defn);
			}
		}
		update_option(self::LABEL_I18N_MIGRATION_OPTION, 1, false);
	}

	public function get_default_table_options(): array {
		return $this->get_table_option_defaults();
	}

	public function get_default_chart_options(): array {
		return self::CHART_OPTION_DEFAULTS;
	}

	private function merge_table_options(array $options_raw): array {
		$schema = self::get_table_option_schema();
		$options = $this->get_table_option_defaults();

		foreach ($schema as $key => $config) {
			if (!array_key_exists($key, $options_raw)) {
				continue;
			}
			$type = $config['type'] ?? '';
			if ($type === 'checkbox') {
				$options[$key] = !empty($options_raw[$key]);
			} elseif ($type === 'number') {
				$min = isset($config['min']) ? (int) $config['min'] : 0;
				$max = isset($config['max']) ? (int) $config['max'] : PHP_INT_MAX;
				$value = (int) $options_raw[$key];
				if ($value <= 0 && $min > 0) {
					$options[$key] = $config['default'];
				} else {
					$options[$key] = min(max($value, $min), $max);
				}
			} elseif ($type === 'text_html') {
				$options[$key] = $this->sanitize_inline_html($options_raw[$key]);
			} elseif ($type === 'checkbox_multi') {
				$choices = isset($config['choices']) && is_array($config['choices']) ? array_keys($config['choices']) : [];
				$options[$key] = $this->sanitize_checkbox_multi($options_raw[$key], $choices);
			}
		}

		// Tables saved before 1.2.3 stored the vertical-scroll on/off state in the height itself
		// (0 = off); when the flag is absent, derive it so those tables keep their behavior.
		if (!array_key_exists('scrollYEnabled', $options_raw)) {
			$options['scrollYEnabled'] = ((int) ($options_raw['scrollY'] ?? 0)) > 0;
		}

		return $options;
	}

	private function sanitize_checkbox_multi($raw, array $allowed): array {
		if (!is_array($raw)) {
			$raw = [$raw];
		}
		$clean = [];
		foreach ($raw as $val) {
			$slug = sanitize_key((string) $val);
			if ($slug !== '' && in_array($slug, $allowed, true) && !in_array($slug, $clean, true)) {
				$clean[] = $slug;
			}
		}
		return $clean;
	}

	private function merge_chart_options(array $options_raw, array $columns): array {
		$options = self::CHART_OPTION_DEFAULTS;
		$slug_map = [];
		foreach ($columns as $col) {
			if (is_array($col)) {
				$slug = $this->resolve_column_slug($col);
			} else {
				$slug = sanitize_text_field((string) $col);
			}
			if ($slug === '') {
				continue;
			}
			$slug_map[$slug] = true;
		}

		if (!empty($options_raw['type']) && in_array($options_raw['type'], BaraTables_Chart_Types::slugs(), true)) {
			$options['type'] = $options_raw['type'];
		}
		if (!empty($options_raw['stack'])) {
			$options['stack'] = true;
		}
		if (!empty($options_raw['position']) && in_array($options_raw['position'], ['above', 'below'], true)) {
			$options['position'] = $options_raw['position'];
		}
		if (isset($options_raw['height'])) {
			$height = (int) $options_raw['height'];
			if ($height >= 120) {
				$options['height'] = min($height, 2000);
			}
		}

		$x_axis = isset($options_raw['x_axis']) ? sanitize_text_field((string) $options_raw['x_axis']) : '';
		if ($x_axis !== '' && isset($slug_map[$x_axis])) {
			$options['x_axis'] = $x_axis;
		}

		$series_raw = isset($options_raw['series']) ? (array) $options_raw['series'] : [];
		$series = [];
		foreach ($series_raw as $slug) {
			$clean = sanitize_text_field((string) $slug);
			if ($clean !== '' && isset($slug_map[$clean]) && $clean !== $options['x_axis'] && !in_array($clean, $series, true)) {
				$series[] = $clean;
			}
		}
		$options['series'] = $series;

		$type_capabilities = BaraTables_Chart_Types::get($options['type']);
		// Single-series charts: if the user picked an X-axis but no series,
		// auto-pick the first other column so the chart renders instead of showing "not configured".
		if ($type_capabilities['single_series'] && !empty($options['x_axis']) && empty($options['series']) && !empty($slug_map)) {
			foreach (array_keys($slug_map) as $slug) {
				if ($slug !== $options['x_axis']) {
					$options['series'] = [$slug];
					break;
				}
			}
		}

		if (!$type_capabilities['stackable']) {
			$options['stack'] = false;
		}

		$special_role_keys = array_values(array_diff(BaraTables_Chart_Types::column_role_keys(), ['x_axis', 'series']));
		foreach ($special_role_keys as $key) {
			if (!empty($options_raw[$key])) {
				$slug = sanitize_text_field((string) $options_raw[$key]);
				if ($slug !== '' && isset($slug_map[$slug])) {
					$options[$key] = $slug;
				}
			}
		}

		return $options;
	}


	public function sanitize_taxonomy_filter(array $post_types, $taxonomy_raw, array $terms_raw): array {
		$post_types = $this->sanitize_post_types($post_types, 'wp_query');
		$taxonomies_raw = is_array($taxonomy_raw) ? $taxonomy_raw : [$taxonomy_raw];
		$out = [];
		foreach ($taxonomies_raw as $tax_raw) {
			$taxonomy = sanitize_key($tax_raw);
			if ($taxonomy === '') {
				continue;
			}
			$valid_pt = false;
			foreach ($post_types as $pt) {
				if (taxonomy_exists($taxonomy) && is_object_in_taxonomy($pt, $taxonomy)) {
					$valid_pt = true;
					break;
				}
			}
			if (!$valid_pt) {
				continue;
			}
			$taxonomy_terms_raw = isset($terms_raw[$taxonomy]) ? (array) $terms_raw[$taxonomy] : [];
			$term_ids = array_values(array_unique(array_filter(array_map('intval', $taxonomy_terms_raw))));
			if (empty($term_ids)) {
				continue;
			}

			$out[] = [
				'taxonomy' => $taxonomy,
				'terms'    => $term_ids,
				'field'    => 'term_id',
				'operator' => 'IN',
			];
		}

		return $out;
	}

	public function sanitize_post_types(array $post_types_raw, string $source_type): array {
		if (!BaraTables_Source_Type::supports_post_type_selection($source_type)) {
			return ['post'];
		}
		return $this->query_sanitizer->sanitize_public_post_types($post_types_raw, true);
	}

	public function prepare_columns_from_request(array $columns_raw, string $custom_meta_raw, string $column_order_raw = ''): array {
		$columns = $columns_raw;

		$custom_meta = array_filter(array_map('trim', explode(',', $custom_meta_raw)));
		foreach ($custom_meta as $meta_key) {
			// Meta keys are case-sensitive free-form strings (e.g. "Price_USD", "product.price").
			// sanitize_key() would lowercase and strip them into a key that matches no stored meta,
			// producing a permanently empty column. Only strip control chars/tags; the source:key
			// slug is re-split colon-safe in normalize_column().
			$meta_key = sanitize_text_field($meta_key);
			if ($meta_key === '') {
				continue;
			}
			$columns[] = 'meta:' . $meta_key;
		}

		$columns = array_unique(array_filter($columns));

		$order_list = $this->sanitize_order_list($column_order_raw);
		$order_map = [];
		foreach ($order_list as $idx => $slug) {
			$order_map[$slug] = $idx;
		}

		if (!empty($order_map)) {
			usort($columns, static function ($a, $b) use ($order_map) {
				$posA = array_key_exists($a, $order_map) ? $order_map[$a] : PHP_INT_MAX;
				$posB = array_key_exists($b, $order_map) ? $order_map[$b] : PHP_INT_MAX;
				return $posA <=> $posB;
			});
		}

		return $columns;
	}

	public function sanitize_order_list(string $raw): array {
		$order_list = array_filter(array_map('trim', explode(',', $raw)));
		$out = [];
		foreach ($order_list as $slug) {
			$clean_slug = sanitize_text_field($slug);
			if ($clean_slug !== '' && !in_array($clean_slug, $out, true)) {
				$out[] = $clean_slug;
			}
		}
		return $out;
	}

	/**
	 * Compatibility adapter for the pre-record column API.
	 *
	 * @deprecated Build records with build_column_records_from_request(), then call
	 *             build_columns_from_records().
	 */
	public function build_columns(array $columns, array $filter_types, array $filter_sorts = [], array $filter_type_priority = [], array $custom_labels = [], array $filter_labels = [], array $hide_titles = [], array $hidden_columns = [], array $searchable = [], array $sort_priority = [], array $sort_direction = [], array $sort_enabled = [], array $sortable = [], array $filter_values = [], array $format_date_flags = [], array $date_formats = []): array {
		$records = $this->build_column_records_from_maps($columns, compact(
			'filter_types',
			'filter_sorts',
			'filter_type_priority',
			'custom_labels',
			'filter_labels',
			'hide_titles',
			'hidden_columns',
			'searchable',
			'sort_priority',
			'sort_direction',
			'sort_enabled',
			'sortable',
			'filter_values',
			'format_date_flags',
			'date_formats'
		));
		return $this->build_columns_from_records($columns, $records);
	}

	/**
	 * Build stored column definitions from canonical per-column records.
	 */
	public function build_columns_from_records(array $columns, array $records): array {
		$out = [];
		foreach ($columns as $raw) {
			$record = isset($records[$raw]) && is_array($records[$raw]) ? $records[$raw] : [];
			$record['slug'] = (string) $raw;
			$out[] = $this->normalize_column_record($record);
		}
		return $out;
	}

	/**
	 * Canonical column-definition factory. Callers provide one record instead of maintaining a
	 * positional argument list whose order previously had to match at every call site.
	 */
	public function normalize_column_record(array $record): array {
		return $this->normalize_column_record_values($record);
	}

	/** @deprecated Pass a single record to normalize_column_record(). */
	public function normalize_column(string $raw, string $filter_type = 'none', string $filter_sort = 'asc', string $custom_label = '', ?string $filter_label = null, bool $hide_title = false, bool $hidden = false, bool $searchable = true, int $sort_priority = 0, string $sort_direction = 'asc', bool $sort_enabled = false, bool $sortable = true, array $filter_values = [], array $filter_type_priority = [], bool $format_date = false, string $date_format = ''): array {
		return $this->normalize_column_record([
			'slug' => $raw,
			'filter' => $filter_type,
			'filter_sort' => $filter_sort,
			'custom_label' => $custom_label,
			'filter_label' => $filter_label,
			'hide_title' => $hide_title,
			'hidden' => $hidden,
			'searchable' => $searchable,
			'sort_priority' => $sort_priority,
			'sort_direction' => $sort_direction,
			'sort_enabled' => $sort_enabled,
			'sortable' => $sortable,
			'filter_values' => $filter_values,
			'filter_type_priority' => $filter_type_priority,
			'format_date' => $format_date,
			'date_format' => $date_format,
		]);
	}

	private function normalize_column_record_values(array $record): array {
		$raw = (string) ($record['slug'] ?? '');
		$parts = explode(':', $raw);
		$source_raw = count($parts) > 1 ? array_shift($parts) : 'core';
		$source = sanitize_key($source_raw);
		if ($source === '') {
			$source = 'core';
		}
		$allowed_sources = ['core', 'meta', 'csv', 'tax', 'external', 'custom'];
		if (!in_array($source, $allowed_sources, true)) {
			$source = 'core';
		}
		$key    = implode(':', $parts);

		// Manual (custom) columns default to a positional "Column N" -- matching the picker
		// and the grid -- rather than the key-derived name used by other sources.
		$default_label = ucwords(str_replace(['_', '-'], ' ', $key));
		if ($source === 'custom' && preg_match('/^col_(\d+)$/', $key, $auto_match)) {
			$default_label = sprintf('Column %d', (int) $auto_match[1]);
		}

		// Auto-label is decided purely by whether the user supplied a heading: the gear
		// field submits an empty string when left at its placeholder default. No
		// string-matching of the label text -- the flag is the single source of truth that
		// display_column_label reads at render.
		$custom_label = (string) ($record['custom_label'] ?? '');
		$auto_label = array_key_exists('auto_label', $record)
			? (bool) $record['auto_label']
			: ($source === 'custom' && $custom_label === '');

		$has_explicit_label = array_key_exists('label', $record);
		$label_raw = $has_explicit_label ? (string) $record['label'] : ($custom_label !== '' ? $custom_label : $default_label);
		$label = $this->sanitize_inline_html($label_raw);
		if ($label === '' && !$has_explicit_label) {
			$label = $default_label;
			$auto_label = ($source === 'custom');
		}
		$filter_label = array_key_exists('filter_label', $record) ? $record['filter_label'] : null;
		$filter_label_raw = $filter_label === null ? $label : (string) $filter_label;
		$filter_label_clean = $this->sanitize_inline_html($filter_label_raw);
		$filter_label_value = $filter_label === null ? ($filter_label_clean !== '' ? $filter_label_clean : $label) : $filter_label_clean;

		$filter_sort = (string) ($record['filter_sort'] ?? 'asc');
		$filter_sort = $filter_sort === 'none' ? 'custom' : $filter_sort;
		$filter_sort = in_array($filter_sort, ['asc', 'desc', 'custom'], true) ? $filter_sort : 'asc';
		$filter_type = (string) ($record['filter'] ?? 'none');
		$sort_priority = (int) ($record['sort_priority'] ?? 0);
		$sort_direction = (string) ($record['sort_direction'] ?? 'asc');

		return [
			'key'    => $key,
			'label'  => $label,
			'auto_label' => $auto_label,
			'filter_label' => $filter_label_value,
			'source' => $source,
			'filter' => in_array($filter_type, ['dropdown', 'dropdown_multi', 'dropdown_plain', 'dropdown_plain_multi', 'checkbox', 'radio'], true) ? $filter_type : 'none',
			'filter_sort' => $filter_sort,
			'slug'   => $source . ':' . $key,
			'hide_title' => !empty($record['hide_title']),
			'hidden' => !empty($record['hidden']),
			'searchable' => array_key_exists('searchable', $record) ? (bool) $record['searchable'] : true,
			'sort_priority' => $sort_priority > 0 ? $sort_priority : 0,
			'sort_direction' => in_array($sort_direction, ['asc', 'desc'], true) ? $sort_direction : 'asc',
			'sort_enabled' => !empty($record['sort_enabled']),
			'sortable' => array_key_exists('sortable', $record) ? (bool) $record['sortable'] : true,
			'filter_values' => isset($record['filter_values']) && is_array($record['filter_values']) ? array_values($record['filter_values']) : [],
			'filter_type_priority' => $this->normalize_data_type_priority_list(isset($record['filter_type_priority']) && is_array($record['filter_type_priority']) ? $record['filter_type_priority'] : []),
			'format_date' => !empty($record['format_date']),
			'date_format' => (string) ($record['date_format'] ?? ''),
		];
	}

	/**
	 * Ordered slugs for a column list, one entry per column (empty string where unresolvable).
	 * Public so render-layer code can align a projected row array with its columns without
	 * re-implementing the slug rules.
	 */
	public function column_slugs_in_order(array $columns): array {
		$slugs = [];
		foreach (array_values($columns) as $col) {
			$slugs[] = is_array($col) ? $this->resolve_column_slug($col) : '';
		}
		return $slugs;
	}

	private function resolve_column_slug(array $col): string {
		if (!empty($col['slug'])) {
			return (string) $col['slug'];
		}
		$source = isset($col['source']) ? (string) $col['source'] : 'core';
		$key = isset($col['key']) ? (string) $col['key'] : '';
		return self::build_slug($source, $key);
	}

	/**
	 * Drop only empty-string entries, re-indexed.
	 *
	 * array_filter() with no callback also drops "0", which silently broke shareable filter
	 * links against 0/1 columns (?btbl_filter[csv:in_stock]=0 became an empty selection).
	 * The rest of this file already uses an explicit `!== ''` test for the same reason.
	 *
	 * @param array $values
	 * @return array
	 */
	private static function filter_non_empty(array $values): array {
		return array_values(array_filter($values, static function ($value) {
			return $value !== '';
		}));
	}

	public static function build_slug(string $source, string $key): string {
		$clean_source = sanitize_key($source);
		$source_part = $clean_source !== '' ? $clean_source : 'core';
		return $source_part . ':' . $key;
	}

	public static function normalize_csv_column_sources(array &$columns): void {
		foreach ($columns as &$col) {
			$col_source = isset($col['source']) ? sanitize_key((string) $col['source']) : 'core';
			if ($col_source === '' || $col_source === 'core') {
				$col['source'] = 'csv';
			}
			if (!empty($col['slug']) && strpos($col['slug'], 'core:') === 0) {
				$col['slug'] = 'csv:' . substr((string) $col['slug'], 5);
			} elseif (empty($col['slug']) && !empty($col['key'])) {
				$col['slug'] = 'csv:' . $col['key'];
			}
		}
		unset($col);
	}

	public function sanitize_column_flags(array $raw, array $columns = [], bool $default = false): array {
		$slugs = !empty($columns) ? $columns : null;
		return $this->sanitize_bool_flags($raw, $slugs, $default);
	}

	public function sanitize_date_formats(array $formats_raw): array {
		$out = [];
		foreach ($formats_raw as $slug => $format) {
			$clean_slug = sanitize_text_field($slug);
			if ($clean_slug === '') {
				continue;
			}
			$clean_format = sanitize_text_field((string) $format);
			if ($clean_format !== '') {
				$out[$clean_slug] = $clean_format;
			}
		}
		return $out;
	}

	public function sanitize_sort_enabled(array $enabled_raw, array $columns): array {
		return $this->sanitize_bool_flags($enabled_raw, $columns, false);
	}

	public function sanitize_sort_priority(array $priorities_raw): array {
		$out = [];
		foreach ($priorities_raw as $slug => $priority) {
			$clean_slug = sanitize_text_field($slug);
			if ($clean_slug === '') {
				continue;
			}
			$prio = (int) $priority;
			if ($prio > 0) {
				$out[$clean_slug] = $prio;
			}
		}
		return $out;
	}

	public function sanitize_sort_direction(array $directions_raw): array {
		$out = [];
		foreach ($directions_raw as $slug => $dir) {
			$clean_slug = sanitize_text_field($slug);
			if ($clean_slug === '') {
				continue;
			}
			$clean_dir = sanitize_key($dir);
			$clean_dir = in_array($clean_dir, ['asc', 'desc'], true) ? $clean_dir : 'asc';
			$out[$clean_slug] = $clean_dir;
		}
		return $out;
	}

	public function sanitize_custom_data(array $column_labels_raw, $rows_raw, int $rows_count = 0, int $cols_count = 0): array {
		$dataset = $this->build_custom_dataset($column_labels_raw, $rows_raw, $rows_count, $cols_count);

		return [
			'columns' => $dataset['columns'],
			'rows' => $dataset['rows'],
			'slugs' => $dataset['slugs'],
		];
	}

	public function build_custom_dataset(array $column_labels_raw, $rows_raw, int $rows_count = 0, int $cols_count = 0): array {
		$rows_raw = is_array($rows_raw) ? $rows_raw : [];

		$column_count = $cols_count > 0 ? $cols_count : count($column_labels_raw);
		if ($column_count <= 0) {
			$column_count = 3;
		}
		$column_count = min($column_count, self::MAX_CUSTOM_COLUMNS);

		$columns = [];
		$slugs = [];
		for ($i = 0; $i < $column_count; $i++) {
			$label_raw = $column_labels_raw[$i] ?? '';
			// Store an empty string for unnamed columns rather than baking "Column N":
			// the positional default is supplied at render. Keeping it empty preserves the
			// "the user gave no name" signal so the column is flagged auto_label at save.
			$columns[] = $this->sanitize_inline_html((string) $label_raw);
			$slugs[] = 'custom:col_' . ($i + 1);
		}

		$target_rows = $rows_count > 0 ? $rows_count : count($rows_raw);
		if ($target_rows <= 0) {
			$target_rows = 5;
		}
		$target_rows = min($target_rows, self::MAX_CUSTOM_ROWS);
		$target_rows = min($target_rows, max(1, intdiv(self::MAX_CUSTOM_CELLS, $column_count)));

		$rows = [];
		for ($r = 0; $r < $target_rows; $r++) {
			$row_source = isset($rows_raw[$r]) && is_array($rows_raw[$r]) ? $rows_raw[$r] : [];
			$row = [];
			for ($c = 0; $c < $column_count; $c++) {
				$cell_raw = $row_source[$c] ?? '';
				$cell = is_scalar($cell_raw) ? wp_kses_post((string) $cell_raw) : '';
				$row[] = $cell;
			}
			$rows[] = $row;
		}

		return [
			'columns' => $columns,
			'rows' => $rows,
			'slugs' => $slugs,
			'rows_count' => $target_rows,
			'cols_count' => $column_count,
		];
	}

	/**
	 * Resolve a column's display header, localized at render time.
	 *
	 * Auto-ness comes solely from the explicit `auto_label` flag (set at save when the user
	 * leaves a manual column's heading blank) or a genuinely empty label -- never from
	 * pattern-matching the label text.
	 *
	 * On en_US "Column %d" returns the identical string, so English tables render unchanged;
	 * user-named labels and non-manual sources are never touched.
	 */
	public function display_column_label(array $col, int $index, string $source_type = ''): string {
		$label = (string) ($col['label'] ?? '');
		if ($label === '' || (BaraTables_Source_Type::is_custom_data($source_type) && !empty($col['auto_label']))) {
			/* translators: %d is the column number. */
			return sprintf(__('Column %d', 'baratables'), $index + 1);
		}
		return $label;
	}

	/**
	 * Forward-fix chart links after a table's id changes. Charts store their link as the
	 * table's id (slug); this rewrites any chart pointing at $old_id to $new_id so the
	 * link survives the rename without leaving an alias behind. Returns the count updated.
	 */
	public function rewrite_chart_table_id(string $old_id, string $new_id): int {
		if ($old_id === '' || $new_id === '' || $old_id === $new_id) {
			return 0;
		}
		// Include 'trash' (which get_posts excludes under 'any'): a trashed chart that is
		// later restored must still point at the renamed table, not a dead id.
		// get_posts() already defaults suppress_filters to true, so this internal maintenance
		// lookup is not altered by third-party query filters without setting it explicitly.
		$ids = get_posts([
			'post_type' => BaraTables_Chart_Repository::CPT,
			'post_status' => ['publish', 'draft', 'pending', 'future', 'private', 'trash'],
			'numberposts' => -1,
			'fields' => 'ids',
			'no_found_rows' => true,
		]);
		// Prime the meta cache in one query instead of letting the loop below issue a separate
		// get_post_meta() lookup per chart. Same pattern (and reason) as
		// BaraTables_Base_Repository::query_items_common().
		if (!empty($ids)) {
			_prime_post_caches($ids, false, true);
		}
		$updated = 0;
		foreach ($ids as $id) {
			$chart = get_post_meta((int) $id, BaraTables_Chart_Repository::META_KEY, true);
			if (!is_array($chart) || ($chart['table_id'] ?? '') !== $old_id) {
				continue;
			}
			$chart['table_id'] = $new_id;
			update_post_meta((int) $id, BaraTables_Chart_Repository::META_KEY, $chart);
			$updated++;
		}
		return $updated;
	}


	public function build_custom_display_columns(array $labels): array {
		$labels = array_values($labels);
		if (empty($labels)) {
			// One empty label: build_columns_from_keys_and_labels() turns it into the
			// translated "Column 1". A literal here would ship untranslated.
			$labels = [''];
		}
		$keys = [];
		foreach ($labels as $idx => $_) {
			$keys[] = 'col_' . ($idx + 1);
		}
		return $this->build_columns_from_keys_and_labels($keys, $labels, 'custom');
	}

	private function build_columns_from_keys_and_labels(array $keys, array $labels, string $source): array {
		$columns = [];
		$used_keys = [];
		foreach ($keys as $idx => $key_raw) {
			$key = sanitize_key((string) $key_raw);
			if ($key === '') {
				$key = 'col_' . ($idx + 1);
			}
			// Two headers that sanitize to the same key (e.g. "Region"/"region", "Q1"/"q1") would
			// otherwise share a slug, so row data keyed by slug collapses onto one column. Suffix
			// duplicates so each column keeps a distinct slug (csv:region, csv:region-2, ...).
			if (isset($used_keys[$key])) {
				$base = $key;
				$n = 2;
				do {
					$key = $base . '-' . $n;
					$n++;
				} while (isset($used_keys[$key]));
			}
			$used_keys[$key] = true;
			$label_raw = $labels[$idx] ?? '';
			/* translators: %d is the column number. */
			$label = $label_raw !== '' ? (string) $label_raw : sprintf(__('Column %d', 'baratables'), $idx + 1);
			$columns[] = $this->normalize_column_record([
				'slug' => self::build_slug($source, $key),
				'label' => $label,
				'auto_label' => $source === 'custom' && $label_raw === '',
			]);
		}
		return $columns;
	}

	private function build_column_definitions_from_assoc(array $keys, string $source): array {
		$labels = [];
		foreach ($keys as $key) {
			$key_safe = sanitize_key((string) $key);
			// $labels is positional against $keys, so an unlabelable key must still occupy its
			// slot. Skipping it shifted every later label up by one, so an external table with a
			// non-ASCII column name rendered the remaining columns under the wrong headings.
			// build_columns_from_keys_and_labels() turns '' into the translated "Column N".
			$labels[] = $key_safe === '' ? '' : ucwords(str_replace(['_', '-'], ' ', $key_safe));
		}
		return $this->build_columns_from_keys_and_labels($keys, $labels, $source);
	}


	/**
	 * Which row-token field the given source actually reads. Empty string means the source has no
	 * row-level access control at all (manual data is filtered by no branch of get_rows()).
	 */
	public static function access_token_field_for_source(string $source_type): string {
		if (BaraTables_Source_Type::is_custom_data($source_type)) {
			return '';
		}
		if (BaraTables_Source_Type::is_csv($source_type)) {
			return 'csv_column';
		}
		if (BaraTables_Source_Type::is_external_db($source_type)) {
			return 'external_column';
		}
		return 'post_meta_key';
	}

	public function sanitize_access_control(array $raw, string $source_type = ''): array {
		$user_meta_key = isset($raw['user_meta_key']) ? sanitize_text_field($raw['user_meta_key']) : '';
		$post_meta_key = isset($raw['post_meta_key']) ? sanitize_text_field($raw['post_meta_key']) : '';
		$csv_column = isset($raw['csv_column']) ? sanitize_text_field($raw['csv_column']) : '';
		$external_column = isset($raw['external_column']) ? sanitize_text_field($raw['external_column']) : '';
		// Store only the row-token field the active source can enforce. The editor hides the other
		// fields with CSS but never disables them, so they re-POST their saved values: a policy set
		// up under one source used to survive a switch to another, where no branch of get_rows()
		// reads it. The Advanced tab still showed it configured while every row served to the
		// public. Dropping the inapplicable fields keeps stored state and enforced state identical.
		if ($source_type !== '') {
			$token_field = self::access_token_field_for_source($source_type);
			$post_meta_key = $token_field === 'post_meta_key' ? $post_meta_key : '';
			$csv_column = $token_field === 'csv_column' ? $csv_column : '';
			$external_column = $token_field === 'external_column' ? $external_column : '';
		}
		// Fail closed. This whole block only runs once a row-token field is set, i.e. the admin
		// has asked for row-level restriction -- defaulting anonymous visitors to 'all' made the
		// feature a no-op for the public until a second, separate dropdown was also changed, so
		// restricted rows stayed world-readable. An explicitly saved 'all' is still honoured.
		$logged_out = isset($raw['logged_out']) && in_array($raw['logged_out'], ['all', 'public_only', 'none'], true)
			? $raw['logged_out']
			: 'public_only';
		if ($post_meta_key === '' && $csv_column === '' && $external_column === '') {
			return [];
		}
		return [
			'user_meta_key' => $user_meta_key,
			'post_meta_key' => $post_meta_key,
			'csv_column' => $csv_column,
			'external_column' => $external_column,
			'logged_out' => $logged_out,
		];
	}

	public function sanitize_external_db_config(array $raw): array {
		$host = isset($raw['host']) ? sanitize_text_field($raw['host']) : '';
		$dbname = isset($raw['name']) ? sanitize_text_field($raw['name']) : '';
		$user = isset($raw['user']) ? sanitize_text_field($raw['user']) : '';
		$password = isset($raw['pass']) ? (string) $raw['pass'] : '';
		$table = isset($raw['table']) ? $this->sanitize_external_identifier((string) $raw['table']) : '';
		$charset = isset($raw['charset']) ? sanitize_text_field($raw['charset']) : '';
		$port = isset($raw['port']) ? min(max((int) $raw['port'], 0), 65535) : 0;
		if ($host === '' || $dbname === '' || $user === '' || $table === '') {
			return [];
		}
		$config = [
			'host' => $host,
			'name' => $dbname,
			'user' => $user,
			'table' => $table,
			'charset' => $charset,
			'port' => $port,
		];
		if ($password !== '') {
			$encrypted = BaraTables_Crypto::encrypt($password);
			if ($encrypted !== '') {
				$config['pass'] = $encrypted;
			}
		}
		return $config;
	}

	private function sanitize_external_identifier(string $identifier): string {
		$identifier = trim($identifier);
		if ($identifier === '') {
			return '';
		}
		$identifier = trim($identifier, " \t\n\r\0\x0B`");
		if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
			return '';
		}
		return $identifier;
	}

	private function create_external_db_connection(string $user, string $password, string $dbname, string $host) {
		if (!class_exists('wpdb')) {
			return null;
		}

		$external_db = new class($user, $password, $dbname, $host) extends wpdb {
			public function __construct($dbuser, $dbpassword, $dbname, $dbhost) {
				$this->dbuser = $dbuser;
				$this->dbpassword = $dbpassword;
				$this->dbname = $dbname;
				$this->dbhost = $dbhost;
				$this->hide_errors();
				$this->db_connect(false);
			}

			// Overrides wpdb::bail(); the signature is fixed by core, and neither argument is
			// wanted here -- a failed external connection must fail silently rather than print
			// WordPress's database-error page over the site.
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- core signature.
			public function bail($message, $error_code = '500') {
				$this->ready = false;
				return false;
			}
		};

		return !empty($external_db->dbh) && !empty($external_db->ready) ? $external_db : null;
	}

	public function find_definition(string $id, bool $require_publish = false): ?array {
		$defn = $this->repo->find_definition($id);
		if (!$defn) {
			return null;
		}
		if (empty($defn['source_type'])) {
			$defn['source_type'] = BaraTables_Source_Type::WP_QUERY;
		}
		if (!isset($defn['columns']) || !is_array($defn['columns'])) {
			$defn['columns'] = [];
		}
		if (empty($defn['status'])) {
			$defn['status'] = 'publish';
		}
		if ($defn['status'] === 'trash') {
			return null;
		}
		if (BaraTables_Source_Type::is_csv($defn['source_type']) && !empty($defn['columns'])) {
			self::normalize_csv_column_sources($defn['columns']);
		}
		if ($require_publish && $defn['status'] !== 'publish') {
			return null;
		}
		return $defn;
	}

	public function get_definition_post_id(string $id): int {
		return $this->repo->get_post_id_by_slug($id);
	}

	/**
	 * Rows for a definition, memoized for the lifetime of the request.
	 *
	 * A page holding a table plus a chart sourced from it (or the same shortcode twice) used to run
	 * the whole pipeline once per render: two WP_Query passes, or two CSV parses, or two external
	 * MySQL connections and SELECTs, plus the override/date/access work each time.
	 *
	 * Deliberately request-scoped only -- never a transient. Rows depend on the current visitor
	 * through row-level access control and on live sources that can change between requests.
	 *
	 * Rows and inferred columns are returned and cached together in BaraTables_Row_Result. get_rows()
	 * remains the array-returning compatibility facade for callers that need rows only.
	 */
	public function get_row_result(array $definition, int $limit = -1): BaraTables_Row_Result {
		$cache_key = $this->row_cache_key($definition, $limit);
		if ($cache_key !== '' && array_key_exists($cache_key, $this->row_cache)) {
			return $this->row_cache[$cache_key];
		}
		$result = $this->get_row_result_uncached($definition, $limit);
		if ($cache_key !== '') {
			$this->row_cache[$cache_key] = $result;
		}
		return $result;
	}

	public function get_rows(array $definition, int $limit = -1): array {
		return $this->get_row_result($definition, $limit)->rows();
	}

	/**
	 * Identity of a row fetch.
	 *
	 * Hashes the WHOLE definition, deliberately. An earlier version listed the fields thought to
	 * affect the row set (access_control, columns, custom_query, value_overrides) and silently
	 * served the wrong rows whenever two definitions shared an id but differed in a field that was
	 * not on the list -- post_types, the taxonomy filter, csv_attachment_id/delimiter/header, the
	 * external_db config, date formats. The editor previews an UNSAVED definition under the saved
	 * table's id, which is exactly that shape. A hand-maintained allowlist here is a bug waiting to
	 * be re-introduced every time the definition grows a field, so hash everything: a missed field
	 * can then only ever cost a cache miss, never a wrong answer.
	 *
	 * The current user is part of the key because row-level access control filters per visitor.
	 * Returns '' when there is no id, i.e. nothing stable to cache against.
	 */
	private function row_cache_key(array $definition, int $limit): string {
		$id = isset($definition['id']) ? (string) $definition['id'] : '';
		if ($id === '') {
			return '';
		}
		// custom_data carries its rows INSIDE the definition (up to MAX_CUSTOM_CELLS), so
		// fingerprinting it would cost an encode+hash of the entire dataset on every call -- more
		// than the custom-data row path spends doing the work, which is pure array manipulation with
		// no I/O. Nothing to memoize, so don't.
		if (BaraTables_Source_Type::is_custom_data($definition['source_type'] ?? '')) {
			return '';
		}
		$fingerprint = wp_json_encode($definition);
		if (!is_string($fingerprint)) {
			// Unencodable definition (a resource or closure smuggled in): do not cache rather than
			// risk keying two different fetches identically.
			return '';
		}
		return implode('|', [
			$id,
			(string) $limit,
			(string) get_current_user_id(),
			md5($fingerprint),
		]);
	}

	private function get_row_result_uncached(array $definition, int $limit = -1): BaraTables_Row_Result {
		$definition['source_type'] = BaraTables_Source_Type::normalize($definition['source_type'] ?? BaraTables_Source_Type::WP_QUERY, BaraTables_Source_Type::WP_QUERY);
		$definition['columns'] = isset($definition['columns']) && is_array($definition['columns']) ? $definition['columns'] : [];
		$access = isset($definition['access_control']) && is_array($definition['access_control']) ? $definition['access_control'] : [];
		// Definitions saved before sanitize_access_control() became source-aware can still hold a
		// policy whose row-token field belongs to a different source. No branch below reads it, so
		// the rows would publish unrestricted. Serve nothing instead: the admin asked for row-level
		// restriction, and an empty table is a visible problem where a quietly unguarded one is not.
		// Re-saving the table clears the orphaned field and restores normal rendering.
		if (!empty($access)) {
			$token_field = self::access_token_field_for_source($definition['source_type']);
			if ($token_field === '' || empty($access[$token_field])) {
				return new BaraTables_Row_Result();
			}
		}
		$access_policy = $this->build_access_policy($access);
		$table_options = $this->get_table_options($definition);
		$configured_limit = max(1, (int) ($table_options['rowLimit'] ?? self::DEFAULT_ROW_LIMIT));
		$row_limit = $limit > 0 ? min($limit, $configured_limit) : $configured_limit;

		if (BaraTables_Source_Type::is_custom_data($definition['source_type'])) {
			return $this->get_row_result_from_custom($definition, $row_limit);
		}

		if (BaraTables_Source_Type::is_external_db($definition['source_type'])) {
			return $this->get_row_result_from_external($definition, $row_limit, $access_policy);
		}

		if (BaraTables_Source_Type::is_csv($definition['source_type'])) {
			return $this->get_row_result_from_csv($definition, $row_limit, $access_policy);
		}
		return $this->get_row_result_from_wp_posts($definition, $row_limit, $access_policy);
	}

	private function build_wp_source_query_args(array $definition, int $row_limit, array $access_policy): ?array {
		$post_types_raw = isset($definition['post_types']) && is_array($definition['post_types']) && !empty($definition['post_types'])
			? array_values(array_filter($definition['post_types']))
			: [$definition['post_type'] ?? 'post'];
		$post_types = $this->query_sanitizer->sanitize_public_post_types($post_types_raw, true);
		$query_args = [
			'post_type'      => $post_types,
			'posts_per_page' => $row_limit,
			'no_found_rows'  => true,
			'post_status'    => BaraTables_Query_Sanitizer::post_status_for_types($post_types),
			'ignore_sticky_posts' => true,
		];

		if ($definition['source_type'] === BaraTables_Source_Type::CUSTOM_QUERY) {
			if (empty($definition['custom_query']) || !is_array($definition['custom_query'])) {
				return null;
			}
			$query_args = $this->query_sanitizer->sanitize_wp_query_args($definition['custom_query']);
			if (empty($query_args)) {
				return null;
			}
			if ($row_limit > 0) {
				$query_args['posts_per_page'] = isset($query_args['posts_per_page'])
					? min((int) $query_args['posts_per_page'], $row_limit)
					: $row_limit;
			}
		}

		if (!empty($access_policy['post_meta_key'])) {
			$meta_query = $this->build_access_meta_query($access_policy['post_meta_key'], $access_policy);
			if ($meta_query === 'none') {
				return null;
			}
			if (!empty($meta_query)) {
				$query_args = $this->append_meta_query($query_args, $meta_query);
			}
		}

		if ($definition['source_type'] !== BaraTables_Source_Type::CUSTOM_QUERY) {
			$tax_query = $this->build_tax_query($definition);
			if (!empty($tax_query)) {
				if (!empty($query_args['tax_query']) && is_array($query_args['tax_query'])) {
					$query_args['tax_query'] = array_merge($query_args['tax_query'], $tax_query); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Required for taxonomy filtering.
				} else {
					$query_args['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Required for taxonomy filtering.
				}
			}
		}
		return $query_args;
	}

	private function get_wp_query_cache_requirements(array $columns): array {
		$wants_author = false;
		$wants_permalink = false;
		foreach ($columns as $col) {
			if (!is_array($col) || ($col['source'] ?? '') !== 'core') {
				continue;
			}
			$col_key = (string) ($col['key'] ?? '');
			if ($col_key === 'post_author') {
				$wants_author = true;
			} elseif ($col_key === 'permalink') {
				$wants_permalink = true;
			}
		}
		return ['author' => $wants_author, 'permalink' => $wants_permalink];
	}

	private function prime_wp_query_dependencies(array $posts, array $requirements): void {
		if (!empty($requirements['author']) && !empty($posts)) {
			cache_users(array_unique(array_map('intval', wp_list_pluck($posts, 'post_author'))));
		}

		// get_permalink() on a hierarchical type walks ancestors via get_page_uri(), querying once
		// per uncached parent. Prime one level in a single query (shared ancestors then hit cache).
		if (!empty($requirements['permalink']) && !empty($posts)) {
			$parent_ids = [];
			foreach ($posts as $post) {
				$parent_id = (int) ($post->post_parent ?? 0);
				if ($parent_id > 0 && is_post_type_hierarchical($post->post_type)) {
					$parent_ids[$parent_id] = true;
				}
			}
			if (!empty($parent_ids)) {
				_prime_post_caches(array_keys($parent_ids), false, false);
			}
		}
	}

	private function build_wp_post_rows(array $posts, array $definition, array $access_policy): array {
		$rows = [];
		foreach ($posts as $post) {
			if (!empty($access_policy['post_meta_key']) && !$this->post_passes_access_policy($post, $access_policy)) {
				continue;
			}
			$row = [];
			foreach ($definition['columns'] as $col) {
				$raw_value = $this->resolve_value($post, $col);
				$slug = $this->resolve_column_slug($col);
				$row[] = $this->apply_overrides($raw_value, $slug, $definition['value_overrides'] ?? [], $post);
			}
			$rows[] = $row;
		}
		return $rows;
	}

	private function get_row_result_from_wp_posts(array $definition, int $row_limit, array $access_policy): BaraTables_Row_Result {
		$query_args = $this->build_wp_source_query_args($definition, $row_limit, $access_policy);
		if ($query_args === null) {
			return new BaraTables_Row_Result();
		}
		// Leave WordPress' bulk post-meta and term-cache priming enabled. Removing either saves one
		// query but risks an N+1 through custom fields, callbacks, or category permalinks.
		$query = new WP_Query($query_args);
		$requirements = $this->get_wp_query_cache_requirements($definition['columns']);
		$this->prime_wp_query_dependencies($query->posts, $requirements);
		return new BaraTables_Row_Result($this->build_wp_post_rows($query->posts, $definition, $access_policy));
	}

	private function get_row_result_from_custom(array $definition, int $limit): BaraTables_Row_Result {
		$custom = isset($definition['custom_data']) && is_array($definition['custom_data']) ? $definition['custom_data'] : [];
		$labels = isset($custom['columns']) && is_array($custom['columns']) ? array_values($custom['columns']) : [];
		$rows_raw = isset($custom['rows']) && is_array($custom['rows']) ? $custom['rows'] : [];

		$column_defs = $this->build_custom_display_columns($labels);
		$column_slugs = $this->column_slugs_in_order($column_defs);
		$overrides = isset($definition['value_overrides']) && is_array($definition['value_overrides'])
			? $definition['value_overrides']
			: [];
		// Date formatting lives on $definition['columns'] (the configured columns carrying the
		// "Format as date" toggle), keyed by slug. The wp_query path applies it via resolve_value();
		// mirror that here so manual-data date columns format too. slug => date_format string.
		$date_format_map = [];
		$definition_columns = isset($definition['columns']) && is_array($definition['columns']) ? $definition['columns'] : [];
		foreach ($definition_columns as $col) {
			if (!is_array($col) || empty($col['format_date'])) {
				continue;
			}
			$slug = $this->resolve_column_slug($col);
			if ($slug !== '') {
				$date_format_map[$slug] = isset($col['date_format']) ? (string) $col['date_format'] : '';
			}
		}
		$column_count = count($column_defs);

		$rows = [];
		foreach ($rows_raw as $row) {
			$values = is_array($row) ? $row : [];
			$normalized = [];
			for ($i = 0; $i < $column_count; $i++) {
				$value = $values[$i] ?? '';
				$normalized[] = is_scalar($value) ? (string) $value : '';
			}
			if (!empty($date_format_map)) {
				foreach ($column_slugs as $idx => $slug) {
					if ($slug === '' || !array_key_exists($slug, $date_format_map)) {
						continue;
					}
					$normalized[$idx] = $this->format_date_value($normalized[$idx] ?? '', $date_format_map[$slug]);
				}
			}
			$rows[] = $normalized;
			if ($limit > 0 && count($rows) >= $limit) {
				break;
			}
		}

		// Overrides run against $column_defs order and BEFORE reorder_rows_by_slug_map() re-orders
		// rows to match $definition['columns'] -- the same position the inline per-row loop used.
		// Shared with the CSV/external paths so the {{slug}}/{{key}} token rules live in one place.
		$rows = $this->apply_ordered_overrides($rows, $column_defs, $overrides);

		if (!empty($definition['columns'])) {
			$slug_map = $this->build_slug_index_map($column_defs);
			$rows = $this->reorder_rows_by_slug_map($rows, $definition['columns'], $slug_map);
		}

		return new BaraTables_Row_Result($rows, $column_defs);
	}

	private function get_row_result_from_csv(array $definition, int $limit, array $access_policy): BaraTables_Row_Result {
		$inferred = [];
		$attachment_id = isset($definition['csv_attachment_id']) ? (int) $definition['csv_attachment_id'] : 0;
		if ($attachment_id <= 0) {
			return new BaraTables_Row_Result();
		}
		if (!$this->is_valid_csv_attachment($attachment_id)) {
			return new BaraTables_Row_Result();
		}
		$path = get_attached_file($attachment_id);
		if (!$path || !file_exists($path) || !is_readable($path)) {
			return new BaraTables_Row_Result();
		}
		$file_size = filesize($path);
		if ($file_size === false || $file_size > self::MAX_CSV_BYTES) {
			return new BaraTables_Row_Result();
		}

		$has_header = !empty($definition['csv_has_header']);
		$delimiter = isset($definition['csv_delimiter']) && is_string($definition['csv_delimiter']) && strlen($definition['csv_delimiter']) === 1
			? $definition['csv_delimiter']
			: ',';

		// With row-level access control the limit must apply to the rows the visitor may SEE, not
		// to the file's first N lines -- otherwise a visitor whose permitted rows sit past line N
		// gets a short or empty table. So when access is enforced, read the whole file (already
		// bounded to MAX_CSV_BYTES above) and apply the limit after filtering, below. Without
		// access control, keep stopping at the limit so a large file is never fully read.
		$defer_limit = !empty($access_policy['csv_column']);

		// Parsed rows are cached BEFORE access filtering, which is per-visitor and must never be
		// cached. filemtime + size are in the key, so an edited file can never serve stale rows.
		// The cache carries rows and inferred columns together so a hit is a complete parse result.
		// Only meaningful on sites with a persistent object cache; elsewhere it is request-scoped
		// and the per-request row cache in get_rows() has already collapsed repeat reads.
		// filemtime has 1-second granularity, so mtime+size alone cannot see a same-second in-place
		// replacement of identical length. Fold in the attachment's own modified time (WordPress
		// media flows bump it) and keep the entry short-lived, so the residual window for an
		// out-of-band overwrite is minutes rather than an hour.
		$csv_cache_key = 'csv_rows_' . md5(implode('|', [
			(string) $attachment_id,
			(string) filemtime($path),
			(string) $file_size,
			(string) get_post_modified_time('U', true, $attachment_id),
			$delimiter,
			$has_header ? '1' : '0',
			$defer_limit ? 'all' : (string) $limit,
		]));
		$cached = wp_cache_get($csv_cache_key, 'baratables');
		if (is_array($cached) && array_key_exists('rows', $cached)) {
			$inferred = isset($cached['inferred']) && is_array($cached['inferred']) ? $cached['inferred'] : [];
			$rows = $cached['rows'];
		} else {
			$rows = [];
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- fgetcsv requires a file handle; WP_Filesystem has no CSV parsing equivalent.
			$handle = fopen($path, 'rb');
			if ($handle !== false) {
				$count = 0;
				while (true) {
					$data = fgetcsv($handle, self::MAX_CSV_LINE_LENGTH, $delimiter, '"', '\\');
					if ($data === false) {
						break;
					}
					// fgetcsv() yields [null] for a blank physical line (a trailing newline, or a
					// spacer row). Counting it would render an all-empty <tr> and, worse, spend one
					// of the row-limit slots on it. The importer's own reader already skips this.
					if ($data === [null]) {
						continue;
					}
					if ($has_header && $count === 0) {
						$inferred = $this->infer_columns_from_header($data);
						$count++;
						continue;
					}
					$rows[] = $data;
					$count++;
					if (!$defer_limit && $limit > 0 && $count >= $limit + ($has_header ? 1 : 0)) {
						break;
					}
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing handle opened for fgetcsv; WP_Filesystem has no CSV parsing equivalent.
				fclose($handle);
				// Only worth writing when a persistent backend can actually serve it on a LATER
				// request -- without one, wp_cache_set() just retains up to 5MB for the rest of this
				// request that nothing can read back (get_rows()'s own per-request cache already
				// short-circuits the repeat). Gate on BYTES, not row count: the hazard is a
				// backend's per-item ceiling (memcached defaults to 1MB), and 5,000 wide rows blow
				// past that while passing a row-count test.
				if (wp_using_ext_object_cache() && $file_size <= 256 * KB_IN_BYTES && count($rows) <= 5000) {
					wp_cache_set(
						$csv_cache_key,
						['rows' => $rows, 'inferred' => $inferred],
						'baratables',
						5 * MINUTE_IN_SECONDS
					);
				}
			}
		}

		if (empty($inferred)) {
			$maxCols = 0;
			foreach ($rows as $row) {
				$maxCols = max($maxCols, is_array($row) ? count($row) : 0);
			}
			if ($maxCols > 0) {
				// Only the count matters: infer_columns_from_header() with $is_header = false
				// derives its own labels and ignores these values.
				$headers = array_fill(0, $maxCols, '');
				$inferred = $this->infer_columns_from_header($headers, false);
			}
		}

		$csv_index_map = $this->build_slug_index_map($inferred);

		// Access control is enforced regardless of whether display columns are configured, so
		// a CSV table with access control but no selected columns never returns unfiltered
		// rows (matching the external-DB path).
		if ($defer_limit) {
			$access_index = $this->resolve_csv_access_column_index($csv_index_map, (string) $access_policy['csv_column']);
			if ($access_index === null) {
				return new BaraTables_Row_Result([], $inferred);
			}
			$rows = array_values(array_filter($rows, static function ($row) use ($access_index) {
				return is_array($row) && array_key_exists($access_index, $row);
			}));
			$rows = $this->filter_rows_by_access($rows, function ($row) use ($access_index) {
				return $row[$access_index];
			}, $access_policy);
		}

		// Deferred limit: now that only visible rows remain, trim to the configured ceiling.
		if ($defer_limit && $limit > 0 && count($rows) > $limit) {
			$rows = array_slice($rows, 0, $limit);
		}

		if (!empty($definition['columns'])) {
			$rows = $this->reorder_rows_by_slug_map($rows, $definition['columns'], $csv_index_map);
			$rows = $this->finalize_ordered_rows($rows, $definition['columns'], $definition['value_overrides'] ?? []);
		}

		return new BaraTables_Row_Result($rows, $inferred);
	}

	private function is_valid_csv_attachment(int $attachment_id): bool {
		$attachment = get_post($attachment_id);
		if (!$attachment || $attachment->post_type !== 'attachment') {
			return false;
		}

		$file = get_attached_file($attachment_id);
		if (!$file || !file_exists($file)) {
			// The attachment row can outlive its file (e.g. the file was deleted but the post
			// lingered); a missing file is not a usable CSV source.
			return false;
		}
		$file_type = wp_check_filetype((string) $file, ['csv' => 'text/csv']);
		if (($file_type['ext'] ?? '') !== 'csv') {
			return false;
		}

		$mime_type = (string) get_post_mime_type($attachment_id);
		return $mime_type === '' || in_array($mime_type, self::CSV_MIME_TYPES, true);
	}

	/**
	 * Match a configured access column against a lookup map's keys, returning the KEY that matched
	 * (or null). Shared by the CSV and external-DB resolvers, which ran the same four-step match --
	 * try raw/normalized/sanitized candidates directly, then via normalize_access_column_key() --
	 * against different lookups (a csv:-prefixed slug->index map, or a raw DB row). The candidate
	 * set is the union of both: extra candidates can only fail to match, never mis-match, because a
	 * bare candidate cannot key a prefixed map and vice versa, and the normalized pass reduces every
	 * form to the same token regardless.
	 *
	 * @param array<string,mixed> $lookup_map Keyed by the column identifiers to match against.
	 */
	private function resolve_access_column_key(array $lookup_map, string $column, string $source): ?string {
		$column = trim($column);
		$prefix = $source . ':';
		$normalized_column = (string) preg_replace('/^' . preg_quote($prefix, '/') . '/i', '', $column);
		$sanitized_column = sanitize_key($column);
		$sanitized_normalized = sanitize_key($normalized_column);
		$candidates = array_values(array_unique(array_filter([
			$column,
			$normalized_column,
			$sanitized_column,
			$sanitized_normalized,
			$normalized_column !== '' ? $prefix . $normalized_column : '',
			$sanitized_normalized !== '' ? $prefix . $sanitized_normalized : '',
		], static function ($candidate) {
			return $candidate !== '';
		})));

		foreach ($candidates as $candidate) {
			if (array_key_exists($candidate, $lookup_map)) {
				return (string) $candidate;
			}
		}

		$normalized_lookup = [];
		foreach (array_keys($lookup_map) as $key) {
			$normalized_key = $this->normalize_access_column_key((string) $key, $source);
			if ($normalized_key !== '' && !isset($normalized_lookup[$normalized_key])) {
				$normalized_lookup[$normalized_key] = (string) $key;
			}
		}

		foreach ($candidates as $candidate) {
			$normalized_candidate = $this->normalize_access_column_key((string) $candidate, $source);
			if ($normalized_candidate !== '' && isset($normalized_lookup[$normalized_candidate])) {
				return $normalized_lookup[$normalized_candidate];
			}
		}

		return null;
	}

	private function resolve_csv_access_column_index(array $csv_index_map, string $column): ?int {
		$key = $this->resolve_access_column_key($csv_index_map, $column, 'csv');
		return $key === null ? null : (int) $csv_index_map[$key];
	}

	/**
	 * The external columns a render actually needs, or [] to mean "SELECT *".
	 *
	 * Returns [] when the definition has no columns (the reader infers them from the result keys,
	 * so it must see every column) or when any wanted name fails identifier validation.
	 */
	private function external_select_columns(array $definition, array $access_policy): array {
		$columns = isset($definition['columns']) && is_array($definition['columns']) ? $definition['columns'] : [];
		if (empty($columns)) {
			return [];
		}
		$wanted = [];
		foreach ($columns as $col) {
			// Every column must be a real external column. A definition carrying a core:* column
			// (imports, hand-edited meta) would otherwise put a non-existent name in the SELECT,
			// so every render took the error+fallback path -- an extra round trip AND a
			// "WordPress database error" in the PHP log on every single page view.
			if (!is_array($col) || ($col['source'] ?? '') !== 'external') {
				return [];
			}
			$clean = $this->sanitize_external_identifier((string) ($col['key'] ?? ''));
			if ($clean === '') {
				return [];
			}
			$wanted[$clean] = true;
		}
		// The access-token column is never displayed but IS read to filter rows, so it has to be
		// fetched too. Its stored name is matched loosely against the real keys, so a narrowed
		// fetch is re-checked by the caller and falls back to SELECT * if the token goes missing.
		$token = (string) ($access_policy['external_column'] ?? '');
		if ($token !== '') {
			$clean_token = $this->sanitize_external_identifier($token);
			if ($clean_token === '') {
				return [];
			}
			$wanted[$clean_token] = true;
		}
		return array_keys($wanted);
	}

	/**
	 * One external fetch. $columns empty means SELECT *. Returns null on any DB error so the
	 * caller can fall back -- a column list that has drifted from the real schema must degrade to
	 * today's SELECT * behaviour, never to a broken table.
	 */
	private function fetch_external_rows($ext_db, string $table, int $fetch_limit, array $columns): ?array {
		// ONE prepared statement for both shapes -- '*' when nothing can be narrowed, otherwise one
		// %i identifier placeholder per column. Only the NUMBER of placeholders is dynamic; every
		// value, including each column name, is bound by prepare(). Kept as a single call so the
		// suite's first-party-DB-call tripwire stays tight (see local-tests/run-tests.sh).
		$select_list = empty($columns) ? '*' : implode(', ', array_fill(0, count($columns), '%i'));
		$args = array_merge($columns, [$table, $fetch_limit]);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Format string is built only from literal '%i' placeholders; all values are bound.
		$sql = $ext_db->prepare('SELECT ' . $select_list . ' FROM %i LIMIT %d', ...$args);
		if (!is_string($sql) || $sql === '') {
			return null;
		}
		// Suppress while probing: a narrowed SELECT is allowed to fail (that is what the caller's
		// SELECT * fallback is for), and wpdb::print_error() would otherwise write to the PHP error
		// log on every render for a definition whose columns drifted from the schema.
		$suppressed = $ext_db->suppress_errors(true);
		$ext_db->last_error = '';
		$results = $ext_db->get_results($sql, ARRAY_A);
		$failed = $ext_db->last_error !== '';
		$ext_db->suppress_errors($suppressed);
		if ($failed) {
			return null;
		}
		return is_array($results) ? $results : null;
	}

	private function get_row_result_from_external(array $definition, int $limit, array $access_policy): BaraTables_Row_Result {
		$inferred = [];
		$config = isset($definition['external_db']) && is_array($definition['external_db']) ? $definition['external_db'] : [];
		$source = $this->connect_external_source($config);
		if ($source === null) {
			return new BaraTables_Row_Result();
		}
		$ext_db = $source['db'];
		$table = $source['table'];
		$per_page = $limit > 0 ? $limit : self::DEFAULT_ROW_LIMIT;

		// With row-level access control the LIMIT must bound the rows the visitor may SEE, not the
		// table's first N physical rows -- otherwise a visitor whose permitted rows sit past row N
		// gets a short or empty table. The rows are filtered in PHP after the fetch, and the source
		// table has no ORDER BY we can page on, so instead of LIMIT $per_page we fetch a bounded
		// superset (capped at the schema's 10,000-row maximum), filter, then slice to $per_page
		// below. Without access control the plain LIMIT is kept so a large table is never overread.
		$access_active = !empty($access_policy['external_column']);
		$fetch_limit = $access_active ? self::MAX_ROW_LIMIT : $per_page;

		// Fetch only the columns this table renders (plus the access-token column) instead of
		// SELECT *. On a wide source that is the difference between pulling every column of up to
		// 10,000 rows and pulling the two or three actually shown. Any drift between the saved
		// column list and the real schema falls back to SELECT *, so behaviour never regresses.
		$select_columns = $this->external_select_columns($definition, $access_policy);
		$results = empty($select_columns)
			? null
			: $this->fetch_external_rows($ext_db, $table, $fetch_limit, $select_columns);

		// A narrowed fetch that SUCCEEDS but lacks the token column would make the access filter
		// deny every row -- an empty table, silently. Re-check and fall back if so.
		if (is_array($results) && !empty($results) && $access_active) {
			$probe = reset($results);
			if (!is_array($probe) || $this->resolve_external_row_key($probe, (string) $access_policy['external_column']) === null) {
				$results = null;
			}
		}

		if (!is_array($results)) {
			$results = $this->fetch_external_rows($ext_db, $table, $fetch_limit, []);
		}
		if (!is_array($results) || empty($results)) {
			return new BaraTables_Row_Result();
		}

		$columns_for_mapping = $definition['columns'];
		if (empty($columns_for_mapping)) {
			// An external table saved with no columns selected falls back to SELECT * -- which
			// would publish the access-token column itself to every visitor who can see the row.
			// Drop it from the inferred set; row-level filtering still uses it via $results.
			$result_keys = array_keys($results[0]);
			$token_column = (string) ($access_policy['external_column'] ?? '');
			if ($token_column !== '') {
				// Probing a single-key row reuses the reader's own resolution rules verbatim, so
				// the column dropped here is exactly the one the access filter reads.
				$result_keys = array_values(array_filter($result_keys, function ($key) use ($token_column) {
					return $this->resolve_external_row_key([$key => null], $token_column) === null;
				}));
			}
			$inferred = $this->build_column_definitions_from_assoc($result_keys, 'external');
			$columns_for_mapping = $inferred;
		}

		$map = [];
		foreach ($columns_for_mapping as $column) {
			$map[$this->resolve_column_slug($column)] = $column['key'] ?? '';
		}

		$eligible_rows = $results;

		if ($access_active) {
			$first_row = reset($eligible_rows);
			// Resolve the token column's real key once here; the key set is the same for every
			// row, and this doubles as the "column is missing -> deny everything" check.
			$token_key = is_array($first_row)
				? $this->resolve_external_row_key($first_row, (string) $access_policy['external_column'])
				: null;
			if ($token_key === null) {
				return new BaraTables_Row_Result([], $inferred);
			}
			$eligible_rows = $this->filter_rows_by_access(
				$eligible_rows,
				static function ($row) use ($token_key) {
					return is_array($row) && array_key_exists($token_key, $row) ? $row[$token_key] : '';
				},
				$access_policy
			);
			// Deferred limit: the fetch pulled a superset, so trim to the configured ceiling now
			// that only visible rows remain.
			if ($per_page > 0 && count($eligible_rows) > $per_page) {
				$eligible_rows = array_slice($eligible_rows, 0, $per_page);
			}
		}

		$ordered = $this->reorder_external_rows_by_slug_map($eligible_rows, $columns_for_mapping, $map);
		$ordered = $this->finalize_ordered_rows($ordered, $columns_for_mapping, $definition['value_overrides'] ?? []);
		return new BaraTables_Row_Result($ordered, $inferred);
	}

	/** Validate and connect an external source before row-fetch policy is applied. */
	private function connect_external_source(array $config): ?array {
		$host = $config['host'] ?? '';
		$dbname = $config['name'] ?? '';
		$user = $config['user'] ?? '';
		$password = BaraTables_Crypto::decrypt($config['pass'] ?? '');
		$table = $config['table'] ?? '';
		$charset = $config['charset'] ?? '';
		$port = isset($config['port']) ? (int) $config['port'] : 0;
		if ($host === '' || $dbname === '' || $user === '' || $table === '') {
			return null;
		}
		$host_with_port = $port > 0 ? $host . ':' . $port : $host;
		$ext_db = $this->create_external_db_connection($user, $password, $dbname, $host_with_port);
		if (!$ext_db) {
			return null;
		}
		if ($charset !== '') {
			$ext_db->set_charset($ext_db->dbh, $charset);
		}
		$table = $this->sanitize_external_identifier((string) $table);
		if ($table === '' || !method_exists($ext_db, 'has_cap') || !$ext_db->has_cap('identifier_placeholders')) {
			return null;
		}
		return ['db' => $ext_db, 'table' => $table];
	}

	/** Shared finalization once a row source has projected values into display-column order. */
	private function finalize_ordered_rows(array $rows, array $columns, array $overrides): array {
		$rows = $this->apply_ordered_date_formats($rows, $this->build_ordered_date_formats($columns));
		return $this->apply_ordered_overrides($rows, $columns, $overrides);
	}

	/**
	 * Resolve which key of an external result row corresponds to $column, or null if none does.
	 *
	 * Inferred column keys are sanitize_key()'d (build_columns_from_keys_and_labels) while the
	 * database's own keys are not, so a column named "Order Total" is stored as "ordertotal" and
	 * none of the direct candidates match -- resolve_access_column_key()'s normalized pass is the
	 * NORMAL path for any column with capitals, spaces or dots, not a rare edge case. That makes it
	 * expensive enough that callers must hoist it out of per-cell loops (see
	 * reorder_external_rows_by_slug_map).
	 */
	private function resolve_external_row_key(array $row, string $column): ?string {
		return $this->resolve_access_column_key($row, $column, 'external');
	}

	private function normalize_access_column_key(string $key, string $source): string {
		$key = preg_replace('/^' . preg_quote($source, '/') . ':/i', '', $key);
		$key = sanitize_key((string) $key);
		return preg_replace('/[^a-z0-9]/', '', $key);
	}

	private function reorder_external_rows_by_slug_map(array $rows, array $definition_columns, array $slug_map): array {
		if (empty($definition_columns) || empty($rows)) {
			return $rows;
		}
		// Every row in a SQL result set carries the identical key set, so resolve each definition
		// column to its actual row key ONCE against a probe row instead of re-running the
		// regex/sanitize_key-heavy matcher for every cell. At 10,000 rows x 12 columns that is
		// 12 resolutions instead of 120,000 (~554ms -> ~2ms).
		$probe = null;
		foreach ($rows as $row) {
			if (is_array($row)) {
				$probe = $row;
				break;
			}
		}
		if ($probe === null) {
			return $rows;
		}

		$resolved_keys = [];
		foreach ($definition_columns as $col) {
			$slug = $this->resolve_column_slug($col);
			$source_key = $slug_map[$slug] ?? null;
			$resolved_keys[] = $source_key !== null
				? $this->resolve_external_row_key($probe, (string) $source_key)
				: null;
		}

		$ordered_rows = [];
		foreach ($rows as $row) {
			if (!is_array($row)) {
				continue;
			}
			$ordered_row = [];
			foreach ($resolved_keys as $key) {
				// array_key_exists still guards a ragged row, matching the old per-row behaviour.
				$ordered_row[] = ($key !== null && array_key_exists($key, $row)) ? $row[$key] : '';
			}
			$ordered_rows[] = $ordered_row;
		}
		return $ordered_rows;
	}

	private function build_slug_index_map(array $columns): array {
		$map = [];
		foreach ($columns as $idx => $col) {
			$map[$this->resolve_column_slug($col)] = $idx;
		}
		return $map;
	}

	private function reorder_rows_by_slug_map(array $rows, array $definition_columns, array $slug_map): array {
		if (empty($definition_columns) || empty($rows)) {
			return $rows;
		}
		$ordered_rows = [];
		foreach ($rows as $row) {
			$ordered_row = [];
			foreach ($definition_columns as $col) {
				$slug = $this->resolve_column_slug($col);
				$source_index = $slug_map[$slug] ?? null;
				$ordered_row[] = ($source_index !== null && array_key_exists($source_index, $row)) ? $row[$source_index] : '';
			}
			$ordered_rows[] = $ordered_row;
		}
		return $ordered_rows;
	}

	private function build_access_policy(array $access): array {
		// Same fail-closed default as sanitize_access_control(): a definition stored before the
		// logged_out key existed must not expose restricted rows to anonymous visitors.
		$logged_out_policy_raw = $access['logged_out'] ?? 'public_only';
		$logged_out_policy = in_array($logged_out_policy_raw, ['all', 'public_only', 'none'], true) ? $logged_out_policy_raw : 'public_only';
		$post_meta_key = isset($access['post_meta_key']) ? sanitize_text_field($access['post_meta_key']) : '';
		$csv_column = isset($access['csv_column']) ? sanitize_text_field($access['csv_column']) : '';
		$external_column_raw = $access['external_column'] ?? '';
		$external_column = $external_column_raw !== '' ? sanitize_text_field($external_column_raw) : '';
		$needs_tokens = $post_meta_key !== '' || $csv_column !== '' || $external_column !== '';
		$user_tokens = $needs_tokens ? $this->get_user_tokens($access['user_meta_key'] ?? '') : [];

		return [
			'logged_out_policy' => $logged_out_policy,
			'user_tokens' => $user_tokens,
			'post_meta_key' => $post_meta_key,
			'csv_column' => $csv_column,
			'external_column' => $external_column,
		];
	}

	private function passes_access_tokens(array $row_tokens, array $user_tokens, string $logged_out_policy): bool {
		$is_logged_in = is_user_logged_in();
		$allow_public = $logged_out_policy !== 'none';
		if (empty($row_tokens)) {
			return $allow_public;
		}
		if (!$is_logged_in) {
			return $logged_out_policy === 'all';
		}
		if (empty($user_tokens)) {
			// A logged-in user with no matching tokens must not see a restricted (tokened) row.
			// The logged_out policy governs anonymous visitors only -- applying it here leaked
			// restricted CSV/external rows to logged-in users under logged_out='all'. Denying
			// matches build_access_meta_query (the WP_Query path), so all three sources agree.
			return false;
		}
		return (bool) array_intersect($row_tokens, $user_tokens);
	}

	private function post_passes_access_policy($post, array $access_policy): bool {
		$meta_key = isset($access_policy['post_meta_key']) ? sanitize_text_field($access_policy['post_meta_key']) : '';
		if ($meta_key === '' || empty($post->ID)) {
			return true;
		}
		$tokens = $this->normalize_tokens(get_post_meta((int) $post->ID, $meta_key, true));
		return $this->passes_access_tokens(
			$tokens,
			$access_policy['user_tokens'] ?? [],
			$access_policy['logged_out_policy'] ?? 'public_only'
		);
	}

	private function infer_columns_from_header(array $header_row, bool $is_header = true): array {
		$keys = [];
		$labels = [];
		foreach ($header_row as $idx => $label) {
			$key = $is_header ? sanitize_title((string) $label) : 'col_' . ($idx + 1);
			if ($key === '') {
				$key = 'col_' . ($idx + 1);
			}
			$keys[] = $key;
			// Empty (not a literal "Column N") so build_columns_from_keys_and_labels() supplies
			// the translated default -- a hardcoded English label here would survive to the
			// front end untranslated, because display_column_label() only localizes blank
			// labels and auto-labelled manual columns.
			$labels[] = $is_header ? (string) $label : '';
		}
		return $this->build_columns_from_keys_and_labels($keys, $labels, 'csv');
	}

	public function definition_with_inferred_columns(array $definition, BaraTables_Row_Result $result): array {
		if (!empty($definition['columns'])) {
			return $definition;
		}
		$source = $definition['source_type'] ?? 'wp_query';
		if (BaraTables_Source_Type::is_csv($source)) {
			return $definition;
		}
		$inferred = $result->inferred_columns();
		if (!empty($inferred)) {
			$definition['columns'] = $inferred;
		}
		return $definition;
	}

	/**
	 * Resolve effective columns with the same atomic result that discovered them.
	 */
	public function resolve_columns(array $definition): array {
		if (!empty($definition['columns'])) {
			return $definition;
		}
		// CSV columns come from the stored header rather than row-shape inference, so there is
		// nothing to prime through a row fetch.
		if (BaraTables_Source_Type::is_csv($definition['source_type'] ?? BaraTables_Source_Type::WP_QUERY)) {
			return $definition;
		}
		// One row is enough to learn the shape. The rows themselves are discarded.
		$result = $this->get_row_result($definition, 1);
		return $this->definition_with_inferred_columns($definition, $result);
	}

	/** Expand compact declarations into the stable public schema shape. */
	private static function build_table_option_schema(): array {
		$schema = [];
		foreach (self::TABLE_OPTION_DEFINITIONS as $key => $definition) {
			$type = (string) $definition[0];
			$config = [
				'type' => $type,
				'default' => $definition[1],
				'label' => null,
			];
			if ($type === 'number') {
				$config['min'] = (int) $definition[2];
				$config['max'] = (int) $definition[3];
				$config['description'] = null;
			} elseif ($type === 'text_html') {
				$config['description'] = null;
			} elseif ($type === 'checkbox_multi') {
				$config['choices'] = $definition[2];
				$config['description'] = null;
			}
			$schema[$key] = $config;
		}

		$schema['rowLimit']['default'] = self::DEFAULT_ROW_LIMIT;
		$schema['rowLimit']['max'] = self::MAX_ROW_LIMIT;
		return $schema;
	}

	public static function get_table_option_schema(): array {
		static $schema_with_labels = null;
		if ($schema_with_labels !== null) {
			return $schema_with_labels;
		}

		$schema = self::build_table_option_schema();
		$schema['paging']['label'] = __('Enable pagination', 'baratables');
		$schema['lengthChange']['label'] = __('Show per page selector', 'baratables');
		$schema['pagingNumbers']['label'] = __('Show page numbers', 'baratables');
		$schema['pagingFirstLast']['label'] = __('Show first/last', 'baratables');
		$schema['pagingPreviousNext']['label'] = __('Show previous/next', 'baratables');
		$schema['searchBox']['label'] = __('Show search box', 'baratables');
		$schema['searchColumns']['label'] = __('Show "Search In" dropdown', 'baratables');
		$schema['info']['label'] = __('Show result summary', 'baratables');
		$schema['infoText']['label'] = __('Summary text', 'baratables');
		$schema['infoEmpty']['label'] = __('Summary (no results)', 'baratables');
		$schema['infoFiltered']['label'] = __('Summary (filtered)', 'baratables');
		$schema['layoutTopStart']['label'] = __('Layout: top left', 'baratables');
		$schema['layoutTopEnd']['label'] = __('Layout: top right', 'baratables');
		$schema['layoutBottomStart']['label'] = __('Layout: bottom left', 'baratables');
		$schema['layoutBottomEnd']['label'] = __('Layout: bottom right', 'baratables');
		$schema['filtersTitle']['label'] = __('Show filters title', 'baratables');
		$schema['filtersTitleText']['label'] = __('Filters title text', 'baratables');
		$schema['ordering']['label'] = __('Allow column sorting', 'baratables');
		$schema['colReorder']['label'] = __('Allow column reordering', 'baratables');
		$schema['stateSave']['label'] = __('Remember table state', 'baratables');
		$schema['autoWidth']['label'] = __('Auto-size columns', 'baratables');
		$schema['scrollX']['label'] = __('Enable horizontal scrolling', 'baratables');
		$schema['scrollYEnabled']['label'] = __('Fixed scroll height', 'baratables');
		$schema['scrollY']['label'] = __('Height (px)', 'baratables');
		$schema['scrollCollapse']['label'] = __('Collapse when shorter', 'baratables');
		$schema['stripe']['label'] = __('Show zebra stripes', 'baratables');
		$schema['rowBorder']['label'] = __('Show row borders', 'baratables');
		$schema['cellBorder']['label'] = __('Show cell borders', 'baratables');
		$schema['hover']['label'] = __('Highlight rows on hover', 'baratables');
		$schema['orderColumn']['label'] = __('Highlight sorted column', 'baratables');
		$schema['compact']['label'] = __('Compact density', 'baratables');
		$schema['pageLength']['label'] = __('Rows per page', 'baratables');
		$schema['rowLimit']['label'] = __('Maximum rows to load', 'baratables');
		$schema['rowLimit']['description'] = __('Rows fetched and rendered, on every data source. Maximum 10,000. Pre-filter larger datasets at the source.', 'baratables');
		$schema['lengthMenuPrefix']['label'] = __('Selector prefix', 'baratables');
		$schema['lengthMenuSuffix']['label'] = __('Selector suffix', 'baratables');
		$schema['paginateFirst']['label'] = __('Pagination label: First', 'baratables');
		$schema['paginatePrevious']['label'] = __('Pagination label: Previous', 'baratables');
		$schema['paginateNext']['label'] = __('Pagination label: Next', 'baratables');
		$schema['paginateLast']['label'] = __('Pagination label: Last', 'baratables');
		$schema['searchText']['label'] = __('Search text', 'baratables');
		$schema['searchPlaceholder']['label'] = __('Search placeholder', 'baratables');
		$schema['searchColumnsLabel']['label'] = __('Dropdown button text', 'baratables');
		$schema['searchColumnsHeading']['label'] = __('Dropdown heading', 'baratables');

		$schema['buttons']['label'] = __('Table buttons', 'baratables');
		$schema['buttons']['description'] = __('Add export and column-visibility buttons to the table.', 'baratables');
		$schema['buttons']['choices'] = [
			'copy' => __('Copy', 'baratables'),
			'csv' => __('Export CSV', 'baratables'),
			'excel' => __('Export Excel', 'baratables'),
			'print' => __('Print', 'baratables'),
			'colvis' => __('Column visibility', 'baratables'),
			'pagelength' => __('Page length button', 'baratables'),
		];
		$schema['buttonTextCopy']['label'] = __('Copy button text', 'baratables');
		$schema['buttonTextCsv']['label'] = __('CSV button text', 'baratables');
		$schema['buttonTextExcel']['label'] = __('Excel button text', 'baratables');
		$schema['buttonTextPrint']['label'] = __('Print button text', 'baratables');
		$schema['buttonTextColvis']['label'] = __('Column visibility button text', 'baratables');
		$schema['buttonTextPagelength']['label'] = __('Page length button text', 'baratables');

		foreach (array_keys(self::TABLE_STYLE_CLASS_MAP) as $key) {
			$schema[$key]['editor_group'] = 'style';
		}

		$inline_controls = [
			'paging' => ['pageLength', 'lengthChange', 'lengthMenuPrefix', 'lengthMenuSuffix', 'pagingNumbers', 'pagingFirstLast', 'paginateFirst', 'paginateLast', 'pagingPreviousNext', 'paginatePrevious', 'paginateNext'],
			'searchBox' => ['searchText', 'searchPlaceholder', 'searchColumns', 'searchColumnsLabel', 'searchColumnsHeading'],
			'info' => ['infoText', 'infoEmpty', 'infoFiltered'],
			'filtersTitle' => ['filtersTitleText'],
			'scrollYEnabled' => ['scrollY', 'scrollCollapse'],
		];
		foreach ($inline_controls as $parent => $children) {
			foreach ($children as $order => $key) {
				$schema[$key]['editor_group'] = 'inline';
				$schema[$key]['editor_parent'] = $parent;
				$schema[$key]['editor_order'] = $order;
			}
		}

		$editor_classes = [
			'pageLength' => ['btbl-page-length-row'],
			'lengthChange' => ['btbl-length-change-flag'],
			'lengthMenuPrefix' => ['btbl-page-length-row', 'btbl-length-menu-row'],
			'lengthMenuSuffix' => ['btbl-page-length-row', 'btbl-length-menu-row'],
			'paginateFirst' => ['btbl-pagination-label-row'],
			'paginatePrevious' => ['btbl-pagination-label-row'],
			'paginateNext' => ['btbl-pagination-label-row'],
			'paginateLast' => ['btbl-pagination-label-row'],
			'searchText' => ['btbl-search-setting-row'],
			'searchPlaceholder' => ['btbl-search-setting-row', 'btbl-search-placeholder-row'],
			'searchColumns' => ['btbl-search-columns-flag'],
			'searchColumnsLabel' => ['btbl-search-setting-row', 'btbl-search-columns-setting'],
			'searchColumnsHeading' => ['btbl-search-setting-row', 'btbl-search-columns-setting'],
			'filtersTitleText' => ['btbl-filters-title-setting'],
			'infoText' => ['btbl-info-setting'],
			'infoEmpty' => ['btbl-info-setting'],
			'infoFiltered' => ['btbl-info-setting'],
		];
		foreach ($editor_classes as $key => $classes) {
			$schema[$key]['editor_classes'] = $classes;
		}

		$dependencies = [
			'pageLength' => ['paging' => true],
			'lengthChange' => ['paging' => true],
			'pagingNumbers' => ['paging' => true],
			'pagingFirstLast' => ['paging' => true],
			'pagingPreviousNext' => ['paging' => true],
			'lengthMenuPrefix' => ['paging' => true, 'lengthChange' => true],
			'lengthMenuSuffix' => ['paging' => true, 'lengthChange' => true],
			'paginateFirst' => ['paging' => true, 'pagingFirstLast' => true],
			'paginateLast' => ['paging' => true, 'pagingFirstLast' => true],
			'paginatePrevious' => ['paging' => true, 'pagingPreviousNext' => true],
			'paginateNext' => ['paging' => true, 'pagingPreviousNext' => true],
			'searchText' => ['searchBox' => true],
			'searchPlaceholder' => ['searchBox' => true],
			'searchColumns' => ['searchBox' => true],
			'searchColumnsLabel' => ['searchBox' => true, 'searchColumns' => true],
			'searchColumnsHeading' => ['searchBox' => true, 'searchColumns' => true],
			'filtersTitleText' => ['filtersTitle' => true],
			'infoText' => ['info' => true],
			'infoEmpty' => ['info' => true],
			'infoFiltered' => ['info' => true],
		];
		foreach ($dependencies as $key => $conditions) {
			$schema[$key]['editor_visible_when'] = $conditions;
		}
		foreach (['lengthChange', 'searchColumns'] as $key) {
			$schema[$key]['editor_reset_when_hidden'] = true;
		}
		foreach (['lengthChange', 'searchColumns', 'pagingNumbers', 'pagingFirstLast', 'pagingPreviousNext'] as $key) {
			$schema[$key]['editor_restore_default'] = true;
		}

		$layout_features = [
			'pagelength' => __('Page length', 'baratables'),
			'buttons' => __('Buttons', 'baratables'),
			'search' => __('Search', 'baratables'),
			'info' => __('Result summary', 'baratables'),
			'paging' => __('Pagination', 'baratables'),
		];
		$layout_zone_labels = [
			'layoutTopStart' => __('Top left', 'baratables'),
			'layoutTopEnd' => __('Top right', 'baratables'),
			'layoutBottomStart' => __('Bottom left', 'baratables'),
			'layoutBottomEnd' => __('Bottom right', 'baratables'),
		];
		foreach ($layout_zone_labels as $key => $short_label) {
			$schema[$key]['editor_group'] = 'layout';
			$schema[$key]['editor_label'] = $short_label;
			$schema[$key]['choices'] = $layout_features;
		}

		$button_text_keys = [
			'copy' => 'buttonTextCopy',
			'csv' => 'buttonTextCsv',
			'excel' => 'buttonTextExcel',
			'print' => 'buttonTextPrint',
			'colvis' => 'buttonTextColvis',
			'pagelength' => 'buttonTextPagelength',
		];
		$schema['buttons']['editor_group'] = 'buttons';
		$schema['buttons']['choice_dependencies'] = [
			'copy' => null,
			'csv' => null,
			'excel' => null,
			'print' => null,
			'colvis' => null,
			'pagelength' => 'lengthChange',
		];
		foreach ($layout_zone_labels as $key => $_short_label) {
			$schema[$key]['choice_dependencies'] = [
				'search' => 'searchBox',
				'pagelength' => 'lengthChange',
				'info' => 'info',
				'paging' => 'paging',
				'buttons' => 'buttons',
			];
		}
		$schema['buttons']['choice_text_options'] = $button_text_keys;
		foreach ($button_text_keys as $key) {
			$schema[$key]['editor_group'] = 'button_text';
		}

		foreach ($schema as $key => &$config) {
			if (($config['type'] ?? '') === 'checkbox' && empty($config['editor_group'])) {
				$config['editor_group'] = 'controls';
			}
		}
		unset($config);

		$schema_with_labels = $schema;
		return $schema_with_labels;
	}

	private function get_table_option_defaults(): array {
		$defaults = [];
		foreach (self::get_table_option_schema() as $key => $config) {
			$defaults[$key] = $config['default'];
		}
		return $defaults;
	}

	public function get_non_sortable_indices(array $definition): array {
		return $this->collect_column_indices($definition, static function ($col): bool {
			return isset($col['sortable']) && $col['sortable'] === false;
		});
	}

	private function build_tax_query(array $definition): array {
		$filters = BaraTables_Taxonomy_Filters::normalize($definition['taxonomy_filter'] ?? []);
		if (empty($filters)) {
			return [];
		}
		$tax_queries = [];
		foreach ($filters as $filter) {
			$taxonomy = isset($filter['taxonomy']) ? sanitize_key($filter['taxonomy']) : '';
			$terms = isset($filter['terms']) ? array_values(array_unique(array_filter(array_map('intval', (array) $filter['terms'])))) : [];
			if ($taxonomy === '' || empty($terms)) {
				continue;
			}
			$field = isset($filter['field']) && in_array($filter['field'], ['term_id', 'slug', 'name'], true)
				? $filter['field']
				: 'term_id';
			$operator = isset($filter['operator']) && in_array($filter['operator'], ['IN', 'NOT IN', 'AND'], true)
				? $filter['operator']
				: 'IN';
			$tax_queries[] = [
				'taxonomy' => $taxonomy,
				'field'    => $field,
				'terms'    => $terms,
				'operator' => $operator,
			];
		}
		if (empty($tax_queries)) {
			return [];
		}
		if (count($tax_queries) === 1) {
			return [$tax_queries[0]];
		}
		return array_merge(['relation' => 'AND'], $tax_queries);
	}

	/**
	 * WP_Query OR-group that admits every "public"/token-less post so the authoritative per-row
	 * post_passes_access_policy() can see it: the access meta is absent, an empty string, or an
	 * empty serialized array (a blank multi-select stored as array() => 'a:0:{}'). Without the
	 * 'a:0:{}' arm, such a post is excluded by the pre-filter before the per-row check runs, so a
	 * row the access model considers public is silently hidden.
	 */
	private function public_token_meta_clause(string $meta_key): array {
		return [
			'relation' => 'OR',
			[
				'key' => $meta_key,
				'compare' => 'NOT EXISTS',
			],
			[
				'key' => $meta_key,
				'value' => '',
				'compare' => '=',
			],
			[
				'key' => $meta_key,
				'value' => 'a:0:{}',
				'compare' => '=',
			],
		];
	}

	private function build_access_meta_query(string $meta_key, array $access_policy) {
		$meta_key = sanitize_text_field($meta_key);
		if ($meta_key === '') {
			return [];
		}
		$logged_out_policy = $access_policy['logged_out_policy'] ?? 'public_only';
		$user_tokens = $access_policy['user_tokens'] ?? [];
		$is_logged_in = is_user_logged_in();
		$allow_public = $logged_out_policy !== 'none';

		if (!$is_logged_in) {
			if ($logged_out_policy === 'none') {
				return 'none';
			}
			if ($logged_out_policy === 'public_only') {
				return [
					$this->public_token_meta_clause($meta_key),
				];
			}
			return [];
		}

		$clauses = [];
		foreach ($user_tokens as $token) {
			$token = (string) $token;
			if ($token === '') {
				continue;
			}
			$clauses[] = [
				'key' => $meta_key,
				'value' => $token,
				'compare' => 'LIKE',
			];
			$clauses[] = [
				'key' => $meta_key,
				'value' => '"' . $token . '"',
				'compare' => 'LIKE',
			];
		}

		if (empty($clauses)) {
			if ($allow_public) {
				return [
					$this->public_token_meta_clause($meta_key),
				];
			}
			return 'none';
		}

		$or_group = array_merge(['relation' => 'OR'], $clauses);

		$meta_query = [
			'relation' => 'OR',
		];
		if ($allow_public) {
			$meta_query[] = $this->public_token_meta_clause($meta_key);
		}
		$meta_query[] = $or_group;
		return $meta_query;
	}

	private function append_meta_query(array $query_args, array $meta_query): array {
		if (empty($meta_query)) {
			return $query_args;
		}
		if (!empty($query_args['meta_query']) && is_array($query_args['meta_query'])) {
			$query_args['meta_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Required to combine configured filters with access-policy filtering.
				'relation' => 'AND',
				$query_args['meta_query'],
				$meta_query,
			];
			return $query_args;
		}
		$query_args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Required for access-policy filtering.
		return $query_args;
	}

	private function filter_rows_by_access(array $rows, callable $token_resolver, array $access_policy): array {
		$logged_out_policy = $access_policy['logged_out_policy'] ?? 'public_only';
		$user_tokens = $access_policy['user_tokens'] ?? [];
		return array_values(array_filter($rows, function ($row) use ($token_resolver, $user_tokens, $logged_out_policy) {
			$raw_tokens = $token_resolver($row);
			$tokens = $this->normalize_tokens($raw_tokens);
			return $this->passes_access_tokens($tokens, $user_tokens, $logged_out_policy);
		}));
	}

	private function get_user_tokens(string $user_meta_key): array {
		if (!is_user_logged_in()) {
			return [];
		}
		$user_meta_key = sanitize_text_field($user_meta_key);
		if ($user_meta_key === '') {
			$user = wp_get_current_user();
			return is_array($user->roles) ? array_values(array_map('strval', $user->roles)) : [];
		}
		$value = get_user_meta(get_current_user_id(), $user_meta_key, true);
		return $this->normalize_tokens($value);
	}

}
