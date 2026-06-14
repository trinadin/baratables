<?php

if (!defined('ABSPATH')) {
	exit;
}

class BaraTables_Post_Input {
	public static function text(string $key, string $default = ''): string {
		return isset($_POST[$key]) ? sanitize_text_field(wp_unslash($_POST[$key])) : $default; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by caller.
	}

	public static function raw(string $key, string $default = ''): string {
		return isset($_POST[$key]) ? (string) wp_unslash($_POST[$key]) : $default; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified by caller. Intentionally returns raw value; caller handles context-specific sanitization.
	}

	public static function int(string $key, int $default = 0): int {
		return isset($_POST[$key]) ? (int) wp_unslash($_POST[$key]) : $default; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified by caller. Sanitized by int cast.
	}

	public static function bool(string $key): bool {
		return !empty($_POST[$key]); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by caller. No unslash needed — truthiness check only.
	}

	public static function array_raw(string $key): array {
		return isset($_POST[$key]) ? (array) wp_unslash($_POST[$key]) : []; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified by caller. Intentionally returns raw array; caller handles sanitization.
	}

	public static function array_text(string $key): array {
		return isset($_POST[$key]) ? array_map('sanitize_text_field', (array) wp_unslash($_POST[$key])) : []; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by caller.
	}

	public static function key(string $key, string $default = ''): string {
		return isset($_POST[$key]) ? sanitize_key(wp_unslash($_POST[$key])) : $default; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by caller.
	}
}


class BaraTables_Admin_Page_Utils {
	public static function render_shortcode_cell(string $shortcode): string {
		return sprintf(
			'<code class="btbl-shortcode" data-shortcode="%s" tabindex="0" role="button">%s</code>',
			esc_attr($shortcode),
			esc_html($shortcode)
		);
	}

	public static function render_shortcode_display(string $shortcode): string {
		if ($shortcode === '') {
			return '';
		}
		return '<strong>' . esc_html__('Shortcode:', 'baratables') . '</strong> '
			. '<span class="btbl-shortcode-permalink">' . self::render_shortcode_cell($shortcode) . '</span>';
	}

	public static function render_title_section(string $label, string $field_name, string $title_value, string $shortcode, bool $include_title): void {
		if ($include_title) : ?>
			<div id="titlediv">
				<div id="titlewrap">
					<label class="screen-reader-text" id="title-prompt-text" for="title"><?php echo esc_html($label); ?></label>
					<input type="text" name="<?php echo esc_attr($field_name); ?>" size="30" value="<?php echo esc_attr($title_value); ?>" id="title" spellcheck="true" autocomplete="off" required />
				</div>
				<?php if ($shortcode !== '') : ?>
					<div class="inside">
						<div id="edit-slug-box" class="hide-if-no-js">
							<?php echo self::render_shortcode_display($shortcode); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is escaped in render_shortcode_display(). ?>
						</div>
					</div>
				<?php endif; ?>
			</div>
		<?php else :
			if ($shortcode !== '') : ?>
				<p><?php echo self::render_shortcode_display($shortcode); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is escaped in render_shortcode_display(). ?></p>
			<?php endif;
		endif;
	}
}


class BaraTables_Admin_Action_Guard {
	public static function user_can_manage(int $post_id = 0): bool {
		if ($post_id > 0) {
			return current_user_can('edit_post', $post_id);
		}
		return current_user_can('manage_options');
	}

	public static function verify_nonce_or_bail(string $nonce_field, string $nonce_action): void {
		if (!isset($_POST[$nonce_field]) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[$nonce_field])), $nonce_action)) {
			wp_die(esc_html__('Security check failed', 'baratables'));
		}
	}

	public static function can_save_post(int $post_id, string $nonce_field, string $nonce_action): bool {
		if (wp_is_post_revision($post_id) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) {
			return false;
		}
		if (!isset($_POST[$nonce_field]) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[$nonce_field])), $nonce_action)) {
			return false;
		}
		return self::user_can_manage($post_id);
	}
}


abstract class BaraTables_Base_Slug_Manager {
	protected $repo;
	private bool $syncing_slug = false;

	public function __construct($repo) {
		$this->repo = $repo;
	}

	public function ensure_slug_on_save(int $post_id, WP_Post $post, bool $update): void {
		if ($this->syncing_slug) {
			return;
		}
		if ($post->post_type !== $this->get_cpt()) {
			return;
		}
		if (wp_is_post_revision($post_id) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) {
			return;
		}

		$meta_slug = get_post_meta($post_id, $this->get_meta_slug_key(), true);
		$definition = get_post_meta($post_id, $this->get_meta_key(), true);
		$definition = is_array($definition) ? $definition : [];

		$this->maybe_resync_slug($post_id, $post, $meta_slug, $definition);
	}

	public function ensure_slug_on_meta($meta_id, $object_id, $meta_key, $meta_value): void {
		if ($this->syncing_slug) {
			return;
		}
		if (!in_array($meta_key, [$this->get_meta_key(), $this->get_meta_slug_key()], true)) {
			return;
		}
		$post = get_post($object_id);
		if (!$post || $post->post_type !== $this->get_cpt()) {
			return;
		}

		$meta_slug = get_post_meta($object_id, $this->get_meta_slug_key(), true);
		$definition = get_post_meta($object_id, $this->get_meta_key(), true);
		$definition = is_array($definition) ? $definition : [];
		if ($meta_slug === '' && !empty($definition['id'])) {
			$meta_slug = $definition['id'];
		}

		$this->maybe_resync_slug($object_id, $post, $meta_slug, $definition);
	}

	private function maybe_resync_slug(int $post_id, WP_Post $post, $meta_slug, array $definition): void {
		$current_slug = $post->post_name;
		if (!is_string($meta_slug) || strpos($meta_slug, $this->get_slug_prefix()) !== 0) {
			return;
		}

		if ($current_slug === '') {
			$base = sanitize_title((string) $post->post_title);
			if ($base === '') {
				$base = (string) $post_id;
			}
			$current_slug = wp_unique_post_slug($base, $post_id, $post->post_status, $post->post_type, $post->post_parent);
			if ($current_slug === '') {
				return;
			}
		}

		if ($meta_slug === $current_slug) {
			return;
		}

		$definition = $this->hydrate_definition($definition, $post, $meta_slug);
		$definition['id'] = $current_slug;

		$this->syncing_slug = true;
		if ($post->post_name !== $current_slug) {
			wp_update_post([
				'ID'        => $post_id,
				'post_name' => $current_slug,
			]);
		}
		update_post_meta($post_id, $this->get_meta_slug_key(), $current_slug);
		update_post_meta($post_id, $this->get_meta_key(), $definition);
		$this->syncing_slug = false;
	}

	abstract protected function get_cpt(): string;

	abstract protected function get_meta_key(): string;

	abstract protected function get_meta_slug_key(): string;

	abstract protected function get_slug_prefix(): string;

	abstract protected function hydrate_definition(array $definition, WP_Post $post, string $meta_slug): array;
}


class BaraTables_Admin_Slug_Manager extends BaraTables_Base_Slug_Manager {
	public function __construct(BaraTables_Repository $repo) {
		parent::__construct($repo);
	}

	protected function get_cpt(): string {
		return BaraTables_Repository::CPT;
	}

	protected function get_meta_key(): string {
		return BaraTables_Repository::META_KEY;
	}

	protected function get_meta_slug_key(): string {
		return BaraTables_Repository::META_SLUG;
	}

	protected function get_slug_prefix(): string {
		return 'btbl_';
	}

	protected function hydrate_definition(array $definition, WP_Post $post, string $meta_slug): array {
		if ((empty($definition) || empty($definition['post_type']) || empty($definition['columns'])) && $meta_slug !== '') {
			$existing = $this->repo->find_definition($meta_slug, true);
			if (is_array($existing)) {
				$definition = array_merge($existing, $definition);
			}
		}
		if (empty($definition['post_type'])) {
			$definition['post_type'] = 'post';
		}
		if (!isset($definition['columns']) || !is_array($definition['columns'])) {
			$definition['columns'] = [];
		}
		if (empty($definition['name']) && !empty($post->post_title)) {
			$definition['name'] = $post->post_title;
		}
		return $definition;
	}
}


class BaraTables_Chart_Slug_Manager extends BaraTables_Base_Slug_Manager {
	public function __construct(BaraTables_Chart_Repository $repo) {
		parent::__construct($repo);
	}

	protected function get_cpt(): string {
		return BaraTables_Chart_Repository::CPT;
	}

	protected function get_meta_key(): string {
		return BaraTables_Chart_Repository::META_KEY;
	}

	protected function get_meta_slug_key(): string {
		return BaraTables_Chart_Repository::META_SLUG;
	}

	protected function get_slug_prefix(): string {
		return 'btbl-chart-';
	}

	protected function hydrate_definition(array $definition, WP_Post $post, string $meta_slug): array {
		if (empty($definition) && $meta_slug !== '') {
			$existing = $this->repo->find_chart($meta_slug, true);
			if (is_array($existing)) {
				$definition = array_merge($existing, $definition);
			}
		}
		if (empty($definition['name']) && !empty($post->post_title)) {
			$definition['name'] = $post->post_title;
		}
		return $definition;
	}
}

class BaraTables_Admin_Assets {
	private string $plugin_url;
	private string $plugin_path;

	public function __construct(string $plugin_url, string $plugin_path) {
		$this->plugin_url = $plugin_url;
		$this->plugin_path = $plugin_path;
	}

	public function enqueue(string $hook): void {
		global $typenow;
		$hook_post_type = $typenow ?: (isset($_GET['post_type']) ? sanitize_key(wp_unslash($_GET['post_type'])) : ''); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading standard WP admin URL parameters.
		if ($hook_post_type === '' && isset($_GET['post'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading standard WP admin URL parameters.
			$post_obj = get_post(absint(wp_unslash($_GET['post']))); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading standard WP admin URL parameters.
			if ($post_obj instanceof WP_Post) {
				$hook_post_type = $post_obj->post_type;
			}
		}
		$is_btbl_list = $hook === 'edit.php'
			&& in_array($hook_post_type, [BaraTables_Repository::CPT, BaraTables_Chart_Repository::CPT], true);
		$is_btbl_editor = in_array($hook, ['post.php', 'post-new.php'], true)
			&& in_array($hook_post_type, [BaraTables_Repository::CPT, BaraTables_Chart_Repository::CPT], true);
		$page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading standard WP admin URL parameters.
		$is_btbl_page = $page === BaraTables_Admin_Options::PAGE_SLUG;
		if (!$is_btbl_list && !$is_btbl_editor && !$is_btbl_page) {
			return;
		}

		wp_enqueue_style(
			'baratables-admin',
			$this->plugin_url . 'assets/admin.css',
			[],
			BaraTables_Asset_Utils::get_asset_version($this->plugin_path, 'assets/admin.css')
		);

		if ($is_btbl_editor || $is_btbl_list) {
			if ($is_btbl_editor) {
				wp_enqueue_media();
			}
			wp_enqueue_script(
				'baratables-admin',
				$this->plugin_url . 'assets/admin.js',
				['jquery'],
				BaraTables_Asset_Utils::get_asset_version($this->plugin_path, 'assets/admin.js'),
				true
			);
		}
	}
}

class BaraTables_Admin_List_Renderer {
	/** @var callable */
	private $definition_loader;
	private array $renderers;

	/**
	 * @param callable(int):array $definition_loader
	 * @param array<string,callable(array,int):void> $renderers
	 */
	public function __construct(callable $definition_loader, array $renderers) {
		$this->definition_loader = $definition_loader;
		$this->renderers = $renderers;
	}

	public function render(string $column, int $post_id): void {
		if (!isset($this->renderers[$column])) {
			return;
		}
		$definition = call_user_func($this->definition_loader, $post_id);
		if (!is_array($definition)) {
			$definition = [];
		}
		call_user_func($this->renderers[$column], $definition, $post_id);
	}
}


class BaraTables_Admin_List_Columns {
	private BaraTables_Admin_List_Renderer $renderer;

	public function __construct() {
		$definition_loader = static function (int $post_id): array {
			$definition = get_post_meta($post_id, BaraTables_Repository::META_KEY, true);
			return is_array($definition) ? $definition : [];
		};

		$renderers = [
			'taxonomy' => static function (array $definition): void {
				$parts = [];
				foreach (BaraTables_Taxonomy_Filters::normalize($definition['taxonomy_filter'] ?? []) as $filter) {
					$tax = isset($filter['taxonomy']) ? sanitize_key($filter['taxonomy']) : '';
					$terms = isset($filter['terms']) && is_array($filter['terms'])
						? array_filter(array_map('intval', $filter['terms']))
						: [];
					if ($tax === '' || empty($terms)) {
						continue;
					}
					$tax_obj = get_taxonomy($tax);
					$tax_label = $tax_obj && !is_wp_error($tax_obj) && !empty($tax_obj->labels->singular_name)
						? $tax_obj->labels->singular_name
						: ucwords(str_replace(['_', '-'], ' ', $tax));

					$term_labels = [];
					foreach ($terms as $term_id) {
						$term_obj = get_term($term_id, $tax);
						if ($term_obj && !is_wp_error($term_obj)) {
							$term_labels[] = $term_obj->name;
						}
					}
					if (!empty($term_labels)) {
						$parts[] = $tax_label . ': ' . implode(', ', $term_labels);
					}
				}

				echo $parts ? esc_html(implode(' | ', $parts)) : '&mdash;';
			},
			'data_source' => static function (array $definition): void {
				$source = BaraTables_Source_Type::normalize($definition['source_type'] ?? BaraTables_Source_Type::WP_QUERY, BaraTables_Source_Type::WP_QUERY);
				if ($source === '') {
					echo '&mdash;';
					return;
				}
				$labels = BaraTables_Source_Type::labels();
				echo esc_html($labels[$source] ?? ucwords(str_replace('_', ' ', $source)));
			},
			'post_type' => static function (array $definition): void {
				$pt = $definition['post_type'] ?? '';
				if ($pt === '') {
					echo '&mdash;';
					return;
				}
				$pt_obj = get_post_type_object($pt);
				echo $pt_obj && !is_wp_error($pt_obj) ? esc_html($pt_obj->labels->singular_name ?? $pt) : esc_html($pt);
			},
			'fields' => static function (array $definition): void {
				if (empty($definition['columns']) || !is_array($definition['columns'])) {
					echo '&mdash;';
					return;
				}
				$labels = array_map(static function ($col) {
					return esc_html($col['label'] ?? '');
				}, $definition['columns']);
				$labels = array_filter($labels, static function ($label) {
					return $label !== '';
				});
				echo $labels ? esc_html(implode(', ', $labels)) : '&mdash;';
			},
			'shortcode' => static function (array $definition, int $post_id): void {
				$id = isset($definition['id']) ? (string) $definition['id'] : (string) get_post_field('post_name', $post_id);
				$shortcode = '[bara_table id="' . sanitize_text_field($id) . '"]';
				echo BaraTables_Admin_Page_Utils::render_shortcode_cell($shortcode); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is escaped in render_shortcode_cell().
			},
		];

		$this->renderer = new BaraTables_Admin_List_Renderer($definition_loader, $renderers);
	}

	public function register_list_columns(array $columns): array {
		return $columns + $this->get_column_labels();
	}

	public function render_list_columns(string $column, int $post_id): void {
		$this->renderer->render($column, $post_id);
	}

	private function get_column_labels(): array {
		return [
			'post_type' => __('Post type', 'baratables'),
			'data_source' => __('Data Source', 'baratables'),
			'taxonomy' => __('Taxonomy', 'baratables'),
			'fields' => __('Fields', 'baratables'),
			'shortcode' => __('Shortcode', 'baratables'),
		];
	}
}


class BaraTables_Chart_List_Columns {
	private BaraTables_Service $table_service;
	private BaraTables_Admin_List_Renderer $renderer;

	public function __construct(BaraTables_Service $table_service) {
		$this->table_service = $table_service;

		$definition_loader = static function (int $post_id): array {
			$chart = get_post_meta($post_id, BaraTables_Chart_Repository::META_KEY, true);
			return is_array($chart) ? $chart : [];
		};

		$renderers = [
			'chart_table' => function (array $chart): void {
				$table = $this->get_table_definition($chart);
				if (!$table) {
					echo '&mdash;';
					return;
				}
				echo esc_html($table['name'] ?? ($table['id'] ?? ''));
			},
			'chart_type' => static function (array $chart): void {
				$type = isset($chart['chart']['type']) ? sanitize_key($chart['chart']['type']) : '';
				if ($type === '') {
					echo '&mdash;';
					return;
				}
				$type_labels = [
					'bar' => __('Bar', 'baratables'),
					'line' => __('Line', 'baratables'),
					'area' => __('Area', 'baratables'),
					'pie' => __('Pie', 'baratables'),
					'gantt' => __('Gantt', 'baratables'),
				];
				echo esc_html($type_labels[$type] ?? ucwords($type));
			},
			'chart_fields' => function (array $chart): void {
				$table = $this->get_table_definition($chart);
				$chart_options = isset($chart['chart']) && is_array($chart['chart']) ? $chart['chart'] : [];
				if (!$table || empty($table['columns'])) {
					echo '&mdash;';
					return;
				}
				$slug_to_label = $this->table_service->build_column_slug_label_map($table['columns']);

				$type = isset($chart_options['type']) ? sanitize_key($chart_options['type']) : 'bar';
				$labels = [];

				if ($type === 'gantt') {
					$keys = [
						$chart_options['gantt_label'] ?? '',
						$chart_options['gantt_start'] ?? '',
						$chart_options['gantt_end'] ?? '',
					];
					foreach ($keys as $slug) {
						if ($slug === '') {
							continue;
						}
						$labels[] = $slug_to_label[$slug] ?? $slug;
					}
				} else {
					$x = $chart_options['x_axis'] ?? '';
					if ($x !== '') {
						$labels[] = $slug_to_label[$x] ?? $x;
					}
					$series = isset($chart_options['series']) && is_array($chart_options['series']) ? $chart_options['series'] : [];
					foreach ($series as $slug) {
						if ($slug === '') {
							continue;
						}
						$labels[] = $slug_to_label[$slug] ?? $slug;
					}
				}

				$labels = array_filter(array_map('strval', $labels));
				echo !empty($labels) ? esc_html(implode(', ', $labels)) : '&mdash;';
			},
			'chart_shortcode' => static function (array $chart, int $post_id): void {
				$id = isset($chart['id']) ? (string) $chart['id'] : (string) get_post_field('post_name', $post_id);
				$shortcode = '[bara_chart id="' . sanitize_text_field($id) . '"]';
				echo BaraTables_Admin_Page_Utils::render_shortcode_cell($shortcode); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is escaped in render_shortcode_cell().
			},
		];

		$this->renderer = new BaraTables_Admin_List_Renderer($definition_loader, $renderers);
	}

	public function register_list_columns(array $columns): array {
		return $columns + $this->get_column_labels();
	}

	public function render_list_columns(string $column, int $post_id): void {
		$this->renderer->render($column, $post_id);
	}

	private function get_column_labels(): array {
		return [
			'chart_table' => __('Table', 'baratables'),
			'chart_type' => __('Type', 'baratables'),
			'chart_fields' => __('Columns', 'baratables'),
			'chart_shortcode' => __('Shortcode', 'baratables'),
		];
	}

	private function get_table_definition(array $chart): ?array {
		$table_id = isset($chart['table_id']) ? sanitize_text_field($chart['table_id']) : '';
		if ($table_id === '') {
			return null;
		}
		return $this->table_service->find_definition($table_id);
	}
}
