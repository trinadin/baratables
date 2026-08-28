<?php

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Canonical semantics for the opt-in typed-cell features.
 *
 * Rows deliberately remain display strings throughout BaraTables. This class derives a strict
 * numeric value from that final display string without replacing it, so sorting, conditional
 * rules, and footers can agree while charts and existing integrations continue receiving strings.
 */
final class BaraTables_Cell_Semantics {
	public const MAX_RULES = 8;
	public const MAX_ABS_NUMBER = 1000000000000;

	private const OPERATORS = ['lt', 'lte', 'eq', 'neq', 'gte', 'gt'];
	private const PRESETS = ['accent', 'success', 'warning', 'danger'];
	private const AGGREGATES = ['none', 'sum', 'average', 'min', 'max'];

	public static function normalize_type($value): string {
		return (string) $value === 'number' ? 'number' : 'auto';
	}

	public static function normalize_aggregate($value, string $type): string {
		$value = (string) $value;
		return $type === 'number' && in_array($value, self::AGGREGATES, true) ? $value : 'none';
	}

	/**
	 * Accept a finite, ungrouped base-10 decimal. Currency, percentages, exponents, and locale
	 * separators are intentionally not guessed.
	 */
	public static function parse_number_from_html($html): ?float {
		$text = html_entity_decode(wp_strip_all_tags((string) $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$text = trim($text);
		if ($text === '' || preg_match('/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)$/D', $text) !== 1) {
			return null;
		}
		$value = (float) $text;
		if (!is_finite($value) || abs($value) > self::MAX_ABS_NUMBER) {
			return null;
		}
		// Every consumer receives the same twelve-decimal canonical value. This is wider than
		// the six-decimal footer display policy, so distinct sortable values are not collapsed.
		return (float) self::canonical_number($value);
	}

	public static function canonical_number(float $value): string {
		$rounded = round($value, 12);
		if (abs($rounded) < 0.0000000000005) {
			$rounded = 0.0;
		}
		return rtrim(rtrim(number_format($rounded, 12, '.', ''), '0'), '.');
	}

	public static function format_aggregate_number(float $value): string {
		$rounded = round($value, 6);
		if (abs($rounded) < 0.0000005) {
			$rounded = 0.0;
		}
		return rtrim(rtrim(number_format($rounded, 6, '.', ''), '0'), '.');
	}

	/**
	 * Parse the editor's compact rule syntax, one rule per line: "< 0 => danger".
	 */
	public static function parse_rule_text($raw, string $type): array {
		if ($type !== 'number' || !is_scalar($raw)) {
			return [];
		}
		$operator_map = [
			'<' => 'lt',
			'<=' => 'lte',
			'=' => 'eq',
			'==' => 'eq',
			'!=' => 'neq',
			'>=' => 'gte',
			'>' => 'gt',
		];
		$rules = [];
		foreach (preg_split('/\R/u', (string) $raw) ?: [] as $line) {
			$line = trim($line);
			if ($line === '' || preg_match('/^(<=|>=|!=|==|<|>|=)\s*(.*?)\s*=>\s*([a-z]+)$/i', $line, $match) !== 1) {
				continue;
			}
			$threshold = self::parse_number_from_html($match[2]);
			$preset = strtolower($match[3]);
			if ($threshold === null || !isset($operator_map[$match[1]]) || !in_array($preset, self::PRESETS, true)) {
				continue;
			}
			$rules[] = [
				'operator' => $operator_map[$match[1]],
				'value' => self::canonical_number($threshold),
				'preset' => $preset,
			];
			if (count($rules) >= self::MAX_RULES) {
				break;
			}
		}
		return $rules;
	}

	public static function normalize_rules($raw, string $type): array {
		if ($type !== 'number' || !is_array($raw)) {
			return [];
		}
		$rules = [];
		foreach ($raw as $rule) {
			if (!is_array($rule)) {
				continue;
			}
			$operator = (string) ($rule['operator'] ?? '');
			$preset = (string) ($rule['preset'] ?? '');
			$threshold = self::parse_number_from_html($rule['value'] ?? '');
			if (!in_array($operator, self::OPERATORS, true) || !in_array($preset, self::PRESETS, true) || $threshold === null) {
				continue;
			}
			$rules[] = [
				'operator' => $operator,
				'value' => self::canonical_number($threshold),
				'preset' => $preset,
			];
			if (count($rules) >= self::MAX_RULES) {
				break;
			}
		}
		return $rules;
	}

	public static function rules_to_text(array $rules): string {
		$symbols = ['lt' => '<', 'lte' => '<=', 'eq' => '=', 'neq' => '!=', 'gte' => '>=', 'gt' => '>'];
		$lines = [];
		foreach (self::normalize_rules($rules, 'number') as $rule) {
			$lines[] = $symbols[$rule['operator']] . ' ' . $rule['value'] . ' => ' . $rule['preset'];
		}
		return implode("\n", $lines);
	}

	public static function matching_preset(?float $value, array $rules): string {
		if ($value === null) {
			return '';
		}
		foreach (self::normalize_rules($rules, 'number') as $rule) {
			$threshold = (float) $rule['value'];
			$matches = false;
			switch ($rule['operator']) {
				case 'lt': $matches = $value < $threshold; break;
				case 'lte': $matches = $value <= $threshold; break;
				case 'eq': $matches = $value === $threshold; break;
				case 'neq': $matches = $value !== $threshold; break;
				case 'gte': $matches = $value >= $threshold; break;
				case 'gt': $matches = $value > $threshold; break;
			}
			if ($matches) {
				return $rule['preset'];
			}
		}
		return '';
	}

	public static function aggregate_rows(array $rows, int $column_index, string $aggregate): string {
		if (!in_array($aggregate, self::AGGREGATES, true) || $aggregate === 'none') {
			return '';
		}
		$values = [];
		foreach ($rows as $row) {
			if (!is_array($row)) {
				continue;
			}
			$value = self::parse_number_from_html($row[$column_index] ?? '');
			if ($value !== null) {
				$values[] = $value;
			}
		}
		if (empty($values)) {
			return '';
		}
		switch ($aggregate) {
			case 'sum': $result = array_sum($values); break;
			case 'average': $result = array_sum($values) / count($values); break;
			case 'min': $result = min($values); break;
			case 'max': $result = max($values); break;
			default: return '';
		}
		return self::format_aggregate_number((float) $result);
	}

	/** Return safe markup and allowlisted classes for one final display value. */
	public static function present_cell(string $safe_html, array $column): array {
		if (self::normalize_type($column['data_type'] ?? 'auto') !== 'number') {
			return ['html' => $safe_html, 'classes' => [], 'number' => null];
		}
		$value = self::parse_number_from_html($safe_html);
		if ($value === null) {
			return ['html' => $safe_html, 'classes' => ['btbl-typed-number', 'btbl-number-invalid'], 'number' => null];
		}
		$canonical = self::canonical_number($value);
		$preset = self::matching_preset($value, $column['conditional_rules'] ?? []);
		$classes = ['btbl-typed-number'];
		$marker_classes = ['btbl-number-marker'];
		if ($preset !== '') {
			$classes[] = 'btbl-condition-' . $preset;
			$marker_classes[] = 'btbl-condition-' . $preset;
		}
		// A zero-size sibling marker keeps the DataTables row value a string without nesting
		// existing block markup (e.g. <div> or <p>) inside an inline wrapper.
		$html = '<span class="' . esc_attr(implode(' ', $marker_classes)) . '" data-btbl-number="' . esc_attr($canonical) . '" hidden></span>' . $safe_html;
		return ['html' => $html, 'classes' => $classes, 'number' => $canonical];
	}

	public static function aggregate_label(string $aggregate): string {
		$labels = [
			'sum' => __('Sum', 'baratables'),
			'average' => __('Average', 'baratables'),
			'min' => __('Minimum', 'baratables'),
			'max' => __('Maximum', 'baratables'),
		];
		return (string) ($labels[$aggregate] ?? '');
	}
}
