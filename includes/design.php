<?php

if (!defined('ABSPATH')) {
	exit;
}

/**
 * The design system: one vocabulary of design fields, resolved at three scopes.
 *
 * A design value can come from three places, and this class owns the order they win in:
 *
 *   the entity's own value  ->  the site default  ->  the theme (or ECharts) default
 *
 * The entity is a table or a chart. The site default is one option, `btbl_default_design`,
 * that the site owner sets once on the Design screen. The theme fallback is what an empty
 * value has always meant, and it still means it: a blank field is "not set here", never
 * "reset to nothing".
 *
 * The resolution happens in PHP at render time rather than in a stylesheet, for one
 * concrete reason: `.btbl-table-wrapper` declares --btbl-accent, --btbl-border, --btbl-muted
 * and --btbl-surface on itself (assets/baratables.css), so a site-wide `:root` rule cannot
 * reach them. Printing the resolved values inline on the wrapper sidesteps specificity
 * entirely, and the precedence stays deterministic: a per-table value beats the site
 * default, and an inline value beats every stylesheet.
 */
class BaraTables_Design {

	/** The site-wide default design. Holds ['table' => [...], 'chart' => [...]]. */
	public const OPTION_KEY = 'btbl_default_design';

	/**
	 * The per-entity opt-out. A table or chart that sets this to false resolves against
	 * the theme alone and ignores the site default entirely.
	 */
	public const OPT_OUT_KEY = 'useSiteDesign';

	/**
	 * Chart design keys that print as CSS custom properties on the chart container, so a
	 * theme can reach them the same way it reaches the table's tokens.
	 */
	public const CHART_VAR_MAP = [
		'styleBackground' => ['--btbl-chart-bg'],
		'stylePadding'    => ['--btbl-chart-padding'],
		'styleRadius'     => ['--btbl-chart-radius'],
	];

	/**
	 * The chart design keys, in the order the editor renders them.
	 */
	public const CHART_KEYS = [
		'stylePalette',
		'styleBackground',
		'styleTextColor',
		'styleGridColor',
		'styleFontSize',
		'stylePadding',
		'styleRadius',
	];

	/**
	 * The table design token keys: every option that maps to a --btbl-* custom property.
	 * These are the keys the site default owns on the table side.
	 *
	 * @return string[]
	 */
	public static function table_token_keys(): array {
		return array_keys(BaraTables_Service::STYLE_VAR_MAP);
	}

	/**
	 * The stored site default, sanitized.
	 *
	 * A missing or malformed option is an empty design, not an error: the plugin has to
	 * render a site that has never touched the Design screen.
	 *
	 * @return array{table:array<string,string>,chart:array<string,mixed>}
	 */
	public static function site_default(): array {
		$stored = get_option(self::OPTION_KEY, []);
		if (!is_array($stored)) {
			$stored = [];
		}
		return [
			'table' => self::sanitize_table_scope(is_array($stored['table'] ?? null) ? $stored['table'] : []),
			'chart' => self::sanitize_chart_scope(is_array($stored['chart'] ?? null) ? $stored['chart'] : []),
		];
	}

	/**
	 * Store the site default. Returns false when nothing changed.
	 *
	 * @param array $raw The submitted design, keyed 'table' and 'chart'.
	 */
	public static function update_site_default(array $raw): bool {
		$clean = [
			'table' => self::sanitize_table_scope(is_array($raw['table'] ?? null) ? $raw['table'] : []),
			'chart' => self::sanitize_chart_scope(is_array($raw['chart'] ?? null) ? $raw['chart'] : []),
		];
		if ($clean === self::site_default()) {
			return false;
		}
		update_option(self::OPTION_KEY, $clean);
		return true;
	}

	/**
	 * The site default's table scope, sanitized.
	 *
	 * Every token is empty-means-inherit, exactly as it is on a table, so the site default
	 * and a table's own design speak the same language.
	 *
	 * @param array $raw
	 * @return array<string,string>
	 */
	public static function sanitize_table_scope(array $raw): array {
		$clean = [];
		foreach (self::table_token_keys() as $key) {
			if (!array_key_exists($key, $raw)) {
				continue;
			}
			$value = self::sanitize_token_value($key, (string) $raw[$key]);
			if ($value !== '') {
				$clean[$key] = $value;
			}
		}
		return $clean;
	}

	/**
	 * The site default's chart scope, sanitized.
	 *
	 * @param array $raw
	 * @return array<string,mixed>
	 */
	public static function sanitize_chart_scope(array $raw): array {
		$clean = [];
		if (array_key_exists('stylePalette', $raw)) {
			$palette = self::sanitize_palette($raw['stylePalette']);
			if ($palette !== []) {
				$clean['stylePalette'] = $palette;
			}
		}
		foreach (['styleBackground', 'styleTextColor', 'styleGridColor'] as $key) {
			if (!array_key_exists($key, $raw)) {
				continue;
			}
			$value = BaraTables_Service::normalize_hex_color((string) $raw[$key]);
			if ($value !== '') {
				$clean[$key] = $value;
			}
		}
		if (array_key_exists('styleFontSize', $raw)) {
			$value = BaraTables_Service::sanitize_chart_font_size($raw['styleFontSize']);
			if ($value !== '') {
				$clean['styleFontSize'] = $value;
			}
		}
		foreach (['stylePadding', 'styleRadius'] as $key) {
			if (!array_key_exists($key, $raw)) {
				continue;
			}
			$value = BaraTables_Service::sanitize_css_size((string) $raw[$key]);
			if ($value !== '') {
				$clean[$key] = $value;
			}
		}
		return $clean;
	}

	/**
	 * The site default's series palette: the same normalization the chart editor applies.
	 *
	 * @param mixed $raw
	 * @return string[]
	 */
	public static function sanitize_palette($raw): array {
		if (!is_array($raw)) {
			return [];
		}
		$palette = [];
		$seen = [];
		foreach ($raw as $color) {
			$hex = BaraTables_Service::normalize_hex_color((string) $color);
			if ($hex === '') {
				continue;
			}
			// Duplicate detection is case-insensitive, exactly as the chart editor's own
			// normalization is: #EEF2F7 and #eef2f7 are the same color, and the
			// first-seen spelling is the one that gets stored.
			$lower = strtolower($hex);
			if (in_array($lower, $seen, true)) {
				continue;
			}
			$seen[] = $lower;
			$palette[] = $hex;
			if (count($palette) >= BaraTables_Service::MAX_CHART_PALETTE_COLORS) {
				break;
			}
		}
		return $palette;
	}

	/**
	 * Sanitize one table design token by its own type.
	 */
	public static function sanitize_token_value(string $key, string $value): string {
		return BaraTables_Service::sanitize_style_value($key, $value);
	}

	/**
	 * Whether an entity opts out of the site default.
	 *
	 * The flag is absent on everything saved before 2.1.0, and absent means "use the site
	 * default", so an upgrade never changes how an existing table looks.
	 */
	public static function opts_out(array $options): bool {
		return array_key_exists(self::OPT_OUT_KEY, $options) && empty($options[self::OPT_OUT_KEY]);
	}

	/**
	 * The effective design tokens for a table.
	 *
	 * The table's own value wins; a blank token falls back to the site default; a token
	 * that is blank in both places stays blank, which is what "inherit the theme" means.
	 *
	 * @param array       $table_options The table's stored options.
	 * @param bool|null   $use_site_design Whether the site default applies at all. Null
	 *                                   reads the entity's own opt-out flag, which is the
	 *                                   normal case; an explicit value lets a render that
	 *                                   has no table of its own decide for itself.
	 * @return array<string,string> The table options with the site default folded in.
	 */
	public static function resolve_table_options(array $table_options, ?bool $use_site_design = null): array {
		$inherit = $use_site_design === null ? !self::opts_out($table_options) : $use_site_design;
		if (!$inherit) {
			return $table_options;
		}
		$default = self::site_default()['table'];
		if ($default === []) {
			return $table_options;
		}
		foreach (self::table_token_keys() as $key) {
			$own = (string) ($table_options[$key] ?? '');
			if ($own !== '') {
				continue;
			}
			$inherited = $default[$key] ?? '';
			if ($inherited !== '') {
				$table_options[$key] = $inherited;
			}
		}
		return $table_options;
	}

	/**
	 * The effective design for a chart.
	 *
	 * @param array $chart_options The chart's stored options.
	 * @return array<string,mixed> The chart options with the site default folded in.
	 */
	public static function resolve_chart_options(array $chart_options): array {
		if (self::opts_out($chart_options)) {
			return $chart_options;
		}
		$default = self::site_default()['chart'];
		if ($default === []) {
			return $chart_options;
		}
		foreach (self::CHART_KEYS as $key) {
			$own = $chart_options[$key] ?? null;
			$inherited = $default[$key] ?? null;
			if ($key === 'stylePalette') {
				if (is_array($own) && $own !== []) {
					continue;
				}
				if (is_array($inherited) && $inherited !== []) {
					$chart_options[$key] = $inherited;
				}
				continue;
			}
			if ($own !== null && (string) $own !== '') {
				continue;
			}
			if ($inherited !== null && (string) $inherited !== '') {
				$chart_options[$key] = $inherited;
			}
		}
		return $chart_options;
	}

	/**
	 * The chart container's custom properties, from the resolved chart options.
	 *
	 * @return array<string,string> Custom property name => raw value.
	 */
	public static function chart_vars_from_options(array $chart_options): array {
		$vars = [];
		foreach (self::CHART_VAR_MAP as $key => $properties) {
			$value = (string) ($chart_options[$key] ?? '');
			if ($value === '') {
				continue;
			}
			if ($key === 'styleBackground') {
				$value = BaraTables_Service::normalize_hex_color($value);
			} else {
				$value = BaraTables_Service::sanitize_css_size($value);
			}
			if ($value === '') {
				continue;
			}
			foreach ($properties as $property) {
				$vars[$property] = $value;
			}
		}
		return $vars;
	}
}
