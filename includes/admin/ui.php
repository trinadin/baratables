<?php

if (!defined('ABSPATH')) {
	exit;
}


class BaraTables_Admin_Tab_General {
	public function render(array $context): void {
		$source_type = $context['source_type'] ?? 'wp_query';
		$source_error = $context['source_error'] ?? '';
		$post_types = $context['post_types'] ?? [];
		$taxonomies = $context['taxonomies'] ?? [];
		$current_pts = $context['current_pts'] ?? [];
		$selected_taxonomy = $context['selected_taxonomy'] ?? [];
		$selected_tax_terms = $context['selected_tax_terms'] ?? [];
		$should_show_source_hint = !empty($context['should_show_source_hint']);
		// Single source for the "(Post, Page)" suffix shown beside a taxonomy when more than one
		// post type is selected; it was written out identically at three render sites. Returns the
		// escaped fragment, matching the previous behaviour -- esc_html() at the echo leaves it
		// alone because WordPress passes $double_encode = false.
		$tax_source_hint = static function (array $tax) use ($should_show_source_hint): string {
			if (!$should_show_source_hint || empty($tax['sources'])) {
				return '';
			}
			return ' (' . esc_html(implode(', ', (array) $tax['sources'])) . ')';
		};
		$custom_columns = $context['custom_columns'] ?? [];
		// The grid's header cells are static text, so there is no btbl_custom_columns[] input for
		// the browser to post back. custom_data['columns'] therefore comes back empty from every
		// editor save, and the headers fell back to "Column N" even when the user had named the
		// columns. The names the user actually typed live on the Columns & filters tab, keyed by
		// slug, so read them from there.
		$grid_header_labels = [];
		foreach ((array) ($context['column_records'] ?? []) as $slug => $column_record) {
			if (is_array($column_record) && !empty($column_record['label'])) {
				$grid_header_labels[$slug] = (string) $column_record['label'];
			}
		}
		$custom_rows = $context['custom_rows'] ?? [];
		$custom_rows_count = $context['custom_rows_count'] ?? 5;
		$custom_cols_count = $context['custom_cols_count'] ?? 3;
		$custom_query_pretty = $context['custom_query_pretty'] ?? '';
		$custom_query_raw = $context['custom_query_raw'] ?? '';
		$external_host = $context['external_host'] ?? '';
		$external_port = $context['external_port'] ?? '';
		$external_name = $context['external_name'] ?? '';
		$external_user = $context['external_user'] ?? '';
		$external_pass = $context['external_pass'] ?? '';
		$external_pass_saved = !empty($context['external_pass_saved']);
		$external_table = $context['external_table'] ?? '';
		$external_charset = $context['external_charset'] ?? '';
		$external_ssl = !empty($context['external_ssl']);
		$external_ssl_ca = $context['external_ssl_ca'] ?? '';
		$csv_attachment_id = $context['csv_attachment_id'] ?? 0;
		$csv_delimiter = $context['csv_delimiter'] ?? ',';
		$csv_has_header = !empty($context['csv_has_header']);
		$remote_url = $context['remote_url'] ?? '';
		$remote_format = $context['remote_format'] ?? BaraTables_Remote_Snapshot::FORMAT_CSV;
		$remote_snapshot = isset($context['remote_snapshot']) && is_array($context['remote_snapshot']) ? $context['remote_snapshot'] : [];
		$remote_stale = !empty($remote_snapshot) && BaraTables_Remote_Snapshot::is_stale(
			['url' => $remote_url, 'format' => $remote_format],
			$remote_snapshot
		);
		$active_tab = $context['active_tab'] ?? 'btbl-tab-general';
		$panel_class = $active_tab === 'btbl-tab-general' ? 'btbl-tab-panel is-active' : 'btbl-tab-panel';
		$source_hidden_class = static function(string $target) use ($source_type): string {
			return BaraTables_Admin_Form_Context::source_hidden_class($target, $source_type);
		};
		$tax_filter_hidden = ($source_type !== 'wp_query' || empty($selected_taxonomy)) ? ' is-hidden' : '';
		?>
		<div id="btbl-tab-general" class="<?php echo esc_attr($panel_class); ?>" role="tabpanel" aria-labelledby="btbl-tab-general-label">
			<?php if ($source_error !== '') : ?>
				<div class="notice notice-error inline"><p><?php echo esc_html($source_error); ?></p></div>
			<?php endif; ?>
			<div class="btbl-control-grid">
				<div class="btbl-control">
					<label class="btbl-small-heading" for="btbl_source_type"><?php esc_html_e('Data source', 'baratables'); ?></label>
					<select name="btbl_source_type" id="btbl_source_type">
						<option value="wp_query" <?php selected($source_type, 'wp_query'); ?>><?php esc_html_e('WP query builder', 'baratables'); ?></option>
						<option value="custom_query" <?php selected($source_type, 'custom_query'); ?>><?php esc_html_e('Custom WP query', 'baratables'); ?></option>
						<option value="custom_data" <?php selected($source_type, 'custom_data'); ?>><?php esc_html_e('Manual data', 'baratables'); ?></option>
						<option value="csv" <?php selected($source_type, 'csv'); ?>><?php esc_html_e('CSV file', 'baratables'); ?></option>
						<option value="external_db" <?php selected($source_type, 'external_db'); ?>><?php esc_html_e('External database', 'baratables'); ?></option>
						<option value="remote_snapshot" <?php selected($source_type, 'remote_snapshot'); ?>><?php esc_html_e('Remote snapshot', 'baratables'); ?></option>
					</select>
				</div>
			</div>
			<div class="btbl-control-grid<?php echo esc_attr($source_hidden_class('csv')); ?>" data-btbl-source="csv">
				<div class="btbl-control">
					<label class="btbl-small-heading" for="btbl_csv_attachment_id"><?php esc_html_e('CSV file', 'baratables'); ?></label>
					<div class="btbl-media-row">
						<input type="text" name="btbl_csv_attachment_id" id="btbl_csv_attachment_id" class="small-text" value="<?php echo $csv_attachment_id > 0 ? esc_attr((int) $csv_attachment_id) : ''; ?>" placeholder="<?php echo esc_attr__('No file chosen', 'baratables'); ?>" readonly />
						<button type="button" class="button btbl-media-select" data-target="#btbl_csv_attachment_id" data-frame-title="<?php echo esc_attr__('Select CSV file', 'baratables'); ?>" data-frame-button="<?php echo esc_attr__('Use CSV', 'baratables'); ?>"><?php esc_html_e('Choose file', 'baratables'); ?></button>
						<button type="button" class="button btbl-media-clear" data-target="#btbl_csv_attachment_id" <?php echo empty($csv_attachment_id) ? 'style="display:none;"' : ''; ?>><?php esc_html_e('Clear', 'baratables'); ?></button>
					</div>
				</div>
				<div class="btbl-control">
					<label class="btbl-small-heading" for="btbl_csv_delimiter"><?php esc_html_e('Delimiter', 'baratables'); ?></label>
					<input type="text" name="btbl_csv_delimiter" id="btbl_csv_delimiter" class="small-text" maxlength="1" value="<?php echo esc_attr($csv_delimiter); ?>" />
					<p class="description"><?php esc_html_e('Usually a comma.', 'baratables'); ?></p>
				</div>
				<div class="btbl-control">
					<label class="btbl-small-heading" for="btbl_csv_has_header"><?php esc_html_e('Header row', 'baratables'); ?></label>
					<label class="btbl-flag">
						<input type="hidden" name="btbl_csv_has_header" value="0" />
						<input type="checkbox" name="btbl_csv_has_header" id="btbl_csv_has_header" value="1" <?php checked($csv_has_header); ?> />
						<span class="btbl-flag-text"><?php esc_html_e('First row contains column headers', 'baratables'); ?></span>
					</label>
				</div>
			</div>
			<div class="btbl-control-grid<?php echo esc_attr($source_hidden_class('remote_snapshot')); ?>" data-btbl-source="remote_snapshot">
				<div class="btbl-control">
					<label class="btbl-small-heading" for="btbl_remote_url"><?php esc_html_e('Public source URL', 'baratables'); ?></label>
					<input type="url" name="btbl_remote_url" id="btbl_remote_url" class="large-text" value="<?php echo esc_attr($remote_url); ?>" placeholder="https://example.com/table.csv" />
					<p class="description"><?php esc_html_e('HTTPS only. Authentication and private sources are not supported.', 'baratables'); ?></p>
				</div>
				<div class="btbl-control">
					<label class="btbl-small-heading" for="btbl_remote_format"><?php esc_html_e('Source format', 'baratables'); ?></label>
					<select name="btbl_remote_format" id="btbl_remote_format">
						<option value="csv" <?php selected($remote_format, 'csv'); ?>><?php esc_html_e('CSV with header row', 'baratables'); ?></option>
						<option value="json" <?php selected($remote_format, 'json'); ?>><?php esc_html_e('JSON record array', 'baratables'); ?></option>
						<option value="google_sheets" <?php selected($remote_format, 'google_sheets'); ?>><?php esc_html_e('Public Google Sheet', 'baratables'); ?></option>
					</select>
				</div>
				<div class="btbl-control btbl-remote-snapshot-control">
					<button type="button" class="button btbl-remote-fetch" data-can-fetch="<?php echo $remote_url !== '' ? '1' : '0'; ?>" <?php disabled($remote_url === ''); ?>><?php esc_html_e('Fetch snapshot', 'baratables'); ?></button>
					<p class="description btbl-remote-status" aria-live="polite" data-saved-url="<?php echo esc_attr($remote_url); ?>" data-saved-format="<?php echo esc_attr($remote_format); ?>" data-dirty-message="<?php echo esc_attr__('Save table changes before fetching a snapshot.', 'baratables'); ?>" data-error-message="<?php echo esc_attr__('The snapshot could not be fetched. The previous snapshot was kept.', 'baratables'); ?>">
						<?php if (empty($remote_snapshot)) : ?>
							<?php esc_html_e('No snapshot yet. Save this source, then fetch it.', 'baratables'); ?>
						<?php elseif ($remote_stale) : ?>
							<?php esc_html_e('The last snapshot is from a different saved URL or format. Published pages keep using it until a refresh succeeds.', 'baratables'); ?>
						<?php else : ?>
							<?php
							echo esc_html(sprintf(
								/* translators: 1: retained row count, 2: retained column count, 3: UTC fetch timestamp. */
								__('%1$d rows and %2$d columns fetched at %3$s UTC.', 'baratables'),
								(int) ($remote_snapshot['row_count'] ?? 0),
								(int) ($remote_snapshot['column_count'] ?? 0),
								(string) ($remote_snapshot['fetched_at_utc'] ?? '')
							));
							?>
							<?php if (!empty($remote_snapshot['truncated_rows']) || !empty($remote_snapshot['truncated_columns'])) : ?>
								<?php esc_html_e(' The source exceeded the snapshot limits and was truncated.', 'baratables'); ?>
							<?php endif; ?>
						<?php endif; ?>
					</p>
					<p class="description"><?php esc_html_e('Published pages never contact this URL.', 'baratables'); ?></p>
				</div>
			</div>
			<div class="btbl-control-grid<?php echo esc_attr($source_hidden_class('custom_data')); ?>" data-btbl-source="custom_data">
				<div class="btbl-control">
					<label class="btbl-small-heading" for="btbl_custom_columns_count"><?php esc_html_e('Number of columns', 'baratables'); ?></label>
					<input type="number" name="btbl_custom_columns_count" id="btbl_custom_columns_count" class="small-text" min="1" max="<?php echo esc_attr(BaraTables_Service::MAX_CUSTOM_COLUMNS); ?>" value="<?php echo esc_attr((int) $custom_cols_count); ?>" />
				</div>
				<div class="btbl-control">
					<label class="btbl-small-heading" for="btbl_custom_rows_count"><?php esc_html_e('Number of rows', 'baratables'); ?></label>
					<input type="number" name="btbl_custom_rows_count" id="btbl_custom_rows_count" class="small-text" min="1" max="<?php echo esc_attr(BaraTables_Service::MAX_CUSTOM_ROWS); ?>" value="<?php echo esc_attr((int) $custom_rows_count); ?>" />
				</div>
			</div>
			<div class="btbl-control-grid<?php echo esc_attr($source_hidden_class('custom_data')); ?>" data-btbl-source="custom_data">
				<div class="btbl-control btbl-custom-grid-control">
					<div class="btbl-control-header">
						<div class="btbl-header-stack">
							<label class="btbl-small-heading" for="btbl_custom_grid"><?php esc_html_e('Custom data', 'baratables'); ?></label>
							<p class="description">
								<?php
								echo esc_html(sprintf(
									/* translators: %d is the maximum number of editable custom-data cells. */
									__('Set the column and row counts, then click Update grid. Supports up to %d cells.', 'baratables'),
									BaraTables_Service::MAX_CUSTOM_CELLS
								));
								?>
							</p>
							<p
								class="description btbl-custom-grid-limit is-hidden"
								aria-live="polite"
								<?php /* translators: %1$d is the column count, %2$d is the row count, %3$d is the maximum number of cells. */ ?>
								data-message="<?php echo esc_attr__('Grid size adjusted to %1$d columns and %2$d rows (maximum %3$d cells).', 'baratables'); ?>"
							></p>
						</div>
						<button type="button" class="button" id="btbl_custom_grid_refresh" hidden><?php esc_html_e('Update grid', 'baratables'); ?></button>
						<button type="button" class="button" id="btbl_custom_grid_undo" hidden><?php esc_html_e('Undo grid change', 'baratables'); ?></button>
					</div>
					<?php $allowed_inline = BaraTables_Service::allowed_inline_html(); ?>
					<div
						id="btbl_custom_grid"
						class="btbl-custom-grid"
						data-cols="<?php echo esc_attr((int) $custom_cols_count); ?>"
						data-rows="<?php echo esc_attr((int) $custom_rows_count); ?>"
						data-max-cols="<?php echo esc_attr(BaraTables_Service::MAX_CUSTOM_COLUMNS); ?>"
						data-max-rows="<?php echo esc_attr(BaraTables_Service::MAX_CUSTOM_ROWS); ?>"
						data-max-cells="<?php echo esc_attr(BaraTables_Service::MAX_CUSTOM_CELLS); ?>"
						<?php // translators: %d is the row number. ?>
						data-row-label="<?php echo esc_attr(__('Row %d', 'baratables')); ?>"
						<?php // translators: %d is the column number. ?>
						data-column-label="<?php echo esc_attr(__('Column %d', 'baratables')); ?>"
						<?php // translators: 1: the row number, 2: the column heading. ?>
						data-cell-label="<?php echo esc_attr(__('Row %1$d, %2$s', 'baratables')); ?>"
						data-heading-label="#"
						data-label-move-up="<?php echo esc_attr__('Move row up', 'baratables'); ?>"
						data-label-move-down="<?php echo esc_attr__('Move row down', 'baratables'); ?>"
						data-label-insert="<?php echo esc_attr__('Insert row below', 'baratables'); ?>"
						data-label-duplicate="<?php echo esc_attr__('Duplicate row', 'baratables'); ?>"
						data-label-delete="<?php echo esc_attr__('Delete row', 'baratables'); ?>"
						<?php // translators: %d is the number of filled cells that would be removed. ?>
						data-confirm-shrink-one="<?php echo esc_attr__('Reducing the grid will remove %d filled cell. Continue?', 'baratables'); ?>"
						<?php // translators: %d is the number of filled cells that would be removed. ?>
						data-confirm-shrink-many="<?php echo esc_attr__('Reducing the grid will remove %d filled cells. Continue?', 'baratables'); ?>"
						<?php // translators: %d is the maximum number of rows the grid supports. ?>
						data-at-row-cap="<?php echo esc_attr__('The grid is at its maximum of %d rows.', 'baratables'); ?>"
						<?php // translators: 1: the row limit the pasted data was cut to, 2: the column limit. ?>
						data-paste-capped="<?php echo esc_attr__('The pasted data was cut to the grid limits (%1$d rows, %2$d columns).', 'baratables'); ?>"
					>
						<?php
						// The column headings, resolved once: the header row prints them, and
						// every cell input names itself with one, so a screen-reader user knows
						// which row and column a cell belongs to.
						$grid_col_labels = [];
						for ($c = 0; $c < $custom_cols_count; $c++) {
							// Slug format is fixed by build_custom_dataset(): 'custom:col_' . (index + 1).
							$grid_col_label = (string) ($custom_columns[$c] ?? '');
							if ($grid_col_label === '') {
								$grid_col_label = (string) ($grid_header_labels['custom:col_' . ($c + 1)] ?? '');
							}
							if ($grid_col_label === '') {
								/* translators: %d is the column number. */
								$grid_col_label = sprintf(__('Column %d', 'baratables'), $c + 1);
							}
							$grid_col_labels[$c] = $grid_col_label;
						}
						/* translators: 1: the row number, 2: the column heading. */
						$grid_cell_label = __('Row %1$d, %2$s', 'baratables');
						?>
						<table class="widefat fixed striped">
							<thead>
								<tr>
									<th scope="col" class="btbl-grid-corner">#</th>
									<?php for ($c = 0; $c < $custom_cols_count; $c++) : ?>
										<th scope="col"><?php echo wp_kses($grid_col_labels[$c], $allowed_inline); ?></th>
									<?php endfor; ?>
								</tr>
							</thead>
							<tbody>
								<?php for ($r = 0; $r < $custom_rows_count; $r++) : ?>
									<?php $row_values = $custom_rows[$r] ?? array_fill(0, $custom_cols_count, ''); ?>
									<tr>
										<?php // translators: %d is the row number. ?>
										<th scope="row" class="btbl-grid-rownum" aria-label="<?php echo esc_attr(sprintf(__('Row %d', 'baratables'), $r + 1)); ?>"><?php echo esc_html((string) ($r + 1)); ?></th>
										<?php for ($c = 0; $c < $custom_cols_count; $c++) : ?>
											<td>
												<input type="text" name="btbl_custom_data[<?php echo esc_attr($r); ?>][<?php echo esc_attr($c); ?>]" value="<?php echo esc_attr($row_values[$c] ?? ''); ?>" title="<?php echo esc_attr($row_values[$c] ?? ''); ?>" aria-label="<?php echo esc_attr(sprintf($grid_cell_label, $r + 1, html_entity_decode(wp_strip_all_tags($grid_col_labels[$c]), ENT_QUOTES, 'UTF-8'))); ?>" />
											</td>
										<?php endfor; ?>
									</tr>
								<?php endfor; ?>
							</tbody>
						</table>
					</div>
				</div>
			</div>
			<div class="btbl-control-grid<?php echo esc_attr($source_hidden_class('wp_query')); ?>" data-btbl-source="wp_query">
				<div class="btbl-control">
					<label class="btbl-small-heading" for="btbl_post_type"><?php esc_html_e('Post type', 'baratables'); ?></label>
					<div class="btbl-chip-picker" role="group" aria-label="<?php echo esc_attr__('Post types', 'baratables'); ?>" data-btbl-target="#btbl_post_type">
						<?php foreach ($post_types as $pt => $label) : ?>
							<?php $is_selected = in_array($pt, $current_pts, true); ?>
							<button type="button" class="btbl-chip<?php echo $is_selected ? ' is-selected' : ''; ?>" data-value="<?php echo esc_attr($pt); ?>" aria-pressed="<?php echo $is_selected ? 'true' : 'false'; ?>">
								<?php echo esc_html($label); ?>
							</button>
						<?php endforeach; ?>
					</div>
					<select name="btbl_post_type[]" id="btbl_post_type" class="btbl-chip-source" multiple>
						<?php foreach ($post_types as $pt => $label) : ?>
							<option value="<?php echo esc_attr($pt); ?>" <?php selected(in_array($pt, $current_pts, true)); ?>><?php echo esc_html($label); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="btbl-control btbl-taxonomy-select">
					<label class="btbl-small-heading" for="btbl_taxonomy"><?php esc_html_e('Taxonomy filter', 'baratables'); ?></label>
					<div class="btbl-chip-picker" role="group" aria-label="<?php echo esc_attr__('Taxonomies', 'baratables'); ?>" data-btbl-target="#btbl_taxonomy">
						<?php foreach ($taxonomies as $tax) : ?>
							<?php
							$tax_selected = in_array($tax['slug'], (array) $selected_taxonomy, true);
							$has_terms = !empty($tax['terms']);
							$chip_classes = 'btbl-chip' . ($tax_selected ? ' is-selected' : '') . ($has_terms ? '' : ' is-disabled');
							$chip_disabled = $has_terms ? 'false' : 'true';
							?>
							<button type="button" class="<?php echo esc_attr($chip_classes); ?>" data-value="<?php echo esc_attr($tax['slug']); ?>" aria-pressed="<?php echo $tax_selected ? 'true' : 'false'; ?>" aria-disabled="<?php echo esc_attr($chip_disabled); ?>">
								<?php echo esc_html($tax['label']) . esc_html($tax_source_hint($tax)); ?>
							</button>
						<?php endforeach; ?>
					</div>
					<select name="btbl_taxonomy[]" id="btbl_taxonomy" class="btbl-chip-source" multiple>
						<?php foreach ($taxonomies as $tax) : ?>
							<option value="<?php echo esc_attr($tax['slug']); ?>" <?php selected(in_array($tax['slug'], (array) $selected_taxonomy, true)); ?>>
								<?php echo esc_html($tax['label']) . esc_html($tax_source_hint($tax)); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
			</div>
			<div class="btbl-control btbl-taxonomy-filter<?php echo esc_attr($tax_filter_hidden); ?>" data-btbl-source="wp_query">
				<div class="btbl-taxonomy-term-picker">
					<?php if (empty($taxonomies)) : ?>
						<p class="description"><?php esc_html_e('No taxonomies available for this post type.', 'baratables'); ?></p>
					<?php else : ?>
							<?php foreach ($taxonomies as $tax) : ?>
								<?php
								$tax_selected = in_array($tax['slug'], (array) $selected_taxonomy, true);
								$tax_group_classes = 'btbl-tax-terms-group' . ($tax_selected ? '' : ' is-hidden');
								?>
								<div class="<?php echo esc_attr($tax_group_classes); ?>" data-taxonomy="<?php echo esc_attr($tax['slug']); ?>">
								<strong class="btbl-small-heading"><?php echo esc_html($tax['label']) . esc_html($tax_source_hint($tax)); ?></strong>
								<?php if (empty($tax['terms'])) : ?>
									<p class="description btbl-tax-terms-empty"><?php esc_html_e('No terms found for this taxonomy yet.', 'baratables'); ?></p>
								<?php else : ?>
									<?php
									$selected_terms_for_tax = $selected_tax_terms[$tax['slug']] ?? [];
									$selected_count = count($selected_terms_for_tax);
									$selected_label = $selected_count > 0
										/* translators: %d is the number of selected taxonomy terms. */
										? sprintf(_n('%d term selected', '%d terms selected', $selected_count, 'baratables'), $selected_count)
										: __('No terms selected', 'baratables');
									?>
									<div class="btbl-tax-terms-toolbar">
										<label class="screen-reader-text" for="btbl_tax_search_<?php echo esc_attr($tax['slug']); ?>"><?php esc_html_e('Search terms', 'baratables'); ?></label>
										<input
											type="search"
											id="btbl_tax_search_<?php echo esc_attr($tax['slug']); ?>"
											class="btbl-term-search"
											placeholder="<?php echo esc_attr__('Search terms', 'baratables'); ?>"
											autocomplete="off"
										/>
										<div class="btbl-term-actions">
											<button type="button" class="button-link btbl-term-action" data-action="select-all"><?php esc_html_e('Select all', 'baratables'); ?></button>
											<button type="button" class="button-link btbl-term-action" data-action="clear"><?php esc_html_e('Clear', 'baratables'); ?></button>
										</div>
									</div>
									<?php if (!empty($tax['truncated'])) : ?>
										<p class="description btbl-tax-terms-truncated">
											<?php
											printf(
												/* translators: %d is the maximum number of taxonomy terms shown in the picker. */
												esc_html__('Showing the first %d terms, so "Select all" covers only these. Terms you have already selected are always listed.', 'baratables'),
												(int) BaraTables_Service::MAX_TERM_PICKER_TERMS
											);
											?>
										</p>
									<?php endif; ?>
									<div class="btbl-tax-terms-meta">
										<span
											class="btbl-term-count"
											data-empty="<?php echo esc_attr__('No terms selected', 'baratables'); ?>"
											<?php // translators: %d is the number of selected taxonomy terms. ?>
											data-singular="<?php echo esc_attr__('%d term selected', 'baratables'); ?>"
											<?php // translators: %d is the number of selected taxonomy terms. ?>
											data-plural="<?php echo esc_attr__('%d terms selected', 'baratables'); ?>"
										>
											<?php echo esc_html($selected_label); ?>
										</span>
									</div>
									<div class="btbl-term-grid">
										<?php foreach ($tax['terms'] as $term) : ?>
											<?php $is_term_selected = in_array((int) $term['id'], $selected_terms_for_tax, true); ?>
											<label class="btbl-term-chip<?php echo $is_term_selected ? ' is-selected' : ''; ?>">
												<input
													type="checkbox"
													name="btbl_tax_terms[<?php echo esc_attr($tax['slug']); ?>][]"
													value="<?php echo esc_attr($term['id']); ?>"
													<?php checked($is_term_selected); ?>
												/>
												<span><?php echo esc_html($term['name']); ?></span>
											</label>
										<?php endforeach; ?>
									</div>
									<p class="description btbl-term-empty is-hidden"><?php esc_html_e('No terms match your search.', 'baratables'); ?></p>
								<?php endif; ?>
							</div>
						<?php endforeach; ?>
					<?php endif; ?>
				</div>
			</div>
			<div class="btbl-control-grid<?php echo esc_attr($source_hidden_class('custom_query')); ?>" data-btbl-source="custom_query">
				<div class="btbl-control">
					<div class="btbl-control-header">
						<label class="btbl-small-heading" for="btbl_custom_query_json"><?php esc_html_e('Custom WP_Query args (JSON)', 'baratables'); ?></label>
						<button type="button" class="button btbl-icon-button" id="btbl_custom_query_refresh" hidden aria-label="<?php echo esc_attr__('Load columns', 'baratables'); ?>" title="<?php echo esc_attr__('Load columns', 'baratables'); ?>"><span class="dashicons dashicons-update" aria-hidden="true"></span></button>
					</div>
					<textarea name="btbl_custom_query_json" id="btbl_custom_query_json" class="large-text code btbl-json-check" data-error-target="btbl_custom_query_error" rows="6" placeholder='{"post_type":["post","product"],"posts_per_page":50,"meta_key":"price","meta_query":[{"key":"price","value":10,"compare":">="}],"orderby":{"meta_value_num":"DESC"},"tax_query":[{"taxonomy":"category","field":"slug","terms":["news","events"],"operator":"IN"}]}' spellcheck="false"><?php echo esc_textarea($custom_query_raw !== '' ? $custom_query_raw : $custom_query_pretty); ?></textarea>
					<p class="description"><?php esc_html_e('Only public post types and published posts are queried, and results are capped.', 'baratables'); ?></p>
					<p class="btbl-json-error" id="btbl_custom_query_error" role="alert" hidden><?php esc_html_e('This is not valid JSON. The query will not be saved until you fix it.', 'baratables'); ?></p>
				</div>
			</div>
			<div class="btbl-control-grid<?php echo esc_attr($source_hidden_class('external_db')); ?>" data-btbl-source="external_db">
				<div class="btbl-control">
					<label class="btbl-small-heading" for="btbl_external_host"><?php esc_html_e('DB host', 'baratables'); ?></label>
					<input type="text" name="btbl_external_host" id="btbl_external_host" class="regular-text" value="<?php echo esc_attr($external_host); ?>" placeholder="127.0.0.1" />
				</div>
				<div class="btbl-control">
					<label class="btbl-small-heading" for="btbl_external_port"><?php esc_html_e('Port (optional)', 'baratables'); ?></label>
					<input type="number" name="btbl_external_port" id="btbl_external_port" class="small-text" value="<?php echo esc_attr($external_port); ?>" min="0" />
				</div>
				<div class="btbl-control">
					<label class="btbl-small-heading" for="btbl_external_name"><?php esc_html_e('Database name', 'baratables'); ?></label>
					<input type="text" name="btbl_external_name" id="btbl_external_name" class="regular-text" value="<?php echo esc_attr($external_name); ?>" />
				</div>
				<div class="btbl-control">
					<label class="btbl-small-heading" for="btbl_external_user"><?php esc_html_e('Username', 'baratables'); ?></label>
					<input type="text" name="btbl_external_user" id="btbl_external_user" class="regular-text" value="<?php echo esc_attr($external_user); ?>" />
				</div>
				<div class="btbl-control">
					<label class="btbl-small-heading" for="btbl_external_pass"><?php esc_html_e('Password', 'baratables'); ?></label>
					<input type="password" name="btbl_external_pass" id="btbl_external_pass" class="regular-text" value="<?php echo esc_attr($external_pass); ?>" />
					<?php if ($external_pass_saved) : ?>
						<p class="description"><?php esc_html_e('A password is saved. Leave this blank to keep the existing password.', 'baratables'); ?></p>
					<?php endif; ?>
				</div>
				<div class="btbl-control">
					<label class="btbl-small-heading" for="btbl_external_table"><?php esc_html_e('Table/view name', 'baratables'); ?></label>
					<input type="text" name="btbl_external_table" id="btbl_external_table" class="regular-text" value="<?php echo esc_attr($external_table); ?>" />
					<p class="description"><?php esc_html_e('Read-only, with results capped. Save the table to connect and load its columns.', 'baratables'); ?></p>
				</div>
			<div class="btbl-control">
				<label class="btbl-small-heading" for="btbl_external_charset"><?php esc_html_e('Charset (optional)', 'baratables'); ?></label>
				<input type="text" name="btbl_external_charset" id="btbl_external_charset" class="regular-text" value="<?php echo esc_attr($external_charset); ?>" placeholder="utf8mb4" />
			</div>
			<div class="btbl-control">
				<label class="btbl-small-heading" for="btbl_external_ssl"><?php esc_html_e('Encrypt the connection (TLS)', 'baratables'); ?></label>
				<label for="btbl_external_ssl">
					<input type="checkbox" name="btbl_external_ssl" id="btbl_external_ssl" value="1" <?php checked($external_ssl); ?> />
					<?php esc_html_e('Use TLS', 'baratables'); ?>
				</label>
				<p class="description"><?php esc_html_e('Without TLS the database password and every row travel in plain text. Turn this on for any host outside your own machine.', 'baratables'); ?></p>
			</div>
			<div class="btbl-control">
				<label class="btbl-small-heading" for="btbl_external_ssl_ca"><?php esc_html_e('CA certificate path', 'baratables'); ?></label>
				<input type="text" name="btbl_external_ssl_ca" id="btbl_external_ssl_ca" class="regular-text" value="<?php echo esc_attr($external_ssl_ca); ?>" placeholder="/etc/ssl/certs/mysql-ca.pem" />
				<p class="description"><?php esc_html_e('Required when TLS is on: the absolute path on the server to a CA certificate that signed the database certificate. PHP\'s MySQL driver cannot use the system trust store, so without this path the server cannot be verified.', 'baratables'); ?></p>
			</div>
			</div>
		</div>
		<?php
	}
}


class BaraTables_Admin_Tab_Columns {
	public function render(array $context): void {
		$source_type = $context['source_type'] ?? 'wp_query';
		$fields = $context['fields'] ?? [];
		$display_columns = $context['display_columns'] ?? [];
		$taxonomies = $context['taxonomies'] ?? [];
		$should_show_source_hint = !empty($context['should_show_source_hint']);
		$tax_sources = $fields['tax_sources'] ?? [];
		$meta_sources = $fields['meta_sources'] ?? [];
		$missing_meta = $context['missing_meta'] ?? [];
		$selected_columns = $context['selected_columns'] ?? [];
		$column_records = $context['column_records'] ?? [];
		$filter_order = $context['filter_order'] ?? [];
		$active_tab = $context['active_tab'] ?? 'btbl-tab-general';
		$column_option_state = ['records' => $column_records];
		$panel_class = $active_tab === 'btbl-tab-columns' ? 'btbl-tab-panel is-active' : 'btbl-tab-panel';
		?>
		<div id="btbl-tab-columns" class="<?php echo esc_attr($panel_class); ?>" role="tabpanel" aria-labelledby="btbl-tab-columns-label">
			<div class="btbl-options-row btbl-options-inline btbl-align-right">
				<label class="btbl-inline">
					<input type="checkbox" id="btbl_select_all_columns" />
					<?php esc_html_e('Select/deselect all columns', 'baratables'); ?>
				</label>
			</div>
			<fieldset class="btbl-fieldset">
				<div class="btbl-columns">
						<?php if ($source_type === 'csv') : ?>
							<?php
							$this->render_simple_column_group(
								$display_columns,
								__('CSV columns', 'baratables'),
								__('No columns yet. Choose a CSV file on the Source tab to load its headers.', 'baratables'),
								'core:',
								$column_option_state,
								$selected_columns
							);
							?>
						<?php elseif ($source_type === 'custom_data') : ?>
							<?php
							$this->render_simple_column_group(
								$display_columns,
								__('Custom columns', 'baratables'),
								__('Set your column and row counts in the Source tab to manage these columns.', 'baratables'),
								'custom:',
								$column_option_state,
								$selected_columns
							);
							?>
						<?php elseif ($source_type === 'external_db') : ?>
							<?php
							$this->render_simple_column_group(
								$display_columns,
								__('External columns', 'baratables'),
								__('No columns detected yet. Save after entering connection details to load a preview.', 'baratables'),
								'external:',
								$column_option_state,
								$selected_columns
							);
							?>
						<?php elseif ($source_type === 'remote_snapshot') : ?>
							<?php
							$this->render_simple_column_group(
								$display_columns,
								__('Snapshot columns', 'baratables'),
								__('No columns yet. Save the remote source, then fetch its first snapshot.', 'baratables'),
								'custom:',
								$column_option_state,
								$selected_columns
							);
							?>
							<?php else : ?>
								<div>
									<strong class="btbl-small-heading"><?php esc_html_e('Core fields', 'baratables'); ?></strong>
									<?php foreach ($fields['core'] as $key => $label) : ?>
										<?php
										$slug = 'core:' . $key;
										$this->render_field_column_option($slug, $label, $column_option_state, in_array($slug, $selected_columns, true));
										?>
									<?php endforeach; ?>
								</div>
								<?php if (!empty($fields['tax'])) : ?>
									<div>
										<strong class="btbl-small-heading"><?php esc_html_e('Taxonomies', 'baratables'); ?></strong>
										<?php foreach ($fields['tax'] as $tax_slug => $tax_label) : ?>
											<?php
											$slug = 'tax:' . $tax_slug;
											$source_names = $should_show_source_hint ? (array) ($tax_sources[$tax_slug] ?? []) : [];
											$this->render_field_column_option($slug, $tax_label, $column_option_state, in_array($slug, $selected_columns, true), $source_names);
											?>
										<?php endforeach; ?>
									</div>
								<?php endif; ?>
								<div>
									<strong class="btbl-small-heading"><?php esc_html_e('Custom meta', 'baratables'); ?></strong>
									<?php if (empty($fields['meta'])) : ?>
										<p class="description"><?php esc_html_e('No meta keys detected for this post type yet.', 'baratables'); ?></p>
									<?php else : ?>
										<?php foreach ($fields['meta'] as $meta_key) : ?>
											<?php
											$slug = 'meta:' . $meta_key;
											$label = $this->format_meta_label($meta_key);
											$source_names = $should_show_source_hint ? (array) ($meta_sources[$meta_key] ?? []) : [];
											$this->render_field_column_option($slug, $label, $column_option_state, in_array($slug, $selected_columns, true), $source_names);
											?>
										<?php endforeach; ?>
									<?php endif; ?>
										<?php if (!empty($missing_meta)) : ?>
											<p class="description"><?php esc_html_e('Meta keys currently selected that are not detected for this post type:', 'baratables'); ?></p>
											<?php foreach ($missing_meta as $meta_key) : ?>
												<?php
												$slug = 'meta:' . $meta_key;
												$label = $this->format_meta_label($meta_key);
												$source_names = $should_show_source_hint ? (array) ($meta_sources[$meta_key] ?? []) : [];
												$this->render_field_column_option($slug, $label, $column_option_state, true, $source_names);
												?>
											<?php endforeach; ?>
										<?php endif; ?>
								</div>
							<?php endif; ?>
						</div>
			</fieldset>
			<div class="btbl-selected-order">
				<strong class="btbl-small-heading"><?php esc_html_e('Selected column order', 'baratables'); ?></strong>
				<p class="description"><?php esc_html_e('Drag to reorder.', 'baratables'); ?></p>
				<ul id="btbl-column-order-list" class="btbl-sortable-list" data-keyboard-hint="<?php esc_attr_e('Use the up and down arrow keys to reorder.', 'baratables'); ?>" data-label-moved="<?php esc_attr(/* translators: 1: the name of the item that moved, 2: its new position. */ __('Moved %1$s to position %2$d.', 'baratables')); ?>" aria-label="<?php esc_attr_e('Selected column order', 'baratables'); ?>"></ul>
				<input type="hidden" name="btbl_column_order" id="btbl_column_order" value="<?php echo esc_attr(implode("\n", $selected_columns)); ?>" />
				<hr class="btbl-order-separator" />
				<strong class="btbl-small-heading"><?php esc_html_e('Selected filter order', 'baratables'); ?></strong>
				<p class="description"><?php esc_html_e('Drag to reorder.', 'baratables'); ?></p>
				<ul id="btbl-filter-order-list" class="btbl-sortable-list" data-keyboard-hint="<?php esc_attr_e('Use the up and down arrow keys to reorder.', 'baratables'); ?>" data-label-moved="<?php esc_attr(/* translators: 1: the name of the item that moved, 2: its new position. */ __('Moved %1$s to position %2$d.', 'baratables')); ?>" aria-label="<?php esc_attr_e('Selected filter order', 'baratables'); ?>"></ul>
				<input type="hidden" name="btbl_filter_order" id="btbl_filter_order" value="<?php echo esc_attr(implode("\n", $filter_order)); ?>" />
			</div>
			</div>
			<?php
		}

	private function render_simple_column_group(array $columns, string $heading, string $empty_message, string $default_prefix, array $base_state, array $selected_columns): void {
		?>
		<div>
			<strong class="btbl-small-heading"><?php echo esc_html($heading); ?></strong>
			<?php if (empty($columns)) : ?>
				<p class="description"><?php echo esc_html($empty_message); ?></p>
				</div>
				<?php return; ?>
			<?php endif; ?>
			<?php
			$boxes = [];
			foreach ($columns as $col) {
				$slug_prefix = isset($col['source']) && $col['source'] !== 'core' ? sanitize_key($col['source']) . ':' : $default_prefix;
				$slug = $slug_prefix . $col['key'];
				$label_display = $col['label'] ?? $col['key'];
				$state = $base_state;
				$state['checked'] = in_array($slug, $selected_columns, true);
				ob_start();
				$this->render_column_option($slug, $label_display, $label_display, $state);
				$boxes[] = (string) ob_get_clean();
			}
			// Two independent column-major stacks (laid side by side on desktop via admin.css):
			// DOM order stays the natural order so the single-column/mobile view reads top-down,
			// while each desktop stack flows on its own -- expanding one never gaps the other.
			$mid = (int) ceil(count($boxes) / 2);
			?>
			<div class="btbl-column-cols">
				<div class="btbl-column-col">
					<?php foreach (array_slice($boxes, 0, $mid) as $box) {
						echo $box; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_column_option() escapes its own output.
					} ?>
				</div>
				<div class="btbl-column-col">
					<?php foreach (array_slice($boxes, $mid) as $box) {
						echo $box; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_column_option() escapes its own output.
					} ?>
				</div>
			</div>
		</div>
		<?php
	}

	private function render_field_column_option(string $slug, string $label, array $base_state, bool $checked, array $source_names = [], bool $allow_sort = true): void {
		$display_label = $label;
		if (!empty($source_names)) {
			$display_label .= ' (' . esc_html(implode(', ', $source_names)) . ')';
		}
		$state = $base_state;
		$state['checked'] = $checked;
		$this->render_column_option($slug, $display_label, $label, $state, $allow_sort);
	}

	private function format_meta_label(string $meta_key): string {
		$label = ucwords(str_replace(['_', '-'], ' ', $meta_key));
		return $label !== '' ? $label : $meta_key;
	}

	private function render_column_option(string $slug, string $display_label, string $default_label, array $state, bool $allow_sort = true): void {
		$records = isset($state['records']) && is_array($state['records']) ? $state['records'] : [];
		$column = isset($records[$slug]) && is_array($records[$slug]) ? $records[$slug] : [];
		$is_checked = !empty($state['checked']);

		// The heading field holds the user's custom name, or is left empty (with the default
		// shown as a placeholder) when the column is auto-labeled -- so a blank submit means
		// "use the default" and is captured as auto_label at save.
		$is_auto_label = !empty($column['auto_label']);
		$custom_label_value = $is_auto_label ? '' : (string) ($column['custom_label'] ?? '');
		$filter_label_value = array_key_exists('filter_label', $column) && $column['filter_label'] !== null
			? (string) $column['filter_label']
			: $default_label;
		$hide_title_checked = !empty($column['hide_title']);
		$hidden_checked = !empty($column['hidden']);
		$searchable_checked = array_key_exists('searchable', $column) ? !empty($column['searchable']) : true;
		$current_filter = (string) ($column['filter'] ?? 'none');
		$sort_enabled = !empty($column['sort_enabled']);
		$sort_priority_val = $column['sort_priority'] ?? '';
		if ($sort_priority_val !== '' && (int) $sort_priority_val < 1) {
			$sort_priority_val = '';
		}
		if ($sort_priority_val === '' && $sort_enabled) {
			$sort_priority_val = '1';
		}
		$sort_direction_val = (string) ($column['sort_direction'] ?? 'asc');
		$filter_sort_val = (string) ($column['filter_sort'] ?? '');
		$filter_type_priority_val = $column['filter_type_priority'] ?? '';
		if (is_array($filter_type_priority_val)) {
			$lines = [];
			foreach ($filter_type_priority_val as $priority_item) {
				if (!is_array($priority_item)) {
					continue;
				}
				$type = isset($priority_item['type']) ? (string) $priority_item['type'] : '';
				if ($type === '') {
					continue;
				}
				$direction = isset($priority_item['direction']) ? (string) $priority_item['direction'] : 'asc';
				$lines[] = $type . ' => ' . $direction;
			}
			$filter_type_priority_val = implode("\n", $lines);
		}
		if ($filter_sort_val === 'none') {
			$filter_sort_val = 'custom';
		}
		$dropdown_multi_checked = !empty($column['dropdown_multi']);
		$dropdown_search_checked = !empty($column['dropdown_search']);
		$sortable_checked = array_key_exists('sortable', $column) ? !empty($column['sortable']) : true;
		$date_format_val = (string) ($column['date_format'] ?? '');
		$format_date_checked = !empty($column['format_date']) || $date_format_val !== '';
		$data_type_val = BaraTables_Cell_Semantics::normalize_type($column['data_type'] ?? 'auto');
		$conditional_rules_text = BaraTables_Cell_Semantics::rules_to_text(
			isset($column['conditional_rules']) && is_array($column['conditional_rules']) ? $column['conditional_rules'] : []
		);
		$footer_aggregate_val = BaraTables_Cell_Semantics::normalize_aggregate($column['footer_aggregate'] ?? 'none', $data_type_val);
		$filter_values_text = '';
		if (isset($column['filter_values']) && is_array($column['filter_values'])) {
			$lines = [];
			foreach ($column['filter_values'] as $item) {
				if (is_array($item)) {
					$label_val = isset($item['label']) ? (string) $item['label'] : '';
					$value_val = isset($item['value']) ? (string) $item['value'] : '';
					$search_terms = isset($item['search_terms']) && is_array($item['search_terms'])
						? array_values(array_map('strval', $item['search_terms']))
						: [];
					if (empty($search_terms)) {
						if ($value_val !== '') {
							$search_terms = [$value_val];
						} elseif ($label_val !== '') {
							$search_terms = [$label_val];
						}
					}
					if ($label_val === '') {
						$label_val = $value_val !== '' ? $value_val : (string) ($search_terms[0] ?? '');
					}
					if ($label_val === '') {
						continue;
					}
					$search_part = implode(', ', array_map('strval', $search_terms));
					$has_blank = in_array('', $search_terms, true);
					$should_show_mapping = $has_blank || count($search_terms) > 1 || ($search_part !== '' && $search_part !== $label_val);
					$lines[] = $should_show_mapping ? ($label_val . ' => ' . $search_part) : $label_val;
				} else {
					$item_str = trim((string) $item);
					if ($item_str !== '') {
						$lines[] = $item_str;
					}
				}
			}
			$filter_values_text = implode("\n", $lines);
		}
		$priority_has_value = $filter_type_priority_val !== '' && $filter_type_priority_val !== '[]' && $filter_type_priority_val !== '{}';
		if ($filter_sort_val === '') {
			$filter_sort_val = ($filter_values_text !== '' || $priority_has_value) ? 'custom' : 'asc';
		}
		if (!in_array($filter_sort_val, ['asc', 'desc', 'custom'], true)) {
			$filter_sort_val = 'asc';
		}
		// "Format as date" is offered for the two WordPress date columns plus every source whose
		// cells are free-form values that can hold a date: post meta, the manual grid, CSV and
		// external DB. The engine already formats all of them (resolve_value for core/meta, the
		// manual-grid date map, and apply_ordered_date_formats for CSV/external) -- this control
		// was gated to core:post_date/post_modified only, so on those sources the checkbox was
		// rendered `is-hidden` (display:none !important) and could never be switched on, despite
		// the 1.1.1 changelog announcing date formatting "on every data source".
		// `tax:` is excluded on purpose: taxonomy columns render term names, never dates.
		$is_date_candidate = preg_match('/^(?:core:(?:post_date|post_modified)$|(?:meta|custom|csv|external):)/', strtolower($slug)) === 1;

		$slug_attr = $slug;
		$allowed_inline = BaraTables_Service::allowed_inline_html();
		$display_label_html = wp_kses($display_label, $allowed_inline);
		$sort_direction_is_desc = $sort_direction_val === 'desc';
		$filter_sort_is_custom = $filter_sort_val === 'custom';
		$column_checkbox_id = 'btbl_col_' . md5($slug_attr);
		$column_options_id = $column_checkbox_id . '_options';
		// The gear button names the column it opens. Five buttons all called "Options"
		// are five identical controls to a screen-reader user.
		// The entities are decoded first: strip_tags() leaves them alone, so a heading
		// like "Revenue &amp; Costs" would be announced as "Revenue and amp semicolon Costs".
		$column_options_name = html_entity_decode(wp_strip_all_tags($display_label), ENT_QUOTES, 'UTF-8');
		if ($column_options_name === '') {
			$column_options_name = $slug_attr;
		}
		?>
			<div class="btbl-checkbox">
				<span class="btbl-checkbox-top">
					<label class="btbl-checkbox-main" for="<?php echo esc_attr($column_checkbox_id); ?>">
						<input type="checkbox" id="<?php echo esc_attr($column_checkbox_id); ?>" name="btbl_columns[]" value="<?php echo esc_attr($slug_attr); ?>" data-label="<?php echo esc_attr($display_label_html); ?>" <?php checked($is_checked); ?> />
						<span class="btbl-field-name"><?php echo wp_kses($display_label_html, $allowed_inline); ?></span>
					</label>
					<button type="button" class="btbl-options-toggle <?php echo $is_checked ? '' : 'is-hidden'; ?>" aria-expanded="false" aria-controls="<?php echo esc_attr($column_options_id); ?>" aria-label="<?php echo esc_attr(sprintf(/* translators: %s is the name of the column or setting the button belongs to. */ __('Options for %s', 'baratables'), $column_options_name)); ?>">
						<span class="dashicons dashicons-admin-generic" aria-hidden="true"></span>
					</button>
				</span>
			<span class="btbl-field-controls">
				<div class="btbl-field-options-body" id="<?php echo esc_attr($column_options_id); ?>">
						<p class="btbl-gear-section-heading"><?php esc_html_e('Column', 'baratables'); ?></p>
					<div class="btbl-options-row btbl-options-inline">
						<div class="btbl-inline">
							<span class="btbl-small-label"><?php esc_html_e('Reference', 'baratables'); ?></span>
							<code class="btbl-shortcode btbl-field-ref" data-shortcode="<?php echo esc_attr($slug_attr); ?>" data-copied-label="<?php echo esc_attr__('Copied', 'baratables'); ?>" tabindex="0" role="button" title="<?php echo esc_attr(sprintf(/* translators: %s is the column reference slug. */ __('Copy column reference: %s', 'baratables'), $slug_attr)); ?>" aria-label="<?php echo esc_attr(sprintf(/* translators: %s is the column reference slug. */ __('Copy column reference: %s', 'baratables'), $slug_attr)); ?>"><?php echo esc_html($slug_attr); ?></code>
						</div>
					</div>
					<div class="btbl-options-row btbl-options-inline">
						<label class="btbl-inline">
							<span class="btbl-small-label"><?php esc_html_e('Column heading', 'baratables'); ?></span>
							<input type="text" name="btbl_custom_labels[<?php echo esc_attr($slug_attr); ?>]" value="<?php echo esc_attr($custom_label_value); ?>" placeholder="<?php echo esc_attr($default_label); ?>" data-default-label="<?php echo esc_attr($default_label); ?>" />
						</label>
					</div>
					<div class="btbl-options-row btbl-options-inline">
						<label class="btbl-inline">
							<input type="checkbox" class="btbl-hide-title" name="btbl_hide_title[<?php echo esc_attr($slug_attr); ?>]" value="1" <?php checked($hide_title_checked); ?> />
							<?php esc_html_e('Hide heading', 'baratables'); ?>
						</label>
					</div>
					<div class="btbl-options-row btbl-options-inline">
						<label class="btbl-inline">
							<input type="checkbox" class="btbl-hide-column" name="btbl_hide_column[<?php echo esc_attr($slug_attr); ?>]" value="1" <?php checked($hidden_checked); ?> />
							<?php esc_html_e('Hide column', 'baratables'); ?>
						</label>
						<?php if ($current_filter !== 'none') : ?>
							<p class="description btbl-field-hint"><?php esc_html_e('A hidden column is not offered to visitors as a filter.', 'baratables'); ?></p>
						<?php endif; ?>
					</div>
					<div class="btbl-options-row btbl-options-inline">
						<label class="btbl-inline">
							<input type="checkbox" class="btbl-searchable-toggle" name="btbl_searchable[<?php echo esc_attr($slug_attr); ?>]" value="1" <?php checked($searchable_checked); ?> />
							<?php esc_html_e('Searchable', 'baratables'); ?>
						</label>
						<p class="description btbl-field-hint"><?php esc_html_e('Include this column when visitors type in the table search box.', 'baratables'); ?></p>
					</div>
					<div class="btbl-options-row btbl-options-inline">
						<label class="btbl-inline">
							<input type="hidden" name="btbl_sortable[<?php echo esc_attr($slug_attr); ?>]" value="0" />
							<input type="checkbox" class="btbl-sortable-toggle" name="btbl_sortable[<?php echo esc_attr($slug_attr); ?>]" value="1" <?php checked($sortable_checked); ?> />
							<?php esc_html_e('Sortable', 'baratables'); ?>
						</label>
						<p class="description btbl-field-hint"><?php esc_html_e('Let visitors click the column header to sort by this column.', 'baratables'); ?></p>
					</div>
					<?php if ($allow_sort) : ?>
					<div class="btbl-options-row btbl-options-inline">
						<label class="btbl-inline">
							<input type="checkbox" class="btbl-sort-enabled" name="btbl_sort_enabled[<?php echo esc_attr($slug_attr); ?>]" value="1" <?php checked($sort_enabled); ?> />
							<?php esc_html_e('Sort by default', 'baratables'); ?>
						</label>
					</div>
					<div class="btbl-options-row btbl-options-inline">
						<label class="btbl-inline">
							<span class="btbl-small-label"><?php esc_html_e('Priority', 'baratables'); ?></span>
							<input type="number" min="0" step="1" class="small-text btbl-sort-priority" name="btbl_sort_priority[<?php echo esc_attr($slug_attr); ?>]" value="<?php echo esc_attr($sort_priority_val); ?>" placeholder="0" />
						</label>
						<label class="btbl-inline">
							<span class="btbl-small-label"><?php esc_html_e('Direction', 'baratables'); ?></span>
							<select name="btbl_sort_direction[<?php echo esc_attr($slug_attr); ?>]" class="btbl-sort-direction">
								<option value="asc" <?php selected(!$sort_direction_is_desc); ?>><?php esc_html_e('Ascending', 'baratables'); ?></option>
								<option value="desc" <?php selected($sort_direction_is_desc); ?>><?php esc_html_e('Descending', 'baratables'); ?></option>
							</select>
						</label>
					</div>
					<?php endif; ?>
					<div class="btbl-options-row btbl-options-inline btbl-date-format-row <?php echo $is_date_candidate ? '' : 'is-hidden'; ?>" data-date-candidate="<?php echo $is_date_candidate ? '1' : '0'; ?>">
						<label class="btbl-inline">
							<input type="hidden" name="btbl_format_date[<?php echo esc_attr($slug_attr); ?>]" value="0" />
							<input type="checkbox" class="btbl-format-date-toggle" name="btbl_format_date[<?php echo esc_attr($slug_attr); ?>]" value="1" <?php checked($format_date_checked); ?> />
							<?php esc_html_e('Format as date', 'baratables'); ?>
						</label>
						<label class="btbl-inline">
							<span class="btbl-small-label"><?php esc_html_e('PHP date format', 'baratables'); ?></span>
							<input type="text" class="btbl-date-format-input" name="btbl_date_format[<?php echo esc_attr($slug_attr); ?>]" value="<?php echo esc_attr($date_format_val); ?>" placeholder="<?php echo esc_attr(get_option('date_format')); ?>" />
						</label>
					</div>
					<p class="btbl-gear-section-heading"><?php esc_html_e('Numeric values', 'baratables'); ?></p>
					<div class="btbl-options-row btbl-options-inline">
						<label class="btbl-inline">
							<span class="btbl-small-label"><?php esc_html_e('Value type', 'baratables'); ?></span>
							<select name="btbl_data_type[<?php echo esc_attr($slug_attr); ?>]" class="btbl-data-type-select">
								<option value="auto" <?php selected($data_type_val === 'auto'); ?>><?php esc_html_e('Automatic', 'baratables'); ?></option>
								<option value="number" <?php selected($data_type_val === 'number'); ?>><?php esc_html_e('Number', 'baratables'); ?></option>
							</select>
						</label>
						<p class="description btbl-field-hint"><?php esc_html_e('Number accepts plain decimals such as -12.5. Currency symbols, percentages, grouping separators, and words are left unchanged but excluded from numeric rules and totals.', 'baratables'); ?></p>
					</div>
					<div class="btbl-number-options <?php echo $data_type_val === 'number' ? '' : 'is-hidden'; ?>">
						<div class="btbl-options-row btbl-options-inline">
							<label class="btbl-inline">
								<span class="btbl-small-label"><?php esc_html_e('Footer calculation', 'baratables'); ?></span>
								<select name="btbl_footer_aggregate[<?php echo esc_attr($slug_attr); ?>]" class="btbl-footer-aggregate-select">
									<option value="none" <?php selected($footer_aggregate_val === 'none'); ?>><?php esc_html_e('None', 'baratables'); ?></option>
									<option value="sum" <?php selected($footer_aggregate_val === 'sum'); ?>><?php esc_html_e('Sum', 'baratables'); ?></option>
									<option value="average" <?php selected($footer_aggregate_val === 'average'); ?>><?php esc_html_e('Average', 'baratables'); ?></option>
									<option value="min" <?php selected($footer_aggregate_val === 'min'); ?>><?php esc_html_e('Minimum', 'baratables'); ?></option>
									<option value="max" <?php selected($footer_aggregate_val === 'max'); ?>><?php esc_html_e('Maximum', 'baratables'); ?></option>
								</select>
							</label>
							<p class="description btbl-field-hint"><?php esc_html_e('Calculates all loaded rows that match the visitor\'s current search and filters, not only the current page.', 'baratables'); ?></p>
						</div>
						<div class="btbl-options-row btbl-options-inline">
							<label class="btbl-inline">
								<span class="btbl-small-label"><?php esc_html_e('Conditional styles', 'baratables'); ?></span>
								<textarea name="btbl_conditional_rules[<?php echo esc_attr($slug_attr); ?>]" class="btbl-conditional-rules" rows="4" placeholder="<?php esc_attr_e("< 0 => danger\n>= 100 => success", 'baratables'); ?>"><?php echo esc_textarea($conditional_rules_text); ?></textarea>
							</label>
							<p class="description btbl-field-hint"><?php esc_html_e('One rule per line, checked from top to bottom. Operators: <, <=, =, !=, >=, >. Styles: accent, success, warning, danger.', 'baratables'); ?></p>
						</div>
					</div>
					<p class="btbl-gear-section-heading"><?php esc_html_e('Filter', 'baratables'); ?></p>
					<div class="btbl-options-row">
						<label class="btbl-inline">
							<span class="btbl-small-label"><?php esc_html_e('Filter type', 'baratables'); ?></span>
							<select name="btbl_filters[<?php echo esc_attr($slug_attr); ?>]" class="btbl-filter-select">
								<option value="none"><?php esc_html_e('No filter', 'baratables'); ?></option>
								<option value="dropdown" <?php selected($current_filter === 'dropdown'); ?>><?php esc_html_e('Dropdown', 'baratables'); ?></option>
								<option value="checkbox" <?php selected($current_filter === 'checkbox'); ?>><?php esc_html_e('Checkboxes', 'baratables'); ?></option>
								<option value="radio" <?php selected($current_filter === 'radio'); ?>><?php esc_html_e('Radio', 'baratables'); ?></option>
							</select>
						</label>
						<label class="btbl-inline">
							<span class="btbl-small-label"><?php esc_html_e('Filter sort', 'baratables'); ?></span>
							<select name="btbl_filter_sort[<?php echo esc_attr($slug_attr); ?>]" class="btbl-filter-sort">
								<option value="asc" <?php selected($filter_sort_val === 'asc'); ?>><?php esc_html_e('Ascending', 'baratables'); ?></option>
								<option value="desc" <?php selected($filter_sort_val === 'desc'); ?>><?php esc_html_e('Descending', 'baratables'); ?></option>
								<option value="custom" <?php selected($filter_sort_val === 'custom'); ?>><?php esc_html_e('Custom', 'baratables'); ?></option>
							</select>
						</label>
					</div>
					<div class="btbl-options-row btbl-filter-sort-row <?php echo ($current_filter !== 'none' && $filter_sort_is_custom) ? '' : 'is-hidden'; ?>">
						<label class="btbl-inline">
							<span class="btbl-small-label"><?php esc_html_e('Data type priority', 'baratables'); ?></span>
							<textarea name="btbl_filter_type_priority[<?php echo esc_attr($slug_attr); ?>]" rows="3" placeholder="<?php esc_attr_e("text => asc\nnumber => desc\ndate => asc", 'baratables'); ?>"><?php echo esc_textarea($filter_type_priority_val); ?></textarea>
						</label>
					</div>
					<div class="btbl-options-row btbl-filter-label-row">
						<label class="btbl-inline">
							<span class="btbl-small-label"><?php esc_html_e('Filter heading', 'baratables'); ?></span>
							<input type="text" name="btbl_filter_labels[<?php echo esc_attr($slug_attr); ?>]" value="<?php echo esc_attr($filter_label_value); ?>" />
						</label>
					</div>
					<div class="btbl-options-row btbl-filter-values-row">
						<label class="btbl-inline">
							<span class="btbl-small-label"><?php esc_html_e('Custom filter values', 'baratables'); ?></span>
							<textarea name="btbl_filter_values[<?php echo esc_attr($slug_attr); ?>]" rows="3" placeholder="<?php esc_attr_e('Label => search1, search2', 'baratables'); ?>"><?php echo esc_textarea($filter_values_text); ?></textarea>
						</label>
						<p class="description btbl-field-hint">
							<?php esc_html_e('One mapping per line: label on the left, the value(s) it matches on the right. For example:', 'baratables'); ?>
							<code><?php echo esc_html('Available => in stock, available'); ?></code>
						</p>
					</div>
					<div class="btbl-options-row">
						<label class="btbl-inline">
							<input type="checkbox" name="btbl_dropdown_multi[<?php echo esc_attr($slug_attr); ?>]" value="1" <?php checked($dropdown_multi_checked); ?> />
							<?php esc_html_e('Allow multiple', 'baratables'); ?>
						</label>
					</div>
					<div class="btbl-options-row">
						<label class="btbl-inline">
							<input type="checkbox" name="btbl_dropdown_search[<?php echo esc_attr($slug_attr); ?>]" value="1" <?php checked($dropdown_search_checked); ?> />
							<?php esc_html_e('Filter search box', 'baratables'); ?>
						</label>
						<p class="description btbl-field-hint"><?php esc_html_e('Adds a search box inside this filter\'s dropdown.', 'baratables'); ?></p>
					</div>
				</div>
			</span>
		</div>
		<?php
	}
}


class BaraTables_Admin_Tab_Table {

	/**
	 * The gear button that reveals one flag setting's inline options. Every flag control
	 * renders the same button shape, differing only in the setting it names, whether the
	 * flag is currently off (disabled gear), and the options block it controls.
	 */
	private static function render_flag_options_toggle(string $setting_name, bool $disabled, string $controls_id = ''): void {
		$controls_attr = $controls_id !== '' ? ' aria-controls="' . esc_attr($controls_id) . '"' : '';
		?>
		<button type="button" class="btbl-options-toggle btbl-flag-options-toggle" aria-expanded="false"<?php echo $controls_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Assembled from escaped parts. ?> aria-label="<?php echo esc_attr(sprintf(/* translators: %s is the name of the column or setting the button belongs to. */ __('Options for %s', 'baratables'), $setting_name)); ?>" <?php disabled($disabled); ?>>
			<span class="dashicons dashicons-admin-generic" aria-hidden="true"></span>
		</button>
		<?php
	}

	public function render(array $context): void {
		$table_options = $context['table_options'] ?? [];
		$option_schema = BaraTables_Service::get_table_option_schema();
		$active_tab = $context['active_tab'] ?? 'btbl-tab-general';
		$panel_class = $active_tab === 'btbl-tab-table' ? 'btbl-tab-panel is-active' : 'btbl-tab-panel';
		$flag_keys = [];
		$inline_controls = [];
		$layout_features = [];
		$layout_dependencies = [];
		$layout_zones = [];
		$buttons_config = null;
		$server_side_config = null;
		foreach ($option_schema as $key => $config) {
			$editor_group = $config['editor_group'] ?? '';
			if ($editor_group === 'controls') {
				$flag_keys[] = $key;
			} elseif ($editor_group === 'inline' && !empty($config['editor_parent'])) {
				$inline_controls[$config['editor_parent']][(int) ($config['editor_order'] ?? 0)] = $key;
			} elseif ($editor_group === 'layout') {
				$layout_zones[$key] = (string) ($config['editor_label'] ?? $config['label'] ?? $key);
				if (empty($layout_features) && !empty($config['choices']) && is_array($config['choices'])) {
					$layout_features = $config['choices'];
				}
				if (empty($layout_dependencies) && !empty($config['choice_dependencies']) && is_array($config['choice_dependencies'])) {
					$layout_dependencies = $config['choice_dependencies'];
				}
			} elseif ($editor_group === 'buttons') {
				$buttons_config = ['key' => $key, 'config' => $config];
			} elseif ($editor_group === 'server_side') {
				$server_side_config = ['key' => $key, 'config' => $config];
			}
		}
		foreach ($inline_controls as &$children) {
			ksort($children);
			$children = array_values($children);
		}
		unset($children);
		$layout_defaults_raw = [];
		foreach (array_keys($layout_zones) as $zone_key) {
			$layout_defaults_raw[$zone_key] = $option_schema[$zone_key]['default'] ?? [];
		}
		$layout_defaults = [];
		$layout_used = [];
		$layout_state = [];
		$layout_allowed = array_keys($layout_features);
		foreach ($layout_zones as $zone_key => $zone_label) {
			$zone_items = isset($table_options[$zone_key]) && is_array($table_options[$zone_key]) ? $table_options[$zone_key] : [];
			$filtered = [];
			foreach ($zone_items as $item) {
				if (!in_array($item, $layout_allowed, true)) {
					continue;
				}
				if (isset($layout_used[$item])) {
					continue;
				}
				$layout_used[$item] = true;
				$filtered[] = $item;
			}
			$layout_state[$zone_key] = $filtered;
			$default_items = isset($layout_defaults_raw[$zone_key]) && is_array($layout_defaults_raw[$zone_key]) ? $layout_defaults_raw[$zone_key] : [];
			$layout_defaults[$zone_key] = array_values(array_filter($default_items, static function ($item) use ($layout_allowed) {
				return in_array($item, $layout_allowed, true);
			}));
		}
		$layout_unused = array_values(array_filter($layout_allowed, static function ($item) use ($layout_used) {
			return !isset($layout_used[$item]);
		}));
		// The override toggles are DERIVED from the stored value: a set caption or a non-default
		// row limit reads as "on", blank/default reads as "off". No separate enabled flag is
		// stored, so existing definitions and the front end are unchanged. The fields render
		// empty with the default shown as the placeholder -- empty IS "use the default", and
		// the save pipeline restores the default from it, so admin-layout.js only has to clear
		// the field when a toggle goes off.
		// (The Accent color override card moved to the Design tab in 2.0 -- same field, same
		// name, same btbl-color-field markup.)
		$caption_value = (string) ($table_options['caption'] ?? '');
		$row_limit_config = $option_schema['rowLimit'] ?? null;
		$row_limit_default = (int) ($row_limit_config['default'] ?? 1000);
		$row_limit_value = (int) ($table_options['rowLimit'] ?? $row_limit_default);
		$row_limit_overridden = $row_limit_value !== $row_limit_default;
		$button_text_defaults = BaraTables_Service::frontend_label_defaults();
		$server_side_state = isset($context['server_side_state']) && is_array($context['server_side_state'])
			? $context['server_side_state']
			: ['requested' => false, 'enabled' => false, 'reason' => ''];
		?>
		<div id="btbl-tab-table" class="<?php echo esc_attr($panel_class); ?>" role="tabpanel" aria-labelledby="btbl-tab-table-label">
			<?php if ($server_side_config) : ?>
				<div class="btbl-control<?php echo ($context['source_type'] ?? '') === 'external_db' ? '' : ' is-hidden'; ?>" data-btbl-source="external_db">
					<strong class="btbl-small-heading"><?php esc_html_e('Large database performance', 'baratables'); ?></strong>
					<input type="hidden" name="btbl_table_options[serverSide]" value="0" />
					<label class="btbl-checkbox-main" for="btbl_table_flag_serverside">
						<input type="checkbox" id="btbl_table_flag_serverside" name="btbl_table_options[serverSide]" value="1" data-default="0" <?php checked(!empty($table_options['serverSide'])); ?> />
						<span class="btbl-field-name"><?php echo esc_html($server_side_config['config']['label']); ?></span>
					</label>
					<p class="description"><?php echo esc_html($server_side_config['config']['description']); ?></p>
					<p class="description"><?php esc_html_e('Requires pagination, selected database columns, and a non-null unique key. Column filters, row access rules, value and date transforms, footer calculations, exports, and print are not available in this mode.', 'baratables'); ?></p>
					<?php if (!empty($server_side_state['requested']) && empty($server_side_state['enabled']) && !empty($server_side_state['reason'])) : ?>
						<p class="description btbl-server-side-warning" role="status"><?php echo esc_html($server_side_state['reason']); ?> <?php esc_html_e('The table will keep using standard loading until this is resolved.', 'baratables'); ?></p>
					<?php elseif (!empty($server_side_state['enabled'])) : ?>
						<p class="description btbl-server-side-ready" role="status"><?php esc_html_e('Ready: database paging, sorting, and literal search are active on the published table.', 'baratables'); ?></p>
					<?php endif; ?>
				</div>
			<?php endif; ?>
				<div class="btbl-control">
					<strong class="btbl-small-heading"><?php esc_html_e('Table settings', 'baratables'); ?></strong>
					<p class="description"><?php esc_html_e('Off keeps the default. Turn one on to set it for this table.', 'baratables'); ?></p>
					<div class="btbl-flag-grid btbl-table-flags btbl-override-flags">
						<div class="btbl-checkbox" data-btbl-override="caption">
							<span class="btbl-checkbox-top">
								<label class="btbl-checkbox-main" for="btbl_override_caption">
									<input type="checkbox" id="btbl_override_caption" data-btbl-override-toggle <?php checked($caption_value !== ''); ?> />
									<span class="btbl-field-name"><?php echo esc_html($option_schema['caption']['label']); ?></span>
								</label>
								<?php self::render_flag_options_toggle($option_schema['caption']['label'], $caption_value === ''); ?>
							</span>
							<div class="btbl-field-options-body<?php echo $caption_value !== '' ? '' : ' is-hidden'; ?>">
								<div class="btbl-options-row btbl-options-inline">
									<label class="btbl-inline" for="btbl_table_caption">
										<span class="btbl-small-label"><?php esc_html_e('Caption text', 'baratables'); ?></span>
										<input type="text" name="btbl_table_options[caption]" id="btbl_table_caption" class="regular-text" value="<?php echo esc_attr($caption_value); ?>" data-btbl-override-field data-default="" />
									</label>
								</div>
							</div>
						</div>
						<div class="btbl-checkbox" data-btbl-override="rowLimit">
							<span class="btbl-checkbox-top">
								<label class="btbl-checkbox-main" for="btbl_override_rowlimit">
									<input type="checkbox" id="btbl_override_rowlimit" data-btbl-override-toggle <?php checked($row_limit_overridden); ?> />
									<span class="btbl-field-name"><?php echo esc_html($row_limit_config['label']); ?></span>
								</label>
								<?php self::render_flag_options_toggle($row_limit_config['label'], !$row_limit_overridden); ?>
							</span>
							<div class="btbl-field-options-body<?php echo $row_limit_overridden ? '' : ' is-hidden'; ?>">
								<div class="btbl-options-row btbl-options-inline">
									<label class="btbl-inline" for="btbl_rowLimit">
										<span class="btbl-small-label"><?php esc_html_e('Rows to load', 'baratables'); ?></span>
										<input
											type="number"
											class="small-text"
											name="btbl_table_options[rowLimit]"
											id="btbl_rowLimit"
											min="<?php echo esc_attr((int) ($row_limit_config['min'] ?? 1)); ?>"
											max="<?php echo esc_attr((int) ($row_limit_config['max'] ?? 10000)); ?>"
											step="1"
											value="<?php echo esc_attr($row_limit_overridden ? (string) $row_limit_value : ''); ?>"
											placeholder="<?php echo esc_attr((string) $row_limit_default); ?>"
											data-btbl-override-field
										/>
									</label>
								</div>
							</div>
						</div>
					</div>
				</div>
				<div class="btbl-control">
					<strong class="btbl-small-heading"><?php esc_html_e('Table controls', 'baratables'); ?></strong>
						<div class="btbl-flag-grid btbl-table-flags">
						<?php foreach ($flag_keys as $flag_key) : ?>
							<?php $config = $option_schema[$flag_key]; ?>
							<?php
							$has_inline = !empty($inline_controls[$flag_key]);
							$input_id = 'btbl_table_flag_' . sanitize_key($flag_key);
							$flag_default = !empty($config['default']) ? '1' : '0';
							?>
							<input type="hidden" name="btbl_table_options[<?php echo esc_attr($flag_key); ?>]" value="0" />
							<div class="btbl-checkbox">
								<span class="btbl-checkbox-top">
									<label class="btbl-checkbox-main" for="<?php echo esc_attr($input_id); ?>">
										<input type="checkbox" id="<?php echo esc_attr($input_id); ?>" name="btbl_table_options[<?php echo esc_attr($flag_key); ?>]" value="1" data-default="<?php echo esc_attr($flag_default); ?>" <?php checked(!empty($table_options[$flag_key])); ?> />
										<span class="btbl-field-name"><?php echo esc_html($config['label']); ?></span>
									</label>
									<?php if ($has_inline) : ?>
										<?php self::render_flag_options_toggle($config['label'], empty($table_options[$flag_key])); ?>
									<?php endif; ?>
								</span>
								<?php if ($has_inline) : ?>
									<div class="btbl-field-options-body">
										<?php foreach ($inline_controls[$flag_key] as $inline_key) : ?>
											<?php $inline_config = $option_schema[$inline_key]; ?>
											<?php
											$row_classes = array_merge(['btbl-options-row', 'btbl-options-inline'], $inline_config['editor_classes'] ?? []);
											$visible_when = !empty($inline_config['editor_visible_when']) ? wp_json_encode($inline_config['editor_visible_when']) : '';
											?>
											<div class="<?php echo esc_attr(implode(' ', $row_classes)); ?>"<?php echo $visible_when !== '' ? ' data-btbl-visible-when="' . esc_attr($visible_when) . '"' : ''; ?>>
												<?php if ($inline_config['type'] === 'checkbox') : ?>
													<?php $inline_default = !empty($inline_config['default']) ? '1' : '0'; ?>
													<label class="btbl-inline">
														<input type="hidden" name="btbl_table_options[<?php echo esc_attr($inline_key); ?>]" value="0" />
														<input
															type="checkbox"
															name="btbl_table_options[<?php echo esc_attr($inline_key); ?>]"
															id="btbl_<?php echo esc_attr($inline_key); ?>"
															value="1"
																	data-default="<?php echo esc_attr($inline_default); ?>"
																	<?php echo !empty($inline_config['editor_reset_when_hidden']) ? 'data-btbl-reset-when-hidden="1"' : ''; ?>
																	<?php echo !empty($inline_config['editor_restore_default']) ? 'data-btbl-restore-default="1"' : ''; ?>
															<?php checked(!empty($table_options[$inline_key])); ?>
														/>
														<?php echo esc_html($inline_config['label']); ?>
													</label>
				<?php elseif ($inline_config['type'] === 'number') : ?>
					<?php
					// Same empty-means-default contract as the override fields: the default rides
					// along as the placeholder, and the save pipeline restores it from an empty value.
					$number_default = (int) $inline_config['default'];
					$number_value = (int) ($table_options[$inline_key] ?? $number_default);
					?>
					<label class="btbl-inline" for="btbl_<?php echo esc_attr($inline_key); ?>">
						<span class="btbl-small-label"><?php echo esc_html($inline_config['label']); ?></span>
						<input
							type="number"
							min="<?php echo esc_attr((int) ($inline_config['min'] ?? 1)); ?>"
							max="<?php echo esc_attr((int) ($inline_config['max'] ?? 500)); ?>"
							step="1"
							name="btbl_table_options[<?php echo esc_attr($inline_key); ?>]"
							id="btbl_<?php echo esc_attr($inline_key); ?>"
							value="<?php echo esc_attr($number_value !== $number_default ? (string) $number_value : ''); ?>"
							placeholder="<?php echo esc_attr((string) $number_default); ?>"
						/>
					</label>
												<?php else : ?>
													<?php
													$input_value = array_key_exists($inline_key, $table_options)
														? $table_options[$inline_key]
														: ($inline_config['default'] ?? '');
													// Visitor-facing labels default to blank in the schema so the front end can
													// supply a TRANSLATED default; show that same translated string here as the
													// placeholder, so a blank field still tells the admin what will render.
													$placeholder_overrides = array_merge(
														BaraTables_Service::frontend_label_defaults(),
														BaraTables_Service::paginate_glyph_defaults()
													);
													$placeholder = $placeholder_overrides[$inline_key] ?? ($inline_config['default'] ?? '');
													?>
													<label class="btbl-inline" for="btbl_<?php echo esc_attr($inline_key); ?>">
														<span class="btbl-small-label"><?php echo esc_html($inline_config['label']); ?></span>
														<input
															type="text"
															name="btbl_table_options[<?php echo esc_attr($inline_key); ?>]"
															id="btbl_<?php echo esc_attr($inline_key); ?>"
															class="regular-text"
															value="<?php echo esc_attr($input_value); ?>"
															<?php echo $placeholder !== '' ? 'placeholder="' . esc_attr($placeholder) . '"' : ''; ?>
														/>
													</label>
													<?php if ($inline_key === 'infoFiltered') : ?>
														<p class="description btbl-field-hint"><?php esc_html_e('Tokens: _START_ first row shown, _END_ last row shown, _TOTAL_ total rows, _MAX_ rows before filtering.', 'baratables'); ?></p>
													<?php endif; ?>
												<?php endif; ?>
											</div>
										<?php endforeach; ?>
									</div>
								<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
	</div>
				<div class="btbl-control btbl-layout-builder" data-defaults="<?php echo esc_attr(wp_json_encode($layout_defaults)); ?>" data-label-moved="<?php echo esc_attr(/* translators: 1: the name of the element that moved, 2: the zone it moved to. */ __('Moved %1$s to %2$s.', 'baratables')); ?>">
					<div class="btbl-layout-header">
						<div class="btbl-header-stack">
							<strong class="btbl-small-heading"><?php esc_html_e('Table layout', 'baratables'); ?></strong>
							<p class="description btbl-help-text"><?php esc_html_e('Drag each control to the corner where it should appear, or focus one and use the arrow keys. An element only appears if its matching control is enabled above.', 'baratables'); ?></p>
						</div>
						<button type="button" class="button btbl-icon-button btbl-layout-reset" hidden aria-label="<?php echo esc_attr__('Reset layout', 'baratables'); ?>" title="<?php echo esc_attr__('Move all elements back to their default zones. Does not change controls or styles.', 'baratables'); ?>"><span class="dashicons dashicons-image-rotate" aria-hidden="true"></span></button>
					</div>
					<div class="btbl-layout-grid" data-disabled-hint="<?php echo esc_attr__('Enable the matching control above to use this element.', 'baratables'); ?>">
						<?php foreach ($layout_zones as $zone_key => $zone_label) : ?>
							<div class="btbl-layout-zone">
								<div class="btbl-layout-zone-label"><?php echo esc_html($zone_label); ?></div>
								<div class="btbl-layout-drop" data-zone="<?php echo esc_attr($zone_key); ?>">
									<?php foreach ($layout_state[$zone_key] as $feature) : ?>
										<?php $this->render_layout_chip($feature, $layout_features[$feature] ?? $feature, $layout_dependencies[$feature] ?? null); ?>
									<?php endforeach; ?>
								</div>
								<div class="btbl-layout-inputs" data-zone-inputs="<?php echo esc_attr($zone_key); ?>">
									<input type="hidden" name="btbl_table_options[<?php echo esc_attr($zone_key); ?>][]" value="" />
									<?php foreach ($layout_state[$zone_key] as $feature) : ?>
										<input type="hidden" name="btbl_table_options[<?php echo esc_attr($zone_key); ?>][]" value="<?php echo esc_attr($feature); ?>" />
									<?php endforeach; ?>
								</div>
							</div>
						<?php endforeach; ?>
					</div>
					<div class="btbl-layout-zone btbl-layout-palette">
						<div class="btbl-layout-zone-label"><?php esc_html_e('Available elements', 'baratables'); ?></div>
						<div class="btbl-layout-drop btbl-layout-palette-drop" data-zone="palette">
							<span class="btbl-palette-placeholder description"><?php esc_html_e('Drag an element here to remove it from the table layout.', 'baratables'); ?></span>
							<?php foreach ($layout_unused as $feature) : ?>
								<?php $this->render_layout_chip($feature, $layout_features[$feature] ?? $feature, $layout_dependencies[$feature] ?? null); ?>
							<?php endforeach; ?>
						</div>
					</div>
				</div>
			<?php if ($buttons_config) : ?>
				<?php $config = $buttons_config['config']; ?>
				<?php $button_text_keys = isset($config['choice_text_options']) && is_array($config['choice_text_options']) ? $config['choice_text_options'] : []; ?>
				<div class="btbl-control">
					<strong class="btbl-small-heading"><?php echo esc_html($config['label']); ?></strong>
					<?php if (!empty($config['description'])) : ?>
						<p class="description"><?php echo esc_html($config['description']); ?></p>
					<?php endif; ?>
					<input type="hidden" name="btbl_table_options[<?php echo esc_attr($buttons_config['key']); ?>][]" value="" />
					<div class="btbl-flag-grid btbl-table-flags">
						<?php foreach ((array) ($config['choices'] ?? []) as $choice => $choice_label) : ?>
							<?php $choice_id = 'btbl_flag_' . sanitize_key($buttons_config['key'] . '_' . $choice); ?>
							<?php
							$choice_key = sanitize_key((string) $choice);
							$text_key = $button_text_keys[$choice_key] ?? '';
							$button_checked = in_array($choice, (array) ($table_options[$buttons_config['key']] ?? []), true);
							$button_text_value = $text_key !== '' && array_key_exists($text_key, $table_options) ? (string) $table_options[$text_key] : '';
							$button_text_id = 'btbl_button_text_' . sanitize_key($choice_key);
							?>
							<div class="btbl-flag-card">
								<div class="btbl-checkbox-top">
									<label class="btbl-checkbox-main" for="<?php echo esc_attr($choice_id); ?>">
										<input
											type="checkbox"
											name="btbl_table_options[<?php echo esc_attr($buttons_config['key']); ?>][]"
											id="<?php echo esc_attr($choice_id); ?>"
											value="<?php echo esc_attr($choice); ?>"
											<?php checked($button_checked); ?>
										/>
										<span class="btbl-field-name"><?php echo esc_html($choice_label); ?></span>
									</label>
									<?php if ($text_key !== '') : ?>
										<?php self::render_flag_options_toggle($choice_label, !$button_checked, $choice_id . '_options'); ?>
									<?php endif; ?>
								</div>
								<?php if ($text_key !== '') : ?>
									<div class="btbl-field-options-body <?php echo $button_checked ? '' : 'is-hidden'; ?>" id="<?php echo esc_attr($choice_id . '_options'); ?>">
										<div class="btbl-options-row btbl-options-inline">
										<label class="btbl-inline" for="<?php echo esc_attr($button_text_id); ?>">
											<span class="btbl-small-label"><?php esc_html_e('Text', 'baratables'); ?></span>
											<input
												type="text"
												name="btbl_table_options[<?php echo esc_attr($text_key); ?>]"
												id="<?php echo esc_attr($button_text_id); ?>"
												class="regular-text"
												value="<?php echo esc_attr($button_text_value); ?>"
												placeholder="<?php echo esc_attr($button_text_defaults[$text_key] ?? $choice_label); ?>"
											/>
										</label>
										</div>
									</div>
								<?php endif; ?>
							</div>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_layout_chip(string $feature, string $label, ?string $option_key): void {
		?>
		<button type="button" class="btbl-layout-chip" draggable="true" data-feature="<?php echo esc_attr($feature); ?>"<?php echo $option_key ? ' data-btbl-option-key="' . esc_attr($option_key) . '"' : ''; ?>>
			<?php echo esc_html($label); ?>
		</button>
		<?php
	}
}

/**
 * The Design tab (2.0): the visual style designer. Every control is a plain
 * btbl_table_options[...] field, so the save and preview pipelines treat it exactly like
 * any other option; the curated controls map 1:1 onto the CSS custom properties consumed
 * by baratables.css. Empty means "inherit the theme" for every token.
 */
class BaraTables_Admin_Tab_Design {
	public function render(array $context): void {
		$table_options = $context['table_options'] ?? [];
		$option_schema = BaraTables_Service::get_table_option_schema();
		$active_tab = $context['active_tab'] ?? 'btbl-tab-general';
		$panel_class = $active_tab === 'btbl-tab-design' ? 'btbl-tab-panel is-active' : 'btbl-tab-panel';
		$style_keys = [];
		foreach ($option_schema as $key => $config) {
			if (($config['editor_group'] ?? '') === 'style') {
				$style_keys[] = $key;
			}
		}

		$config = [
			'previewSelector' => '#btbl-preview-target .btbl-table-wrapper',
			'varMap' => BaraTables_Service::STYLE_VAR_MAP,
			// The type of every token, so the preview sanitizes each value the way the
			// server does instead of guessing the type from the key's name.
			'tokenTypes' => BaraTables_Service::design_token_types(),
			// The site default design (2.1.0), so the preview and Copy CSS show the
			// EFFECTIVE design: the table's own token, else the site default, else the
			// theme. Without this the preview would show a blank where the published
			// page shows the inherited colour.
			'siteDefault' => BaraTables_Design::site_default()['table'],
			'useSiteDesign' => !BaraTables_Design::opts_out($table_options),
			// The Additional CSS byte budget, so the editor's preview caps the payload
			// exactly where the server will.
			'maxCssBytes' => BaraTables_Service::MAX_CUSTOM_CSS_BYTES,
			// Translated here rather than hardcoded in the script: the Design tab's only
			// feedback message is user-facing copy, and the POT sync cannot see a string
			// that is not wrapped in a gettext call.
			'copiedLabel' => __('Copied to your clipboard.', 'baratables'),
			// The em dash is written as a Unicode escape so this file stays ASCII.
			'clipboardUnavailableLabel' => __("Clipboard unavailable \u{2014} the CSS is selected below; copy it manually.", 'baratables'),
			// The instruction the copied CSS carries. It is shipped rather than written
			// into the script, because it differs per surface: only a table has the
			// "Theme CSS" output mode to point at.
			'copiedCssNote' => __('Paste into Appearance > Customize > Additional CSS (or a child theme stylesheet), then switch the Design tab to "Theme CSS" so this plugin stops printing its own inline copy.', 'baratables'),
		];
		?>
		<div id="btbl-tab-design" class="<?php echo esc_attr($panel_class); ?>" role="tabpanel" aria-labelledby="btbl-tab-design-label">
			<p class="description btbl-help-text"><?php esc_html_e('Styles apply to the Table preview below the builder as you edit, and publish with the table. Leave anything blank to inherit the site default, and then your theme.', 'baratables'); ?></p>
			<div class="btbl-control">
				<strong class="btbl-small-heading"><?php esc_html_e('Site design', 'baratables'); ?></strong>
				<p class="description"><?php esc_html_e('The site default applies to every table that leaves a value blank here.', 'baratables'); ?></p>
				<div class="btbl-checkbox btbl-design-field">
					<input type="hidden" name="btbl_table_options[useSiteDesign]" value="0" />
					<span class="btbl-checkbox-top">
						<label class="btbl-checkbox-main" for="btbl_design_useSiteDesign">
							<input
								type="checkbox"
								id="btbl_design_useSiteDesign"
								name="btbl_table_options[useSiteDesign]"
								value="1"
								data-default="1"
								<?php checked(!BaraTables_Design::opts_out($table_options)); ?>
							/>
							<span class="btbl-field-name"><?php esc_html_e('Use the site default design', 'baratables'); ?></span>
						</label>
					</span>
				</div>
			</div>
			<div class="btbl-control" data-btbl-design-config="<?php echo esc_attr(wp_json_encode($config)); ?>">
				<strong class="btbl-small-heading"><?php esc_html_e('Style presets', 'baratables'); ?></strong>
				<p class="description"><?php esc_html_e('One click fills the controls below; keep tweaking from there. Your data and table settings are not touched.', 'baratables'); ?></p>
				<div class="btbl-flag-grid btbl-preset-grid">
					<?php BaraTables_Admin_Design_Fields::render_preset_buttons('data-btbl-preset'); ?>
				</div>
			</div>
			<?php BaraTables_Admin_Design_Fields::render_table_groups($table_options, 'btbl_table_options', 'btbl_design', BaraTables_Design::blank_accent($table_options)); ?>
			<?php if (!empty($style_keys)) : ?>
				<div class="btbl-control btbl-design-fields">
					<strong class="btbl-small-heading"><?php esc_html_e('Table features', 'baratables'); ?></strong>
					<p class="description"><?php esc_html_e('Zebra stripes and row highlights use the colors from the Rows group above.', 'baratables'); ?></p>
					<div class="btbl-flag-grid btbl-table-flags">
						<?php foreach ($style_keys as $style_key) : ?>
							<?php $style_config = $option_schema[$style_key]; ?>
							<?php $input_id = 'btbl_design_' . $style_key; ?>
							<?php $style_default = !empty($style_config['default']) ? '1' : '0'; ?>
							<input type="hidden" name="btbl_table_options[<?php echo esc_attr($style_key); ?>]" value="0" />
							<div class="btbl-checkbox">
								<span class="btbl-checkbox-top">
									<label class="btbl-checkbox-main" for="<?php echo esc_attr($input_id); ?>">
										<input type="checkbox" id="<?php echo esc_attr($input_id); ?>" name="btbl_table_options[<?php echo esc_attr($style_key); ?>]" value="1" data-default="<?php echo esc_attr($style_default); ?>" data-btbl-style-feature data-btbl-live-style <?php checked(!empty($table_options[$style_key])); ?> />
										<span class="btbl-field-name"><?php echo esc_html($style_config['label']); ?></span>
									</label>
								</span>
							</div>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endif; ?>
			<div class="btbl-control">
				<strong class="btbl-small-heading"><?php esc_html_e('Style output', 'baratables'); ?></strong>
				<div class="btbl-flag-grid btbl-style-mode-grid">
					<?php $style_mode = ($table_options['styleMode'] ?? 'inline') === 'external' ? 'external' : 'inline'; ?>
					<div class="btbl-checkbox btbl-style-mode-card">
						<label class="btbl-checkbox-main" for="btbl_style_mode_inline">
							<input type="radio" id="btbl_style_mode_inline" name="btbl_table_options[styleMode]" value="inline" data-btbl-live-style <?php checked($style_mode, 'inline'); ?> />
							<span class="btbl-field-name"><?php esc_html_e('Inline - managed here', 'baratables'); ?></span>
						</label>
						<p class="description btbl-style-mode-hint"><?php esc_html_e('The styles print with the table. Default.', 'baratables'); ?></p>
					</div>
					<div class="btbl-checkbox btbl-style-mode-card">
						<label class="btbl-checkbox-main" for="btbl_style_mode_external">
							<input type="radio" id="btbl_style_mode_external" name="btbl_table_options[styleMode]" value="external" data-btbl-live-style <?php checked($style_mode, 'external'); ?> />
							<span class="btbl-field-name"><?php esc_html_e('Theme CSS - copied out', 'baratables'); ?></span>
						</label>
						<p class="description btbl-style-mode-hint"><?php esc_html_e('The plugin prints no design CSS with the table. Copy the generated CSS into Additional CSS or your child theme; your copy stays in charge.', 'baratables'); ?></p>
					</div>
				</div>
				<p class="description btbl-external-note" hidden data-btbl-external-note><?php esc_html_e('Inline output is off. This preview still shows your styles; visitors get whatever your theme CSS applies.', 'baratables'); ?></p>
				<div class="btbl-options-row btbl-copy-css-row">
					<button type="button" class="button" data-btbl-copy-css><?php esc_html_e('Copy CSS', 'baratables'); ?></button>
					<span class="description" aria-live="polite" data-btbl-copy-status></span>
				</div>
				<pre class="btbl-copy-css-output" hidden tabindex="0" aria-label="<?php esc_attr_e('Generated table CSS', 'baratables'); ?>"></pre>
				<div class="btbl-custom-css-row">
					<label class="btbl-small-label" for="btbl_design_custom_css"><?php echo esc_html($option_schema['customCss']['label'] ?? __('Additional CSS', 'baratables')); ?></label>
					<textarea
						name="btbl_table_options[customCss]"
						id="btbl_design_custom_css"
						class="large-text code"
						rows="8"
						spellcheck="false"
						placeholder="td { font-style: italic; }"
						data-btbl-custom-css
						data-btbl-live-style
					><?php echo esc_textarea((string) ($table_options['customCss'] ?? '')); ?></textarea>
					<p class="description btbl-field-hint"><?php echo esc_html($option_schema['customCss']['description'] ?? ''); ?></p>
				</div>
			</div>
		</div>
		<?php
	}
}

class BaraTables_Admin_Tab_Advanced {
	public function render(array $context): void {
		$source_type = $context['source_type'] ?? 'wp_query';
		$active_tab = $context['active_tab'] ?? 'btbl-tab-general';
		$panel_class = $active_tab === 'btbl-tab-advanced' ? 'btbl-tab-panel is-active' : 'btbl-tab-panel';
		$value_overrides_pretty = $context['value_overrides_pretty'] ?? '';
		$value_overrides_raw = $context['value_overrides_raw'] ?? '';
		$access_user_meta = $context['access_user_meta'] ?? '';
		$access_post_meta = $context['access_post_meta'] ?? '';
		$access_csv_column = $context['access_csv_column'] ?? '';
		$access_external_column = $context['access_external_column'] ?? '';
		$access_logged_out = $context['access_logged_out'] ?? 'public_only';
		$source_hidden_class = static function(string $target) use ($source_type): string {
			return BaraTables_Admin_Form_Context::source_hidden_class($target, $source_type);
		};
		?>
		<div id="btbl-tab-advanced" class="<?php echo esc_attr($panel_class); ?>" role="tabpanel" aria-labelledby="btbl-tab-advanced-label">
			<div class="btbl-control">
				<label class="btbl-small-heading" for="btbl_custom_meta"><?php esc_html_e('Additional meta keys (one per line)', 'baratables'); ?></label>
				<textarea name="btbl_custom_meta" id="btbl_custom_meta" class="regular-text" rows="3" placeholder="price&#10;rating&#10;field_custom"></textarea>
				<p class="description"><?php esc_html_e('Add keys that are not auto-detected, then Update to apply.', 'baratables'); ?></p>
			</div>
			<div class="btbl-control">
				<label class="btbl-small-heading" for="btbl_value_overrides_json"><?php esc_html_e('Value overrides (JSON)', 'baratables'); ?></label>
				<textarea name="btbl_value_overrides_json" id="btbl_value_overrides_json" class="large-text code btbl-json-check" data-error-target="btbl_value_overrides_error" rows="6" placeholder='[{"column":"core:post_content","search":"http","replace":"<a href=\"{{core:permalink}}\"><span class=\"dashicons dashicons-admin-links\"></span></a>"},{"column":"*","regex":true,"search":"#link:(.*?)#","replace":"$1"}]' spellcheck="false"><?php echo esc_textarea($value_overrides_raw !== '' ? $value_overrides_raw : $value_overrides_pretty); ?></textarea>
				<p class="description btbl-help-text"><?php esc_html_e('Rules applied after values are resolved. Each needs a column slug (or "*" for all), a search string, and a replace string. Set regex=true for a pattern like #pattern#. In replace, WordPress-content tables accept {{core:post_title}} and {{meta:your_key}}; CSV, external database, and manual tables accept {{row.slug}} for another column in the same row.', 'baratables'); ?></p>
				<p class="btbl-json-error" id="btbl_value_overrides_error" role="alert" hidden><?php esc_html_e('This is not valid JSON. The rules will not be saved until you fix it.', 'baratables'); ?></p>
			</div>
			<?php
			// Manual data is the one source with no row-token field, and its row path has no access
			// policy -- so on a manual table every control
			// here was editable, was saved, and did precisely nothing. Setting "Logged-out visitors
			// see: No rows" on one left every row public. Hide the block rather than keep offering a
			// restriction that is not enforced. Listing the four sources that DO enforce it means a
			// new source has to opt in deliberately instead of inheriting a false promise.
			$access_sources = 'wp_query custom_query csv external_db';
			?>
			<div class="btbl-control btbl-access-control<?php echo esc_attr($source_hidden_class($access_sources)); ?>" data-btbl-source="<?php echo esc_attr($access_sources); ?>">
				<strong class="btbl-small-heading"><?php esc_html_e('Access control', 'baratables'); ?></strong>
				<p class="description btbl-help-text"><?php esc_html_e('Show a row only to visitors whose tokens match it. Set where row tokens and user tokens are stored. Leave blank to disable.', 'baratables'); ?></p>
				<?php
				// Warn whenever this section will not survive the save. Without
				// the source's row-token field, sanitize_access_control() returns [] and
				// apply_request_to_definition() unsets access_control entirely -- so choosing
				// "Logged-out visitors see: No rows" on its own is silently discarded and the
				// control reads as the default again on the next load, which looks like the table
				// was hidden from the public when nothing was saved at all.
				$row_token_set = (in_array($source_type, ['wp_query', 'custom_query'], true) && $access_post_meta !== '')
					|| ($source_type === 'csv' && $access_csv_column !== '')
					|| ($source_type === 'external_db' && $access_external_column !== '');
				$access_configured = $access_user_meta !== '' || $access_logged_out !== 'public_only';
				if ($access_configured && !$row_token_set) :
				?>
					<p class="description"><em><?php esc_html_e('Set the row-token field below. Without it nothing in this section is saved, and every row stays visible.', 'baratables'); ?></em></p>
				<?php endif; ?>
				<div class="btbl-control-grid btbl-access-grid">
					<div class="btbl-control">
						<label class="btbl-small-heading" for="btbl_access_user_meta"><?php esc_html_e('User meta key (tokens)', 'baratables'); ?></label>
						<input type="text" name="btbl_access_user_meta" id="btbl_access_user_meta" class="regular-text" value="<?php echo esc_attr($access_user_meta); ?>" placeholder="_btbl_user_tokens" />
						<p class="description"><?php esc_html_e('Read tokens from this user meta key. Leave blank to fall back to user roles.', 'baratables'); ?></p>
					</div>
					<div class="btbl-control<?php echo esc_attr($source_hidden_class('wp_query custom_query')); ?>" data-btbl-source="wp_query custom_query">
						<label class="btbl-small-heading" for="btbl_access_post_meta"><?php esc_html_e('Post meta key (row tokens)', 'baratables'); ?></label>
						<input type="text" name="btbl_access_post_meta" id="btbl_access_post_meta" class="regular-text" value="<?php echo esc_attr($access_post_meta); ?>" placeholder="_btbl_allowed_tokens" />
						<p class="description"><?php esc_html_e('Rows are shown if tokens here overlap the user tokens. Empty/missing = public.', 'baratables'); ?></p>
					</div>
					<div class="btbl-control<?php echo esc_attr($source_hidden_class('csv')); ?>" data-btbl-source="csv">
						<label class="btbl-small-heading" for="btbl_access_csv_column"><?php esc_html_e('CSV column (row tokens)', 'baratables'); ?></label>
						<input type="text" name="btbl_access_csv_column" id="btbl_access_csv_column" class="regular-text" value="<?php echo esc_attr($access_csv_column); ?>" placeholder="allowed_tokens" />
						<p class="description"><?php esc_html_e('Use the header/slug for the CSV column containing allowed tokens.', 'baratables'); ?></p>
					</div>
						<div class="btbl-control<?php echo esc_attr($source_hidden_class('external_db')); ?>" data-btbl-source="external_db">
							<label class="btbl-small-heading" for="btbl_access_external_column"><?php esc_html_e('External column (row tokens)', 'baratables'); ?></label>
							<input type="text" name="btbl_access_external_column" id="btbl_access_external_column" class="regular-text" value="<?php echo esc_attr($access_external_column); ?>" placeholder="allowed_tokens" />
							<p class="description"><?php esc_html_e('Column name from your external table or view that contains allowed tokens.', 'baratables'); ?></p>
						</div>
					<div class="btbl-control">
						<label class="btbl-small-heading" for="btbl_access_logged_out"><?php esc_html_e('Logged-out visitors see', 'baratables'); ?></label>
						<select name="btbl_access_logged_out" id="btbl_access_logged_out">
							<option value="all" <?php selected($access_logged_out, 'all'); ?>><?php esc_html_e('All rows', 'baratables'); ?></option>
							<option value="public_only" <?php selected($access_logged_out, 'public_only'); ?>><?php esc_html_e('Only public rows (empty tokens)', 'baratables'); ?></option>
							<option value="none" <?php selected($access_logged_out, 'none'); ?>><?php esc_html_e('No rows', 'baratables'); ?></option>
						</select>
					</div>
				</div>
			</div>
		</div>
		<?php
	}
}

