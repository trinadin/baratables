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
	 * The form field each chart design key is edited in.
	 *
	 * The chart's options are a flat set of form fields rather than the table's
	 * "prefix[key]" shape, so the key and the field name are two vocabularies that
	 * have to be kept in step. Stating the mapping once here and shipping it to the
	 * client means the preview, Copy CSS and the preset buttons all read it, instead
	 * of each restating it -- which is how they drifted apart in the first place.
	 *
	 * @var array<string,string>
	 */
	public const CHART_FIELD_MAP = [
		'stylePalette'    => 'btbl_chart_style_palette',
		'styleBackground' => 'btbl_chart_style_background',
		'styleTextColor'  => 'btbl_chart_style_text_color',
		'styleGridColor'  => 'btbl_chart_style_grid_color',
		'styleFontSize'   => 'btbl_chart_style_font_size',
		'stylePadding'    => 'btbl_chart_style_padding',
		'styleRadius'     => 'btbl_chart_style_radius',
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
	 * Store the site default.
	 *
	 * @param array $raw The submitted design, keyed 'table' and 'chart'.
	 * @return array<string,string> The design values the server rejected, keyed by option key.
	 */
	public static function update_site_default(array $raw): array {
		$previous = self::site_default();
		$table = self::sanitize_table_scope_result(
			is_array($raw['table'] ?? null) ? $raw['table'] : [],
			$previous['table']
		);
		$chart = self::sanitize_chart_scope_result(
			is_array($raw['chart'] ?? null) ? $raw['chart'] : [],
			$previous['chart']
		);
		$clean = ['table' => $table['clean'], 'chart' => $chart['clean']];
		if ($clean !== $previous) {
			update_option(self::OPTION_KEY, $clean);
		}
		return array_merge($table['rejected'], $chart['rejected']);
	}

	/**
	 * The site default's table scope, sanitized.
	 *
	 * Every token is empty-means-inherit, exactly as it is on a table, so the site default
	 * and a table's own design speak the same language.
	 *
	 * @param array $raw
	 * @param array $previous The scope already stored, kept for a value the server rejects.
	 * @return array<string,string>
	 */
	public static function sanitize_table_scope(array $raw, array $previous = []): array {
		return self::sanitize_table_scope_result($raw, $previous)['clean'];
	}

	/**
	 * The site default's table scope, with the values the server rejected.
	 *
	 * A rejected value is not a value: dropping it would delete a default the owner can
	 * see and cannot explain. So the stored one is kept and the rejection is reported.
	 *
	 * @return array{clean:array<string,string>,rejected:array<string,string>}
	 */
	private static function sanitize_table_scope_result(array $raw, array $previous = []): array {
		$clean = [];
		$rejected = [];
		foreach (self::table_token_keys() as $key) {
			if (!array_key_exists($key, $raw)) {
				continue;
			}
			$submitted = (string) $raw[$key];
			$value = self::sanitize_token_value($key, $submitted);
			if ($value !== '') {
				$clean[$key] = $value;
				continue;
			}
			// Blank means "not set here", which is a legitimate choice: the default is
			// cleared. A non-blank value the server rejects is not.
			if (trim($submitted) === '') {
				continue;
			}
			$rejected[$key] = trim($submitted);
			if (isset($previous[$key]) && (string) $previous[$key] !== '') {
				$clean[$key] = (string) $previous[$key];
			}
		}
		return ['clean' => $clean, 'rejected' => $rejected];
	}

	/**
	 * The site default's chart scope, sanitized.
	 *
	 * @param array $raw
	 * @param array $previous The scope already stored, kept for a value the server rejects.
	 * @return array<string,mixed>
	 */
	public static function sanitize_chart_scope(array $raw, array $previous = []): array {
		return self::sanitize_chart_scope_result($raw, $previous)['clean'];
	}

	/**
	 * The site default's chart scope, with the values the server rejected.
	 *
	 * @return array{clean:array<string,mixed>,rejected:array<string,string>}
	 */
	private static function sanitize_chart_scope_result(array $raw, array $previous = []): array {
		$clean = [];
		$rejected = [];
		if (array_key_exists('stylePalette', $raw)) {
			$palette = self::sanitize_palette($raw['stylePalette']);
			if (self::palette_has_invalid_color($raw['stylePalette'])) {
				// A malformed colour is left out and the rest are kept -- the behaviour
				// the chart editor has always had. What it did not do is say so, so a
				// colour the owner typed could vanish without a word.
				$rejected['stylePalette'] = 'palette';
			}
			if ($palette !== []) {
				$clean['stylePalette'] = $palette;
			}
		}
		foreach (['styleBackground', 'styleTextColor', 'styleGridColor'] as $key) {
			if (!array_key_exists($key, $raw)) {
				continue;
			}
			$submitted = (string) $raw[$key];
			$value = BaraTables_Service::normalize_hex_color($submitted);
			if ($value !== '') {
				$clean[$key] = $value;
				continue;
			}
			if (trim($submitted) === '') {
				continue;
			}
			$rejected[$key] = trim($submitted);
			if (isset($previous[$key]) && (string) $previous[$key] !== '') {
				$clean[$key] = (string) $previous[$key];
			}
		}
		if (array_key_exists('styleFontSize', $raw)) {
			$submitted = (string) $raw['styleFontSize'];
			$value = BaraTables_Service::sanitize_chart_font_size($submitted);
			if ($value !== '') {
				$clean['styleFontSize'] = $value;
			} elseif (trim($submitted) !== '') {
				$rejected['styleFontSize'] = trim($submitted);
				if (isset($previous['styleFontSize']) && (string) $previous['styleFontSize'] !== '') {
					$clean['styleFontSize'] = (string) $previous['styleFontSize'];
				}
			}
		}
		foreach (['stylePadding', 'styleRadius'] as $key) {
			if (!array_key_exists($key, $raw)) {
				continue;
			}
			$submitted = (string) $raw[$key];
			$value = BaraTables_Service::sanitize_css_size($submitted);
			if ($value !== '') {
				$clean[$key] = $value;
				continue;
			}
			if (trim($submitted) === '') {
				continue;
			}
			$rejected[$key] = trim($submitted);
			if (isset($previous[$key]) && (string) $previous[$key] !== '') {
				$clean[$key] = (string) $previous[$key];
			}
		}
		return ['clean' => $clean, 'rejected' => $rejected];
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
	 * Whether a submitted palette holds a colour the server would not accept.
	 *
	 * A duplicate is not a rejection -- the same colour twice is one colour, and the
	 * first-seen spelling is the one that is kept. A colour that is not a hex is.
	 *
	 * @param mixed $raw
	 */
	public static function palette_has_invalid_color($raw): bool {
		return BaraTables_Service::palette_has_invalid_color($raw);
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
