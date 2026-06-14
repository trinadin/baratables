<?php

if (!defined('ABSPATH')) {
	exit;
}

class BaraTables_Admin_Pages {
	private BaraTables_Service $service;
	private string $nonce_action;
	private string $nonce_field;
	private BaraTables_Admin_Tab_General $tab_general;
	private BaraTables_Admin_Tab_Columns $tab_columns;
	private BaraTables_Admin_Tab_Table $tab_table;
	private BaraTables_Admin_Tab_Advanced $tab_advanced;

	public function __construct(BaraTables_Service $service, string $nonce_action, string $nonce_field) {
		$this->service = $service;
		$this->nonce_action = $nonce_action;
		$this->nonce_field = $nonce_field;
		$this->tab_general = new BaraTables_Admin_Tab_General();
		$this->tab_columns = new BaraTables_Admin_Tab_Columns();
		$this->tab_table = new BaraTables_Admin_Tab_Table();
		$this->tab_advanced = new BaraTables_Admin_Tab_Advanced();
	}

	public function render_table_form(array $context, ?array $editing_defn, string $page_slug, bool $wrap_form = true, bool $include_title = true, string $title_fallback = ''): void {
		$form_action = $context['form_action'] ?? '';
		$active_tab = $context['active_tab'] ?? 'btbl-tab-general';
		$display_columns = $context['display_columns'] ?? [];
		$definition_columns = is_array($editing_defn['columns'] ?? null) ? $editing_defn['columns'] : [];
		$table_id = $editing_defn['id'] ?? '';
		$shortcode = $table_id !== '' ? '[bara_table id="' . sanitize_text_field((string) $table_id) . '"]' : '';
		$title_value = $editing_defn['name'] ?? $title_fallback;
		$nav_class = static function (string $tab) use ($active_tab): string {
			$base = 'nav-tab btbl-tab-link';
			return $active_tab === $tab ? 'nav-tab nav-tab-active btbl-tab-link' : $base;
		};
		?>
		<?php if ($wrap_form) : ?>
			<form method="post" autocomplete="off">
		<?php endif; ?>
			<?php wp_nonce_field($this->nonce_action, $this->nonce_field); ?>
			<?php if ($wrap_form) : ?>
				<input type="hidden" name="btbl_action" value="<?php echo esc_attr($form_action); ?>" />
			<?php endif; ?>
			<input type="hidden" name="btbl_active_tab" id="btbl_active_tab" value="<?php echo esc_attr($active_tab); ?>" />
			<?php if ($editing_defn) : ?>
				<input type="hidden" name="btbl_table_id" value="<?php echo esc_attr($editing_defn['id'] ?? ''); ?>" />
			<?php endif; ?>
			<?php BaraTables_Admin_Page_Utils::render_title_section(
				__('Table name', 'baratables'),
				'btbl_name',
				$title_value,
				$shortcode,
				$include_title
			); ?>
			<div class="btbl-tab-wrapper">
				<h2 class="nav-tab-wrapper btbl-nav-tab-wrapper">
					<a href="#btbl-tab-general" class="<?php echo esc_attr($nav_class('btbl-tab-general')); ?>" data-target="btbl-tab-general"><?php esc_html_e('Source', 'baratables'); ?></a>
					<a href="#btbl-tab-columns" class="<?php echo esc_attr($nav_class('btbl-tab-columns')); ?>" data-target="btbl-tab-columns"><?php esc_html_e('Columns & Filters', 'baratables'); ?></a>
					<a href="#btbl-tab-table" class="<?php echo esc_attr($nav_class('btbl-tab-table')); ?>" data-target="btbl-tab-table"><?php esc_html_e('Options', 'baratables'); ?></a>
					<a href="#btbl-tab-advanced" class="<?php echo esc_attr($nav_class('btbl-tab-advanced')); ?>" data-target="btbl-tab-advanced"><?php esc_html_e('Advanced', 'baratables'); ?></a>
				</h2>

				<?php
				$this->tab_general->render($context, $editing_defn, $page_slug);
				$this->tab_columns->render($context, $editing_defn);
				$this->tab_table->render($context);
				$this->tab_advanced->render($context);
				?>
			</div>
			<?php if ($wrap_form) : ?>
				<p class="btbl-submit-row">
					<button type="submit" class="button button-primary">
						<?php echo $editing_defn ? esc_html__('Update Table', 'baratables') : esc_html__('Publish Table', 'baratables'); ?>
					</button>
				</p>
			<?php endif; ?>
		<?php if ($wrap_form) : ?>
			</form>
		<?php endif;
	}

	public function render_preview_panel(array $definition, array $rows): void {
		$definition['columns'] = isset($definition['columns']) && is_array($definition['columns']) ? $definition['columns'] : [];
		$allowed_inline = BaraTables_Service::allowed_inline_html();
		$table_options = $this->service->get_table_options($definition);
		$table_classes = ['widefat', 'btbl-preview-table'];
		foreach (BaraTables_Service::TABLE_STYLE_CLASS_MAP as $option_key => $class_name) {
			if (($table_options[$option_key] ?? true) !== false) {
				$table_classes[] = $class_name;
			}
		}
		$preview_rows = $rows;
		if (!empty($table_options['paging'])) {
			$page_length = isset($table_options['pageLength']) ? (int) $table_options['pageLength'] : 0;
			if ($page_length > 0) {
				$preview_rows = array_slice($rows, 0, $page_length);
			}
		}
		$total_rows = count($rows);
		$display_rows = count($preview_rows);
		$sorted_columns = [];
		if (!empty($table_options['orderColumn']) && !empty($table_options['ordering'])) {
			foreach ($this->resolve_preview_sort_rules($definition) as $rule) {
				if (!array_key_exists($rule['index'], $sorted_columns)) {
					$sorted_columns[$rule['index']] = $rule['direction'];
				}
			}
		}
		$info_text = '';
		if (!empty($table_options['info'])) {
			$default_info = __('Showing _START_ to _END_ of _TOTAL_ entries', 'baratables');
			$default_empty = __('Showing 0 to 0 of 0 entries', 'baratables');
			$template = $display_rows > 0
				? (string) ($table_options['infoText'] ?? '')
				: (string) ($table_options['infoEmpty'] ?? '');
			$template = $template !== '' ? $template : ($display_rows > 0 ? $default_info : $default_empty);
			$info_text = str_replace(
				['_START_', '_END_', '_TOTAL_', '_MAX_'],
				[$display_rows > 0 ? 1 : 0, $display_rows, $total_rows, $total_rows],
				$template
			);
		}
		$layout_zones = [
			'topStart' => $table_options['layoutTopStart'] ?? [],
			'topEnd' => $table_options['layoutTopEnd'] ?? [],
			'bottomStart' => $table_options['layoutBottomStart'] ?? [],
			'bottomEnd' => $table_options['layoutBottomEnd'] ?? [],
		];
		$layout_seen = [];
		$layout_controls = [
			'pagelength' => !empty($table_options['lengthChange']),
			'search' => !empty($table_options['searchBox']),
			'buttons' => !empty($table_options['buttons']),
			'info' => !empty($table_options['info']) && $info_text !== '',
			'paging' => !empty($table_options['paging']),
		];
		$search_label = (string) ($table_options['searchText'] ?? '');
		$search_placeholder = (string) ($table_options['searchPlaceholder'] ?? '');
		$length_prefix = (string) ($table_options['lengthMenuPrefix'] ?? '');
		$length_suffix = (string) ($table_options['lengthMenuSuffix'] ?? '');
		$page_length = isset($table_options['pageLength']) ? (int) $table_options['pageLength'] : 10;
		$length_choices = array_unique(array_filter([$page_length, 10, 25, 50, 100]));
		sort($length_choices);
		$button_labels = [
			'copy' => __('Copy', 'baratables'),
			'csv' => __('CSV', 'baratables'),
			'print' => __('Print', 'baratables'),
			'colvis' => __('Columns', 'baratables'),
			'pagelength' => __('Page length', 'baratables'),
		];
		$button_text_map = [
			'copy' => (string) ($table_options['buttonTextCopy'] ?? ''),
			'csv' => (string) ($table_options['buttonTextCsv'] ?? ''),
			'print' => (string) ($table_options['buttonTextPrint'] ?? ''),
			'colvis' => (string) ($table_options['buttonTextColvis'] ?? ''),
			'pagelength' => (string) ($table_options['buttonTextPagelength'] ?? ''),
		];
		$paginate_defaults = ['first' => '«', 'previous' => '‹', 'next' => '›', 'last' => '»'];
		$paginate_labels = [];
		foreach ($paginate_defaults as $key => $fallback) {
			$custom = (string) ($table_options['paginate' . ucfirst($key)] ?? '');
			$paginate_labels[$key] = $custom !== '' ? $custom : $fallback;
		}

		$zone_context = [
			'table_options' => $table_options,
			'allowed_inline' => $allowed_inline,
			'layout_controls' => $layout_controls,
			'length_prefix' => $length_prefix,
			'length_suffix' => $length_suffix,
			'length_choices' => $length_choices,
			'page_length' => $page_length,
			'button_labels' => $button_labels,
			'button_text_map' => $button_text_map,
			'search_label' => $search_label,
			'search_placeholder' => $search_placeholder,
			'info_text' => $info_text,
			'paginate_labels' => $paginate_labels,
		];
		?>
		<?php if (empty($definition['columns'])) : ?>
			<p><?php esc_html_e('No columns selected yet for this table.', 'baratables'); ?></p>
		<?php elseif (empty($preview_rows)) : ?>
			<p><?php esc_html_e('No data available for this table yet.', 'baratables'); ?></p>
		<?php else : ?>
			<div class="btbl-preview-layout">
				<div class="btbl-preview-layout-row btbl-preview-layout-top">
					<div class="btbl-preview-layout-zone btbl-preview-layout-start">
						<?php $this->render_layout_zone_items((array) $layout_zones['topStart'], $layout_seen, $zone_context); ?>
					</div>
					<div class="btbl-preview-layout-zone btbl-preview-layout-end">
						<?php $this->render_layout_zone_items((array) $layout_zones['topEnd'], $layout_seen, $zone_context); ?>
					</div>
				</div>
				<div class="btbl-preview-table-wrapper">
					<table class="<?php echo esc_attr(implode(' ', $table_classes)); ?>">
						<thead>
							<tr>
								<?php foreach ($definition['columns'] as $idx => $col) : ?>
									<?php if (!empty($col['hidden'])) { continue; } ?>
									<?php
									$header_class = [];
									$sort_dir = $sorted_columns[$idx] ?? null;
									if ($sort_dir !== null) {
										$header_class[] = 'btbl-preview-sorted';
										$header_class[] = 'btbl-preview-sorted-' . $sort_dir;
									}
									?>
									<th<?php echo !empty($header_class) ? ' class="' . esc_attr(implode(' ', $header_class)) . '"' : ''; ?>>
										<?php echo !empty($col['hide_title']) ? '&nbsp;' : wp_kses((string) ($col['label'] ?? ''), $allowed_inline); ?>
									</th>
								<?php endforeach; ?>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($preview_rows as $row) : ?>
								<tr>
									<?php foreach ($definition['columns'] as $idx => $col) : ?>
										<?php if (!empty($col['hidden'])) { continue; } ?>
										<?php $cell = $row[$idx] ?? ''; ?>
										<?php
										$cell_class = [];
										if (array_key_exists($idx, $sorted_columns)) {
											$cell_class[] = 'btbl-preview-sorted';
										}
										?>
										<td<?php echo !empty($cell_class) ? ' class="' . esc_attr(implode(' ', $cell_class)) . '"' : ''; ?>>
											<?php echo wp_kses_post($cell); ?>
										</td>
									<?php endforeach; ?>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<div class="btbl-preview-layout-row btbl-preview-layout-bottom">
					<div class="btbl-preview-layout-zone btbl-preview-layout-start">
						<?php $this->render_layout_zone_items((array) $layout_zones['bottomStart'], $layout_seen, $zone_context); ?>
					</div>
					<div class="btbl-preview-layout-zone btbl-preview-layout-end">
						<?php $this->render_layout_zone_items((array) $layout_zones['bottomEnd'], $layout_seen, $zone_context); ?>
					</div>
				</div>
			</div>
		<?php endif;
	}

	public function apply_preview_sort(array $rows, array $definition): array {
		$resolved_rules = $this->resolve_preview_sort_rules($definition);
		if (empty($resolved_rules) || empty($rows)) {
			return $rows;
		}

		usort($rows, static function ($a, $b) use ($resolved_rules) {
			foreach ($resolved_rules as $rule) {
				$idx = $rule['index'];
				$dir = $rule['direction'];
				$valA = $a[$idx] ?? '';
				$valB = $b[$idx] ?? '';

				if ($valA === $valB) {
					continue;
				}

				$cmp = 0;
				if (is_numeric($valA) && is_numeric($valB)) {
					$cmp = (float) $valA < (float) $valB ? -1 : 1;
				} else {
					$cmp = strnatcasecmp((string) $valA, (string) $valB);
				}

				return $dir === 'desc' ? -$cmp : $cmp;
			}

			return 0;
		});

		return $rows;
	}

	private function render_layout_zone_items(array $items, array &$layout_seen, array $ctx): void {
		$table_options = $ctx['table_options'];
		$allowed_inline = $ctx['allowed_inline'];
		$layout_controls = $ctx['layout_controls'];

		foreach ($items as $item) {
			$item = sanitize_key((string) $item);
			if ($item === '' || !empty($layout_seen[$item]) || empty($layout_controls[$item])) {
				continue;
			}
			$layout_seen[$item] = true;

			if ($item === 'pagelength') : ?>
				<div class="btbl-preview-control btbl-preview-length">
					<label>
						<?php if ($ctx['length_prefix'] !== '') : ?>
							<span class="btbl-preview-label"><?php echo wp_kses($ctx['length_prefix'], $allowed_inline); ?></span>
						<?php endif; ?>
						<select disabled>
							<?php foreach ($ctx['length_choices'] as $choice) : ?>
								<option value="<?php echo esc_attr($choice); ?>" <?php selected($choice === $ctx['page_length']); ?>>
									<?php echo esc_html((string) $choice); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<?php if ($ctx['length_suffix'] !== '') : ?>
							<span class="btbl-preview-label"><?php echo wp_kses($ctx['length_suffix'], $allowed_inline); ?></span>
						<?php endif; ?>
					</label>
				</div>
			<?php elseif ($item === 'buttons') : ?>
				<div class="btbl-preview-control btbl-preview-buttons">
					<div class="btbl-preview-button-group">
						<?php foreach ((array) ($table_options['buttons'] ?? []) as $choice) :
							$choice = sanitize_key((string) $choice);
							if (!isset($ctx['button_labels'][$choice])) {
								continue;
							}
							$label = $ctx['button_text_map'][$choice] !== '' ? $ctx['button_text_map'][$choice] : $ctx['button_labels'][$choice];
							?>
							<button type="button" class="button button-small" disabled><?php echo wp_kses($label, $allowed_inline); ?></button>
						<?php endforeach; ?>
					</div>
				</div>
			<?php elseif ($item === 'search') : ?>
				<div class="btbl-preview-control btbl-preview-search">
					<label>
						<?php if ($ctx['search_label'] !== '') : ?>
							<span class="btbl-preview-label"><?php echo wp_kses($ctx['search_label'], $allowed_inline); ?></span>
						<?php endif; ?>
						<input type="search" placeholder="<?php echo esc_attr($ctx['search_placeholder']); ?>" disabled />
					</label>
				</div>
			<?php elseif ($item === 'info') : ?>
				<div class="btbl-preview-control btbl-preview-info"><?php echo wp_kses_post($ctx['info_text']); ?></div>
			<?php elseif ($item === 'paging') : ?>
				<div class="btbl-preview-control btbl-preview-paging">
					<?php if (!empty($table_options['pagingFirstLast'])) : ?>
						<button type="button" class="btbl-preview-page" disabled><?php echo wp_kses($ctx['paginate_labels']['first'], $allowed_inline); ?></button>
					<?php endif; ?>
					<?php if (!empty($table_options['pagingPreviousNext'])) : ?>
						<button type="button" class="btbl-preview-page" disabled><?php echo wp_kses($ctx['paginate_labels']['previous'], $allowed_inline); ?></button>
					<?php endif; ?>
					<?php if (!empty($table_options['pagingNumbers'])) : ?>
						<button type="button" class="btbl-preview-page is-current" disabled>1</button>
						<button type="button" class="btbl-preview-page" disabled>2</button>
						<button type="button" class="btbl-preview-page" disabled>3</button>
					<?php endif; ?>
					<?php if (!empty($table_options['pagingPreviousNext'])) : ?>
						<button type="button" class="btbl-preview-page" disabled><?php echo wp_kses($ctx['paginate_labels']['next'], $allowed_inline); ?></button>
					<?php endif; ?>
					<?php if (!empty($table_options['pagingFirstLast'])) : ?>
						<button type="button" class="btbl-preview-page" disabled><?php echo wp_kses($ctx['paginate_labels']['last'], $allowed_inline); ?></button>
					<?php endif; ?>
				</div>
			<?php endif;
		}
	}

	private function resolve_preview_sort_rules(array $definition): array {
		$order_rules = $this->service->get_default_sort_order($definition);
		if (empty($order_rules)) {
			return [];
		}
		$slug_to_index = $this->service->map_column_slug_to_index($definition);
		$resolved_rules = [];
		foreach ($order_rules as $rule) {
			$slug = $rule['slug'] ?? '';
			if (!array_key_exists($slug, $slug_to_index)) {
				continue;
			}
			$resolved_rules[] = [
				'index' => $slug_to_index[$slug],
				'direction' => $rule['direction'] === 'desc' ? 'desc' : 'asc',
			];
		}
		return $resolved_rules;
	}
}
