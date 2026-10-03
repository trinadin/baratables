<?php

if (!defined('ABSPATH')) {
	exit;
}

/** Admin-only transport and early-bounded parsers for stored remote snapshots. */
final class BaraTables_Remote_Snapshot {
	public const FORMAT_CSV = 'csv';
	public const FORMAT_JSON = 'json';
	public const FORMAT_GOOGLE_SHEETS = 'google_sheets';
	public const MAX_BYTES = 5242880;
	private const MAX_CELL_BYTES = 65536;
	private const MAX_JSON_RECORD_BYTES = 262144;
	private const FORMATS = [self::FORMAT_CSV, self::FORMAT_JSON, self::FORMAT_GOOGLE_SHEETS];

	public static function sanitize_format($raw): string {
		$format = sanitize_key((string) $raw);
		return in_array($format, self::FORMATS, true) ? $format : self::FORMAT_CSV;
	}

	/** Normalize syntax only. DNS/redirect safety checks belong exclusively to explicit Fetch. */
	public static function sanitize_url($raw): string {
		if (!is_scalar($raw)) {
			return '';
		}
		$url = esc_url_raw(trim((string) $raw), ['https']);
		$parts = $url !== '' ? wp_parse_url($url) : false;
		$host = is_array($parts) ? strtolower(trim((string) ($parts['host'] ?? ''), '[]')) : '';
		$public_ip = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
		if (
			!is_array($parts)
			|| strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
			|| empty($parts['host'])
			|| isset($parts['user'])
			|| isset($parts['pass'])
			|| (isset($parts['port']) && (int) $parts['port'] !== 443)
			|| $host === 'localhost'
			|| substr($host, -10) === '.localhost'
			|| (preg_match('/^[0-9.]+$/', $host) === 1 && filter_var($host, FILTER_VALIDATE_IP) === false)
			// Hex/decimal-integer shorthands ("0x7f000001") are IP literals in disguise: the
			// notation dodges FILTER_VALIDATE_IP, so reject any digit-bearing host made only of
			// hex digits and dots that is not a valid public IP. wp_http_validate_url re-checks
			// per hop; this keeps stage 1 honest on its own.
			|| (preg_match('/^[0-9a-fA-FxX.]+$/', $host) === 1 && preg_match('/\\d/', $host) === 1 && $public_ip === false)
			|| (filter_var($host, FILTER_VALIDATE_IP) !== false && $public_ip === false)
		) {
			return '';
		}
		return $url;
	}

	/** Accept only ordinary public share/edit URLs; published/embed variants are not v1 inputs. */
	public static function google_sheets_export_url(string $configured_url): string {
		$url = self::sanitize_url($configured_url);
		$parts = $url !== '' ? wp_parse_url($url) : false;
		if (
			!is_array($parts)
			|| strtolower((string) ($parts['host'] ?? '')) !== 'docs.google.com'
			|| preg_match('#^/spreadsheets/d/([A-Za-z0-9_-]{10,})(?:/|$)#', (string) ($parts['path'] ?? ''), $match) !== 1
		) {
			return '';
		}
		$query = [];
		parse_str((string) ($parts['query'] ?? ''), $query);
		$fragment = [];
		parse_str((string) ($parts['fragment'] ?? ''), $fragment);
		$gid_raw = $query['gid'] ?? ($fragment['gid'] ?? '0');
		if (!is_scalar($gid_raw) || !preg_match('/^\d+$/', (string) $gid_raw)) {
			return '';
		}
		return 'https://docs.google.com/spreadsheets/d/' . rawurlencode($match[1])
			. '/export?format=csv&gid=' . rawurlencode((string) $gid_raw);
	}

	/** @return array|WP_Error Immutable last-successful snapshot suitable for postmeta. */
	public static function fetch(string $raw_url, string $raw_format, BaraTables_Service $service) {
		$format = self::sanitize_format($raw_format);
		$configured_url = self::sanitize_url($raw_url);
		if ($configured_url === '') {
			return new WP_Error('remote_url_invalid', __('Enter a valid public HTTPS URL without embedded credentials.', 'baratables'));
		}
		$fetch_url_for_hops = $format === self::FORMAT_GOOGLE_SHEETS ? self::google_sheets_export_url($configured_url) : $configured_url;
		if ($fetch_url_for_hops === '') {
			return new WP_Error('remote_google_url_invalid', __('Enter a public Google Sheets URL from docs.google.com with an optional numeric gid.', 'baratables'));
		}
		if (!wp_http_validate_url($fetch_url_for_hops)) {
			return self::kept_error('remote_url_unsafe', __('The remote URL did not resolve to a safe public address.', 'baratables'));
		}
		// Follow redirects manually so every hop is re-held to the same contract as the first
		// request: HTTPS, port 443, no credentials, public address. Core's redirect follower
		// validates hops for safety but still accepts an http:// (and port 80/8080) downgrade
		// after the first request, which would move the snapshot content to a plaintext hop.
		$request_args = static function () use ($format): array {
			return [
				'timeout' => 10,
				'redirection' => 0,
				'reject_unsafe_urls' => true,
				'limit_response_size' => self::MAX_BYTES + 1,
				'headers' => [
					'Accept' => $format === self::FORMAT_JSON ? 'application/json' : 'text/csv,text/plain;q=0.9',
					'Accept-Encoding' => 'identity',
				],
				'user-agent' => 'BaraTables WordPress plugin',
			];
		};
		$response = null;
		$status = 0;
		for ($hop = 0; $hop <= 3; $hop++) {
			$response = wp_safe_remote_get($fetch_url_for_hops, $request_args());
			if (is_wp_error($response)) {
				return self::kept_error('remote_fetch_failed', __('The remote source could not be reached safely.', 'baratables'));
			}
			$status = (int) wp_remote_retrieve_response_code($response);
			if ($status < 301 || $status > 308) {
				break;
			}
			$location = trim((string) wp_remote_retrieve_header($response, 'location'));
			$next = $location === '' ? '' : WP_Http::make_absolute_url($location, $fetch_url_for_hops);
			if ($next === '' || self::sanitize_url($next) === '' || !wp_http_validate_url($next)) {
				return self::kept_error('remote_redirect_unsafe', __('The remote source redirected to an address that is not a safe public HTTPS URL.', 'baratables'));
			}
			if ($hop === 3) {
				return self::kept_error('remote_fetch_failed', __('The remote source used too many redirects.', 'baratables'));
			}
			$fetch_url_for_hops = $next;
		}
		// The request sends Accept-Encoding: identity, so any content-coding on the response
		// means the source ignored it. Refuse coded responses so a compressed body is never
		// retained or parsed as a snapshot. (On the cURL transport libcurl transparently decodes
		// and strips the header, so this fires only where the coding is visible -- chiefly the
		// fsockopen transport, where expansion happens before size caps apply.)
		if (trim((string) wp_remote_retrieve_header($response, 'content-encoding')) !== '') {
			return self::kept_error('remote_content_encoded', __('The remote source returned compressed content, which is not supported.', 'baratables'));
		}
		if ($status !== 200 || trim((string) wp_remote_retrieve_header($response, 'content-range')) !== '') {
			return self::kept_error('remote_http_error', sprintf(
				/* translators: %d is an HTTP status code. */
				__('The remote source returned HTTP %d.', 'baratables'),
				$status
			));
		}
		$content_length = wp_remote_retrieve_header($response, 'content-length');
		if (is_scalar($content_length) && preg_match('/^\d+$/', trim((string) $content_length)) && (int) $content_length > self::MAX_BYTES) {
			return self::kept_error('remote_body_too_large', __('The remote response exceeded the 5 MB snapshot limit.', 'baratables'));
		}
		$body = (string) wp_remote_retrieve_body($response);
		if ($body === '' || strlen($body) > self::MAX_BYTES) {
			return self::kept_error('remote_body_invalid', __('The remote response was empty or exceeded the 5 MB snapshot limit.', 'baratables'));
		}
		$content_type = strtolower((string) wp_remote_retrieve_header($response, 'content-type'));
		if (!self::content_type_matches($content_type, $format)) {
			return self::kept_error('remote_content_type', __('The response content type did not match the selected source format.', 'baratables'));
		}

		$parsed = $format === self::FORMAT_JSON ? self::parse_json_records($body) : self::parse_csv($body);
		if (is_wp_error($parsed)) {
			return self::kept_error($parsed->get_error_code(), $parsed->get_error_message());
		}
		$dataset = $service->sanitize_custom_data(
			$parsed['columns'],
			$parsed['rows'],
			count($parsed['rows']),
			count($parsed['columns'])
		);
		$dataset_error = self::validate_sanitized_dataset($dataset);
		if ($dataset_error) {
			return self::kept_error($dataset_error->get_error_code(), $dataset_error->get_error_message());
		}
		if (empty($dataset['columns']) || empty($dataset['rows'])) {
			return self::kept_error('remote_snapshot_empty', __('The remote source needs a header and at least one data row.', 'baratables'));
		}
		return [
			'configured_url' => $configured_url,
			'configured_format' => $format,
			'fetched_at_utc' => gmdate('c'),
			'content_sha256' => hash('sha256', $body),
			'row_count' => count($dataset['rows']),
			'column_count' => count($dataset['columns']),
			'truncated_rows' => !empty($parsed['truncated_rows']),
			'truncated_columns' => !empty($parsed['truncated_columns']),
			'data' => ['columns' => $dataset['columns'], 'rows' => $dataset['rows']],
		];
	}

	public static function is_stale(array $config, array $snapshot): bool {
		return self::sanitize_url($config['url'] ?? '') !== (string) ($snapshot['configured_url'] ?? '')
			|| self::sanitize_format($config['format'] ?? '') !== (string) ($snapshot['configured_format'] ?? '');
	}

	/** Merge only a successful same-schema snapshot into a latest stored definition. */
	public static function merge_into_definition(array $definition, array $snapshot, BaraTables_Service $service) {
		if (!BaraTables_Source_Type::is_remote_snapshot($definition['source_type'] ?? '')) {
			return new WP_Error('remote_source_not_saved', __('Save remote snapshot as the data source before fetching.', 'baratables'));
		}
		$previous = isset($definition['remote_snapshot']) && is_array($definition['remote_snapshot']) ? $definition['remote_snapshot'] : [];
		$previous_columns = isset($previous['data']['columns']) && is_array($previous['data']['columns'])
			? array_values($previous['data']['columns'])
			: [];
		$new_columns = isset($snapshot['data']['columns']) && is_array($snapshot['data']['columns'])
			? array_values($snapshot['data']['columns'])
			: [];
		if (!empty($previous_columns) && $previous_columns !== $new_columns) {
			return new WP_Error('remote_schema_changed', __('The remote column headings changed. The previous snapshot was kept; create a new table or restore the original schema before refreshing.', 'baratables'));
		}
		if (empty($previous_columns)) {
			$definition['columns'] = $service->build_custom_display_columns($new_columns);
		}
		$definition['remote_snapshot'] = $snapshot;
		return $definition;
	}

	/** Commit a fetched snapshot without overwriting editor changes made while HTTP was in flight. */
	public static function commit_fetched_snapshot(int $post_id, array $fetched_config, array $snapshot, BaraTables_Service $service) {
		$expected_config = self::canonical_config($fetched_config);
		if ($expected_config['url'] === '') {
			return new WP_Error('remote_source_changed', __('The saved remote source changed while the snapshot was being fetched. No changes were made.', 'baratables'));
		}
		for ($attempt = 0; $attempt < 2; $attempt++) {
			$latest = get_post_meta($post_id, BaraTables_Repository::META_KEY, true);
			if (
				!is_array($latest)
				|| !BaraTables_Source_Type::is_remote_snapshot($latest['source_type'] ?? '')
				|| self::canonical_config(isset($latest['remote_source']) && is_array($latest['remote_source']) ? $latest['remote_source'] : []) !== $expected_config
			) {
				return new WP_Error('remote_source_changed', __('The saved remote source changed while the snapshot was being fetched. No changes were made.', 'baratables'));
			}
			$merged = self::merge_into_definition($latest, $snapshot, $service);
			if (is_wp_error($merged)) {
				return $merged;
			}
			$error = BaraTables_Base_Repository::persist_definition_if_unchanged(
				$post_id,
				BaraTables_Repository::META_KEY,
				$latest,
				$merged
			);
			if (!$error) {
				return $merged;
			}
			if ($error->get_error_code() !== 'baratables_definition_conflict') {
				return $error;
			}
		}
		return new WP_Error('baratables_definition_conflict', __('The table changed again while the snapshot was being saved. No remote data was written.', 'baratables'));
	}

	private static function canonical_config(array $config): array {
		return [
			'url' => self::sanitize_url($config['url'] ?? ''),
			'format' => self::sanitize_format($config['format'] ?? ''),
		];
	}

	/** @return array|WP_Error */
	private static function parse_csv(string $body) {
		$trimmed = ltrim($body, "\xEF\xBB\xBF \t\r\n");
		if ($trimmed === '' || preg_match('/^(?:<!doctype\s+html|<html\b|[\[{])/i', $trimmed)) {
			return new WP_Error('remote_csv_mismatch', __('The response was not a CSV table.', 'baratables'));
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- In-memory bounded stream required by fgetcsv().
		$handle = fopen('php://temp/maxmemory:' . (self::MAX_BYTES + 1), 'w+b');
		if (!is_resource($handle)) {
			return new WP_Error('remote_csv_open_failed', __('The CSV response could not be opened safely.', 'baratables'));
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Writes only to the in-memory stream above.
		fwrite($handle, $body);
		rewind($handle);
		$header = fgetcsv($handle, 0, ',', '"', '\\');
		if (!is_array($header) || empty($header)) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes only the bounded php://temp stream above.
			fclose($handle);
			return new WP_Error('remote_csv_empty', __('The CSV source needs a header and at least one data row.', 'baratables'));
		}
		$truncated_columns = count($header) > BaraTables_Service::MAX_CUSTOM_COLUMNS;
		$header = array_slice($header, 0, BaraTables_Service::MAX_CUSTOM_COLUMNS);
		foreach ($header as &$heading) {
			$heading = self::checked_scalar($heading);
			if (is_wp_error($heading)) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes only the bounded php://temp stream above.
				fclose($handle);
				return $heading;
			}
		}
		unset($heading);
		$column_count = count($header);
		$row_limit = min(BaraTables_Service::MAX_CUSTOM_ROWS, max(1, intdiv(BaraTables_Service::MAX_CUSTOM_CELLS, max(1, $column_count))));
		$rows = [];
		$truncated_rows = false;
		while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
			$nonblank = array_filter($row, static function ($value) {
				return $value !== null && trim((string) $value) !== '';
			});
			if (empty($nonblank)) {
				continue;
			}
			if (count($rows) >= $row_limit) {
				$truncated_rows = true;
				break;
			}
			if (count($row) > $column_count) {
				$truncated_columns = true;
			}
			$normalized = [];
			for ($i = 0; $i < $column_count; $i++) {
				$value = self::checked_scalar($row[$i] ?? '');
				if (is_wp_error($value)) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes only the bounded php://temp stream above.
					fclose($handle);
					return $value;
				}
				$normalized[] = $value;
			}
			$rows[] = $normalized;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes only the bounded php://temp stream above.
		fclose($handle);
		if (empty($rows)) {
			return new WP_Error('remote_csv_empty', __('The CSV source needs a header and at least one data row.', 'baratables'));
		}
		return compact('rows', 'truncated_rows', 'truncated_columns') + ['columns' => $header];
	}

	/** @return array|WP_Error Parse only a top-level array of flat associative records. */
	private static function parse_json_records(string $body) {
		$trimmed = trim($body);
		if ($trimmed === '' || $trimmed[0] !== '[' || substr($trimmed, -1) !== ']') {
			return new WP_Error('remote_json_mismatch', __('JSON sources must be a top-level array of records.', 'baratables'));
		}
		$scanned = self::json_array_elements($trimmed);
		if (is_wp_error($scanned)) {
			return $scanned;
		}
		$elements = $scanned['elements'];
		$records = [];
		$columns = [];
		$column_map = [];
		$truncated_rows = !empty($scanned['truncated']);
		$truncated_columns = false;
		foreach ($elements as $element) {
			if (count($records) >= BaraTables_Service::MAX_CUSTOM_ROWS) {
				$truncated_rows = true;
				break;
			}
			$record = json_decode($element, true, 8);
			if (!is_array($record) || self::is_list($record)) {
				return new WP_Error('remote_json_records_only', __('Every JSON row must be an object with named scalar fields.', 'baratables'));
			}
			$clean_record = [];
			foreach ($record as $key => $value) {
				if (!is_scalar($value) && $value !== null) {
					return new WP_Error('remote_json_flat_only', __('Nested JSON objects and arrays are not supported.', 'baratables'));
				}
				$key = self::checked_scalar((string) $key);
				if (is_wp_error($key)) {
					return $key;
				}
				if (!array_key_exists($key, $column_map)) {
					if (count($columns) >= BaraTables_Service::MAX_CUSTOM_COLUMNS) {
						$truncated_columns = true;
						continue;
					}
					$column_map[$key] = count($columns);
					$columns[] = $key;
				}
				$clean_value = self::checked_scalar($value === null ? '' : $value);
				if (is_wp_error($clean_value)) {
					return $clean_value;
				}
				$clean_record[$key] = $clean_value;
			}
			$records[] = $clean_record;
		}
		if (empty($records) || empty($columns)) {
			return new WP_Error('remote_json_empty', __('The JSON source needs at least one record with a named field.', 'baratables'));
		}
		$row_limit = min(count($records), max(1, intdiv(BaraTables_Service::MAX_CUSTOM_CELLS, count($columns))));
		if ($row_limit < count($records)) {
			$truncated_rows = true;
			$records = array_slice($records, 0, $row_limit);
		}
		$rows = [];
		foreach ($records as $record) {
			$row = [];
			foreach ($columns as $column) {
				$row[] = $record[$column] ?? '';
			}
			$rows[] = $row;
		}
		return compact('columns', 'rows', 'truncated_rows', 'truncated_columns');
	}

	/** Split a top-level JSON array without decoding or retaining the whole object graph. */
	private static function json_array_elements(string $json) {
		$elements = [];
		$start = 1;
		$depth = 0;
		$in_string = false;
		$escaped = false;
		$length = strlen($json);
		for ($i = 1; $i < $length - 1; $i++) {
			$char = $json[$i];
			if ($in_string) {
				if ($escaped) {
					$escaped = false;
				} elseif ($char === '\\') {
					$escaped = true;
				} elseif ($char === '"') {
					$in_string = false;
				}
				continue;
			}
			if ($char === '"') {
				$in_string = true;
			} elseif ($char === '{' || $char === '[') {
				$depth++;
			} elseif ($char === '}' || $char === ']') {
				$depth--;
				if ($depth < 0) {
					return new WP_Error('remote_json_invalid', __('The JSON response was malformed.', 'baratables'));
				}
			} elseif ($char === ',' && $depth === 0) {
				$element = trim(substr($json, $start, $i - $start));
				if ($element === '' || strlen($element) > self::MAX_JSON_RECORD_BYTES) {
					return new WP_Error('remote_json_record_too_large', __('A JSON record was empty or too large to process safely.', 'baratables'));
				}
				if (count($elements) <= BaraTables_Service::MAX_CUSTOM_ROWS) {
					$elements[] = $element;
				} elseif (!self::valid_flat_record($element)) {
					return new WP_Error('remote_json_records_only', __('Every JSON row must be an object with named scalar fields.', 'baratables'));
				}
				$start = $i + 1;
			}
		}
		if ($in_string || $depth !== 0) {
			return new WP_Error('remote_json_invalid', __('The JSON response was malformed.', 'baratables'));
		}
		$last = trim(substr($json, $start, $length - 1 - $start));
		if ($last === '' && trim(substr($json, 1, -1)) !== '') {
			return new WP_Error('remote_json_invalid', __('The JSON response was malformed.', 'baratables'));
		}
		if ($last !== '') {
			if (strlen($last) > self::MAX_JSON_RECORD_BYTES) {
				return new WP_Error('remote_json_record_too_large', __('A JSON record was too large to process safely.', 'baratables'));
			}
			if (count($elements) <= BaraTables_Service::MAX_CUSTOM_ROWS) {
				$elements[] = $last;
			} elseif (!self::valid_flat_record($last)) {
				return new WP_Error('remote_json_records_only', __('Every JSON row must be an object with named scalar fields.', 'baratables'));
			}
		}
		return [
			'elements' => array_slice($elements, 0, BaraTables_Service::MAX_CUSTOM_ROWS + 1),
			'truncated' => count($elements) > BaraTables_Service::MAX_CUSTOM_ROWS,
		];
	}

	private static function valid_flat_record(string $element): bool {
		$record = json_decode($element, true, 8);
		if (!is_array($record) || self::is_list($record)) {
			return false;
		}
		foreach ($record as $value) {
			if (!is_scalar($value) && $value !== null) {
				return false;
			}
		}
		return true;
	}

	/** @return string|WP_Error */
	private static function checked_scalar($value) {
		$value = is_scalar($value) ? (string) $value : '';
		if (preg_match('//u', $value) !== 1) {
			return new WP_Error('remote_cell_invalid_utf8', __('A remote heading or cell was not valid UTF-8 text.', 'baratables'));
		}
		if (strlen($value) > self::MAX_CELL_BYTES) {
			return new WP_Error('remote_cell_too_large', __('A remote heading or cell exceeded the 64 KB safety limit.', 'baratables'));
		}
		return $value;
	}

	/** Re-assert byte budgets after wp_kses() may have expanded entities. */
	private static function validate_sanitized_dataset(array $dataset): ?WP_Error {
		$total_bytes = 0;
		$check = static function ($value) use (&$total_bytes): ?WP_Error {
			$value = is_scalar($value) ? (string) $value : '';
			$length = strlen($value);
			if (preg_match('//u', $value) !== 1) {
				return new WP_Error('remote_cell_invalid_utf8', __('A remote heading or cell was not valid UTF-8 text.', 'baratables'));
			}
			if ($length > self::MAX_CELL_BYTES) {
				return new WP_Error('remote_cell_too_large', __('A remote heading or cell exceeded the 64 KB safety limit after sanitization.', 'baratables'));
			}
			$total_bytes += $length;
			if ($total_bytes > self::MAX_BYTES) {
				return new WP_Error('remote_data_too_large', __('The sanitized remote table exceeded the 5 MB retained-data limit.', 'baratables'));
			}
			return null;
		};
		foreach (isset($dataset['columns']) && is_array($dataset['columns']) ? $dataset['columns'] : [] as $value) {
			$error = $check($value);
			if ($error) {
				return $error;
			}
		}
		foreach (isset($dataset['rows']) && is_array($dataset['rows']) ? $dataset['rows'] : [] as $row) {
			foreach (is_array($row) ? $row : [] as $value) {
				$error = $check($value);
				if ($error) {
					return $error;
				}
			}
		}
		return null;
	}

	private static function is_list(array $value): bool {
		return $value === [] || array_keys($value) === range(0, count($value) - 1);
	}

	private static function content_type_matches(string $content_type, string $format): bool {
		$media_type = trim(explode(';', $content_type, 2)[0]);
		if ($format === self::FORMAT_JSON) {
			return $media_type === '' || $media_type === 'text/plain' || substr($media_type, -5) === '+json' || $media_type === 'application/json';
		}
		return in_array($media_type, ['text/csv', 'application/csv'], true);
	}

	private static function kept_error(string $code, string $message): WP_Error {
		return new WP_Error($code, $message . ' ' . __('The previous snapshot was kept.', 'baratables'));
	}

}
