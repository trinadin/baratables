<?php

if (!defined('ABSPATH')) {
	exit;
}

class BaraTables_Admin_Options {
	public const PAGE_SLUG = 'baratables-options';
	private const MAX_IMPORT_BYTES = 5242880;
	private const MAX_IMPORT_DEPTH = 64;
	private BaraTables_Service $service;

	public function __construct(BaraTables_Service $service) {
		$this->service = $service;
	}

	public function register_menu(): void {
		$parent = 'edit.php?post_type=' . BaraTables_Repository::CPT;
		add_submenu_page(
			$parent,
			__('Import', 'baratables'),
			__('Import', 'baratables'),
			'manage_options',
			self::PAGE_SLUG,
			[$this, 'render_page']
		);
	}

	public function render_page(): void {
		if (!current_user_can('manage_options')) {
			return;
		}
		$errors = [];
		$import_preview = null;
		$import_payload_raw = '';

		$action = !empty($_POST['btbl_options_action']) ? sanitize_key(wp_unslash($_POST['btbl_options_action'])) : '';

		if ($action === 'import_ninja' || $action === 'import_create') {
			check_admin_referer('btbl_options_import', '_btbl_options_nonce');

			$payloads = $this->collect_import_payload();
			$json_raw = $payloads['raw'];
			$decoded = $payloads['decoded'];

			if (!empty($payloads['error'])) {
				$errors[] = $payloads['error'];
			}

			if (empty($errors) && $json_raw === '') {
				$errors[] = __('Please provide an export JSON file or paste its contents.', 'baratables');
			}

			if (empty($errors) && !is_array($decoded)) {
				$errors[] = __('Could not decode JSON. Please check the file contents.', 'baratables');
			}

			if (empty($errors)) {
				$import_preview = $this->build_preview($decoded);
				if (!$import_preview) {
					$errors[] = __('JSON parsed, but no recognizable table export data was found.', 'baratables');
				}
			}

			if (empty($errors) && $action === 'import_create') {
				$result = $this->map_and_save_definition($decoded);
				if (!empty($result['error'])) {
					$errors[] = $result['error'];
				} elseif (!empty($result['post_id'])) {
					$edit_link = get_edit_post_link((int) $result['post_id'], '');
					if ($edit_link) {
						$redirect = add_query_arg(['imported' => '1'], $edit_link);
					} else {
						$redirect = add_query_arg(['post_type' => BaraTables_Repository::CPT, 'imported' => '1'], admin_url('edit.php'));
					}
					wp_safe_redirect($redirect);
					exit;
				}
			}

			if ($json_raw !== '') {
				$import_payload_raw = $json_raw;
			}
		}

		?>
		<div class="wrap btbl-admin">
			<h1><?php esc_html_e('Import', 'baratables'); ?></h1>
			<?php foreach ($errors as $message) : ?>
				<div class="notice notice-error is-dismissible"><p><?php echo esc_html($message); ?></p></div>
			<?php endforeach; ?>
			<form method="post" enctype="multipart/form-data" autocomplete="off">
				<?php wp_nonce_field('btbl_options_import', '_btbl_options_nonce'); ?>
				<input type="hidden" name="btbl_options_action" value="<?php echo esc_attr($import_preview ? 'import_create' : 'import_ninja'); ?>" />
				<?php if ($import_payload_raw !== '') : ?>
					<textarea name="btbl_import_payload" hidden><?php echo esc_textarea($import_payload_raw); ?></textarea>
				<?php endif; ?>
				<div class="btbl-control-grid">
						<div class="btbl-control">
							<label class="btbl-small-heading" for="btbl_import_file"><?php esc_html_e('Upload export JSON', 'baratables'); ?></label>
							<input type="file" name="btbl_import_file" id="btbl_import_file" accept=".json,application/json" />
							<p class="description"><?php esc_html_e('Choose a table export (.json).', 'baratables'); ?></p>
						</div>
					<div class="btbl-control">
						<label class="btbl-small-heading" for="btbl_import_json"><?php esc_html_e('Or paste JSON', 'baratables'); ?></label>
						<textarea name="btbl_import_json" id="btbl_import_json" class="large-text code" rows="8" placeholder="<?php esc_attr_e('Paste the export JSON here…', 'baratables'); ?>"></textarea>
						<p class="description"><?php esc_html_e('If both are provided, the uploaded file wins.', 'baratables'); ?></p>
					</div>
				</div>
				<?php if ($import_preview) : ?>
					<div class="btbl-control">
						<?php // translators: %s is the name of the table being imported. ?>
						<h3><?php echo esc_html(sprintf(__('Import preview: %s', 'baratables'), $import_preview['title'])); ?></h3>
						<ul>
							<?php // translators: %d is the number of columns. ?>
							<li><?php echo esc_html(sprintf(__('Columns: %d', 'baratables'), $import_preview['column_count'])); ?></li>
							<?php // translators: %s is the rows per page setting. ?>
							<li><?php echo esc_html(sprintf(__('Rows per page: %s', 'baratables'), $import_preview['per_page'])); ?></li>
							<?php // translators: %s is Yes or No. ?>
							<li><?php echo esc_html(sprintf(__('Search enabled: %s', 'baratables'), $import_preview['search_enabled'] ? __('Yes', 'baratables') : __('No', 'baratables'))); ?></li>
							<?php // translators: %s is Yes or No. ?>
							<li><?php echo esc_html(sprintf(__('Ordering enabled: %s', 'baratables'), $import_preview['ordering_enabled'] ? __('Yes', 'baratables') : __('No', 'baratables'))); ?></li>
						</ul>
						<?php if (!empty($import_preview['columns'])) : ?>
							<p><strong><?php esc_html_e('Column labels:', 'baratables'); ?></strong> <?php echo esc_html(implode(', ', $import_preview['columns'])); ?></p>
						<?php endif; ?>
						<p class="description"><?php esc_html_e('Review the mapping below and click Create to finish importing.', 'baratables'); ?></p>
					</div>
				<?php endif; ?>
				<p class="btbl-submit-row">
					<?php if ($import_preview) : ?>
						<button type="submit" class="button button-primary"><?php esc_html_e('Create Table from Import', 'baratables'); ?></button>
						<button type="submit" class="button" name="btbl_options_action" value="import_ninja"><?php esc_html_e('Re-analyze', 'baratables'); ?></button>
					<?php else : ?>
						<button type="submit" class="button button-primary"><?php esc_html_e('Analyze Import', 'baratables'); ?></button>
					<?php endif; ?>
				</p>
			</form>
		</div>
		<?php
	}

	private function build_preview(array $decoded): ?array {
		$title = isset($decoded['post']['post_title']) ? (string) $decoded['post']['post_title'] : '';
		$columns = [];
		if (!empty($decoded['columns']) && is_array($decoded['columns'])) {
			foreach ($decoded['columns'] as $col) {
				if (isset($col['name']) && $col['name'] !== '') {
					$columns[] = (string) $col['name'];
				}
			}
		}
		$settings = isset($decoded['settings']) && is_array($decoded['settings']) ? $decoded['settings'] : [];
		$per_page = isset($settings['perPage']) ? (string) $settings['perPage'] : '';
		$search_enabled = !empty($settings['enable_search']) && (string) $settings['enable_search'] !== '0';
		$ordering_enabled = !empty($settings['column_sorting']) && (string) $settings['column_sorting'] !== '0';

		if ($title === '' && empty($columns)) {
			return null;
		}

		return [
			'title' => $title !== '' ? $title : __('Untitled import', 'baratables'),
			'column_count' => count($columns),
			'columns' => $columns,
			'per_page' => $per_page !== '' ? $per_page : __('Default', 'baratables'),
			'search_enabled' => $search_enabled,
			'ordering_enabled' => $ordering_enabled,
		];
	}

	private function collect_import_payload(): array {
		$json_raw = '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by caller (handle_import_action).
		$tmp_file = isset($_FILES['btbl_import_file']['tmp_name']) ? sanitize_text_field(wp_unslash($_FILES['btbl_import_file']['tmp_name'])) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by caller (handle_import_action).
		$file_name = isset($_FILES['btbl_import_file']['name']) ? sanitize_file_name(wp_unslash($_FILES['btbl_import_file']['name'])) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified by caller; upload error is server-provided metadata.
		$upload_error = isset($_FILES['btbl_import_file']['error']) ? (int) $_FILES['btbl_import_file']['error'] : UPLOAD_ERR_NO_FILE;
		if ($upload_error !== UPLOAD_ERR_NO_FILE) {
			if ($upload_error !== UPLOAD_ERR_OK || $tmp_file === '' || !is_uploaded_file($tmp_file)) {
				return [
					'raw' => '',
					'decoded' => null,
					'error' => __('Could not read the uploaded import file.', 'baratables'),
				];
			}
			if (!$this->is_valid_import_upload($file_name)) {
				return [
					'raw' => '',
					'decoded' => null,
					'error' => __('Import uploads must be JSON files.', 'baratables'),
				];
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified by caller; upload size is server-provided metadata.
			$file_size = isset($_FILES['btbl_import_file']['size']) ? (int) $_FILES['btbl_import_file']['size'] : 0;
			if ($file_size > self::MAX_IMPORT_BYTES) {
				return [
					'raw' => '',
					'decoded' => null,
					'error' => $this->get_import_size_error(),
				];
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading an uploaded JSON temp file; wp_remote_get() is for remote URLs.
			$json_raw = (string) file_get_contents($tmp_file);
			if (strlen($json_raw) > self::MAX_IMPORT_BYTES) {
				return [
					'raw' => '',
					'decoded' => null,
					'error' => $this->get_import_size_error(),
				];
			}
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by caller (handle_import_action).
		if ($json_raw === '' && !empty($_POST['btbl_import_payload'])) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified by caller (handle_import_action). JSON string is parsed via json_decode; not used as raw output.
			$json_raw = (string) wp_unslash($_POST['btbl_import_payload']);
			if (strlen($json_raw) > self::MAX_IMPORT_BYTES) {
				return [
					'raw' => '',
					'decoded' => null,
					'error' => $this->get_import_size_error(),
				];
			}
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by caller (handle_import_action).
		if ($json_raw === '' && !empty($_POST['btbl_import_json'])) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified by caller (handle_import_action). JSON string parsed via json_decode; not used as raw output.
			$json_raw = (string) wp_unslash($_POST['btbl_import_json']);
			if (strlen($json_raw) > self::MAX_IMPORT_BYTES) {
				return [
					'raw' => '',
					'decoded' => null,
					'error' => $this->get_import_size_error(),
				];
			}
		}
		$json_raw = trim($json_raw);
		$decoded = $json_raw !== '' ? json_decode($json_raw, true, self::MAX_IMPORT_DEPTH) : null;
		return [
			'raw' => $json_raw,
			'decoded' => $decoded,
			'error' => '',
		];
	}

	private function is_valid_import_upload(string $file_name): bool {
		if ($file_name === '') {
			return false;
		}
		$file_type = wp_check_filetype($file_name, ['json' => 'application/json']);
		return ($file_type['ext'] ?? '') === 'json';
	}

	private function get_import_size_error(): string {
		return sprintf(
			/* translators: %s is the maximum import file size. */
			__('Import JSON is too large. Maximum size is %s.', 'baratables'),
			size_format(self::MAX_IMPORT_BYTES)
		);
	}

	private function map_and_save_definition(array $export): array {
		$columns = $export['columns'] ?? [];
		if (!is_array($columns) || empty($columns)) {
			return ['error' => __('No columns found to import.', 'baratables')];
		}
		$settings = isset($export['settings']) && is_array($export['settings']) ? $export['settings'] : [];
		$title = isset($export['post']['post_title']) ? sanitize_text_field((string) $export['post']['post_title']) : '';
		$name = $title !== '' ? $title : __('Imported Table', 'baratables');
		$table_id = '';

		$table_options = $this->service->get_default_table_options();
		if (isset($settings['perPage'])) {
			$per_page = (int) $settings['perPage'];
			if ($per_page > 0) {
				$table_options['pageLength'] = $per_page;
			}
		}
		if (array_key_exists('enable_search', $settings)) {
			$table_options['searchBox'] = !empty($settings['enable_search']) && (string) $settings['enable_search'] !== '0';
		}
		if (array_key_exists('column_sorting', $settings)) {
			$table_options['ordering'] = !empty($settings['column_sorting']) && (string) $settings['column_sorting'] !== '0';
		}
		if (array_key_exists('show_all', $settings)) {
			$table_options['paging'] = empty($settings['show_all']) || (string) $settings['show_all'] === '0';
		}

		$mapped_columns = [];
		$column_key_map = [];
		$sorting_column = '';
		$sorting_direction = 'asc';
		$sorting_type = '';
		if (isset($settings['sorting_column'])) {
			$sorting_column = sanitize_key((string) $settings['sorting_column']);
		}
		if (isset($settings['sorting_column_by'])) {
			$sorting_direction = $this->normalize_sort_direction($settings['sorting_column_by'], 'asc');
		}
		if (isset($settings['sorting_type'])) {
			$sorting_type = sanitize_key((string) $settings['sorting_type']);
		}
		if ($sorting_type !== '' && $sorting_type !== 'by_column') {
			$sorting_column = '';
		}

		foreach ($columns as $col) {
			if (!is_array($col) || empty($col['name'])) {
				continue;
			}
			$label = sanitize_text_field((string) $col['name']);
			$source = 'core';
			$key = '';
			$source_type = isset($col['source_type']) ? sanitize_key((string) $col['source_type']) : '';
			if ($source_type === 'custom') {
				$source = 'meta';
				if (!empty($col['wp_post_custom_data_value'])) {
					$key = sanitize_key((string) $col['wp_post_custom_data_value']);
				}
			} elseif ($source_type === 'tax_data') {
				$source = 'tax';
				if (!empty($col['original_name'])) {
					$key = (string) $col['original_name'];
				} elseif (!empty($col['key'])) {
					$key = (string) $col['key'];
				}
				$key = preg_replace('/^post\\./', '', (string) $key);
				$key = sanitize_key((string) $key);
			} else {
				if (!empty($col['original_name'])) {
					$key = sanitize_key((string) $col['original_name']);
				} elseif (!empty($col['key'])) {
					$key = sanitize_key((string) $col['key']);
				} else {
					$key = sanitize_key($label);
				}
			}
			if ($key === '') {
				$key = 'col_' . (count($mapped_columns) + 1);
			}
			$slug = BaraTables_Service::build_slug($source, $key);
			$is_date = isset($col['data_type']) && $col['data_type'] === 'date';
			$date_format_raw = isset($col['dateFormat']) ? (string) $col['dateFormat'] : '';
			$time_format_raw = isset($col['timeFormat']) ? (string) $col['timeFormat'] : '';
			$date_format = $this->convert_ninja_date_format($date_format_raw);
			$time_format = $this->convert_ninja_time_format($time_format_raw);
			$show_time = $this->parse_export_bool($col['showTime'] ?? false, false);
			if ($show_time && $time_format !== '') {
				$date_format = trim($date_format !== '' ? ($date_format . ' ' . $time_format) : $time_format);
			}
			$breakpoints = isset($col['breakpoints']) ? strtolower(trim((string) $col['breakpoints'])) : '';
			$hidden = $breakpoints !== '' && preg_match('/\\bhidden\\b/', $breakpoints);
			$unsortable = $this->parse_export_bool($col['unsortable'] ?? false, false);
			$mapped_columns[] = [
				'key' => $key,
				'label' => $label,
				'filter' => 'none',
				'filter_sort' => 'asc',
				'slug' => $slug,
				'source' => $source,
				'hide_title' => !empty($col['classes']) && strpos((string) $col['classes'], 'hide-title') !== false,
				'hidden' => $hidden,
				'searchable' => true,
				'sort_priority' => 0,
				'sort_direction' => 'asc',
				'sort_enabled' => false,
				'sortable' => !$unsortable,
				'filter_values' => [],
				'format_date' => $is_date || $date_format !== '',
				'date_format' => $date_format,
			];
			$col_index = count($mapped_columns) - 1;
			$column_key_map[$key] = $col_index;
			if (!empty($col['key'])) {
				$column_key_map[sanitize_key((string) $col['key'])] = $col_index;
			}
			if (!empty($col['original_name'])) {
				$column_key_map[sanitize_key((string) $col['original_name'])] = $col_index;
			}
		}

		if (empty($mapped_columns)) {
			return ['error' => __('No valid columns were mapped from the export.', 'baratables')];
		}

		$post_types = [];
		$metas = $export['metas'] ?? [];
		if (!empty($metas['_ninja_table_wpposts_ds_post_types']) && is_array($metas['_ninja_table_wpposts_ds_post_types'])) {
			foreach ($metas['_ninja_table_wpposts_ds_post_types'] as $pt) {
				$clean = sanitize_key((string) $pt);
				if ($clean !== '') {
					$post_types[] = $clean;
				}
			}
		}
		if (empty($post_types)) {
			$post_types[] = 'post';
		}

		if (!empty($table_options['ordering']) && $sorting_column !== '' && isset($column_key_map[$sorting_column])) {
			$sort_idx = $column_key_map[$sorting_column];
			if (isset($mapped_columns[$sort_idx]) && !empty($mapped_columns[$sort_idx]['sortable'])) {
				$mapped_columns[$sort_idx]['sort_enabled'] = true;
				$mapped_columns[$sort_idx]['sort_priority'] = 1;
				$mapped_columns[$sort_idx]['sort_direction'] = $sorting_direction;
			}
		}

		$filter_order = [];
		$custom_filters = $metas['_ninja_table_custom_filters'] ?? [];
		if (is_array($custom_filters)) {
			foreach ($custom_filters as $filter) {
				if (!is_array($filter)) {
					continue;
				}
				$target_key = isset($filter['dynamic_select_column']) ? sanitize_key((string) $filter['dynamic_select_column']) : '';
				if ($target_key === '' && !empty($filter['columns']) && is_array($filter['columns'])) {
					$first = reset($filter['columns']);
					$target_key = sanitize_key((string) $first);
				}
				if ($target_key === '' || !isset($column_key_map[$target_key])) {
					continue;
				}
				$col_idx = $column_key_map[$target_key];
				if (!isset($mapped_columns[$col_idx])) {
					continue;
				}

				$filter_type = $this->map_filter_type($filter);
				if ($filter_type !== '') {
					$mapped_columns[$col_idx]['filter'] = $filter_type;
				}

				$filter_label = isset($filter['title']) ? sanitize_text_field((string) $filter['title']) : '';
				if ($filter_label !== '') {
					$mapped_columns[$col_idx]['filter_label'] = $filter_label;
				}

				$disable_auto = $this->parse_export_bool($filter['disable_auto_sorting'] ?? false, false);
				$filter_sort = $disable_auto ? 'custom' : $this->normalize_sort_direction($filter['sorting_type'] ?? 'asc', 'asc');
				$mapped_columns[$col_idx]['filter_sort'] = $filter_sort;
				$mapped_columns[$col_idx]['filter_strict'] = $this->parse_export_bool($filter['strict'] ?? false, false);

				$type_priority = $this->map_filter_type_priority($filter['sorting_method'] ?? '');
				if (!empty($type_priority)) {
					$mapped_columns[$col_idx]['filter_type_priority'] = $type_priority;
				}

				$select_value_type = isset($filter['select_value_type']) ? sanitize_key((string) $filter['select_value_type']) : '';
				if ($select_value_type === 'manual' && !empty($filter['options']) && is_array($filter['options'])) {
					$manual_values = [];
					foreach ($filter['options'] as $option) {
						if (!is_array($option)) {
							continue;
						}
						$label_opt = isset($option['label']) ? sanitize_text_field((string) $option['label']) : '';
						$value_opt = isset($option['value']) ? sanitize_text_field((string) $option['value']) : '';
						if ($label_opt === '' && $value_opt === '') {
							continue;
						}
						if ($value_opt === '') {
							$value_opt = $label_opt;
						}
						if ($label_opt === '') {
							$label_opt = $value_opt;
						}
						$search_terms = array_values(array_filter([$label_opt, $value_opt], static function ($item) {
							return $item !== '';
						}));
						$manual_values[] = [
							'label' => $label_opt,
							'value' => $value_opt,
							'search_terms' => $search_terms,
						];
					}
					if (!empty($manual_values)) {
						$mapped_columns[$col_idx]['filter_values'] = $manual_values;
					}
				}

				$filter_order[] = $mapped_columns[$col_idx]['slug'];
			}
		}

		$definition = [
			'id' => $table_id,
			'name' => $name,
			'post_type' => $post_types[0],
			'post_types' => $post_types,
			'source_type' => 'wp_query',
			'columns' => $mapped_columns,
			'table_options' => $table_options,
			'filter_order' => array_values(array_unique(array_filter($filter_order))),
			'status' => 'publish',
		];

		$post_id = wp_insert_post([
			'post_title' => $name,
			'post_type' => BaraTables_Repository::CPT,
			'post_status' => 'publish',
		], true);
		if (is_wp_error($post_id) || !$post_id) {
			return ['error' => __('Failed to create the imported table.', 'baratables')];
		}
		$post = get_post((int) $post_id);
		if (!$post) {
			return ['error' => __('Failed to create the imported table.', 'baratables')];
		}
		$table_id = (string) $post->post_name;
		if ($table_id === '') {
			return ['error' => __('Failed to generate a table slug for the import.', 'baratables')];
		}
		$definition['id'] = $table_id;

		BaraTables_Base_Repository::persist((int) $post_id, BaraTables_Repository::META_KEY, BaraTables_Repository::META_SLUG, $definition, $table_id);

		return ['id' => $table_id, 'post_id' => $post_id];
	}

	private function parse_export_bool($value, bool $default = false): bool {
		if (is_bool($value)) {
			return $value;
		}
		if (is_numeric($value)) {
			return (int) $value !== 0;
		}
		$clean = strtolower(trim((string) $value));
		if ($clean === '') {
			return $default;
		}
		if (in_array($clean, ['1', 'true', 'yes', 'y', 'on'], true)) {
			return true;
		}
		if (in_array($clean, ['0', 'false', 'no', 'n', 'off'], true)) {
			return false;
		}
		return $default;
	}

	private function normalize_sort_direction($value, string $default = 'asc'): string {
		$clean = sanitize_key((string) $value);
		return $clean === 'desc' ? 'desc' : $default;
	}

	private function map_filter_type(array $filter): string {
		$type = isset($filter['type']) ? sanitize_key((string) $filter['type']) : '';
		$is_multi = $this->parse_export_bool($filter['is_multi_select'] ?? false, false);
		if ($type === 'checkbox') {
			return 'checkbox';
		}
		if ($type === 'radio') {
			return 'radio';
		}
		if ($type === 'select' || $type === 'dropdown') {
			return $is_multi ? 'dropdown_multi' : 'dropdown';
		}
		return '';
	}

	private function map_filter_type_priority($method): array {
		$clean = sanitize_key((string) $method);
		if ($clean === '') {
			return [];
		}
		$map = [
			'numeric' => 'number',
			'number' => 'number',
			'date' => 'date',
			'text' => 'text',
			'string' => 'text',
		];
		if (!isset($map[$clean])) {
			return [];
		}
		return [
			[
				'type' => $map[$clean],
				'direction' => 'asc',
			],
		];
	}

	private function convert_ninja_date_format(string $format): string {
		return $this->convert_ninja_format($format, [
			'YYYY' => 'Y',
			'YY' => 'y',
			'MMMM' => 'F',
			'MMM' => 'M',
			'MM' => 'm',
			'M' => 'n',
			'DD' => 'd',
			'D' => 'j',
			'dddd' => 'l',
			'ddd' => 'D',
		]);
	}

	private function convert_ninja_time_format(string $format): string {
		return $this->convert_ninja_format($format, [
			'HH' => 'H',
			'H' => 'G',
			'hh' => 'h',
			'h' => 'g',
			'mm' => 'i',
			'm' => 'i',
			'ss' => 's',
			's' => 's',
			'A' => 'A',
			'a' => 'a',
		]);
	}

	private function convert_ninja_format(string $format, array $map): string {
		$format = (string) $format;
		if ($format === '') {
			return '';
		}

		$tokens = array_keys($map);
		usort($tokens, static function ($a, $b) {
			return strlen($b) <=> strlen($a);
		});

		$has_tokens = false;
		foreach ($tokens as $token) {
			if (strpos($format, $token) !== false) {
				$has_tokens = true;
				break;
			}
		}
		if (!$has_tokens) {
			return $format;
		}

		$parts = preg_split('/(\\[[^\\]]*\\])/', $format, -1, PREG_SPLIT_DELIM_CAPTURE);
		if ($parts === false) {
			$parts = [$format];
		}

		$out = '';
		foreach ($parts as $part) {
			if ($part === '') {
				continue;
			}
			if ($part[0] === '[' && substr($part, -1) === ']') {
				$literal = substr($part, 1, -1);
				$out .= $this->escape_php_date_literal($literal);
				continue;
			}
			$converted = $part;
			foreach ($tokens as $token) {
				$converted = str_replace($token, $map[$token], $converted);
			}
			$out .= $converted;
		}
		return $out;
	}

	private function escape_php_date_literal(string $text): string {
		if ($text === '') {
			return '';
		}
		$chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
		if ($chars === false) {
			return '';
		}
		$out = '';
		foreach ($chars as $char) {
			$out .= '\\' . $char;
		}
		return $out;
	}
}
