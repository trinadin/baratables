<?php

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Import controller: collects an uploaded/pasted table export, hands it to BaraTables_Importer
 * for format detection + mapping, previews the result, and creates a BaraTables table from it.
 *
 * Named BaraTables_Admin_Options for historical reasons; all format-specific logic lives in
 * includes/admin/import.php. The stable contract here is PAGE_SLUG, which is the admin URL.
 */
class BaraTables_Admin_Options {
	public const PAGE_SLUG = 'baratables-options';
	private const MAX_IMPORT_BYTES = 5242880;
	private BaraTables_Service $service;
	/** @var string[] Errors collected while handling the request, rendered by render_page(). */
	private array $errors = [];
	/** @var array<string,mixed>|null Importer analysis, or null when there is nothing to preview. */
	private ?array $analysis = null;
	private string $import_filename = '';
	/** Handoff key for the analyzed payload, so the Create step need not re-upload it. */
	private string $import_token = '';
	private const HANDOFF_PREFIX = 'btbl_import_handoff_';
	private const HANDOFF_TTL = 900; // 15 minutes: long enough to read a preview, short enough to expire.

	public function __construct(BaraTables_Service $service) {
		$this->service = $service;
	}

	public function register_menu(): void {
		$parent = 'edit.php?post_type=' . BaraTables_Repository::CPT;
		$hook = add_submenu_page(
			$parent,
			__('Import a Table', 'baratables'),
			__('Import', 'baratables'),
			'manage_options',
			self::PAGE_SLUG,
			[$this, 'render_page']
		);
		if ($hook) {
			// The POST must be handled on load-*, before a single byte is echoed. WordPress calls
			// render_page() from wp-admin/admin.php only after admin-header.php has emitted the
			// whole <head>, admin bar and menu, so a wp_safe_redirect() issued from the renderer
			// can never take effect and its exit() truncates the page before admin-footer.php.
			add_action('load-' . $hook, [$this, 'handle_import_request']);
		}
	}

	/**
	 * Handles the import POST on `load-{$hook}`, i.e. before any output has been sent, so that a
	 * successful create can actually redirect. Results are stashed on the instance for
	 * render_page() to display.
	 */
	public function handle_import_request(): void {
		if (!current_user_can('manage_options')) {
			return;
		}

		$action = !empty($_POST['btbl_options_action']) ? sanitize_key(wp_unslash($_POST['btbl_options_action'])) : '';
		$is_analyze = $action === 'import_analyze';
		$is_create = $action === 'import_create';
		if (!$is_analyze && !$is_create) {
			return;
		}

		check_admin_referer('btbl_options_import', '_btbl_options_nonce');

		$errors = [];
		$analysis = null;

		// The follow-up step reclaims the payload the Analyze step already read, instead of the form
		// re-posting it. It used to round-trip through a hidden textarea, so a file close to the
		// 5 MB cap was uploaded once and then posted back again -- the second POST could exceed
		// post_max_size even though the original upload had not.
		//
		// Claimed for BOTH actions, and unconditionally, so that:
		//   - "Re-analyze" works without re-picking the file (the button posts a second, later
		//     btbl_options_action, and PHP takes the last one, so this is not a Create);
		//   - the stored copy is always consumed rather than left to idle out its TTL.
		$handoff = $this->claim_handoff();

		// A file or pasted text supplied on THIS request always wins over the stored copy: the file
		// input and the paste box stay on screen next to the preview, so choosing a different file
		// there and pressing Create has to import that file, not the previous one.
		$payloads = $this->collect_import_payload();
		if (!empty($payloads['error'])) {
			$errors[] = $payloads['error'];
			$json_raw = '';
			$this->import_filename = $payloads['filename'];
		} elseif ($payloads['raw'] !== '') {
			$json_raw = $payloads['raw'];
			$this->import_filename = $payloads['filename'];
		} elseif ($handoff !== null) {
			$json_raw = $handoff['raw'];
			$this->import_filename = $handoff['filename'];
		} else {
			$json_raw = '';
			$this->import_filename = $payloads['filename'];
		}

		if (empty($errors) && $json_raw === '') {
			$errors[] = __('Please choose an export file or paste its contents.', 'baratables');
		}

		if (empty($errors)) {
			$analysis = BaraTables_Importer::analyze($json_raw, $this->import_filename, $this->service);
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
				$redirect = $edit_link
					? $edit_link
					: add_query_arg(['post_type' => BaraTables_Repository::CPT], admin_url('edit.php'));
				wp_safe_redirect($redirect);
				exit;
			}
		}

		// Only a previewed-but-not-yet-created payload needs to survive to the next request.
		// Errors must NOT skip this: a Create that failed to persist re-renders the preview with
		// no handoff token, so the next Create claims nothing and fails with "Please choose an
		// export file", blaming the user for a file they already uploaded. A successful Create
		// redirects and exits above, so reaching here always means the payload is still needed.
		if ($analysis && $json_raw !== '') {
			$this->import_token = $this->store_handoff($json_raw, $this->import_filename);
			if ($this->import_token === '') {
				// Say so now, at the preview, rather than letting Create fail with a message that
				// blames the user for not choosing a file. Pasting is a real way out: pasted text
				// is read straight from this request and never needs the stored copy.
				$errors[] = __('This site could not hold the import file between steps. Try a smaller file, or paste its contents into the box above.', 'baratables');
				$analysis = null;
			}
		}
		$this->errors = $errors;
		$this->analysis = $analysis;
	}

	/**
	 * Parks the analyzed payload for the follow-up Create request and returns its key.
	 * Scoped to the current user so one admin's handoff is never readable by another.
	 */
	private function store_handoff(string $raw, string $filename): string {
		// random_bytes() rather than wp_generate_password(), which ends in an apply_filters() call
		// ('random_password') that security plugins do hook to force symbols in. One such filter
		// would push the token outside the [A-Za-z0-9]{32} shape claim_handoff() checks for and
		// break every import on the site. 16 bytes of hex is the same 32 characters, unfilterable.
		$token = bin2hex(random_bytes(16));
		$stored = set_transient(
			self::HANDOFF_PREFIX . get_current_user_id() . '_' . $token,
			['raw' => $raw, 'filename' => $filename],
			self::HANDOFF_TTL
		);
		// A persistent object cache can silently refuse an oversized value -- memcached's default
		// slab limit is 1 MB, well under this plugin's 5 MB import cap -- and a DB transient can
		// exceed max_allowed_packet. Handing back a token for a value that was never stored sends
		// the user to a Create step that fails with "Please choose an export file", every time,
		// with no way to tell why. Report the failure so the caller can say something true.
		return $stored ? $token : '';
	}

	/**
	 * Reads and consumes the payload parked by store_handoff(). Returns null when no usable token
	 * was posted, in which case the caller falls back to reading the request normally.
	 */
	private function claim_handoff(): ?array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by the caller.
		$token_raw = isset($_POST['btbl_import_token']) ? sanitize_text_field(wp_unslash($_POST['btbl_import_token'])) : '';
		// Shape is fixed by store_handoff(): 16 random bytes as 32 hex chars (bin2hex(random_bytes(16))).
		if ($token_raw === '' || !preg_match('/^[A-Za-z0-9]{32}$/', $token_raw)) {
			return null;
		}
		$key = self::HANDOFF_PREFIX . get_current_user_id() . '_' . $token_raw;
		$stored = get_transient($key);
		delete_transient($key);
		if (!is_array($stored) || !isset($stored['raw']) || !is_string($stored['raw']) || $stored['raw'] === '') {
			return null;
		}
		return [
			'raw' => $stored['raw'],
			'filename' => isset($stored['filename']) && is_string($stored['filename']) ? $stored['filename'] : '',
		];
	}

	public function render_page(): void {
		if (!current_user_can('manage_options')) {
			return;
		}
		$errors = $this->errors;
		$analysis = $this->analysis;
		$import_token = $this->import_token;

		?>
		<div class="wrap btbl-admin">
			<h1><?php esc_html_e('Import a Table', 'baratables'); ?></h1>
			<?php
			// The "hide help text" preference is global (admin_body_class) and the CSS blanks every
			// .description inside .btbl-admin. Without the toggle rendered here, a user who hid help
			// in the table editor lost this page's guidance with no way to bring it back.
			BaraTables_Help::render_toggle();
			?>
			<p class="description"><?php esc_html_e('Create a table from another plugin\'s export. Accepts JSON, XML, CSV, or TXT. A spreadsheet needs a header row followed by data rows. Charts are not imported.', 'baratables'); ?></p>
			<?php foreach ($errors as $message) : ?>
				<div class="notice notice-error is-dismissible"><p><?php echo esc_html($message); ?></p></div>
			<?php endforeach; ?>
			<form method="post" enctype="multipart/form-data" autocomplete="off">
				<?php wp_nonce_field('btbl_options_import', '_btbl_options_nonce'); ?>
				<input type="hidden" name="btbl_options_action" value="<?php echo esc_attr($analysis ? 'import_create' : 'import_analyze'); ?>" />
				<?php if ($import_token !== '') : ?>
					<input type="hidden" name="btbl_import_token" value="<?php echo esc_attr($import_token); ?>" />
				<?php endif; ?>
				<div class="btbl-control-grid">
						<div class="btbl-control">
							<label class="btbl-small-heading" for="btbl_import_file"><?php esc_html_e('Upload a table export', 'baratables'); ?></label>
							<input type="file" name="btbl_import_file" id="btbl_import_file" accept=".json,.xml,.csv,.txt,application/json,application/xml,text/csv,text/plain" />
							<p class="description"><?php esc_html_e('Accepts .json, .xml, .csv, or .txt (max 5 MB).', 'baratables'); ?></p>
						</div>
					<div class="btbl-control">
						<label class="btbl-small-heading" for="btbl_import_json"><?php esc_html_e('Or paste the export', 'baratables'); ?></label>
						<textarea name="btbl_import_json" id="btbl_import_json" class="large-text code" rows="8" placeholder="<?php esc_attr_e('Paste the export contents here', 'baratables'); ?>"></textarea>
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
						<?php
						// Show the actual table, not just a description of it. This is the moment the
						// user decides whether to create it, and the editor already has a renderer that
						// reflects the saved options -- reuse it instead of describing the shape in prose.
						$import_definition = $analysis['definitions'][0] ?? null;
						if (is_array($import_definition)) {
							$import_rows = $this->service->get_rows($import_definition, 25);
							$import_definition = $this->service->ensure_columns_inferred($import_definition);
							if (!empty($import_definition['columns'])) {
								$import_pages = new BaraTables_Admin_Pages(
									$this->service,
									BaraTables_Admin::NONCE_ACTION,
									BaraTables_Admin::NONCE_FIELD
								);
								echo '<div class="btbl-admin btbl-admin-embed">';
								$import_pages->render_preview_panel($import_definition, $import_rows);
								echo '</div>';
							}
						}
						?>
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
	 * Gather the raw import text supplied on THIS request: an uploaded file first, then the paste
	 * textarea, with the usual size and type guards. Returns an empty 'raw' when neither was
	 * supplied, which is the caller's signal to fall back to the stored handoff.
	 *
	 * @return array{raw:string,filename:string,error:string}
	 */
	private function collect_import_payload(): array {
		$raw = '';
		$filename = '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by caller (handle_import_request).
		$tmp_file = isset($_FILES['btbl_import_file']['tmp_name']) ? sanitize_text_field(wp_unslash($_FILES['btbl_import_file']['tmp_name'])) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by caller (handle_import_request).
		$file_name = isset($_FILES['btbl_import_file']['name']) ? sanitize_file_name(wp_unslash($_FILES['btbl_import_file']['name'])) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified by caller; upload error is server-provided metadata.
		$upload_error = isset($_FILES['btbl_import_file']['error']) ? (int) $_FILES['btbl_import_file']['error'] : UPLOAD_ERR_NO_FILE;
		if ($upload_error !== UPLOAD_ERR_NO_FILE) {
			if ($upload_error !== UPLOAD_ERR_OK || $tmp_file === '' || !is_uploaded_file($tmp_file)) {
				return ['raw' => '', 'filename' => '', 'error' => __('Could not read the uploaded import file.', 'baratables')];
			}
			if (!$this->is_valid_import_upload($file_name)) {
				return ['raw' => '', 'filename' => '', 'error' => __('Import uploads must be a .json, .xml, .csv, or .txt file.', 'baratables')];
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
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by caller (handle_import_request).
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
