<?php

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Query + JSON input sanitization for custom WP_Query args, meta/tax/date sub-queries,
 * value-override and custom-query JSON.
 */
final class BaraTables_Query_Sanitizer {
	public function sanitize_custom_query_json(string $raw_json): array {
		return $this->sanitize_wp_query_args($this->decode_json_array($raw_json));
	}

	public function sanitize_public_post_types(array $post_types_raw, bool $fallback_to_post = true): array {
		$post_types = [];
		foreach ($post_types_raw as $post_type_raw) {
			$post_type = sanitize_key((string) $post_type_raw);
			$post_type_obj = $post_type !== '' ? get_post_type_object($post_type) : null;
			if ($post_type_obj && !empty($post_type_obj->public)) {
				$post_types[] = $post_type;
			}
		}

		$post_types = array_values(array_unique($post_types));
		if (empty($post_types) && $fallback_to_post) {
			$post_types[] = 'post';
		}

		return $post_types;
	}

	/**
	 * The post_status a set of post types needs.
	 *
	 * Attachments are always 'inherit'; everything else the plugin lists is 'publish'. Kept in one
	 * place so the builder path and the custom-query path cannot disagree about it.
	 */
	public static function post_status_for_types(array $post_types) {
		return in_array('attachment', $post_types, true)
			? ['publish', 'inherit']
			: 'publish';
	}

	public function sanitize_wp_query_args(array $args): array {
		if (empty($args)) {
			return [];
		}

		$post_type_requested = array_key_exists('post_type', $args);
		$post_types_raw = $args['post_type'] ?? ['post'];
		$post_types_raw = is_array($post_types_raw) ? $post_types_raw : [$post_types_raw];
		$post_types = $this->sanitize_public_post_types($post_types_raw, false);
		if (empty($post_types) && $post_type_requested) {
			return [];
		}
		$effective_post_types = !empty($post_types) ? $post_types : ['post'];
		$clean = [
			'post_status' => self::post_status_for_types($effective_post_types),
			'no_found_rows' => true,
			'ignore_sticky_posts' => true,
			'post_type' => count($effective_post_types) === 1 ? $effective_post_types[0] : $effective_post_types,
		];
		$has_supported_arg = $post_type_requested && !empty($post_types);

		if (isset($args['posts_per_page'])) {
			$posts_per_page = (int) $args['posts_per_page'];
			if ($posts_per_page !== 0) {
				// Clamp only to the rowLimit ceiling; the effective per-table cap is applied at
				// render time via min(posts_per_page, rowLimit). Previously this clamped to a
				// fixed 500, so an explicit posts_per_page silently overrode (and undercut) the
				// table's configured "Maximum rows" -- e.g. rowLimit 10000 still yielded
				// 500 rows whenever the query JSON set posts_per_page.
				$max_rows = BaraTables_Service::MAX_ROW_LIMIT;
				$clean['posts_per_page'] = $posts_per_page < 0
					? $max_rows
					: min(max($posts_per_page, 1), $max_rows);
				$has_supported_arg = true;
			}
		}

		$argument_groups = [
			'integer' => ['paged', 'page', 'offset', 'p', 'page_id', 'author', 'post_parent', 'year', 'monthnum', 'day', 'w'],
			'text' => ['s', 'name', 'pagename', 'meta_key', 'meta_value'],
			'id_list' => ['post__in', 'post__not_in', 'post_parent__in', 'post_parent__not_in', 'author__in', 'author__not_in', 'category__in', 'category__not_in', 'tag__in', 'tag__not_in'],
		];
		foreach ($argument_groups as $strategy => $keys) {
			foreach ($keys as $key) {
				if (!isset($args[$key])) {
					continue;
				}
				$sanitized = $this->sanitize_query_argument($strategy, $args[$key]);
				if ($sanitized['accepted']) {
					$clean[$key] = $sanitized['value'];
					$has_supported_arg = true;
				}
			}
		}

		if (isset($args['order'])) {
			$clean['order'] = $this->sanitize_query_order($args['order']);
			$has_supported_arg = true;
		}

		if (isset($args['orderby'])) {
			$orderby = $this->sanitize_query_orderby($args['orderby']);
			if (!empty($orderby)) {
				$clean['orderby'] = $orderby;
				$has_supported_arg = true;
			}
		}

		if (isset($args['meta_compare'])) {
			$compare = $this->sanitize_meta_compare($args['meta_compare']);
			if ($compare !== '') {
				$clean['meta_compare'] = $compare;
				$has_supported_arg = true;
			}
		}

		if (isset($args['meta_type'])) {
			$type = $this->sanitize_meta_type($args['meta_type']);
			if ($type !== '') {
				$clean['meta_type'] = $type;
				$has_supported_arg = true;
			}
		}

		if (isset($args['meta_query']) && is_array($args['meta_query'])) {
			$meta_query = $this->sanitize_meta_query($args['meta_query']);
			if (!empty($meta_query)) {
				$clean['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- User-configured table source.
				$has_supported_arg = true;
			}
		}

		if (isset($args['tax_query']) && is_array($args['tax_query'])) {
			$tax_query = $this->sanitize_custom_tax_query($args['tax_query']);
			if (!empty($tax_query)) {
				$clean['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- User-configured table source.
				$has_supported_arg = true;
			}
		}

		if (isset($args['date_query']) && is_array($args['date_query'])) {
			$date_query = $this->sanitize_date_query($args['date_query']);
			if (!empty($date_query)) {
				$clean['date_query'] = $date_query;
				$has_supported_arg = true;
			}
		}

		return $has_supported_arg ? $clean : [];
	}

	/** @return array{accepted:bool,value:mixed} */
	private function sanitize_query_argument(string $strategy, $raw): array {
		if ($strategy === 'integer') {
			return ['accepted' => true, 'value' => absint($raw)];
		}
		if ($strategy === 'text') {
			$value = is_scalar($raw) ? sanitize_text_field((string) $raw) : '';
			return ['accepted' => $value !== '', 'value' => $value];
		}
		$ids = $this->sanitize_int_list($raw);
		return ['accepted' => !empty($ids), 'value' => $ids];
	}

	private function sanitize_int_list($raw): array {
		$items = is_array($raw) ? $raw : explode(',', (string) $raw);
		$ids = [];
		foreach ($items as $item) {
			$id = absint($item);
			if ($id > 0) {
				$ids[] = $id;
			}
		}
		return array_values(array_unique($ids));
	}

	private function sanitize_query_order($order): string {
		return strtoupper((string) $order) === 'DESC' ? 'DESC' : 'ASC';
	}

	private function sanitize_query_orderby($orderby) {
		$allowed = [
			'none' => 'none',
			'id' => 'ID',
			'author' => 'author',
			'title' => 'title',
			'name' => 'name',
			'type' => 'type',
			'date' => 'date',
			'modified' => 'modified',
			'parent' => 'parent',
			'rand' => 'rand',
			'comment_count' => 'comment_count',
			'menu_order' => 'menu_order',
			'meta_value' => 'meta_value', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- User-configured custom query ordering; row count is capped.
			'meta_value_num' => 'meta_value_num',
			'post__in' => 'post__in',
			'relevance' => 'relevance',
		];

		if (is_array($orderby)) {
			$out = [];
			foreach ($orderby as $key => $direction) {
				if (is_int($key)) {
					if (!is_scalar($direction)) {
						continue;
					}
					$raw_key = $direction;
					$order = 'ASC';
				} else {
					$raw_key = $key;
					$order = is_scalar($direction) ? $this->sanitize_query_order($direction) : 'ASC';
				}
				$clean_key = sanitize_key((string) $raw_key);
				if (!isset($allowed[$clean_key])) {
					continue;
				}
				$out[$allowed[$clean_key]] = $order;
			}
			return $out;
		}

		$clean = sanitize_key((string) $orderby);
		return $allowed[$clean] ?? null;
	}

	private function sanitize_meta_query(array $query, int $depth = 0): array {
		return $this->sanitize_nested_query(
			$query,
			[$this, 'sanitize_meta_clause'],
			static function (array $clause): bool {
				return array_key_exists('key', $clause);
			},
			$depth
		);
	}

	private function sanitize_meta_clause(array $clause): array {
		$key = isset($clause['key']) ? sanitize_text_field((string) $clause['key']) : '';
		if ($key === '') {
			return [];
		}

		$out = ['key' => $key];
		if (array_key_exists('value', $clause)) {
			$out['value'] = $this->sanitize_query_value($clause['value']);
		}
		$this->add_sanitized_compare($out, $clause);
		if (isset($clause['type'])) {
			$type = $this->sanitize_meta_type($clause['type']);
			if ($type !== '') {
				$out['type'] = $type;
			}
		}
		return $out;
	}

	private function sanitize_meta_compare($compare): string {
		$compare = strtoupper(trim((string) $compare));
		$allowed = ['=', '!=', '>', '>=', '<', '<=', 'LIKE', 'NOT LIKE', 'IN', 'NOT IN', 'BETWEEN', 'NOT BETWEEN', 'EXISTS', 'NOT EXISTS', 'REGEXP', 'NOT REGEXP', 'RLIKE'];
		return in_array($compare, $allowed, true) ? $compare : '';
	}

	private function add_sanitized_compare(array &$output, array $clause): void {
		if (!isset($clause['compare'])) {
			return;
		}
		$compare = $this->sanitize_meta_compare($clause['compare']);
		if ($compare !== '') {
			$output['compare'] = $compare;
		}
	}

	private function sanitize_meta_type($type): string {
		$type = strtoupper(sanitize_key((string) $type));
		$allowed = ['NUMERIC', 'BINARY', 'CHAR', 'DATE', 'DATETIME', 'DECIMAL', 'SIGNED', 'TIME', 'UNSIGNED'];
		return in_array($type, $allowed, true) ? $type : '';
	}

	private function sanitize_query_value($value) {
		if (is_array($value)) {
			$values = [];
			foreach ($value as $item) {
				if (is_scalar($item)) {
					$values[] = sanitize_text_field((string) $item);
				}
			}
			return $values;
		}
		return is_scalar($value) ? sanitize_text_field((string) $value) : '';
	}

	private function sanitize_custom_tax_query(array $query, int $depth = 0): array {
		return $this->sanitize_nested_query(
			$query,
			[$this, 'sanitize_tax_clause'],
			static function (array $clause): bool {
				return array_key_exists('taxonomy', $clause);
			},
			$depth
		);
	}

	private function sanitize_tax_clause(array $clause): array {
		$taxonomy = isset($clause['taxonomy']) ? sanitize_key((string) $clause['taxonomy']) : '';
		if ($taxonomy === '' || !taxonomy_exists($taxonomy)) {
			return [];
		}
		$field = isset($clause['field']) && in_array($clause['field'], ['term_id', 'slug', 'name', 'term_taxonomy_id'], true)
			? $clause['field']
			: 'term_id';
		$terms_raw = $clause['terms'] ?? [];
		$terms_raw = is_array($terms_raw) ? $terms_raw : [$terms_raw];
		$terms = [];
		foreach ($terms_raw as $term) {
			$terms[] = $field === 'term_id' || $field === 'term_taxonomy_id' ? absint($term) : sanitize_text_field((string) $term);
		}
		$terms = array_values(array_filter(array_unique($terms), static function ($term) {
			return $term !== '' && $term !== 0;
		}));
		if (empty($terms)) {
			return [];
		}
		$operator = isset($clause['operator']) ? strtoupper((string) $clause['operator']) : 'IN';
		$operator = in_array($operator, ['IN', 'NOT IN', 'AND', 'EXISTS', 'NOT EXISTS'], true) ? $operator : 'IN';
		return [
			'taxonomy' => $taxonomy,
			'field' => $field,
			'terms' => $terms,
			'operator' => $operator,
			'include_children' => !empty($clause['include_children']),
		];
	}

	private function sanitize_date_query(array $query, int $depth = 0): array {
		return $this->sanitize_nested_query($query, [$this, 'sanitize_date_clause'], null, $depth);
	}

	/**
	 * Sanitize the relation/group structure shared by WP_Meta_Query, WP_Tax_Query, and
	 * WP_Date_Query while leaving each query type's leaf semantics in its own sanitizer.
	 *
	 * A null marker means "try the leaf sanitizer first, recurse if it produced nothing"; date
	 * clauses need that behavior because they have no single required identifying key.
	 */
	private function sanitize_nested_query(array $query, callable $sanitize_leaf, ?callable $is_leaf, int $depth): array {
		if ($depth > 2) {
			return [];
		}
		$out = [];
		if (isset($query['relation'])) {
			$out['relation'] = strtoupper((string) $query['relation']) === 'OR' ? 'OR' : 'AND';
		}
		foreach ($query as $key => $clause) {
			if ($key === 'relation' || !is_array($clause)) {
				continue;
			}
			$leaf = $is_leaf === null || $is_leaf($clause);
			$clean_clause = $leaf ? $sanitize_leaf($clause) : [];
			if (!$leaf || ($is_leaf === null && empty($clean_clause))) {
				$clean_clause = $this->sanitize_nested_query($clause, $sanitize_leaf, $is_leaf, $depth + 1);
			}
			if (!empty($clean_clause)) {
				$out[] = $clean_clause;
			}
		}
		return count($out) > (isset($out['relation']) ? 1 : 0) ? $out : [];
	}

	private function sanitize_date_clause(array $clause): array {
		$out = [];
		foreach (['year', 'month', 'monthnum', 'week', 'w', 'day', 'hour', 'minute', 'second'] as $int_key) {
			if (isset($clause[$int_key])) {
				$out[$int_key] = absint($clause[$int_key]);
			}
		}
		foreach (['before', 'after'] as $date_key) {
			if (isset($clause[$date_key]) && is_scalar($clause[$date_key])) {
				$out[$date_key] = sanitize_text_field((string) $clause[$date_key]);
			}
		}
		if (isset($clause['inclusive'])) {
			$out['inclusive'] = !empty($clause['inclusive']);
		}
		$this->add_sanitized_compare($out, $clause);
		return $out;
	}

	public function sanitize_value_overrides(string $raw_json): array {
		$decoded = $this->decode_json_array($raw_json);
		if (empty($decoded)) {
			return [];
		}

		$clean = [];
		foreach ($decoded as $rule) {
			if (!is_array($rule)) {
				continue;
			}
			// value_overrides is a raw-JSON textarea; a malformed rule can carry an array where a
			// string is expected. Guard with is_scalar (as the rest of this file does) so an array
			// search/replace skips the rule cleanly instead of emitting an "Array to string" warning.
			$column = (isset($rule['column']) && is_scalar($rule['column'])) ? sanitize_text_field((string) $rule['column']) : '';
			$search = (isset($rule['search']) && is_scalar($rule['search'])) ? (string) $rule['search'] : '';
			$replace = (isset($rule['replace']) && is_scalar($rule['replace'])) ? (string) $rule['replace'] : '';
			$regex = !empty($rule['regex']);
			if ($column === '' || $search === '') {
				continue;
			}
			$clean[] = [
				'column'  => $column,
				'search'  => $search,
				'replace' => $replace,
				'regex'   => $regex,
			];
		}
		return $clean;
	}

	private function decode_json_array(string $raw_json): array {
		$raw_json = trim($raw_json);
		if ($raw_json === '') {
			return [];
		}
		$decoded = json_decode($raw_json, true);
		if (!is_array($decoded)) {
			$decoded = json_decode(stripslashes($raw_json), true);
		}
		return is_array($decoded) ? $decoded : [];
	}
}
