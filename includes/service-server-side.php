<?php

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Bounded, opt-in server paging for external database tables.
 *
 * This deliberately does not attempt to translate BaraTables' PHP-only row policies or
 * display transforms into SQL. Definitions using those features stay on the established
 * materialized path instead of receiving subtly different results.
 */
trait BaraTables_Server_Side_Trait {
	/** Return the effective mode and a human-readable reason when a requested mode is unsafe. */
	public function get_server_side_state(array $definition, bool $verify_source = true): array {
		$cache_json = $verify_source ? wp_json_encode($definition) : false;
		$cache_key = is_string($cache_json) ? md5($cache_json) : '';
		if ($cache_key !== '' && isset($this->server_side_state_cache[$cache_key])) {
			return $this->server_side_state_cache[$cache_key];
		}
		$options = $this->get_table_options($definition);
		$requested = !empty($options['serverSide']);
		$state = [
			'requested' => $requested,
			'enabled' => false,
			'reason' => '',
			'key' => '',
		];
		if (!$requested) {
			return $state;
		}

		$reason = $this->server_side_definition_reason($definition, $options);
		if ($reason !== '') {
			$state['reason'] = $reason;
			return $state;
		}
		if (!$verify_source) {
			$state['enabled'] = true;
			return $state;
		}

		$config = isset($definition['external_db']) && is_array($definition['external_db']) ? $definition['external_db'] : [];
		$source = $this->connect_external_source($config);
		if (is_wp_error($source)) {
			$state['reason'] = __('The external database could not be reached.', 'baratables');
			return $state;
		}
		if (!$this->configure_server_side_timeouts($source['db'])) {
			$state['reason'] = __('This database cannot enforce the required query timeout.', 'baratables');
			return $state;
		}
		$key = $this->find_external_stable_key($source['db'], $source['table']);
		if ($key === '') {
			$state['reason'] = __('The source needs a non-null, single-column primary or unique key.', 'baratables');
			return $state;
		}
		$state['enabled'] = true;
		$state['key'] = $key;
		if ($cache_key !== '') {
			$this->server_side_state_cache[$cache_key] = $state;
		}
		return $state;
	}

	private function server_side_definition_reason(array $definition, array $options): string {
		if (!BaraTables_Source_Type::is_external_db($definition['source_type'] ?? '')) {
			return __('Server paging is available only for External Database tables.', 'baratables');
		}
		$columns = isset($definition['columns']) && is_array($definition['columns']) ? $definition['columns'] : [];
		if (empty($columns)) {
			return __('Select the external database columns to display first.', 'baratables');
		}
		if (count($columns) > self::MAX_CUSTOM_COLUMNS) {
			return __('Server paging supports at most 100 displayed columns.', 'baratables');
		}
		$slugs = [];
		$keys = [];
		$searchable_count = 0;
		foreach ($columns as $column) {
			if (!is_array($column) || ($column['source'] ?? '') !== 'external') {
				return __('Every displayed column must come directly from the external database.', 'baratables');
			}
			$key = $this->sanitize_external_identifier((string) ($column['key'] ?? ''));
			$slug = $this->resolve_column_slug($column);
			if ($key === '' || $slug === '' || isset($slugs[$slug]) || isset($keys[$key])) {
				return __('Displayed external columns must have unique, valid names.', 'baratables');
			}
			$slugs[$slug] = true;
			$keys[$key] = true;
			if (!isset($column['searchable']) || $column['searchable'] !== false) {
				$searchable_count++;
			}
			if (($column['filter'] ?? 'none') !== 'none') {
				return __('Column filter controls are not available with server paging.', 'baratables');
			}
			if (!empty($column['format_date'])) {
				return __('Formatted date columns are not available with server paging.', 'baratables');
			}
			if (BaraTables_Cell_Semantics::normalize_aggregate($column['footer_aggregate'] ?? 'none', $column['data_type'] ?? 'auto') !== 'none') {
				return __('Footer calculations are not available with server paging.', 'baratables');
			}
			// Number columns use BaraTables' strict display-string parser for sorting. Ordering the
			// raw database field would diverge for textual sources (for example "10" before "2"),
			// so retain the exact standard-mode semantics instead of approximating them in SQL.
			if (BaraTables_Cell_Semantics::normalize_type($column['data_type'] ?? 'auto') === 'number') {
				return __('Number column semantics are not available with server paging.', 'baratables');
			}
		}
		if ($searchable_count > self::SERVER_SIDE_MAX_SEARCH_COLUMNS) {
			return __('Server paging supports at most 20 searchable columns.', 'baratables');
		}
		if (count($this->get_default_sort_order($definition)) > self::SERVER_SIDE_MAX_SORTS) {
			return __('Server paging supports at most three default sort columns.', 'baratables');
		}
		if (empty($options['paging'])) {
			return __('Pagination must be enabled for server paging.', 'baratables');
		}
		if ((int) ($options['pageLength'] ?? 25) > self::SERVER_SIDE_MAX_PAGE_LENGTH) {
			return __('Rows per page must be 100 or fewer for server paging.', 'baratables');
		}
		if (!empty($definition['access_control'])) {
			return __('Row access rules are not available with server paging.', 'baratables');
		}
		if (!empty($definition['value_overrides'])) {
			return __('Value overrides are not available with server paging.', 'baratables');
		}
		$exports = array_intersect((array) ($options['buttons'] ?? []), ['copy', 'csv', 'excel', 'pdf', 'print']);
		if (!empty($exports)) {
			return __('Data export and print buttons are not available with server paging.', 'baratables');
		}
		return '';
	}

	/** Stable public table token. It rejects malformed requests; published data remains public. */
	public function get_server_side_token(array $definition): string {
		return hash_hmac('sha256', 'server-table|' . (string) ($definition['id'] ?? ''), wp_salt('auth'));
	}

	/** Execute one bounded draw against an already-published, revalidated definition. */
	public function get_server_side_page(array $definition, array $request) {
		return $this->get_server_side_page_internal($definition, $request, false);
	}

	/** Execute an SSR first page whose shared visitor budget was reserved before source I/O. */
	public function get_server_side_page_reserved(array $definition, array $request) {
		return $this->get_server_side_page_internal($definition, $request, true);
	}

	private function get_server_side_page_internal(array $definition, array $request, bool $rate_reserved) {
		// Reject malformed public input before source verification or any data statement. The
		// parser uses only the saved definition's allowlists; it does not need a live connection.
		$parsed = $this->sanitize_server_side_request($definition, $request);
		if (is_wp_error($parsed)) {
			return $parsed;
		}
		if (!$rate_reserved && !$this->consume_server_side_rate_limit((string) ($definition['id'] ?? ''))) {
			return new WP_Error('server_side_rate_limited');
		}
		$state = $this->get_server_side_state($definition, true);
		if (empty($state['enabled']) || empty($state['key'])) {
			return new WP_Error('server_side_ineligible');
		}
		$config = isset($definition['external_db']) && is_array($definition['external_db']) ? $definition['external_db'] : [];
		$source = $this->connect_external_source($config);
		if (is_wp_error($source) || !$this->configure_server_side_timeouts($source['db'])) {
			return new WP_Error('server_side_unavailable');
		}

		$db = $source['db'];
		$table = $source['table'];
		$columns = array_values($definition['columns']);
		$column_map = [];
		foreach ($columns as $column) {
			$column_map[$this->resolve_column_slug($column)] = $this->sanitize_external_identifier((string) $column['key']);
		}

		$total_sql = $db->prepare('SELECT COUNT(*) FROM %i', $table);
		$total = $this->server_side_get_count($db, $total_sql);
		if ($total === null) {
			return new WP_Error('server_side_read_failed');
		}

		$where = '';
		$where_values = [];
		if ($parsed['term'] !== '') {
			$search_columns = [];
			foreach ($parsed['search_slugs'] as $slug) {
				if (isset($column_map[$slug])) {
					$search_columns[] = $column_map[$slug];
				}
			}
			if (empty($search_columns)) {
				return new WP_Error('server_side_bad_request');
			}
			$like = '%' . $db->esc_like($parsed['term']) . '%';
			$parts = [];
			foreach ($search_columns as $column_name) {
				$parts[] = '%i LIKE %s';
				$where_values[] = $column_name;
				$where_values[] = $like;
			}
			$where = ' WHERE (' . implode(' OR ', $parts) . ')';
		}

		$filtered = $total;
		if ($where !== '') {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Dynamic text contains literal placeholders only; every value is bound below.
			$count_sql = $db->prepare('SELECT COUNT(*) FROM %i' . $where, ...array_merge([$table], $where_values));
			$filtered = $this->server_side_get_count($db, $count_sql);
			if ($filtered === null) {
				return new WP_Error('server_side_read_failed');
			}
		}

		$order = [];
		foreach ($parsed['sorts'] as $sort) {
			$order[$column_map[$sort['slug']]] = $sort['direction'];
		}
		if (!isset($order[$state['key']])) {
			$order[$state['key']] = 'ASC';
		}
		$order_parts = [];
		$order_values = [];
		foreach ($order as $column_name => $direction) {
			// Qualify the source column: selected byte-capped expressions intentionally reuse the
			// source name as their alias, and an unqualified ORDER BY would sort that BINARY alias
			// lexically (for example 102 before 3) instead of using the database column's type.
			$order_parts[] = '%i.%i ' . $direction;
			$order_values[] = $table;
			$order_values[] = $column_name;
		}

		// Bound bytes on the database side, before mysqli buffers the result. Fetch one byte over
		// the effective per-cell budget so PHP can distinguish an exact fit from truncation.
		$cell_count = max(1, $parsed['length'] * count($column_map));
		$raw_cell_budget = min(self::SERVER_SIDE_MAX_CELL_BYTES, max(1, intdiv(self::SERVER_SIDE_MAX_RESPONSE_BYTES, $cell_count)));
		$select_parts = [];
		$select_values = [];
		foreach ($column_map as $column_name) {
			$select_parts[] = 'LEFT(CAST(%i AS BINARY), %d) AS %i';
			$select_values[] = $column_name;
			$select_values[] = $raw_cell_budget + 1;
			$select_values[] = $column_name;
		}
		$sql_values = array_merge($select_values, [$table], $where_values, $order_values, [$parsed['length'], $parsed['start']]);
		$sql_format = 'SELECT ' . implode(', ', $select_parts) . ' FROM %i' . $where
			. ' ORDER BY ' . implode(', ', $order_parts) . ' LIMIT %d OFFSET %d';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Dynamic text contains literal placeholders and allowlisted ASC/DESC only.
		$page_sql = $db->prepare($sql_format, ...$sql_values);
		$raw_rows = $this->server_side_get_results($db, $page_sql, true);
		if (is_wp_error($raw_rows)) {
			return $raw_rows;
		}
		$ordered = $this->reorder_external_rows_by_slug_map($raw_rows, $columns, $column_map);
		$models = (new BaraTables_Table_Presentation($this))->build($definition, [])['columns'];
		$presented = $this->present_server_side_rows($ordered, $models, $raw_cell_budget);
		if (is_wp_error($presented)) {
			return $presented;
		}
		$encoded_data = wp_json_encode($presented);
		if (!is_string($encoded_data) || strlen($encoded_data) > self::SERVER_SIDE_MAX_RESPONSE_BYTES - 4096) {
			return new WP_Error('server_side_response_too_large');
		}

		return [
			'draw' => $parsed['draw'],
			'recordsTotal' => min($total, self::SERVER_SIDE_MAX_WINDOW),
			'recordsFiltered' => min($filtered, self::SERVER_SIDE_MAX_WINDOW),
			'data' => $presented,
			'_raw' => $ordered,
		];
	}

	/** Reserve the shared public-read budget before an SSR path verifies or connects its source. */
	public function reserve_server_side_initial_page(array $definition): bool {
		return $this->consume_server_side_rate_limit((string) ($definition['id'] ?? ''));
	}

	/**
	 * Atomic, fixed-cardinality visitor buckets shared by SSR and AJAX reads.
	 *
	 * A global table counter lets one visitor deny service to everyone, while an option per IP lets
	 * rotating clients grow wp_options without bound. A keyed 256-way shard keeps the persistent
	 * keyspace bounded per published table and isolates ordinary visitors. The conditional UPDATE
	 * is atomic in MySQL/MariaDB; LAST_INSERT_ID returns this connection's new count without a
	 * racy read-after-write.
	 */
	private function consume_server_side_rate_limit(string $table_id): bool {
		global $wpdb;
		$address = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash((string) $_SERVER['REMOTE_ADDR'])) : 'unknown';
		$shard = substr(hash_hmac('sha256', $address, wp_salt('nonce')), 0, 2);
		$rate_key = 'btbl_ss_rate_' . md5($table_id) . '_' . $shard;
		$option_name = '_transient_' . $rate_key;
		$timeout_name = '_transient_timeout_' . $rate_key;
		$window = (string) floor(time() / 60);
		if (add_option($option_name, $window . ':1', '', false)) {
			add_option($timeout_name, time() + 70, '', false);
			return true;
		}
		update_option($timeout_name, time() + 70, false);

		$sql = $wpdb->prepare(
			"UPDATE %i SET option_value = CONCAT(%s, ':', LAST_INSERT_ID(IF(SUBSTRING_INDEX(option_value, ':', 1) = %s, LEAST(CAST(SUBSTRING_INDEX(option_value, ':', -1) AS UNSIGNED) + 1, %d), 1))) WHERE option_name = %s",
			$wpdb->options,
			$window,
			$window,
			self::SERVER_SIDE_MAX_DRAWS_PER_MINUTE + 1,
			$option_name
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery -- Prepared atomic counter; Options API cannot conditionally increment.
		$updated = $wpdb->query($sql);
		if ($updated !== 1) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Connection-local value from the atomic UPDATE above.
		$count = (int) $wpdb->get_var('SELECT LAST_INSERT_ID()');
		wp_cache_delete($option_name, 'options');
		wp_cache_delete($timeout_name, 'options');
		return $count >= 1 && $count <= self::SERVER_SIDE_MAX_DRAWS_PER_MINUTE;
	}

	/** Remove every visitor shard for a deleted or renamed table identity. */
	public function delete_server_side_rate_limits(string $table_id): void {
		global $wpdb;
		$hash = md5($table_id);
		$value_like = $wpdb->esc_like('_transient_btbl_ss_rate_' . $hash . '_') . '%';
		$timeout_like = $wpdb->esc_like('_transient_timeout_btbl_ss_rate_' . $hash . '_') . '%';
		$sql = $wpdb->prepare('DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s', $wpdb->options, $value_like, $timeout_like);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery -- Prepared bounded cleanup of plugin-owned transient shards.
		$wpdb->query($sql);
		for ($index = 0; $index < 256; $index++) {
			$shard = str_pad(dechex($index), 2, '0', STR_PAD_LEFT);
			$rate_key = 'btbl_ss_rate_' . $hash . '_' . $shard;
			wp_cache_delete('_transient_' . $rate_key, 'options');
			wp_cache_delete('_transient_timeout_' . $rate_key, 'options');
		}
	}

	/** Sanitize and decorate a bounded page while enforcing both cell and response budgets. */
	private function present_server_side_rows(array $ordered, array $models, int $raw_cell_budget = self::SERVER_SIDE_MAX_CELL_BYTES) {
		$rows = [];
		$response_bytes = 0;
		foreach ($ordered as $row) {
			$out = [];
			foreach ($models as $index => $model) {
				$cell = (string) ($row[$index] ?? '');
				if (strlen($cell) > $raw_cell_budget) {
					return new WP_Error('server_side_response_too_large');
				}
				if ($cell !== '' && preg_match('//u', $cell) !== 1) {
					return new WP_Error('server_side_invalid_encoding');
				}
				$clean = (strpbrk($cell, '<&') === false) ? $cell : wp_kses_post($cell);
				$semantic = BaraTables_Cell_Semantics::present_cell($clean, $model);
				if (strlen($semantic['html']) > self::SERVER_SIDE_MAX_CELL_BYTES) {
					return new WP_Error('server_side_response_too_large');
				}
				$out[] = $semantic['html'];
				$response_bytes += strlen($semantic['html']);
				if ($response_bytes > self::SERVER_SIDE_MAX_RESPONSE_BYTES) {
					return new WP_Error('server_side_response_too_large');
				}
			}
			$rows[] = $out;
		}

		return $rows;
	}

	private function sanitize_server_side_request(array $definition, array $request) {
		foreach (['draw', 'start', 'length'] as $integer_field) {
			if (!isset($request[$integer_field])) {
				continue;
			}
			$integer_value = $request[$integer_field];
			if (!is_int($integer_value) && (!is_string($integer_value) || preg_match('/^-?\d+$/D', $integer_value) !== 1)) {
				return new WP_Error('server_side_bad_request');
			}
		}
		$draw = isset($request['draw']) ? (int) $request['draw'] : 0;
		$start = isset($request['start']) ? (int) $request['start'] : 0;
		$length = isset($request['length']) ? (int) $request['length'] : 25;
		$raw_term = $request['btbl_search_term'] ?? '';
		if (!is_scalar($raw_term)) {
			return new WP_Error('server_side_bad_request');
		}
		$raw_term = (string) $raw_term;
		if (strlen($raw_term) > self::SERVER_SIDE_MAX_SEARCH_BYTES || ($raw_term !== '' && preg_match('//u', $raw_term) !== 1)) {
			return new WP_Error('server_side_bad_request');
		}
		// The value is a prepared LIKE operand, never markup or an identifier. Preserve it byte for
		// byte so "literal search" does not silently rewrite strings such as <tag> or %2F.
		$term = $raw_term;
		if ($draw < 0 || $start < 0 || $length < 1 || $length > self::SERVER_SIDE_MAX_PAGE_LENGTH || $start + $length > self::SERVER_SIDE_MAX_WINDOW) {
			return new WP_Error('server_side_bad_request');
		}
		if (isset($request['search']) && !is_array($request['search'])) {
			return new WP_Error('server_side_bad_request');
		}
		$search_options = isset($request['search']) ? $request['search'] : [];
		if (!empty($search_options['regex']) && $search_options['regex'] !== 'false') {
			return new WP_Error('server_side_bad_request');
		}
		if (strlen($term) > self::SERVER_SIDE_MAX_SEARCH_BYTES) {
			return new WP_Error('server_side_bad_request');
		}

		$known = [];
		$searchable = [];
		$sortable = [];
		foreach (array_values($definition['columns']) as $column) {
			$slug = $this->resolve_column_slug($column);
			$known[$slug] = true;
			if (!isset($column['searchable']) || $column['searchable'] !== false) {
				$searchable[$slug] = true;
			}
			if (!isset($column['sortable']) || $column['sortable'] !== false) {
				$sortable[$slug] = true;
			}
		}
		$search_slugs = $this->decode_server_side_list($request['btbl_search_columns'] ?? []);
		if (is_wp_error($search_slugs)) {
			return $search_slugs;
		}
		foreach ($search_slugs as $slug) {
			if (!isset($searchable[$slug])) {
				return new WP_Error('server_side_bad_request');
			}
		}
		if (empty($search_slugs)) {
			$search_slugs = array_keys($searchable);
		}
		if (count($search_slugs) > self::SERVER_SIDE_MAX_SEARCH_COLUMNS) {
			return new WP_Error('server_side_bad_request');
		}
		$search_slugs = array_values(array_unique(array_filter($search_slugs, static function ($slug) use ($searchable) {
			return isset($searchable[$slug]);
		})));

		$sorts = [];
		if (isset($request['order']) && !is_array($request['order'])) {
			return new WP_Error('server_side_bad_request');
		}
		$order = isset($request['order']) ? $request['order'] : [];
		if (count($order) > self::SERVER_SIDE_MAX_SORTS) {
			return new WP_Error('server_side_bad_request');
		}
		if (isset($request['columns']) && !is_array($request['columns'])) {
			return new WP_Error('server_side_bad_request');
		}
		$request_columns = isset($request['columns']) ? $request['columns'] : [];
		if (count($request_columns) > self::MAX_CUSTOM_COLUMNS) {
			return new WP_Error('server_side_bad_request');
		}
		foreach (array_slice($order, 0, self::SERVER_SIDE_MAX_SORTS) as $rule) {
			if (!is_array($rule)) {
				return new WP_Error('server_side_bad_request');
			}
			if (isset($rule['name']) && !is_scalar($rule['name'])) {
				return new WP_Error('server_side_bad_request');
			}
			if (isset($rule['dir']) && !is_scalar($rule['dir'])) {
				return new WP_Error('server_side_bad_request');
			}
			$slug = $this->sanitize_server_side_slug($rule['name'] ?? '');
			if ($slug === '' && isset($rule['column'])) {
				$raw_index = $rule['column'];
				if (!is_int($raw_index) && (!is_string($raw_index) || preg_match('/^\d+$/D', $raw_index) !== 1)) {
					return new WP_Error('server_side_bad_request');
				}
				$index = (int) $raw_index;
				$column_rule = $request_columns[$index] ?? null;
				if (!is_array($column_rule) || !isset($column_rule['name']) || !is_scalar($column_rule['name'])) {
					return new WP_Error('server_side_bad_request');
				}
				$slug = $this->sanitize_server_side_slug($column_rule['name']);
			}
			$direction = strtolower((string) ($rule['dir'] ?? ''));
			if (!isset($sortable[$slug]) || !in_array($direction, ['asc', 'desc'], true)) {
				return new WP_Error('server_side_bad_request');
			}
			$sorts[] = ['slug' => $slug, 'direction' => strtoupper($direction)];
		}
		if (empty($sorts)) {
			foreach (array_slice($this->get_default_sort_order($definition), 0, self::SERVER_SIDE_MAX_SORTS) as $rule) {
				$slug = $this->sanitize_server_side_slug($rule['slug'] ?? '');
				if (isset($sortable[$slug])) {
					$sorts[] = ['slug' => $slug, 'direction' => ($rule['direction'] ?? '') === 'desc' ? 'DESC' : 'ASC'];
				}
			}
		}
		return compact('draw', 'start', 'length', 'term', 'search_slugs', 'sorts');
	}

	private function decode_server_side_list($raw) {
		if (is_string($raw)) {
			if (strlen($raw) > 4096) {
				return new WP_Error('server_side_bad_request');
			}
			$decoded = json_decode($raw, true);
			if (!is_array($decoded)) {
				return new WP_Error('server_side_bad_request');
			}
			$raw = $decoded;
		}
		if (!is_array($raw)) {
			return new WP_Error('server_side_bad_request');
		}
		if (count($raw) > self::SERVER_SIDE_MAX_SEARCH_COLUMNS) {
			return new WP_Error('server_side_bad_request');
		}
		$out = [];
		foreach ($raw as $value) {
			$slug = $this->sanitize_server_side_slug($value);
			if ($slug === '') {
				return new WP_Error('server_side_bad_request');
			}
			$out[] = $slug;
		}
		return array_values($out);
	}

	private function sanitize_server_side_slug($value): string {
		if (!is_scalar($value)) {
			return '';
		}
		$slug = trim((string) $value);
		return strlen($slug) <= 191 && preg_match('/^[A-Za-z0-9:_-]+$/', $slug) ? $slug : '';
	}

	private function configure_server_side_timeouts($db): bool {
		$key = is_object($db) ? spl_object_hash($db) : '';
		if ($key !== '' && array_key_exists($key, $this->server_side_timeout_connections)) {
			return $this->server_side_timeout_connections[$key] !== '';
		}
		if (!method_exists($db, 'btbl_transport_timeouts_enabled') || !$db->btbl_transport_timeouts_enabled()) {
			return false;
		}
		$version = method_exists($db, 'db_server_info') ? (string) $db->db_server_info() : '';
		$dialect = stripos($version, 'mariadb') !== false ? 'mariadb' : 'mysql';
		$suppressed = $db->suppress_errors(true);
		$db->last_error = '';
		if ($dialect === 'mariadb') {
			$set = $db->query('SET SESSION max_statement_time = 3');
			$verified = $set !== false ? (float) $db->get_var('SELECT @@SESSION.max_statement_time') : 0.0;
			$enforced = $db->last_error === '' && $verified >= 2.9 && $verified <= 3.1;
		} else {
			$set = $db->query('SET SESSION max_execution_time = 3000');
			$verified = $set !== false ? (int) $db->get_var('SELECT @@SESSION.max_execution_time') : 0;
			$enforced = $db->last_error === '' && $verified === 3000;
		}
		$db->suppress_errors($suppressed);
		if (!$enforced) {
			$dialect = '';
		}
		if ($key !== '') {
			$this->server_side_timeout_connections[$key] = $dialect;
		}
		return $dialect !== '';
	}

	private function find_external_stable_key($db, string $table): string {
		$connection_identity = method_exists($db, 'btbl_connection_identity') ? $db->btbl_connection_identity() : '';
		$schema_cache_key = 'btbl_ss_schema_' . md5($connection_identity . '|' . $table);
		$cached = get_transient($schema_cache_key);
		if (is_array($cached) && array_key_exists('key', $cached)) {
			return $this->sanitize_external_identifier((string) $cached['key']);
		}
		$sql = $db->prepare('SHOW INDEX FROM %i', $table);
		$indexes = $this->server_side_get_results($db, $sql, false);
		if (is_wp_error($indexes)) {
			return '';
		}
		$groups = [];
		foreach ($indexes as $index) {
			$name = (string) ($index['Key_name'] ?? '');
			if ($name === '' || (int) ($index['Non_unique'] ?? 1) !== 0) {
				continue;
			}
			$groups[$name][] = $index;
		}
		uksort($groups, static function ($a, $b) {
			if ($a === 'PRIMARY') { return -1; }
			if ($b === 'PRIMARY') { return 1; }
			return strcmp($a, $b);
		});
		foreach ($groups as $parts) {
			if (count($parts) !== 1) {
				continue;
			}
			$part = $parts[0];
			$column = $this->sanitize_external_identifier((string) ($part['Column_name'] ?? ''));
			$nullable = strtoupper((string) ($part['Null'] ?? ''));
			if ($column !== '' && $nullable !== 'YES' && empty($part['Sub_part'])) {
				set_transient($schema_cache_key, ['key' => $column], 300);
				return $column;
			}
		}
		set_transient($schema_cache_key, ['key' => ''], 60);
		return '';
	}

	private function server_side_get_count($db, $sql): ?int {
		$value = $this->server_side_get_var($db, $sql);
		return is_numeric($value) && (int) $value >= 0 ? (int) $value : null;
	}

	private function server_side_get_var($db, $sql) {
		if (!is_string($sql) || $sql === '') {
			return null;
		}
		$sql = $this->server_side_bounded_sql($db, $sql);
		if ($sql === '') {
			return null;
		}
		$suppressed = $db->suppress_errors(true);
		$db->last_error = '';
		$value = $db->get_var($sql);
		$failed = $db->last_error !== '';
		$db->suppress_errors($suppressed);
		do_action('baratables_server_side_query', 'count', $sql, !$failed);
		return $failed ? null : $value;
	}

	private function server_side_get_results($db, $sql, bool $bounded) {
		if (!is_string($sql) || $sql === '') {
			return new WP_Error('server_side_read_failed');
		}
		if ($bounded) {
			$sql = $this->server_side_bounded_sql($db, $sql);
			if ($sql === '') {
				return new WP_Error('server_side_unavailable');
			}
		}
		$suppressed = $db->suppress_errors(true);
		$db->last_error = '';
		$rows = $db->get_results($sql, ARRAY_A);
		$failed = $db->last_error !== '';
		$db->suppress_errors($suppressed);
		do_action('baratables_server_side_query', $bounded ? 'page' : 'schema', $sql, !$failed);
		return !$failed && is_array($rows) ? $rows : new WP_Error('server_side_read_failed');
	}

	private function server_side_bounded_sql($db, string $sql): string {
		$key = is_object($db) ? spl_object_hash($db) : '';
		$dialect = $key !== '' ? ($this->server_side_timeout_connections[$key] ?? '') : '';
		// configure_server_side_timeouts() has set and read back the dialect's SESSION limit.
		// Returning the statement unchanged avoids relying on an optimizer hint a fork may ignore.
		return in_array($dialect, ['mysql', 'mariadb'], true) ? $sql : '';
	}
}
