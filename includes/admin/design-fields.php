<?php

if (!defined('ABSPATH')) {
	exit;
}

/**
 * The shared design controls (2.1.0).
 *
 * One renderer for every design field, used by the table editor's Design tab, the
 * chart editor's Design tab and the site Design screen. When a control lives in one
 * surface and not the others, the surfaces stop feeling like one system -- and the
 * third surface is exactly what makes that visible. So the markup lives here once.
 */
class BaraTables_Admin_Design_Fields {

	/**
	 * The table design groups, in the order the surfaces render them.
	 *
	 * One list, used by the table editor's Design tab and by the site Design screen.
	 * Copied per surface, the two drift: a group added to one and not the other is
	 * exactly the inconsistency this release set out to remove.
	 *
	 * @return array<int,array<string,mixed>> Each group has a heading and its field keys.
	 */
	public static function table_groups(): array {
		return [
			[
				'heading' => __('Colors', 'baratables'),
				'fields' => ['accentColor', 'textColor', 'borderColor'],
			],
			[
				'heading' => __('Header row', 'baratables'),
				'fields' => ['headerBgColor', 'headerTextColor', 'headerWeight'],
			],
			[
				'heading' => __('Rows', 'baratables'),
				'fields' => ['stripeColor', 'hoverColor'],
			],
			[
				'heading' => __('Footer totals', 'baratables'),
				'fields' => ['footerBgColor', 'footerTextColor'],
			],
			[
				'heading' => __('Conditional cell colors', 'baratables'),
				'description' => __('Used by the per-column conditional formatting rules on the Columns &amp; filters tab.', 'baratables'),
				'fields' => ['successBgColor', 'successColor', 'warningBgColor', 'warningColor', 'dangerBgColor', 'dangerColor'],
			],
		];
	}

	/**
	 * The table's size fields, in the order the surfaces render them.
	 *
	 * @return string[]
	 */
	public static function table_size_fields(): array {
		return ['fontSize', 'cellPadding', 'borderRadius'];
	}

	/**
	 * The table design groups and the typography block, rendered for one surface.
	 *
	 * @param array  $values      The current values, keyed by option key.
	 * @param string $name_prefix The form field name prefix.
	 * @param string $id_prefix   The element id prefix.
	 */
	public static function render_table_groups(array $values, string $name_prefix = 'btbl_table_options', string $id_prefix = 'btbl_design'): void {
		$schema = BaraTables_Service::get_table_option_schema();
		$theme_accent = BaraTables_Service::resolve_theme_accent();
		foreach (self::table_groups() as $group) {
			?>
			<div class="btbl-control btbl-design-fields">
				<strong class="btbl-small-heading"><?php echo esc_html($group['heading']); ?></strong>
				<?php if (!empty($group['description'])) : ?>
					<p class="description"><?php echo esc_html($group['description']); ?></p>
				<?php endif; ?>
				<div class="btbl-flag-grid btbl-table-flags">
					<?php foreach ($group['fields'] as $field_key) : ?>
						<?php self::render_field($field_key, $schema[$field_key] ?? [], $values, $theme_accent, $name_prefix, $id_prefix); ?>
					<?php endforeach; ?>
				</div>
			</div>
			<?php
		}
		?>
		<div class="btbl-control btbl-design-fields">
			<strong class="btbl-small-heading"><?php esc_html_e('Typography & shape', 'baratables'); ?></strong>
			<div class="btbl-flag-grid btbl-table-flags">
				<?php foreach (self::table_size_fields() as $field_key) : ?>
					<?php self::render_field($field_key, $schema[$field_key] ?? [], $values, $theme_accent, $name_prefix, $id_prefix); ?>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * The chart's colour, type and container groups, rendered for one surface.
	 *
	 * The chart editor's Design tab and the site Design screen offer the same chart
	 * controls, so they are rendered from one definition. The grid colour is the one
	 * field the surfaces treat differently: the chart editor hides it for the chart
	 * types that have no grid, while the site screen always shows it because the site
	 * default has to cover every chart type.
	 *
	 * @param array $values           The current values, keyed by chart option key.
	 * @param bool  $grid_field_marker Whether the grid colour carries the marker the
	 *                                 chart editor uses to hide it for gridless types.
	 */
	public static function render_chart_style_groups(array $values, bool $grid_field_marker): void {
		?>
		<div class="btbl-control">
			<strong class="btbl-small-heading"><?php esc_html_e('Colors & type', 'baratables'); ?></strong>
			<div class="btbl-flag-grid btbl-table-flags btbl-design-fields">
				<?php
				self::render_color_card('btbl_chart_style_background', __('Background', 'baratables'), (string) ($values['styleBackground'] ?? ''), __('Canvas default', 'baratables'));
				self::render_color_card('btbl_chart_style_text_color', __('Text color', 'baratables'), (string) ($values['styleTextColor'] ?? ''), __('ECharts default', 'baratables'));
				self::render_color_card('btbl_chart_style_grid_color', __('Grid color', 'baratables'), (string) ($values['styleGridColor'] ?? ''), __('ECharts default', 'baratables'), $grid_field_marker ? 'data-btbl-chart-grid-field' : '');
				self::render_size_card('btbl_chart_style_font_size', __('Font size', 'baratables'), (string) ($values['styleFontSize'] ?? ''), '14px', 'chart-font');
				?>
			</div>
			<p class="description btbl-field-hint"><?php esc_html_e('Text and grid colors apply to axis labels, legends, and grid lines. The palette colors the series, the slices, and a Gantt\'s task groups.', 'baratables'); ?></p>
		</div>
		<div class="btbl-control">
			<strong class="btbl-small-heading"><?php esc_html_e('Chart container', 'baratables'); ?></strong>
			<p class="description"><?php esc_html_e('The space and rounding around the chart itself, rather than inside it.', 'baratables'); ?></p>
			<div class="btbl-flag-grid btbl-table-flags btbl-design-fields">
				<?php
				self::render_size_card('btbl_chart_style_padding', __('Padding', 'baratables'), (string) ($values['stylePadding'] ?? ''), __('None', 'baratables'));
				self::render_size_card('btbl_chart_style_radius', __('Corner radius', 'baratables'), (string) ($values['styleRadius'] ?? ''), __('None', 'baratables'));
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * The style presets a surface offers, with their button labels.
	 *
	 * The preset NAMES come from one registry (assets/admin-core.js), so the table
	 * editor, the chart editor and the site Design screen offer the same looks. The
	 * LABELS differ where the look itself differs: a chart has no header row, so its
	 * dark-canvas preset reads "High contrast" rather than "Bold header".
	 *
	 * @param bool $chart Whether these are the chart's presets.
	 * @return array<string,string> The button label per preset name.
	 */
	public static function preset_labels(bool $chart = false): array {
		if ($chart) {
			return [
				'default' => __('Clear overrides', 'baratables'),
				'minimal' => __('Minimal', 'baratables'),
				'bold' => __('High contrast', 'baratables'),
				'midnight' => __('Midnight', 'baratables'),
				'paper' => __('Paper', 'baratables'),
			];
		}
		return [
			'default' => __('Clear overrides', 'baratables'),
			'minimal' => __('Minimal', 'baratables'),
			'bold' => __('Bold header', 'baratables'),
			'midnight' => __('Midnight', 'baratables'),
			'paper' => __('Paper', 'baratables'),
		];
	}

	/**
	 * The preset buttons, rendered for one surface.
	 *
	 * @param string $attribute The data attribute that names the preset: 'data-btbl-preset'
	 *                           for the table's presets, 'data-btbl-chart-preset' for the
	 *                           chart's.
	 * @param bool   $chart     Whether these are the chart's presets.
	 */
	public static function render_preset_buttons(string $attribute, bool $chart = false): void {
		$buttons = [];
		foreach (self::preset_labels($chart) as $name => $label) {
			$buttons[] = sprintf(
				'<button type="button" class="button btbl-preset-button" %1$s="%2$s">%3$s</button>',
				esc_attr($attribute),
				esc_attr($name),
				esc_html($label)
			);
		}
		echo implode('', $buttons); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Every part is escaped above.
	}

	/**
	 * One design field.
	 *
	 * @param string $key        The option key, e.g. 'accentColor'.
	 * @param array  $config     The schema entry for that key.
	 * @param array  $values     The current values, keyed by option key.
	 * @param string $theme_accent The theme's accent color, used as the accent placeholder.
	 * @param string $name_prefix The form field name prefix, e.g. 'btbl_table_options'.
	 * @param string $id_prefix   The element id prefix.
	 */
	public static function render_field(string $key, array $config, array $values, string $theme_accent, string $name_prefix = 'btbl_table_options', string $id_prefix = 'btbl_design'): void {
		$label = (string) ($config['label'] ?? $key);
		$description = (string) ($config['description'] ?? '');
		// Raw schema key, matching the Options tab's btbl_rowLimit precedent: the id mirrors
		// the stored camelCase option key (sanitize_key would lowercase it).
		$input_id = $id_prefix . '_' . $key;
		$value = (string) ($values[$key] ?? '');
		?>
		<div class="btbl-checkbox btbl-design-field" data-btbl-design-field="<?php echo esc_attr($key); ?>">
			<span class="btbl-checkbox-top">
				<label class="btbl-field-name" for="<?php echo esc_attr($input_id); ?>"><?php echo esc_html($label); ?></label>
			</span>
			<?php if (($config['type'] ?? '') === 'color') : ?>
				<div class="btbl-color-field" data-btbl-theme-accent="<?php echo esc_attr($key === 'accentColor' ? $theme_accent : ''); ?>">
					<input
						type="text"
						id="<?php echo esc_attr($input_id); ?>"
						name="<?php echo esc_attr($name_prefix . '[' . $key . ']'); ?>"
						class="btbl-color-value"
						value="<?php echo esc_attr($value); ?>"
						placeholder="<?php echo esc_attr($key === 'accentColor' ? $theme_accent : __('Inherit', 'baratables')); ?>"
						data-btbl-live-style
					/>
					<input type="color" class="btbl-color-picker" value="<?php echo esc_attr($value !== '' ? $value : ($key === 'accentColor' ? $theme_accent : '#ffffff')); ?>" aria-label="<?php echo esc_attr(sprintf(
						/* translators: %s is the control label, e.g. "Background". */
						__('Pick %s', 'baratables'),
						$label
					)); ?>" />
				</div>
			<?php elseif (!empty($config['choices']) && is_array($config['choices'])) : ?>
				<select id="<?php echo esc_attr($input_id); ?>" name="<?php echo esc_attr($name_prefix . '[' . $key . ']'); ?>" data-btbl-live-style>
					<option value=""><?php echo esc_html(($config['type'] ?? '') === 'css_weight' ? __('Inherit', 'baratables') : ''); ?></option>
					<?php foreach ($config['choices'] as $choice_value => $choice_label) : ?>
						<option value="<?php echo esc_attr((string) $choice_value); ?>" <?php selected($value, (string) $choice_value); ?>><?php echo esc_html((string) $choice_label); ?></option>
					<?php endforeach; ?>
				</select>
			<?php else : ?>
				<input
					type="text"
					id="<?php echo esc_attr($input_id); ?>"
					name="<?php echo esc_attr($name_prefix . '[' . $key . ']'); ?>"
					class="small-text"
					value="<?php echo esc_attr($value); ?>"
					placeholder="<?php echo esc_attr(self::size_placeholder($key)); ?>"
					data-btbl-live-style
					data-btbl-size-field
					data-btbl-size-kind="<?php echo esc_attr($key === 'fontSize' ? 'font' : 'css'); ?>"
				/>
			<?php endif; ?>
			<?php if ($description !== '') : ?>
				<p class="description btbl-field-hint"><?php echo esc_html($description); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/** A color control with its own field name, for the chart editor's flat names. */
	public static function render_color_card(string $name, string $label, string $value, string $placeholder, string $marker = ''): void {
		?>
		<div class="btbl-checkbox btbl-design-field"<?php echo $marker !== '' ? ' ' . esc_attr($marker) : ''; ?>>
			<span class="btbl-checkbox-top">
				<label class="btbl-field-name" for="<?php echo esc_attr($name); ?>"><?php echo esc_html($label); ?></label>
			</span>
			<div class="btbl-color-field">
				<input
					type="text"
					id="<?php echo esc_attr($name); ?>"
					class="btbl-color-value"
					name="<?php echo esc_attr($name); ?>"
					value="<?php echo esc_attr($value); ?>"
					placeholder="<?php echo esc_attr($placeholder); ?>"
				/>
				<input type="color" class="btbl-color-picker" value="<?php echo esc_attr($value !== '' ? $value : '#ffffff'); ?>" aria-label="<?php echo esc_attr(sprintf(
					/* translators: %s is the control label, e.g. "Background". */
					__('Pick %s', 'baratables'),
					$label
				)); ?>" />
			</div>
		</div>
		<?php
	}

	/** A size control with its own field name, for the chart editor's flat names. */
	public static function render_size_card(string $name, string $label, string $value, string $placeholder, string $kind = 'css'): void {
		?>
		<div class="btbl-checkbox btbl-design-field" data-btbl-design-field="<?php echo esc_attr($name); ?>">
			<span class="btbl-checkbox-top">
				<label class="btbl-field-name" for="<?php echo esc_attr($name); ?>"><?php echo esc_html($label); ?></label>
			</span>
			<input
				type="text"
				id="<?php echo esc_attr($name); ?>"
				class="small-text"
				name="<?php echo esc_attr($name); ?>"
				value="<?php echo esc_attr($value); ?>"
				placeholder="<?php echo esc_attr($placeholder); ?>"
				data-btbl-size-field
				data-btbl-size-kind="<?php echo esc_attr($kind); ?>"
			/>
		</div>
		<?php
	}

	/**
	 * The series palette editor.
	 *
	 * One markup, used by the chart editor's Design tab and by the site Design screen,
	 * so the chip editor drives both unchanged.
	 *
	 * @param string[] $palette The palette's colors, in order.
	 */
	public static function render_palette_editor(array $palette): void {
		?>
		<div class="btbl-palette-editor" data-btbl-palette-editor data-label-remove="<?php esc_attr_e('Remove color', 'baratables'); ?>">
			<div class="btbl-palette-chips">
				<?php foreach ($palette as $color) : ?>
					<?php self::render_chip($color); ?>
				<?php endforeach; ?>
			</div>
			<div class="btbl-palette-add">
				<div class="btbl-color-field">
					<input
						type="text"
						class="btbl-color-value"
						value=""
						placeholder="#2271b1"
						aria-label="<?php esc_attr_e('New palette color (hex)', 'baratables'); ?>"
					/>
					<input type="color" class="btbl-color-picker" value="#2271b1" aria-label="<?php esc_attr_e('Pick new palette color', 'baratables'); ?>" />
				</div>
				<button type="button" class="button" data-btbl-palette-add><?php esc_html_e('Add color', 'baratables'); ?></button>
			</div>
			<input type="hidden" name="btbl_chart_style_palette" value="<?php echo esc_attr((string) wp_json_encode(array_values($palette))); ?>" data-btbl-palette-input />
		</div>
		<?php
	}

	/** One chip of the series palette editor. */
	public static function render_chip(string $color): void {
		?>
		<span class="btbl-palette-chip" draggable="true" data-color="<?php echo esc_attr($color); ?>">
			<span class="btbl-palette-swatch" style="background-color: <?php echo esc_attr($color); ?>"></span>
			<code><?php echo esc_html($color); ?></code>
			<button type="button" class="btbl-palette-chip-remove" aria-label="<?php esc_attr_e('Remove color', 'baratables'); ?>">&times;</button>
		</span>
		<?php
	}

	/** The example size shown in an empty size field. */
	public static function size_placeholder(string $key): string {
		$placeholders = [
			'fontSize' => '14px',
			'cellPadding' => '8px 12px',
			'borderRadius' => '6px',
		];
		return $placeholders[$key] ?? '';
	}
}
