<?php

if (!defined('ABSPATH')) {
	exit;
}

class BaraTables_Admin {
	public const NONCE_ACTION = 'baratables_save';
	public const NONCE_FIELD = '_baratables_nonce';

	private BaraTables_Service $service;
	private BaraTables_Repository $repo;
	private BaraTables_Admin_Assets $assets;
	private BaraTables_Admin_Action_Handler $actions;
	private BaraTables_Admin_Pages $pages;

	public function __construct(BaraTables_Service $service, BaraTables_Repository $repo, string $plugin_url, string $plugin_path) {
		$this->service = $service;
		$this->repo = $repo;
		$this->assets = new BaraTables_Admin_Assets($plugin_url, $plugin_path);
		$this->actions = new BaraTables_Admin_Action_Handler($service);
		$this->pages = new BaraTables_Admin_Pages($service, self::NONCE_ACTION, self::NONCE_FIELD);
		$list_columns = new BaraTables_Admin_List_Columns();
		$slug_manager = new BaraTables_Admin_Slug_Manager($repo);
		$options_page = new BaraTables_Admin_Options($service);

		add_action('save_post_' . BaraTables_Repository::CPT, [$slug_manager, 'ensure_slug_on_save'], 10, 3);
		add_action('added_post_meta', [$slug_manager, 'ensure_slug_on_meta'], 10, 4);
		add_action('updated_post_meta', [$slug_manager, 'ensure_slug_on_meta'], 10, 4);
		add_filter('manage_' . BaraTables_Repository::CPT . '_posts_columns', [$list_columns, 'register_list_columns']);
		add_action('manage_' . BaraTables_Repository::CPT . '_posts_custom_column', [$list_columns, 'render_list_columns'], 10, 2);
		add_action('admin_menu', [$options_page, 'register_menu']);
		add_action('add_meta_boxes_' . BaraTables_Repository::CPT, [$this, 'register_meta_boxes']);
		add_action('save_post_' . BaraTables_Repository::CPT, [$this, 'save_table_from_editor'], 9, 3);
	}

	public function register_cpt(): void {
		$this->repo->register_cpt();
	}

	public function register_meta_boxes(): void {
		add_meta_box(
			'btbl-table-builder',
			__('Table Builder', 'baratables'),
			[$this, 'render_table_metabox'],
			BaraTables_Repository::CPT,
			'normal',
			'high'
		);

		add_meta_box(
			'btbl-table-preview',
			__('Table Preview', 'baratables'),
			[$this, 'render_table_preview_metabox'],
			BaraTables_Repository::CPT,
			'normal',
			'default'
		);
	}

	public function render_table_metabox(WP_Post $post): void {
		$existing = $this->get_existing_table_definition_for_post($post);
		if ($existing && empty($existing['post_type'])) {
			$existing['post_type'] = 'post';
		}

		$context_builder = new BaraTables_Admin_Form_Context($this->service);
		$context = $context_builder->build($existing);
		$page_slug = $existing ? 'baratables-edit' : 'baratables-add';
		echo '<div class="btbl-admin btbl-admin-embed">';
		$this->pages->render_table_form($context, $existing, $page_slug, false, false, get_the_title($post));
		echo '</div>';
	}

	public function render_table_preview_metabox(WP_Post $post): void {
		$definition = $this->get_existing_table_definition_for_post($post) ?: [];
		if (empty($definition)) {
			echo '<p>' . esc_html__('Save the table to see a preview.', 'baratables') . '</p>';
			return;
		}

		$preview_defn = $this->service->ensure_columns_inferred($definition);
		$preview_rows = $this->service->get_rows($preview_defn, 50);
		$preview_rows = $this->pages->apply_preview_sort($preview_rows, $preview_defn);
		echo '<div class="btbl-admin btbl-admin-embed">';
		$this->pages->render_preview_panel($preview_defn, $preview_rows);
		echo '</div>';
	}

	public function save_table_from_editor(int $post_id, WP_Post $post, bool $update): void {
		if ($post->post_type !== BaraTables_Repository::CPT) {
			return;
		}
		if (!BaraTables_Admin_Action_Guard::can_save_post($post_id, self::NONCE_FIELD, self::NONCE_ACTION)) {
			return;
		}

		$request = $this->actions->collect_table_request_data();
		if ($request['name'] === '') {
			$request['name'] = BaraTables_Post_Input::text('post_title');
		}

		$existing = $this->get_existing_table_definition_for_post($post);
		$definition = $this->actions->apply_request_to_definition($request, $existing, $update);
		$slug = $post->post_name !== '' ? $post->post_name : '';
		if ($slug === '') {
			$base = sanitize_title((string) $post->post_title);
			if ($base === '') {
				$base = (string) $post_id;
			}
			$slug = wp_unique_post_slug($base, $post_id, $post->post_status, $post->post_type, $post->post_parent);
			if ($slug !== '') {
				wp_update_post([
					'ID' => $post_id,
					'post_name' => $slug,
				]);
			}
		}
		$definition['id'] = $slug;
		$definition['name'] = $request['name'] !== '' ? $request['name'] : ($definition['name'] ?? $post->post_title);
		$definition['status'] = $post->post_status;

		BaraTables_Base_Repository::persist($post_id, BaraTables_Repository::META_KEY, BaraTables_Repository::META_SLUG, $definition, $slug);
	}

	private function get_existing_table_definition_for_post(WP_Post $post): ?array {
		$existing = $this->service->find_definition($post->post_name, true);
		if (!$existing) {
			$meta = get_post_meta($post->ID, BaraTables_Repository::META_KEY, true);
			$existing = is_array($meta) ? $meta : null;
		}
		return $existing;
	}

	public function enqueue_admin_assets(string $hook): void {
		$this->assets->enqueue($hook);
	}

}


class BaraTables_Chart_Admin {
	private BaraTables_Chart_Service $chart_service;
	private BaraTables_Chart_Repository $chart_repo;
	private BaraTables_Service $table_service;
	private BaraTables_Admin_Tab_Chart $tab_chart;
	private string $nonce_action;
	private string $nonce_field;

	public function __construct(BaraTables_Chart_Service $chart_service, BaraTables_Chart_Repository $chart_repo, BaraTables_Service $table_service, string $nonce_action, string $nonce_field) {
		$this->chart_service = $chart_service;
		$this->chart_repo = $chart_repo;
		$this->table_service = $table_service;
		$this->tab_chart = new BaraTables_Admin_Tab_Chart();
		$this->nonce_action = $nonce_action;
		$this->nonce_field = $nonce_field;
		$list_columns = new BaraTables_Chart_List_Columns($table_service);
		$slug_manager = new BaraTables_Chart_Slug_Manager($chart_repo);

		add_filter('manage_' . BaraTables_Chart_Repository::CPT . '_posts_columns', [$list_columns, 'register_list_columns']);
		add_action('manage_' . BaraTables_Chart_Repository::CPT . '_posts_custom_column', [$list_columns, 'render_list_columns'], 10, 2);
		add_action('save_post_' . BaraTables_Chart_Repository::CPT, [$slug_manager, 'ensure_slug_on_save'], 10, 3);
		add_action('added_post_meta', [$slug_manager, 'ensure_slug_on_meta'], 10, 4);
		add_action('updated_post_meta', [$slug_manager, 'ensure_slug_on_meta'], 10, 4);
		add_action('add_meta_boxes_' . BaraTables_Chart_Repository::CPT, [$this, 'register_meta_boxes']);
		add_action('save_post_' . BaraTables_Chart_Repository::CPT, [$this, 'save_chart_from_editor'], 9, 3);
	}

	public function register_cpt(): void {
		$this->chart_repo->register_cpt();
	}

	public function register_meta_boxes(): void {
		add_meta_box(
			'btbl-chart-builder',
			__('Chart Builder', 'baratables'),
			[$this, 'render_chart_metabox'],
			BaraTables_Chart_Repository::CPT,
			'normal',
			'high'
		);
	}

	public function render_chart_metabox(WP_Post $post): void {
		$chart = $this->chart_service->find_chart($post->post_name, true);
		if (!$chart) {
			$chart = get_post_meta($post->ID, BaraTables_Chart_Repository::META_KEY, true);
			$chart = is_array($chart) ? $chart : null;
		}
		$selected_table = isset($_GET['table']) ? sanitize_text_field(wp_unslash($_GET['table'])) : ($chart['table_id'] ?? ''); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Admin URL parameter.
		$context = $this->chart_service->build_form_context($chart, $selected_table);
		$page_slug = $chart ? 'btbl-charts-edit' : 'btbl-charts-add';

		echo '<div class="btbl-admin btbl-admin-embed">';
		$this->render_chart_form($context, $chart, $chart ? 'update' : 'create', false, false, get_the_title($post));
		echo '</div>';
	}

	private function render_chart_form(
		array $context,
		?array $chart,
		string $action,
		bool $wrap_form = true,
		bool $include_title = true,
		string $title_fallback = ''
	): void {
		$chart_options = $context['chart_options'] ?? $this->table_service->get_default_chart_options();
		$column_choices = $context['column_choices'] ?? [];
		$table_choices = $context['table_choices'] ?? [];
		$selected_table = $context['selected_table'] ?? '';
		$page_slug = $chart ? 'btbl-charts-edit' : 'btbl-charts-add';
		$chart_id = $chart['id'] ?? '';
		$shortcode = $chart_id !== '' ? '[bara_chart id="' . sanitize_text_field((string) $chart_id) . '"]' : '';
		$title_value = $chart['name'] ?? $title_fallback;
		?>
		<?php if ($wrap_form) : ?>
			<form method="post" autocomplete="off">
		<?php endif; ?>
			<?php wp_nonce_field($this->nonce_action, $this->nonce_field); ?>
			<?php if ($wrap_form) : ?>
				<input type="hidden" name="btbl_chart_action" value="<?php echo esc_attr($action); ?>" />
			<?php endif; ?>
			<input type="hidden" name="btbl_chart_id" id="btbl_chart_id" value="<?php echo esc_attr($chart['id'] ?? ''); ?>" />
			<input type="hidden" name="btbl_active_tab" id="btbl_active_tab" value="btbl-tab-chart" />
			<?php BaraTables_Admin_Page_Utils::render_title_section(
				__('Chart name', 'baratables'),
				'btbl_chart_name',
				$title_value,
				$shortcode,
				$include_title
			); ?>
			<div class="btbl-tab-wrapper">
				<h2 class="nav-tab-wrapper btbl-nav-tab-wrapper">
					<a href="#btbl-tab-chart" class="nav-tab nav-tab-active btbl-tab-link" data-target="btbl-tab-chart"><?php esc_html_e('Chart', 'baratables'); ?></a>
				</h2>
				<?php
				$this->tab_chart->render([
					'chart_options' => $chart_options,
					'active_tab' => 'btbl-tab-chart',
					'table_choices' => $table_choices,
						'selected_table' => $selected_table,
						'page_slug' => $page_slug,
					], $column_choices);
				?>
			</div>
			<?php if ($wrap_form) : ?>
				<p class="btbl-submit-row">
					<button type="submit" class="button button-primary">
						<?php echo $chart ? esc_html__('Update Chart', 'baratables') : esc_html__('Publish Chart', 'baratables'); ?>
					</button>
				</p>
			<?php endif; ?>
		<?php if ($wrap_form) : ?>
			</form>
		<?php endif;
	}

	public function save_chart_from_editor(int $post_id, WP_Post $post, bool $update): void {
		if ($post->post_type !== BaraTables_Chart_Repository::CPT) {
			return;
		}
		if (!BaraTables_Admin_Action_Guard::can_save_post($post_id, $this->nonce_field, $this->nonce_action)) {
			return;
		}

		$name = BaraTables_Post_Input::text('btbl_chart_name');
		if ($name === '') {
			$name = BaraTables_Post_Input::text('post_title');
		}
		$table_id = BaraTables_Post_Input::text('btbl_chart_table');
		$options = $this->collect_chart_options_from_request();

		$existing = $this->chart_service->find_chart($post->post_name, true);
		$prepared = $this->chart_service->prepare_chart_definition([
			'name' => $name,
			'table_id' => $table_id,
			'chart' => $options,
		], $existing);

		$chart = $prepared['definition'];
		if (empty($prepared['table_definition'])) {
			return;
		}

		$slug = $post->post_name !== '' ? $post->post_name : ($chart['id'] ?? BaraTables_Id_Generator::generate_chart_id());
		$chart['id'] = $slug;
		$chart['name'] = $name !== '' ? $name : ($chart['name'] ?? $post->post_title);
		$chart['status'] = $post->post_status;

		BaraTables_Base_Repository::persist($post_id, BaraTables_Chart_Repository::META_KEY, BaraTables_Chart_Repository::META_SLUG, $chart, $slug);
	}

	public function collect_chart_options_from_request(): array {
		$p = BaraTables_Post_Input::class;
		return [
			'type'           => $p::raw('btbl_chart_type'),
			'x_axis'         => $p::raw('btbl_chart_x_axis'),
			'series'         => $p::array_raw('btbl_chart_series'),
			'stack'          => $p::raw('btbl_chart_stack'),
			'height'         => $p::raw('btbl_chart_height'),
			'gantt_label'    => $p::raw('btbl_chart_gantt_label'),
			'gantt_start'    => $p::raw('btbl_chart_gantt_start'),
			'gantt_end'      => $p::raw('btbl_chart_gantt_end'),
			'gantt_group'    => $p::raw('btbl_chart_gantt_group'),
			'gantt_progress' => $p::raw('btbl_chart_gantt_progress'),
		];
	}

}
