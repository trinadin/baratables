<?php

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Import controller: collects an uploaded/pasted table export, hands it to BaraTables_Importer
 * for format detection + mapping, previews the result, and creates a BaraTables table from it.
 *
 * The class name is kept for backward compatibility (it is referenced as BaraTables_Admin_Options
 * elsewhere); all format-specific logic lives in includes/admin/import.php.
 */
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
			__('Import a Table', 'baratables'),
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
		$analysis = null;
		$import_payload_raw = '';
		$import_filename = '';

		$action = !empty($_POST['btbl_options_action']) ? sanitize_key(wp_unslash($_POST['btbl_options_action'])) : '';
		$is_analyze = $action === 'import_analyze';
		$is_create = $action === 'import_create';

		if ($is_analyze || $is_create) {
			check_admin_referer('btbl_options_import', '_btbl_options_nonce');

			$payloads = $this->collect_import_payload();
			$json_raw = $payloads['raw'];
			$import_filename = $payloads['filename'];

			if (!empty($payloads['error'])) {
				$errors[] = $payloads['error'];
			}

			if (empty($errors) && $json_raw === '') {
				$errors[] = __('Please choose an export file or paste its contents.', 'baratables');
			}

			if (empty($errors)) {
				$analysis = BaraTables_Importer::analyze($json_raw, $import_filename, $this->service);
				if (empty($analysis['ok'])) {
					$errors[] = $analysis['message'] !== ''
						? $analysis['message']
						: __('The file was not recognized as a supported table export.', 'baratables');
					$analysis = null;
				}
			}

			if (empty($errors) && $is_create && $analysis && !empty($analysis['definitions'])) {
				$result = $this->persist_definition($analysis['definitions'][0]);
				if (!empty($result['error'])) {
					$errors[] = $result['error'];
				} elseif (!empty($result['post_id'])) {
					BaraTables_Admin_Notice::queue(
						__('Table imported successfully. Review the columns, then update the table to save any changes.', 'baratables'),
						'success'
					);
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
			<h1><?php esc_html_e('Import a Table', 'baratables'); ?></h1>
			<p class="description"><?php esc_html_e('Import a table export from another table plugin to create a BaraTables table. Supported files: a JSON or XML table export, or a CSV spreadsheet (a header row followed by data rows). Charts are not imported.', 'baratables'); ?></p>
			<?php foreach ($errors as $message) : ?>
				<div class="notice notice-error is-dismissible"><p><?php echo esc_html($message); ?></p></div>
			<?php endforeach; ?>
			<form method="post" enctype="multipart/form-data" autocomplete="off">
				<?php wp_nonce_field('btbl_options_import', '_btbl_options_nonce'); ?>
				<input type="hidden" name="btbl_options_action" value="<?php echo esc_attr($analysis ? 'import_create' : 'import_analyze'); ?>" />
				<?php if ($import_payload_raw !== '') : ?>
					<textarea name="btbl_import_payload" hidden><?php echo esc_textarea($import_payload_raw); ?></textarea>
					<input type="hidden" name="btbl_import_filename" value="<?php echo esc_attr($import_filename); ?>" />
				<?php endif; ?>
				<div class="btbl-control-grid">
						<div class="btbl-control">
							<label class="btbl-small-heading" for="btbl_import_file"><?php esc_html_e('Upload a table export', 'baratables'); ?></label>
							<input type="file" name="btbl_import_file" id="btbl_import_file" accept=".json,.xml,.csv,.txt,.xls,.xlsx,application/json,application/xml,text/csv,text/plain" />
							<p class="description"><?php esc_html_e('Accepts .json, .xml, or .csv (max 5 MB).', 'baratables'); ?></p>
						</div>
					<div class="btbl-control">
						<label class="btbl-small-heading" for="btbl_import_json"><?php esc_html_e('Or paste the export', 'baratables'); ?></label>
						<textarea name="btbl_import_json" id="btbl_import_json" class="large-text code" rows="8" placeholder="<?php esc_attr_e('Paste the export contents here…', 'baratables'); ?>"></textarea>
						<p class="description"><?php esc_html_e('If both are provided, the uploaded file wins.', 'baratables'); ?></p>
					</div>
				</div>
				<?php if ($analysis) : $preview = $analysis['previews'][0]; ?>
					<div class="btbl-control">
						<?php // translators: %s is the name of the table being imported. ?>
						<h3><?php echo esc_html(sprintf(__('Import preview: %s', 'baratables'), $preview['title'])); ?></h3>
						<ul>
							<?php // translators: %s is the data source type, e.g. Manual data or WordPress query. ?>
							<li><?php echo esc_html(sprintf(__('Source: %s', 'baratables'), $preview['data_type'])); ?></li>
							<?php // translators: %d is the number of columns. ?>
							<li><?php echo esc_html(sprintf(__('Columns: %d', 'baratables'), $preview['column_count'])); ?></li>
							<?php if ($preview['row_count'] !== null) : ?>
								<?php // translators: %d is the number of rows. ?>
								<li><?php echo esc_html(sprintf(__('Rows: %d', 'baratables'), $preview['row_count'])); ?></li>
							<?php endif; ?>
							<?php // translators: %s is the rows per page setting. ?>
							<li><?php echo esc_html(sprintf(__('Rows per page: %s', 'baratables'), $preview['per_page'])); ?></li>
							<?php // translators: %s is Yes or No. ?>
							<li><?php echo esc_html(sprintf(__('Search enabled: %s', 'baratables'), $preview['search_enabled'] ? __('Yes', 'baratables') : __('No', 'baratables'))); ?></li>
							<?php // translators: %s is Yes or No. ?>
							<li><?php echo esc_html(sprintf(__('Ordering enabled: %s', 'baratables'), $preview['ordering_enabled'] ? __('Yes', 'baratables') : __('No', 'baratables'))); ?></li>
						</ul>
						<?php if (!empty($preview['columns'])) : ?>
							<p><strong><?php esc_html_e('Column labels:', 'baratables'); ?></strong> <?php echo esc_html(implode(', ', $preview['columns'])); ?></p>
						<?php endif; ?>
						<?php if (!empty($analysis['warnings'])) : ?>
							<div class="notice notice-warning inline">
								<p><strong><?php esc_html_e('Notes:', 'baratables'); ?></strong></p>
								<ul style="list-style: disc; margin-left: 1.5em;">
									<?php foreach ($analysis['warnings'] as $warning) : ?>
										<li><?php echo esc_html($warning); ?></li>
									<?php endforeach; ?>
								</ul>
							</div>
						<?php endif; ?>
						<p class="description"><?php esc_html_e('Review the details above and click Create to finish importing.', 'baratables'); ?></p>
					</div>
				<?php endif; ?>
				<p class="btbl-submit-row">
					<?php if ($analysis) : ?>
						<button type="submit" class="button button-primary"><?php esc_html_e('Create Table from Import', 'baratables'); ?></button>
						<button type="submit" class="button" name="btbl_options_action" value="import_analyze"><?php esc_html_e('Re-analyze', 'baratables'); ?></button>
					<?php else : ?>
						<button type="submit" class="button button-primary"><?php esc_html_e('Analyze Import', 'baratables'); ?></button>
					<?php endif; ?>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Insert a CPT post for the definition, stamp its slug as the table id, and persist the meta.
	 *
	 * @return array{id:string,post_id:int}|array{error:string}
	 */
	private function persist_definition(array $definition): array {
		$name = isset($definition['name']) && $definition['name'] !== ''
			? (string) $definition['name']
			: __('Imported Table', 'baratables');
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

		return ['id' => $table_id, 'post_id' => (int) $post_id];
	}

	/**
	 * Gather the raw import text from (in priority order) an uploaded file, a re-submitted hidden
	 * payload, or the paste textarea, with the same size/type guards as before.
	 *
	 * @return array{raw:string,filename:string,error:string}
	 */
	private function collect_import_payload(): array {
		$raw = '';
		$filename = '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by caller (render_page).
		$tmp_file = isset($_FILES['btbl_import_file']['tmp_name']) ? sanitize_text_field(wp_unslash($_FILES['btbl_import_file']['tmp_name'])) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by caller (render_page).
		$file_name = isset($_FILES['btbl_import_file']['name']) ? sanitize_file_name(wp_unslash($_FILES['btbl_import_file']['name'])) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified by caller; upload error is server-provided metadata.
		$upload_error = isset($_FILES['btbl_import_file']['error']) ? (int) $_FILES['btbl_import_file']['error'] : UPLOAD_ERR_NO_FILE;
		if ($upload_error !== UPLOAD_ERR_NO_FILE) {
			if ($upload_error !== UPLOAD_ERR_OK || $tmp_file === '' || !is_uploaded_file($tmp_file)) {
				return ['raw' => '', 'filename' => '', 'error' => __('Could not read the uploaded import file.', 'baratables')];
			}
			if (!$this->is_valid_import_upload($file_name)) {
				return ['raw' => '', 'filename' => '', 'error' => __('Import uploads must be a .json, .xml, or .csv file.', 'baratables')];
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified by caller; upload size is server-provided metadata.
			$file_size = isset($_FILES['btbl_import_file']['size']) ? (int) $_FILES['btbl_import_file']['size'] : 0;
			if ($file_size > self::MAX_IMPORT_BYTES) {
				return ['raw' => '', 'filename' => '', 'error' => $this->get_import_size_error()];
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading an uploaded temp file; wp_remote_get() is for remote URLs.
			$raw = (string) file_get_contents($tmp_file);
			if (strlen($raw) > self::MAX_IMPORT_BYTES) {
				return ['raw' => '', 'filename' => '', 'error' => $this->get_import_size_error()];
			}
			$filename = $file_name;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by caller (render_page).
		if ($raw === '' && !empty($_POST['btbl_import_payload'])) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified by caller. Parsed by the importer; not echoed raw.
			$raw = (string) wp_unslash($_POST['btbl_import_payload']);
			if (strlen($raw) > self::MAX_IMPORT_BYTES) {
				return ['raw' => '', 'filename' => '', 'error' => $this->get_import_size_error()];
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by caller.
			if (!empty($_POST['btbl_import_filename'])) {
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by caller.
				$filename = sanitize_file_name(wp_unslash($_POST['btbl_import_filename']));
			}
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by caller (render_page).
		if ($raw === '' && !empty($_POST['btbl_import_json'])) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified by caller. Parsed by the importer; not echoed raw.
			$raw = (string) wp_unslash($_POST['btbl_import_json']);
			if (strlen($raw) > self::MAX_IMPORT_BYTES) {
				return ['raw' => '', 'filename' => '', 'error' => $this->get_import_size_error()];
			}
		}
		$raw = trim($raw);
		return ['raw' => $raw, 'filename' => $filename, 'error' => ''];
	}

	private function is_valid_import_upload(string $file_name): bool {
		if ($file_name === '') {
			return false;
		}
		$allowed = [
			'json' => 'application/json',
			'xml' => 'application/xml',
			'csv' => 'text/csv',
			'txt' => 'text/plain',
			'xls' => 'application/vnd.ms-excel',
			'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		];
		$file_type = wp_check_filetype($file_name, $allowed);
		return in_array($file_type['ext'] ?? '', array_keys($allowed), true);
	}

	private function get_import_size_error(): string {
		return sprintf(
			/* translators: %s is the maximum import file size. */
			__('Import file is too large. Maximum size is %s.', 'baratables'),
			size_format(self::MAX_IMPORT_BYTES)
		);
	}
}
