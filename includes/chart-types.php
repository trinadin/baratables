<?php

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Canonical registry of BaraTables chart types.
 *
 * Single source of truth for each type's slug, translated label, gallery preview image, and
 * admin-list Dashicon, so the list can no longer drift across the sanitizer whitelist, the
 * chart-type <select>, the gallery, and the Charts list column (which previously each kept
 * their own hand-synced copy).
 */
class BaraTables_Chart_Types {

	/**
	 * Ordered map of presentation metadata and behavioral capabilities.
	 *
	 * A method rather than a constant because the labels run through __() at call time.
	 *
	 * @return array<string, array{label:string,image:string,icon:string,mode:string,stackable:bool,single_series:bool,required_roles:string[],column_roles:string[]}>
	 */
	public static function all(): array {
		return [
			'bar'            => self::type(__('Bar', 'baratables'), 'bar-simple.webp', 'chart-bar'),
			'horizontal_bar' => self::type(__('Horizontal Bar', 'baratables'), 'bar-y-category.webp', 'chart-bar'),
			'line'           => self::type(__('Line', 'baratables'), 'line-simple.webp', 'chart-line'),
			'area'           => self::type(__('Area', 'baratables'), 'area-basic.webp', 'chart-area'),
			'radar'          => self::type(__('Radar', 'baratables'), 'radar.svg', 'chart-area', 'radar', false),
			'pie'            => self::type(__('Pie', 'baratables'), 'pie-simple.webp', 'chart-pie', 'single_series', false),
			'donut'          => self::type(__('Donut', 'baratables'), 'pie-doughnut.webp', 'chart-pie', 'single_series', false),
			'treemap'        => self::type(__('Treemap', 'baratables'), 'treemap.svg', 'screenoptions', 'treemap', false, ['x_axis', 'series'], ['x_axis', 'series'], true),
			'scatter'        => self::type(__('Scatter', 'baratables'), 'scatter-simple.webp', 'chart-line', 'point', false),
			'bubble'         => self::type(__('Bubble', 'baratables'), 'bubble-gradient.webp', 'chart-line', 'point', false),
			'heatmap'        => self::type(
				__('Heatmap', 'baratables'),
				'heatmap.svg',
				'grid-view',
				'heatmap',
				false,
				['heatmap_x', 'heatmap_y', 'heatmap_value'],
				['heatmap_x', 'heatmap_y', 'heatmap_value']
			),
			'funnel'         => self::type(__('Funnel', 'baratables'), 'funnel.webp', 'filter', 'single_series', false),
			'gantt'          => self::type(
				__('Gantt', 'baratables'),
				'custom-gantt-flight.webp',
				'calendar-alt',
				'gantt',
				false,
				['gantt_label', 'gantt_start', 'gantt_end'],
				['gantt_label', 'gantt_start', 'gantt_end', 'gantt_group', 'gantt_progress']
			),
		];
	}

	private static function type(string $label, string $image, string $icon, string $mode = 'standard', bool $stackable = true, array $required_roles = ['x_axis', 'series'], array $column_roles = ['x_axis', 'series'], ?bool $single_series = null): array {
		return [
			'label' => $label,
			'image' => $image,
			'icon' => $icon,
			'mode' => $mode,
			'stackable' => $stackable,
			'single_series' => $single_series ?? $mode === 'single_series',
			'required_roles' => $required_roles,
			'column_roles' => $column_roles,
		];
	}

	public static function get(string $slug): array {
		$types = self::all();
		return $types[$slug] ?? $types['bar'];
	}

	/** Every saved column-reference key used by at least one chart mode. */
	public static function column_role_keys(): array {
		$keys = [];
		foreach (self::all() as $type) {
			foreach ($type['column_roles'] as $key) {
				$keys[$key] = true;
			}
		}
		return array_keys($keys);
	}

	/** Ordered, de-duplicated column slugs referenced by a saved chart configuration. */
	public static function referenced_columns(array $options, bool $required_only = false): array {
		$roles = $required_only
			? self::get((string) ($options['type'] ?? 'bar'))['required_roles']
			: self::column_role_keys();
		$slugs = [];
		foreach ($roles as $role) {
			$values = $role === 'series' ? (array) ($options[$role] ?? []) : [$options[$role] ?? ''];
			foreach ($values as $slug) {
				$slug = (string) $slug;
				if ($slug !== '') {
					$slugs[$slug] = true;
				}
			}
		}
		return array_keys($slugs);
	}

	public static function is_configured(array $options): bool {
		$type = self::get((string) ($options['type'] ?? 'bar'));
		foreach ($type['required_roles'] as $role) {
			if ($role === 'series') {
				if (empty($options[$role]) || !is_array($options[$role])) {
					return false;
				}
			} elseif (empty($options[$role])) {
				return false;
			}
		}
		return true;
	}

	/**
	 * All valid type slugs, for sanitization whitelists.
	 *
	 * @return string[]
	 */
	public static function slugs(): array {
		return array_keys(self::all());
	}

	/**
	 * Map of slug => translated label.
	 *
	 * @return array<string, string>
	 */
	public static function labels(): array {
		return array_map(static function (array $type): string {
			return $type['label'];
		}, self::all());
	}

	/**
	 * Map of slug => gallery preview image filename.
	 *
	 * @return array<string, string>
	 */
	public static function images(): array {
		return array_map(static function (array $type): string {
			return $type['image'];
		}, self::all());
	}
}
