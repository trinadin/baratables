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
				<span class="btbl-field-name"><?php echo esc_html($label); ?></span>
			</span>
			<?php if (($config['type'] ?? '') === 'color') : ?>
				<div class="btbl-color-field" data-btbl-theme-accent="<?php echo esc_attr($key === 'accentColor' ? $theme_accent : ''); ?>">
					<input
						type="text"
						id="<?php echo esc_attr($input_id); ?>"
						name="<?php echo esc_attr($name_prefix . '[' . $key . ']'); ?>"
						class="btbl-color-value"
						value="<?php echo esc_attr($value); ?>"
						placeholder="<?php echo esc_attr($key === 'accentColor' ? $theme_accent : __('Theme default', 'baratables')); ?>"
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
					<option value=""><?php echo esc_html(($config['type'] ?? '') === 'css_weight' ? __('Theme default', 'baratables') : ''); ?></option>
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
				<span class="btbl-field-name"><?php echo esc_html($label); ?></span>
			</span>
			<div class="btbl-color-field">
				<input
					type="text"
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
	public static function render_size_card(string $name, string $label, string $value, string $placeholder): void {
		?>
		<div class="btbl-checkbox btbl-design-field" data-btbl-design-field="<?php echo esc_attr($name); ?>">
			<span class="btbl-checkbox-top">
				<span class="btbl-field-name"><?php echo esc_html($label); ?></span>
			</span>
			<input
				type="text"
				class="small-text"
				name="<?php echo esc_attr($name); ?>"
				value="<?php echo esc_attr($value); ?>"
				placeholder="<?php echo esc_attr($placeholder); ?>"
			/>
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
