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

	public function __construct(array $rows = [], array $inferred_columns = []) {
		$this->rows = array_values($rows);
		$this->inferred_columns = array_values($inferred_columns);
	}

	public function rows(): array {
		return $this->rows;
	}

	public function inferred_columns(): array {
		return $this->inferred_columns;
	}
}
