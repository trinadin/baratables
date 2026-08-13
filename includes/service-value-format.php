<?php

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Cell value resolution + formatting for BaraTables_Service: reading a core/meta/taxonomy
 * value for a post, stringifying it, applying date formats, value overrides and merge tags.
 * Extracted from the service class; runs in the class scope via the trait.
 */
trait BaraTables_Value_Format_Trait {
	public function resolve_value(WP_Post $post, array $column): string {
		if ($column['source'] === 'core') {
			$value = $this->get_core_value($post, $column['key']);
		} elseif ($column['source'] === 'tax') {
			$value = $this->get_taxonomy_value($post, $column['key']);
		} else {
			$value = $this->get_meta_value($post->ID, $column['key']);
		}

		$value = $this->stringify_cell_value($value);

		if (!empty($column['format_date'])) {
			$format = isset($column['date_format']) ? (string) $column['date_format'] : '';
			// post_date/post_modified arrive from get_core_value() already rendered by
			// get_the_date()/get_the_modified_date(), which drop the time and localize the month
			// name. Re-parsing that with strtotime() zeroes the clock (so "Y-m-d H:i" always ends
			// in 00:00) and mis-parses entirely on non-English sites. Format the raw column value.
			if ($column['source'] === 'core' && in_array($column['key'], ['post_date', 'post_modified'], true)) {
				$raw_date = $column['key'] === 'post_date' ? $post->post_date : $post->post_modified;
				if (is_string($raw_date) && $raw_date !== '' && $raw_date !== '0000-00-00 00:00:00') {
					$value = $raw_date;
				}
			}
			$value = $this->format_date_value($value, $format);
		}

		// Deliberately NOT wp_kses_post()'d here. Escaping happens at output, where it is
		// authoritative for all five sources: the front-end <td> (frontend.php) and the admin
		// preview (pages.php) both wp_kses_post() every cell, and the chart payload's values pass
		// through btblExtractText()/btblParseNumber() in baratables.js before reaching ECharts.
		// A pass here could not be authoritative anyway -- the compiled override pass runs after
		// value resolution and can reintroduce markup. The CSV, external-DB and manual paths never
		// kses'd at row-build time either, so this keeps all five consistent.
		return $value;
	}

	/**
	 * Reduce any resolved cell value to a string.
	 *
	 * Post meta is not guaranteed to be scalar. get_meta_value() prefers ACF's get_field(),
	 * which returns typed values: WP_Post for a Post Object field, WP_Term for Taxonomy, and
	 * nested arrays for Relationship/Repeater. WP_Post has no __toString, so a bare (string)
	 * cast raises an uncaught Error and white-screens every page carrying the shortcode.
	 * Every non-scalar shape is reduced here instead of at the cast sites.
	 */
	private function stringify_cell_value($value, int $depth = 0): string {
		if (is_string($value)) {
			return $value;
		}
		if (is_scalar($value)) {
			return (string) $value;
		}
		if ($value === null) {
			return '';
		}
		if (is_array($value)) {
			if ($depth >= self::MAX_VALUE_FLATTEN_DEPTH) {
				return '';
			}
			$parts = [];
			foreach ($value as $item) {
				$part = $this->stringify_cell_value($item, $depth + 1);
				if ($part !== '') {
					$parts[] = $part;
				}
			}
			return implode(', ', $parts);
		}
		if (is_object($value)) {
			if ($value instanceof WP_Post) {
				return (string) $value->post_title;
			}
			if ($value instanceof WP_Term) {
				return (string) $value->name;
			}
			if ($value instanceof DateTimeInterface) {
				return $value->format('Y-m-d H:i:s');
			}
			if (method_exists($value, '__toString')) {
				return (string) $value;
			}
		}

		return '';
	}

	/**
	 * Build a list of date_format strings aligned to a column list's order: one entry per
	 * column, a string when that column has the "Format as date" toggle on, or null when it
	 * does not. Returns [] when no column is date-formatted so callers can skip cheaply.
	 *
	 * Used by the CSV and external-DB paths, whose rows are emitted already ordered to match
	 * the column list, so the index-aligned list maps straight onto each row's cells. (The
	 * custom-data path applies formatting earlier, before its value-override stage, so it keeps
	 * its own slug-keyed builder.)
	 */
	private function build_ordered_date_formats(array $columns): array {
		$has_any = false;
		$formats = [];
		foreach (array_values($columns) as $col) {
			if (is_array($col) && !empty($col['format_date'])) {
				$formats[] = isset($col['date_format']) ? (string) $col['date_format'] : '';
				$has_any = true;
			} else {
				$formats[] = null;
			}
		}
		return $has_any ? $formats : [];
	}

	/**
	 * Apply an index-aligned date_format list (from build_ordered_date_formats) to rows that are
	 * already ordered to match the same column list. Cells whose format entry is null are left as-is.
	 */
	private function apply_ordered_date_formats(array $rows, array $formats): array {
		if (empty($formats)) {
			return $rows;
		}
		foreach ($rows as &$row) {
			if (!is_array($row)) {
				continue;
			}
			foreach ($formats as $idx => $format) {
				if ($format === null || !array_key_exists($idx, $row)) {
					continue;
				}
				$row[$idx] = $this->format_date_value((string) $row[$idx], $format);
			}
		}
		unset($row);
		return $rows;
	}

	/**
	 * Apply value-override rules to rows already ordered to match the same column list.
	 *
	 * Each row first yields a {{slug}} / {{row.slug}} token map, then every cell is run through the
	 * rules. This is the single implementation for every source -- manual, CSV and external DB.
	 * The custom-data row path used to carry its own hand-synced copy of these token rules, so a
	 * change to token semantics had to be made twice or manual tables silently diverged.
	 */
	private function apply_ordered_overrides(array $rows, array $columns, array $overrides): array {
		if (empty($overrides)) {
			return $rows;
		}
		$slugs = $this->column_slugs_in_order($columns);
		if (empty(array_filter($slugs))) {
			return $rows;
		}
		$compiled = $this->compile_overrides_for_columns($overrides, $slugs);
		if (empty($compiled['rules'])) {
			return $rows;
		}
		foreach ($rows as &$row) {
			if (!is_array($row)) {
				continue;
			}
			$row_tokens = [];
			if ($compiled['uses_row_tokens']) {
				foreach ($slugs as $idx => $slug) {
					if ($slug === '' || !array_key_exists($idx, $row)) {
						continue;
					}
					$value = (string) $row[$idx];
					$row_tokens[strtolower($slug)] = $value;
					$separator = strpos($slug, ':');
					if ($separator !== false) {
						$key = substr($slug, $separator + 1);
						if ($key !== '') {
							$row_tokens[strtolower($key)] = $value;
						}
					}
				}
			}
			foreach ($slugs as $idx => $slug) {
				if ($slug === '' || !array_key_exists($idx, $row) || empty($compiled['rules'][$slug])) {
					continue;
				}
				$row[$idx] = $this->apply_compiled_overrides_with(
					(string) $row[$idx],
					$compiled['rules'][$slug],
					function (string $replace) use ($row_tokens): string {
						return $this->replace_row_tokens($replace, $row_tokens);
					}
				);
			}
		}
		unset($row);
		return $rows;
	}

	private function format_date_value($value, string $format): string {
		// Callers normally pass a string, but a meta value can be an object or array (ACF);
		// reduce first so no (string) cast below can fatal on a WP_Post/WP_Term.
		if (!is_scalar($value)) {
			$value = $this->stringify_cell_value($value);
		}
		$format = $format !== '' ? $format : get_option('date_format');
		if ($value === '' || $format === '') {
			return (string) $value;
		}

		if (is_numeric($value)) {
			$intVal = (int) $value;
			// Treat very large integers as JS millisecond timestamps. The threshold
			// (1e11) sits well above any plausible seconds-timestamp date (1e11 s ~ year
			// 5138) and below any modern ms timestamp (~1.7e12), so a seconds-timestamp
			// for a post-2033 date is no longer misread as milliseconds.
			if ($intVal > 100000000000) {
				$intVal = (int) ($intVal / 1000);
			}
			// Without a lower bound, a small integer (a year like 2024, an age, a count,
			// an ID) would be read as epoch seconds and render as a 1970-era date. Only
			// treat integers large enough to be a plausible real timestamp (|n| >= 1e8,
			// ~year 1973) as epoch; leave smaller numbers as their raw value.
			if (abs($intVal) < 100000000) {
				return (string) $value;
			}
			// A genuine Unix epoch must go through wp_date(), which converts UTC to the site
			// timezone. date_i18n() treats its timestamp argument as a "timestamp with offset"
			// (a documented core quirk), so it would render the UTC wall clock labelled as
			// local time -- every value shifted by the site's offset, and dates landing on the
			// wrong day whenever UTC and local straddle midnight.
			return wp_date($format, $intVal);
		}

		$timestamp = strtotime((string) $value);
		if ($timestamp === false) {
			return (string) $value;
		}

		// Deliberately date_i18n(), not wp_date(): WordPress sets PHP's default timezone to UTC,
		// so strtotime() reads an already-local date string ("2026-06-26 04:33:06", post_date)
		// as if it were UTC. date_i18n()'s legacy handling converts it straight back to the same
		// wall clock, which is exactly right here. wp_date() would shift it by the site offset.
		return date_i18n($format, $timestamp);
	}

	/**
	 * Compile wildcard and column-specific rules into the exact ordered list each column needs.
	 * Regex validity is checked once per row set instead of once per matching cell.
	 *
	 * @return array{rules:array<string,array<int,array<string,mixed>>>,uses_row_tokens:bool}
	 */
	private function compile_overrides_for_columns(array $overrides, array $column_slugs): array {
		$rules = [];
		foreach (array_unique(array_filter(array_map('strval', $column_slugs))) as $slug) {
			$rules[$slug] = [];
		}
		$uses_row_tokens = false;
		foreach ($overrides as $rule) {
			if (!is_array($rule) || !isset($rule['column'], $rule['search']) || (string) $rule['column'] === '' || (string) $rule['search'] === '') {
				continue;
			}
			$column = (string) $rule['column'];
			$search = (string) $rule['search'];
			$replace = isset($rule['replace']) ? (string) $rule['replace'] : '';
			$is_regex = !empty($rule['regex']);
			if ($is_regex) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Validate a user-supplied pattern without emitting a warning.
				set_error_handler(static function () {});
				$valid = preg_match($search, '') !== false;
				restore_error_handler();
				if (!$valid) {
					continue;
				}
			}
			$compiled_rule = [
				'search' => $search,
				'replace' => $replace,
				'regex' => $is_regex,
			];
			if (preg_match('/{{\\s*(?:row\\.)?[a-z0-9_:-]+\\s*}}/i', $replace)) {
				$uses_row_tokens = true;
			}
			if ($column === '*') {
				foreach ($rules as &$column_rules) {
					$column_rules[] = $compiled_rule;
				}
				unset($column_rules);
			} elseif (isset($rules[$column])) {
				$rules[$column][] = $compiled_rule;
			}
		}
		$rules = array_filter($rules);
		return ['rules' => $rules, 'uses_row_tokens' => $uses_row_tokens];
	}

	private function apply_compiled_overrides_with(string $value, array $rules, callable $resolve_replace): string {
		if ($value === '' || empty($rules)) {
			return $value;
		}

		foreach ($rules as $rule) {
			$search = $rule['search'];
			if (!empty($rule['regex'])) {
				$resolved_replace = $resolve_replace($rule['replace']);
				$result = preg_replace($search, $resolved_replace, $value);
				if (is_string($result)) {
					$value = $result;
				}
			} elseif (strpos($value, $search) !== false) {
				$value = str_replace($search, $resolve_replace($rule['replace']), $value);
			}
		}

		return $value;
	}

	private function replace_merge_tags(string $text, WP_Post $post): string {
		if ($text === '') {
			return $text;
		}

		$pattern = '/{{\s*(core|meta):([^}]+)\s*}}/i';
		$text = preg_replace_callback($pattern, function ($matches) use ($post) {
			$source = strtolower($matches[1]);
			$raw_key = trim($matches[2]);
			$key = sanitize_key($raw_key);
			if ($key === '') {
				return '';
			}

			$value = $source === 'core'
				? $this->get_core_value($post, $key)
				: $this->get_meta_value($post->ID, $key);

			return wp_kses_post($this->stringify_cell_value($value));
		}, $text);

		return $text;
	}

	private function replace_row_tokens(string $text, array $row_tokens): string {
		if ($text === '' || empty($row_tokens)) {
			return $text;
		}
		return preg_replace_callback('/{{\\s*(?:row\\.)?([a-z0-9_:-]+)\\s*}}/i', function ($matches) use ($row_tokens) {
			$token = strtolower($matches[1]);
			return array_key_exists($token, $row_tokens) ? $row_tokens[$token] : $matches[0];
		}, $text);
	}

	/**
	 * WP_Post fields a `core:` column may expose.
	 *
	 * The reader used to return ANY property by name, so a column slug of `core:post_password`
	 * published each post's plaintext password. A slug like that is reachable without the admin
	 * ever typing it: the importer builds `core:<original_name>` straight from an uploaded file
	 * (see BaraTables_Import), so a crafted export could hide it behind an innocuous heading.
	 *
	 * Allowlist rather than denylist, and deliberately WIDER than the nine keys the picker offers,
	 * so legacy/imported columns such as `core:post_name` keep working. `post_password` and
	 * `post_content_filtered` are the sensitive omissions.
	 */
	private static function allowed_core_keys(): array {
		// Constants declared by traits require PHP 8.2. Keep this request-local immutable list in a
		// method so BaraTables continues to load on its declared PHP 7.4 platform floor.
		static $keys = [
			'ID', 'post_author', 'post_date', 'post_date_gmt', 'post_content', 'post_title',
			'post_excerpt', 'post_status', 'comment_status', 'ping_status', 'post_name',
			'post_modified', 'post_modified_gmt', 'post_parent', 'guid', 'menu_order',
			'post_type', 'post_mime_type', 'comment_count', 'permalink',
		];
		return $keys;
	}

	public function get_core_value(WP_Post $post, string $key) {
		// Password-protected posts: WordPress lists them publicly but withholds their content, and
		// core does that inside get_the_title()/get_the_excerpt(). The content branches below read
		// $post->post_content directly, so without this the table published the protected body --
		// and the excerpt fast path bypassed the gate get_the_excerpt() used to provide.
		// Matches core's own semantics: the row still appears, the protected text does not.
		//
		// Test the column and password field before post_password_required(), whose password hash
		// check is expensive and applies to every protected row when the site-wide cookie exists.
		//
		// Deliberately NOT memoized. Caching the verdict per post makes this stateful, and the
		// state it depends on (the wp-postpass_ cookie) is exactly what the caller may change --
		// a memo returned "locked" to a visitor who had since unlocked the post. The remaining
		// win was 2x on an already-narrow path; correctness is worth more than that here.
		if (in_array($key, ['post_content', 'post_excerpt'], true)
			&& (string) $post->post_password !== ''
			&& post_password_required($post)) {
			return '';
		}

		switch ($key) {
			case 'ID':
				return $post->ID;
			case 'post_title':
				return get_the_title($post);
			case 'post_excerpt':
				// A manual excerpt is returned as-is by get_the_excerpt(). With no manual excerpt,
				// get_the_excerpt() auto-generates one by running the FULL the_content filter chain
				// (do_blocks + every content filter) per row -- the dominant cost on a large table.
				// Fall back to a cheap content trim in that case, mirroring the post_content branch.
				if (trim((string) $post->post_excerpt) === '') {
					// Mirror wp_trim_excerpt()'s own pipeline minus the expensive the_content pass:
					// honour excerpt_length/excerpt_more (themes very commonly filter these), and
					// strip block delimiters -- without excerpt_remove_blocks() a post built from
					// dynamic blocks trims to an EMPTY cell, because its content is all comments.
					// Everything wp_trim_excerpt() does EXCEPT the the_content pass (which is the
					// expensive part and the only reason this fast path exists). Each omission below
					// was a real behaviour difference from core:
					//   - excerpt_remove_blocks: a dynamic-block post otherwise trims to nothing;
					//   - excerpt_remove_footnotes: footnote markup otherwise leaks into the cell;
					//   - _x('55','excerpt_length'): locales (e.g. ja_JP) translate the word count;
					//   - the excerpt_length / excerpt_more / wp_trim_excerpt / get_the_excerpt
					//     filters: themes, translation and SEO plugins all hook these, and a table
					//     column that ignored them showed a different excerpt from the rest of the
					//     site. All are core's own hook names ON PURPOSE -- a prefixed name would be
					//     a filter nothing listens to, which is the bug, not the fix.
					$excerpt_source = strip_shortcodes((string) $post->post_content);
					if (function_exists('excerpt_remove_blocks')) {
						$excerpt_source = excerpt_remove_blocks($excerpt_source);
					}
					if (function_exists('excerpt_remove_footnotes')) {
						$excerpt_source = excerpt_remove_footnotes($excerpt_source);
					}
					// Both the hook name and the text domain are core's ON PURPOSE. The filter is the one
					// themes already hook, and _x('55','excerpt_length') is core's own word count, which
					// some locales translate (ja_JP uses characters). Using our own prefix/domain would
					// create a filter and a string nobody uses -- reintroducing the divergence this fixes.
					/** This filter is documented in wp-includes/formatting.php */
					// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.WP.I18n.TextDomainMismatch -- Intentionally core's filter and core's string.
					$excerpt_length = (int) apply_filters('excerpt_length', (int) _x('55', 'excerpt_length', 'default'));
					/** This filter is documented in wp-includes/formatting.php */
					// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Intentionally core's excerpt_more filter.
					$excerpt_more = apply_filters('excerpt_more', ' ' . '[&hellip;]');
					$trimmed = wp_trim_words($excerpt_source, $excerpt_length, $excerpt_more);
					// Only get_the_excerpt is applied here. Core hooks wp_trim_excerpt ONTO
					// get_the_excerpt (default-filters.php), so applying both ran the wp_trim_excerpt
					// filter twice -- verified by diffing against core's own output. Passing
					// already-trimmed text also makes core's wp_trim_excerpt skip regeneration, so
					// the_content is still never invoked.
					/** This filter is documented in wp-includes/post-template.php */
					// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Intentionally core's get_the_excerpt filter.
					return apply_filters('get_the_excerpt', $trimmed, $post);
				}
				return get_the_excerpt($post);
			case 'post_content':
				return wp_trim_words($post->post_content, 40);
			case 'post_date':
				return get_the_date('', $post);
			case 'post_modified':
				return get_the_modified_date('', $post);
			case 'post_author':
				return get_the_author_meta('display_name', $post->post_author);
			case 'post_status':
				return $post->post_status;
			case 'permalink':
				return get_permalink($post);
			default:
				if (!in_array($key, self::allowed_core_keys(), true)) {
					return '';
				}
					return $post->$key ?? '';
		}
	}

	private function get_taxonomy_value(WP_Post $post, string $taxonomy): string {
		$taxonomy = sanitize_key($taxonomy);
		if ($taxonomy === '' || !taxonomy_exists($taxonomy) || !is_object_in_taxonomy($post->post_type, $taxonomy)) {
			return '';
		}
		$terms = get_the_terms($post, $taxonomy);
		if (is_wp_error($terms) || empty($terms)) {
			return '';
		}
		return implode(', ', wp_list_pluck($terms, 'name'));
	}

	public function get_meta_value(int $post_id, string $key) {
		if (function_exists('get_field')) {
			$acf_value = get_field($key, $post_id);
			if ($acf_value !== null) {
				return $acf_value;
			}
		}

		return get_post_meta($post_id, $key, true);
	}

}
