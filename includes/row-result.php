<?php

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Atomic result of resolving a table source.
 *
 * Rows and discovered columns belong to the same fetch and cache entry, so no caller can observe
 * columns left behind by another table or by a differently limited read.
 */
final class BaraTables_Row_Result {
	private array $rows;
	private array $inferred_columns;
	private string $error_code;

	public function __construct(array $rows = [], array $inferred_columns = [], string $error_code = '') {
		$this->rows = array_values($rows);
		$this->inferred_columns = array_values($inferred_columns);
		$this->error_code = sanitize_key($error_code);
	}

	public static function failure(string $error_code, array $inferred_columns = []): self {
		return new self([], $inferred_columns, $error_code);
	}

	public function rows(): array {
		return $this->rows;
	}

	public function inferred_columns(): array {
		return $this->inferred_columns;
	}

	public function has_error(): bool {
		return $this->error_code !== '';
	}

	public function error_code(): string {
		return $this->error_code;
	}

	/** Safe, administrator-facing explanation; raw filesystem/SQL/credential details never enter the result. */
	public function error_message(): string {
		$messages = [
			'csv_missing' => __('The CSV source file is missing or is no longer a valid CSV attachment.', 'baratables'),
			'csv_unreadable' => __('The CSV source file cannot be read by WordPress.', 'baratables'),
			'csv_too_large' => __('The CSV source file exceeds BaraTables\' 5 MB read limit.', 'baratables'),
			'csv_read_failed' => __('The CSV source file could not be read.', 'baratables'),
			'custom_query_invalid' => __('The custom WordPress query is empty or invalid.', 'baratables'),
			'external_configuration' => __('The external database source is incomplete or unsupported.', 'baratables'),
			'external_connection' => __('BaraTables could not connect to the external database.', 'baratables'),
			'external_read_failed' => __('The external database query could not be completed.', 'baratables'),
		];
		return $messages[$this->error_code] ?? __('The table source could not be read.', 'baratables');
	}
}
