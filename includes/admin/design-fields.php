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
				// The raw ampersand is deliberate: it is the msgid 2.0.1 shipped, and
				// WordPress.org translates by msgid, so escaping it here would drop the
				// existing translation of this line.
				'description' => __('Used by the per-column conditional formatting rules on the Columns & filters tab.', 'baratables'),
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
	public static function render_table_groups(array $values, string $name_prefix = 'btbl_table_options', string $id_prefix = 'btbl_design', string $blank_accent = ''): void {
		$schema = BaraTables_Service::get_table_option_schema();
		// The accent that resolves when the accent field is blank. The table editor passes
		// the accent that actually applies to that table; the site screen edits the site
		// default itself, so a blank field there resolves to the theme's accent.
		$blank_accent = $blank_accent !== '' ? $blank_accent : BaraTables_Service::resolve_theme_accent();
		foreach (self::table_groups() as $group) {
			?>
			<div class="btbl-control btbl-design-fields">
				<strong class="btbl-small-heading"><?php echo esc_html($group['heading']); ?></strong>
				<?php if (!empty($group['description'])) : ?>
					<p class="description"><?php echo esc_html($group['description']); ?></p>
				<?php endif; ?>
				<div class="btbl-flag-grid btbl-table-flags">
					<?php foreach ($group['fields'] as $field_key) : ?>
						<?php self::render_field($field_key, $schema[$field_key] ?? [], $values, $blank_accent, $name_prefix, $id_prefix); ?>
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
					<?php self::render_field($field_key, $schema[$field_key] ?? [], $values, $blank_accent, $name_prefix, $id_prefix); ?>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * The chart's style fields, in the order the surfaces render them.
	 *
	 * One list, so the chart editor's Design tab, the site Design screen and the copy
	 * that names a rejected value cannot drift apart. The form field name comes from the
	 * server's chart field map -- the one place that says which key lives in which field.
	 *
	 * @return array<int,array<string,string>> Each field has a key, a name, a label, a
	 *                                                group, a type and a placeholder.
	 */
	public static function chart_style_fields(): array {
		$fields = [
			[
				'key'         => 'styleBackground',
				'label'       => __('Background', 'baratables'),
				'group'       => 'colors',
				'type'        => 'color',
				'placeholder' => __('Canvas default', 'baratables'),
			],
			[
				'key'         => 'styleTextColor',
				'label'       => __('Text color', 'baratables'),
				'group'       => 'colors',
				'type'        => 'color',
				'placeholder' => __('ECharts default', 'baratables'),
			],
			[
				'key'         => 'styleGridColor',
				'label'       => __('Grid color', 'baratables'),
				'group'       => 'colors',
				'type'        => 'color',
				'placeholder' => __('ECharts default', 'baratables'),
				// The marker the chart editor uses to hide the grid colour for the chart
				// types that have no axes.
				'marker'      => 'data-btbl-chart-grid-field',
			],
			[
				'key'         => 'styleFontSize',
				'label'       => __('Font size', 'baratables'),
				'group'       => 'colors',
				'type'        => 'chart-font',
				'placeholder' => '14px',
			],
			[
				'key'         => 'stylePadding',
				'label'       => __('Padding', 'baratables'),
				'group'       => 'container',
				'type'        => 'css',
				'placeholder' => __('None', 'baratables'),
			],
			[
				'key'         => 'styleRadius',
				'label'       => __('Corner radius', 'baratables'),
				'group'       => 'container',
				'type'        => 'css',
				'placeholder' => __('None', 'baratables'),
			],
		];
		$map = BaraTables_Design::CHART_FIELD_MAP;
		foreach ($fields as $index => $field) {
			$fields[$index]['name'] = (string) ($map[$field['key']] ?? '');
		}
		return $fields;
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
		$fields = self::chart_style_fields();
		?>
		<div class="btbl-control">
			<strong class="btbl-small-heading"><?php esc_html_e('Colors & type', 'baratables'); ?></strong>
			<div class="btbl-flag-grid btbl-table-flags btbl-design-fields">
				<?php
				foreach ($fields as $field) {
					if ($field['group'] !== 'colors') {
						continue;
					}
					$marker = ($grid_field_marker && $field['key'] === 'styleGridColor') ? $field['marker'] : '';
					if ($field['type'] === 'color') {
						self::render_color_card($field['name'], $field['label'], (string) ($values[$field['key']] ?? ''), $field['placeholder'], $marker);
						continue;
					}
					self::render_size_card($field['name'], $field['label'], (string) ($values[$field['key']] ?? ''), $field['placeholder'], $field['type']);
				}
				?>
			</div>
			<p class="description btbl-field-hint"><?php esc_html_e('Text and grid colors apply to axis labels, legends, and grid lines. The palette colors the series, the slices, and a Gantt\'s task groups.', 'baratables'); ?></p>
		</div>
		<div class="btbl-control">
			<strong class="btbl-small-heading"><?php esc_html_e('Chart container', 'baratables'); ?></strong>
			<p class="description"><?php esc_html_e('The space and rounding around the chart itself, rather than inside it.', 'baratables'); ?></p>
			<div class="btbl-flag-grid btbl-table-flags btbl-design-fields">
				<?php
				foreach ($fields as $field) {
					if ($field['group'] !== 'container') {
						continue;
					}
					self::render_size_card($field['name'], $field['label'], (string) ($values[$field['key']] ?? ''), $field['placeholder'], $field['type']);
				}
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
	 * @param string $blank_accent The accent that resolves when the field is blank, used
	 *                             as the accent placeholder and to park the swatch.
	 * @param string $name_prefix The form field name prefix, e.g. 'btbl_table_options'.
	 * @param string $id_prefix   The element id prefix.
	 */
	public static function render_field(string $key, array $config, array $values, string $blank_accent, string $name_prefix = 'btbl_table_options', string $id_prefix = 'btbl_design'): void {
		$label = (string) ($config['label'] ?? $key);
		$description = (string) ($config['description'] ?? '');
		// Raw schema key, matching the Options tab's btbl_rowLimit precedent: the id mirrors
		// the stored camelCase option key (sanitize_key would lowercase it).
		$input_id = $id_prefix . '_' . $key;
		$value = (string) ($values[$key] ?? '');
		// The hint is linked to the control, so a screen reader reads the field's guidance
		// with the field instead of leaving it as a free-floating paragraph.
		$hint_id = $input_id . '_hint';
		$described_by = $description !== '' ? ' aria-describedby="' . esc_attr($hint_id) . '"' : '';
		?>
		<div class="btbl-checkbox btbl-design-field" data-btbl-design-field="<?php echo esc_attr($key); ?>">
			<span class="btbl-checkbox-top">
				<label class="btbl-field-name" for="<?php echo esc_attr($input_id); ?>"><?php echo esc_html($label); ?></label>
			</span>
			<?php if (($config['type'] ?? '') === 'color') : ?>
				<div class="btbl-color-field" data-btbl-blank-accent="<?php echo esc_attr($key === 'accentColor' ? $blank_accent : ''); ?>">
					<input
						type="text"
						id="<?php echo esc_attr($input_id); ?>"
						name="<?php echo esc_attr($name_prefix . '[' . $key . ']'); ?>"
						class="btbl-color-value"
						value="<?php echo esc_attr($value); ?>"
						placeholder="<?php echo esc_attr($key === 'accentColor' ? $blank_accent : __('Inherit', 'baratables')); ?>"
						data-btbl-live-style
						<?php echo $described_by; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from esc_attr() above. ?>
					/>
					<input type="color" class="btbl-color-picker" value="<?php echo esc_attr($value !== '' ? $value : ($key === 'accentColor' ? $blank_accent : '#ffffff')); ?>" aria-label="<?php echo esc_attr(sprintf(
						/* translators: %s is the control label, e.g. "Background". */
						__('Pick %s', 'baratables'),
						$label
					)); ?>" />
				</div>
			<?php elseif (!empty($config['choices']) && is_array($config['choices'])) : ?>
				<select id="<?php echo esc_attr($input_id); ?>" name="<?php echo esc_attr($name_prefix . '[' . $key . ']'); ?>" data-btbl-live-style<?php echo $described_by; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from esc_attr() above. ?>>
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
					<?php echo $described_by; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from esc_attr() above. ?>
				/>
			<?php endif; ?>
			<?php if ($description !== '') : ?>
				<p class="description btbl-field-hint" id="<?php echo esc_attr($hint_id); ?>"><?php echo esc_html($description); ?></p>
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
	 * The hint that explains what the series palette does on this surface.
	 *
	 * The two surfaces differ, and the difference is the whole point of the sentence: on
	 * the site screen the palette IS the default, while in the chart editor an empty
	 * palette inherits the site default's.
	 *
	 * @param bool $is_site_default Whether this is the site Design screen.
	 */
	public static function palette_hint(bool $is_site_default = false): string {
		if ($is_site_default) {
			return __('Colors are used in order across series and slices. Drag to reorder. An empty palette leaves the series to the default colors, led by the accent.', 'baratables');
		}
		return __('Colors are used in order across series and slices. Drag to reorder. An empty palette inherits the site default\'s palette, or the default colors led by the accent.', 'baratables');
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
		// The remove button's accessible name is a template: the client fills in the
		// colour when it builds a chip, so the rendered name and the client-built one
		// come from one translatable string.
		/* translators: %s is a hex color, e.g. "#2271b1". */
		$remove_label = esc_attr__("Remove color %s", 'baratables');
		$move_label = esc_attr__('Use the arrow keys to move this color.', 'baratables');
		?>
		<div class="btbl-palette-editor" data-btbl-palette-editor data-label-remove="<?php echo esc_attr($remove_label); ?>" data-label-move="<?php echo esc_attr($move_label); ?>">
			<div class="btbl-palette-chips" role="list">
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
		<span class="btbl-palette-chip" draggable="true" tabindex="0" role="listitem" data-color="<?php echo esc_attr($color); ?>" title="<?php esc_attr_e('Use the arrow keys to move this color.', 'baratables'); ?>">
			<span class="btbl-palette-swatch" style="background-color: <?php echo esc_attr($color); ?>"></span>
			<code><?php echo esc_html($color); ?></code>
			<button type="button" class="btbl-palette-chip-remove" aria-label="<?php echo esc_attr(sprintf(
				/* translators: %s is a hex color, e.g. "#2271b1". */
				__('Remove color %s', 'baratables'),
				$color
			)); ?>">&times;</button>
		</span>
		<?php
	}

	/**
	 * The label of every design value, keyed by its option key.
	 *
	 * The copy that names a rejected value uses this, so the notice says "Accent color"
	 * rather than "accentColor", and says the same thing on every surface.
	 *
	 * @return array<string,string>
	 */
	public static function value_labels(): array {
		$labels = [];
		foreach (BaraTables_Service::get_table_option_schema() as $key => $config) {
			if (($config['editor_group'] ?? '') === 'design') {
				$labels[$key] = (string) ($config['label'] ?? $key);
			}
		}
		foreach (self::chart_style_fields() as $field) {
			$labels[$field['key']] = $field['label'];
		}
		$labels['stylePalette'] = __('Series palette', 'baratables');
		return $labels;
	}

	/**
	 * The notice for design values the server would not accept.
	 *
	 * A rejected value keeps the one already stored, so the user needs to be told: without
	 * this, their change would simply not take effect and they would have no way to know
	 * why.
	 *
	 * @param array<string,string> $rejected The option key => the value that was submitted.
	 * @return string The notice text, or '' when there is nothing to report.
	 */
	public static function rejected_values_notice(array $rejected): string {
		if ($rejected === []) {
			return '';
		}
		$labels = self::value_labels();
		$names = [];
		$palette_rejected = false;
		foreach (array_keys($rejected) as $key) {
			if ($key === 'stylePalette') {
				$palette_rejected = true;
				continue;
			}
			$names[] = $labels[$key] ?? $key;
		}
		$parts = [];
		if ($names !== []) {
			$parts[] = sprintf(
				/* translators: %s is a comma-separated list of field names, e.g. "Accent color, Cell padding". */
				__('These values were not accepted, so the previous ones were kept: %s.', 'baratables'),
				implode(', ', $names)
			);
		}
		if ($palette_rejected) {
			$parts[] = __('Some colors in the series palette were not accepted, so they were left out.', 'baratables');
		}
		return implode(' ', $parts);
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
