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
	 * Ordered map: slug => ['label' => translated string, 'image' => webp filename, 'icon' => dashicon].
	 *
	 * A method rather than a constant because the labels run through __() at call time.
	 *
	 * @return array<string, array{label: string, image: string, icon: string}>
	 */
	public static function all(): array {
		return [
			'bar'            => ['label' => __('Bar', 'baratables'),            'image' => 'bar-simple.webp',          'icon' => 'chart-bar'],
			'horizontal_bar' => ['label' => __('Horizontal Bar', 'baratables'), 'image' => 'bar-y-category.webp',       'icon' => 'chart-bar'],
			'line'           => ['label' => __('Line', 'baratables'),           'image' => 'line-simple.webp',         'icon' => 'chart-line'],
			'area'           => ['label' => __('Area', 'baratables'),           'image' => 'area-basic.webp',          'icon' => 'chart-area'],
			'pie'            => ['label' => __('Pie', 'baratables'),            'image' => 'pie-simple.webp',          'icon' => 'chart-pie'],
			'donut'          => ['label' => __('Donut', 'baratables'),          'image' => 'pie-doughnut.webp',        'icon' => 'chart-pie'],
			'scatter'        => ['label' => __('Scatter', 'baratables'),        'image' => 'scatter-simple.webp',      'icon' => 'chart-line'],
			'bubble'         => ['label' => __('Bubble', 'baratables'),         'image' => 'bubble-gradient.webp',     'icon' => 'chart-line'],
			'funnel'         => ['label' => __('Funnel', 'baratables'),         'image' => 'funnel.webp',              'icon' => 'filter'],
			'gantt'          => ['label' => __('Gantt', 'baratables'),          'image' => 'custom-gantt-flight.webp', 'icon' => 'calendar-alt'],
		];
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
