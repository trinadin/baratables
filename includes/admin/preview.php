<?php

if (!defined('ABSPATH')) {
	exit;
}

/** Admin/import preview rendering without constructing the complete editor-tab object graph. */
class BaraTables_Admin_Preview_Renderer {
	private BaraTables_Table_Presentation $table_presentation;

	public function __construct(BaraTables_Service $service) {
		$this->table_presentation = new BaraTables_Table_Presentation($service);
	}

	public function render(array $definition, array $rows, string $source_error = ''): void {
		$definition['columns'] = isset($definition['columns']) && is_array($definition['columns']) ? $definition['columns'] : [];
		$allowed_inline = BaraTables_Service::allowed_inline_html();
		$presentation = $this->table_presentation->build($definition, $rows, true);
		$table_options = $presentation['raw_options'];
		$table_classes = array_merge(['widefat', 'btbl-preview-table'], $presentation['style_classes']);
		$preview_rows = $presentation['preview_rows'];
		$sorted_columns = $presentation['sorted_columns'];
		$layout_zones = $presentation['layout_zones'];
		$layout_seen = [];

		?>
		<?php if ($source_error !== '') : ?>
			<div class="notice notice-error inline"><p><?php echo esc_html($source_error); ?></p></div>
		<?php elseif (empty($definition['columns'])) : ?>
			<p><?php esc_html_e('No columns selected yet for this table.', 'baratables'); ?></p>
		<?php elseif (empty($preview_rows)) : ?>
			<p><?php esc_html_e('No data available for this table yet.', 'baratables'); ?></p>
		<?php else : ?>
			<div class="btbl-preview-layout">
				<div class="btbl-preview-layout-row btbl-preview-layout-top">
					<div class="btbl-preview-layout-zone btbl-preview-layout-start">
						<?php $this->render_layout_zone_items((array) $layout_zones['topStart'], $layout_seen, $presentation, $allowed_inline); ?>
					</div>
					<div class="btbl-preview-layout-zone btbl-preview-layout-end">
						<?php $this->render_layout_zone_items((array) $layout_zones['topEnd'], $layout_seen, $presentation, $allowed_inline); ?>
					</div>
				</div>
				<div class="btbl-preview-table-wrapper">
					<table class="<?php echo esc_attr(implode(' ', $table_classes)); ?>">
						<?php $preview_caption = trim(wp_strip_all_tags((string) ($table_options['caption'] ?? ''))); ?>
						<?php if ($preview_caption !== '') : ?>
							<caption><?php echo wp_kses($preview_caption, $allowed_inline); ?></caption>
						<?php endif; ?>
						<thead>
							<tr>
								<?php foreach ($presentation['columns'] as $idx => $column_model) : ?>
									<?php if ($column_model['hidden']) { continue; } ?>
									<?php
									$header_class = [];
									$sort_dir = $column_model['sort_direction'];
									if ($sort_dir !== null) {
										$header_class[] = 'btbl-preview-sorted';
										$header_class[] = 'btbl-preview-sorted-' . $sort_dir;
									}
									?>
									<th scope="col"<?php echo !empty($header_class) ? ' class="' . esc_attr(implode(' ', $header_class)) . '"' : ''; ?>>
										<?php echo wp_kses($column_model['heading'], $allowed_inline); ?>
									</th>
								<?php endforeach; ?>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($preview_rows as $row) : ?>
								<tr>
									<?php foreach ($presentation['columns'] as $idx => $column_model) : ?>
										<?php if ($column_model['hidden']) { continue; } ?>
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
						<?php $this->render_layout_zone_items((array) $layout_zones['bottomStart'], $layout_seen, $presentation, $allowed_inline); ?>
					</div>
					<div class="btbl-preview-layout-zone btbl-preview-layout-end">
						<?php $this->render_layout_zone_items((array) $layout_zones['bottomEnd'], $layout_seen, $presentation, $allowed_inline); ?>
					</div>
				</div>
			</div>
		<?php endif;
	}

	public function sort(array $rows, array $definition): array {
		$resolved_rules = $this->table_presentation->sort_rules($definition);
		if (empty($resolved_rules) || empty($rows)) {
			return $rows;
		}

		usort($rows, static function ($a, $b) use ($resolved_rules) {
			foreach ($resolved_rules as $rule) {
				$idx = $rule['index'];
				$dir = $rule['direction'];
				$val_a = $a[$idx] ?? '';
				$val_b = $b[$idx] ?? '';

				if ($val_a === $val_b) {
					continue;
				}

				$cmp = is_numeric($val_a) && is_numeric($val_b)
					? ((float) $val_a < (float) $val_b ? -1 : 1)
					: strnatcasecmp((string) $val_a, (string) $val_b);
				return $dir === 'desc' ? -$cmp : $cmp;
			}
			return 0;
		});

		return $rows;
	}

	private function render_layout_zone_items(array $items, array &$layout_seen, array $ctx, array $allowed_inline): void {
		$table_options = $ctx['raw_options'];
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
							$label = $ctx['button_labels'][$choice];
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
}
