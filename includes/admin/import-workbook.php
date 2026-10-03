<?php

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Bounded, read-only XLSX/ODS importer.
 *
 * Workbooks are ZIP containers, but this class never extracts them to disk, follows external
 * relationships, evaluates formulas, imports macros, or interprets presentation metadata. It
 * reads only the workbook/sheet XML needed to recover visible cell values.
 */
class BaraTables_Import_Workbook {
	private const XLSX_MAIN_NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
	private const XLSX_DOC_REL_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
	private const XLSX_PACKAGE_REL_NS = 'http://schemas.openxmlformats.org/package/2006/relationships';
	private const ODS_OFFICE_NS = 'urn:oasis:names:tc:opendocument:xmlns:office:1.0';
	private const ODS_TABLE_NS = 'urn:oasis:names:tc:opendocument:xmlns:table:1.0';
	private const ODS_TEXT_NS = 'urn:oasis:names:tc:opendocument:xmlns:text:1.0';
	private const ODS_STYLE_NS = 'urn:oasis:names:tc:opendocument:xmlns:style:1.0';
	private const MAX_XML_BYTES = 5242880;
	private const MAX_PARAGRAPH_BYTES = 65536;
	private const MAX_SHARED_STRINGS = 100000;
	private const MAX_WORKSHEETS = 200;
	private const PARSE_ROWS = 1002; // Header + 1,001 rows lets the shared builder prove truncation.
	private const PARSE_COLUMNS = 101; // One beyond the public limit for the same reason.

	/** @return array{tables:array[],message:string} */
	public static function to_normalized_list(ZipArchive $zip, string $format): array {
		if (!class_exists('XMLReader')) {
			return self::failure(__('Spreadsheet imports require the PHP XML extension on this server.', 'baratables'));
		}
		if ($format === 'xlsx') {
			return self::parse_xlsx($zip);
		}
		if ($format === 'ods') {
			return self::parse_ods($zip);
		}
		return self::failure(__('The spreadsheet format was not recognized.', 'baratables'));
	}

	/** @return array{tables:array[],message:string} */
	private static function parse_xlsx(ZipArchive $zip): array {
		$workbook_xml = self::entry($zip, 'xl/workbook.xml');
		$rels_xml = self::entry($zip, 'xl/_rels/workbook.xml.rels');
		if ($workbook_xml === null || $rels_xml === null) {
			return self::failure(__('The XLSX file is missing its workbook metadata or is damaged.', 'baratables'));
		}
		$workbook_result = self::xlsx_workbook_metadata($workbook_xml);
		$relationships_result = self::xlsx_relationship_targets($rels_xml);
		if ($workbook_result['error'] !== '' || $relationships_result['error'] !== '') {
			return self::failure(__('The XLSX workbook metadata could not be parsed safely.', 'baratables'));
		}
		$relationship_targets = $relationships_result['values'];

		$shared_strings_result = self::xlsx_shared_strings($zip);
		$date_styles_result = self::xlsx_date_styles($zip);
		if ($shared_strings_result['error'] !== '' || $date_styles_result['error'] !== '') {
			return self::failure($shared_strings_result['error'] !== '' ? $shared_strings_result['error'] : $date_styles_result['error']);
		}
		$shared_strings = $shared_strings_result['values'];
		$date_styles = $date_styles_result['values'];
		$date_1904 = $workbook_result['date_1904'];

		$tables = [];
		$selected_table_found = false;
		$seen_targets = [];
		if (empty($workbook_result['sheets'])) {
			return self::failure(__('The XLSX file did not contain any worksheets.', 'baratables'));
		}
		foreach ($workbook_result['sheets'] as $sheet) {
			$state = $sheet['state'];
			if ($state !== '' && $state !== 'visible') {
				continue;
			}
			$name = $sheet['name'];
			$rel_id = $sheet['relationship_id'];
			if ($rel_id === '' || !isset($relationship_targets[$rel_id])) {
				continue;
			}
			$target = $relationship_targets[$rel_id];
			if (isset($seen_targets[$target])) {
				continue;
			}
			$seen_targets[$target] = true;
			$sheet_xml = self::entry($zip, $target);
			if ($sheet_xml === null) {
				return self::failure(__('An XLSX worksheet is missing or could not be read.', 'baratables'));
			}
			$parsed = self::xlsx_sheet($sheet_xml, $shared_strings, $date_styles, $date_1904, $selected_table_found);
			if (!empty($parsed['error'])) {
				return self::failure($parsed['error']);
			}
			if (empty($parsed['rows'])) {
				continue;
			}
			if ($selected_table_found) {
				// A one-column placeholder is enough for build_manual() to count this usable
				// worksheet. Retaining every later grid would multiply the workbook's memory.
				$tables[] = BaraTables_Import_Builder::normalized($name, [''], [], true);
				continue;
			}
			$rows = $parsed['rows'];
			$labels = array_shift($rows);
			$tables[] = BaraTables_Import_Builder::normalized(
				$name !== '' ? $name : __('Imported worksheet', 'baratables'),
				is_array($labels) ? $labels : [],
				$rows,
				true,
				[],
				$parsed['warnings'],
				[],
				!empty($parsed['truncated'])
			);
			$selected_table_found = true;
		}

		return empty($tables)
			? self::failure(__('The XLSX file contained no visible worksheet with table data.', 'baratables'))
			: ['tables' => $tables, 'message' => ''];
	}

	/** @return array{values:array<string,string>,error:string} */
	private static function xlsx_relationship_targets(string $raw): array {
		$previous = libxml_use_internal_errors(true);
		libxml_clear_errors();
		$reader = self::reader($raw);
		if (!($reader instanceof XMLReader)) {
			libxml_clear_errors();
			libxml_use_internal_errors($previous);
			return ['values' => [], 'error' => 'invalid'];
		}
		$targets = [];
		$too_many = false;
		while ($reader->read()) {
			if ($reader->nodeType !== XMLReader::ELEMENT || $reader->namespaceURI !== self::XLSX_PACKAGE_REL_NS || $reader->localName !== 'Relationship') {
				continue;
			}
			$id = (string) $reader->getAttribute('Id');
			$target = (string) $reader->getAttribute('Target');
			$target_mode = strtolower((string) $reader->getAttribute('TargetMode'));
			if ($id === '' || $target === '' || $target_mode === 'external') {
				continue;
			}
			$target = ltrim(str_replace('\\', '/', $target), '/');
			if (strpos($target, 'xl/') !== 0) {
				$target = 'xl/' . $target;
			}
			if (preg_match('#^xl/worksheets/[A-Za-z0-9_.-]+\.xml$#', $target) === 1) {
				$targets[$id] = $target;
				if (count($targets) > self::MAX_WORKSHEETS) {
					$too_many = true;
					break;
				}
			}
		}
		$valid = self::finish_reader($reader, $previous);
		return ['values' => $valid && !$too_many ? $targets : [], 'error' => $valid && !$too_many ? '' : 'invalid'];
	}

	/** @return array{sheets:array<int,array{name:string,state:string,relationship_id:string}>,date_1904:bool,error:string} */
	private static function xlsx_workbook_metadata(string $raw): array {
		$previous = libxml_use_internal_errors(true);
		libxml_clear_errors();
		$reader = self::reader($raw);
		if (!($reader instanceof XMLReader)) {
			libxml_clear_errors();
			libxml_use_internal_errors($previous);
			return ['sheets' => [], 'date_1904' => false, 'error' => 'invalid'];
		}
		$sheets = [];
		$date_1904 = false;
		$too_many = false;
		while ($reader->read()) {
			if ($reader->nodeType !== XMLReader::ELEMENT || $reader->namespaceURI !== self::XLSX_MAIN_NS) {
				continue;
			}
			if ($reader->localName === 'workbookPr') {
				$date_1904 = BaraTables_Import_Util::to_bool((string) $reader->getAttribute('date1904'));
			} elseif ($reader->localName === 'sheet') {
				$sheets[] = [
					'name' => sanitize_text_field((string) $reader->getAttribute('name')),
					'state' => strtolower((string) $reader->getAttribute('state')),
					'relationship_id' => (string) $reader->getAttributeNs('id', self::XLSX_DOC_REL_NS),
				];
				if (count($sheets) > self::MAX_WORKSHEETS) {
					$too_many = true;
					break;
				}
			}
		}
		$valid = self::finish_reader($reader, $previous);
		return ['sheets' => $valid && !$too_many ? $sheets : [], 'date_1904' => $date_1904, 'error' => $valid && !$too_many ? '' : 'invalid'];
	}

	/** @return array{values:string[],error:string} */
	private static function xlsx_shared_strings(ZipArchive $zip): array {
		$raw = self::entry($zip, 'xl/sharedStrings.xml', true);
		if ($raw === '') {
			return ['values' => [], 'error' => ''];
		}
		if ($raw === null) {
			return ['values' => [], 'error' => __('The XLSX shared-string data exceeded the safe import limit.', 'baratables')];
		}
		$previous = libxml_use_internal_errors(true);
		libxml_clear_errors();
		$reader = self::reader($raw);
		if (!($reader instanceof XMLReader)) {
			libxml_clear_errors();
			libxml_use_internal_errors($previous);
			return ['values' => [], 'error' => __('The XLSX shared-string data could not be parsed safely.', 'baratables')];
		}
		$strings = [];
		$current = '';
		$inside_item = false;
		$too_many = false;
		while ($reader->read()) {
			if ($reader->namespaceURI !== self::XLSX_MAIN_NS) {
				continue;
			}
			if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'si') {
				$current = '';
				$inside_item = true;
				if ($reader->isEmptyElement) {
					$strings[] = '';
					$inside_item = false;
					if (count($strings) > self::MAX_SHARED_STRINGS) {
						$too_many = true;
						break;
					}
				}
			} elseif ($inside_item && $reader->nodeType === XMLReader::ELEMENT && $reader->localName === 't') {
				$current .= $reader->readString();
			} elseif ($inside_item && $reader->nodeType === XMLReader::END_ELEMENT && $reader->localName === 'si') {
				$strings[] = $current;
				$inside_item = false;
				if (count($strings) > self::MAX_SHARED_STRINGS) {
					$too_many = true;
					break;
				}
			}
		}
		$reader->close();
		$valid = empty(libxml_get_errors());
		libxml_clear_errors();
		libxml_use_internal_errors($previous);
		if ($too_many) {
			return ['values' => [], 'error' => __('The XLSX shared-string table exceeded the safe import limit.', 'baratables')];
		}
		if (!$valid) {
			return ['values' => [], 'error' => __('The XLSX shared-string data could not be parsed safely.', 'baratables')];
		}
		return ['values' => $strings, 'error' => ''];
	}

	/** @return array{values:array<int,array{date:bool,time:bool}>,error:string} */
	private static function xlsx_date_styles(ZipArchive $zip): array {
		$raw = self::entry($zip, 'xl/styles.xml', true);
		if ($raw === '') {
			return ['values' => [], 'error' => ''];
		}
		if ($raw === null) {
			return ['values' => [], 'error' => __('The XLSX style data exceeded the safe import limit.', 'baratables')];
		}
		$previous = libxml_use_internal_errors(true);
		libxml_clear_errors();
		$reader = self::reader($raw);
		if (!($reader instanceof XMLReader)) {
			libxml_clear_errors();
			libxml_use_internal_errors($previous);
			return ['values' => [], 'error' => __('The XLSX style data could not be parsed safely.', 'baratables')];
		}
		$formats = [];
		$style_format_ids = [];
		$inside_cell_formats = false;
		$cell_formats_depth = -1;
		while ($reader->read()) {
			if ($reader->namespaceURI !== self::XLSX_MAIN_NS) {
				continue;
			}
			if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'numFmt') {
				$id = (int) $reader->getAttribute('numFmtId');
				if ($id >= 0) {
					$formats[$id] = (string) $reader->getAttribute('formatCode');
				}
			} elseif ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'cellXfs') {
				$inside_cell_formats = true;
				$cell_formats_depth = $reader->depth;
			} elseif ($inside_cell_formats && $reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'xf') {
				$style_format_ids[] = (int) $reader->getAttribute('numFmtId');
			} elseif ($inside_cell_formats && $reader->nodeType === XMLReader::END_ELEMENT && $reader->localName === 'cellXfs' && $reader->depth === $cell_formats_depth) {
				$inside_cell_formats = false;
			}
		}
		$valid = self::finish_reader($reader, $previous);
		if (!$valid) {
			return ['values' => [], 'error' => __('The XLSX style data could not be parsed safely.', 'baratables')];
		}
		$styles = [];
		foreach ($style_format_ids as $index => $id) {
			$built_in_date = in_array($id, array_merge(range(14, 17), [22], range(27, 36), range(50, 58)), true);
			$code = isset($formats[$id]) ? $formats[$id] : '';
			$clean = preg_replace('/"[^"]*"|\\\\.|\[[^\]]*\]/', '', $code);
			$clean = is_string($clean) ? $clean : '';
			$has_time = in_array($id, array_merge(range(18, 22), range(45, 47)), true)
				|| preg_match('/[hs]/i', $clean) === 1;
			$is_date = $built_in_date
				|| preg_match('/[yd]/i', $clean) === 1
				|| (!$has_time && preg_match('/m/i', $clean) === 1);
			if ($is_date || $has_time) {
				$styles[(int) $index] = ['date' => $is_date, 'time' => $has_time];
			}
		}
		return ['values' => $styles, 'error' => ''];
	}

	/** @return array{rows:array[],warnings:string[],error:string,truncated:bool} */
	private static function xlsx_sheet(string $raw, array $shared_strings, array $date_styles, bool $date_1904, bool $count_only = false): array {
		$previous = libxml_use_internal_errors(true);
		libxml_clear_errors();
		$reader = self::reader($raw);
		if (!($reader instanceof XMLReader)) {
			libxml_clear_errors();
			libxml_use_internal_errors($previous);
			return ['rows' => [], 'warnings' => [], 'error' => __('An XLSX worksheet could not be parsed safely.', 'baratables')];
		}
		$rows = [];
		$rows_truncated = false;
		$has_formula = false;
		$missing_formula_cache = false;
		$has_merge = false;
		$inside_row = false;
		$inside_cell = false;
		$row = [];
		$last_value = -1;
		$next_column = 0;
		$column = 0;
		$type = '';
		$style = -1;
		$value_raw = '';
		$inline = '';
		$formula = false;
		$has_cached_value = false;
		while ($reader->read()) {
			if ($reader->namespaceURI !== self::XLSX_MAIN_NS) {
				continue;
			}
			if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'mergeCell') {
				$has_merge = true;
				continue;
			}
			if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'row') {
				// Row numbers are 1-based and a gap means blank rows the writer omitted entirely,
				// so pad up to the declared number: a later row keeps its workbook position the
				// same way an explicit blank row does (cells already honor their own r attribute).
				$row_number = (int) $reader->getAttribute('r');
				if ($row_number > 1 && !empty($rows)) {
					while (count($rows) < $row_number - 1 && count($rows) < self::PARSE_ROWS) {
						$rows[] = [];
					}
				}
				if ($reader->isEmptyElement) {
					// A self-closed <row/> never emits an END_ELEMENT event, so treat it as a
					// completed row here: interior blank rows keep their place in the sheet.
					if (!empty($rows)) {
						$rows[] = [];
						if (!$count_only && count($rows) >= self::PARSE_ROWS) {
							$rows_truncated = true;
							break;
						}
						if ($count_only) {
							break;
						}
					}
					continue;
				}
				$inside_row = true;
				$row = [];
				$last_value = -1;
				$next_column = 0;
				continue;
			}
			if ($inside_row && $reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'c') {
				$inside_cell = true;
				$reference = strtoupper((string) $reader->getAttribute('r'));
				$source_column = $next_column;
				if (preg_match('/^([A-Z]+)[0-9]+$/', $reference, $matches) === 1) {
					$resolved = self::xlsx_column_index($matches[1]);
					$source_column = $resolved >= 0 ? $resolved : $next_column;
				}
				$next_column = $source_column + 1;
				$column = min($source_column, self::PARSE_COLUMNS - 1);
				$type = (string) $reader->getAttribute('t');
				$style_attribute = $reader->getAttribute('s');
				$style = $style_attribute === null ? -1 : (int) $style_attribute;
				$value_raw = '';
				$inline = '';
				$formula = false;
				$has_cached_value = false;
				continue;
			}
			if ($inside_cell && $reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'f') {
				$formula = true;
				$has_formula = true;
				continue;
			}
			if ($inside_cell && $reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'v') {
				$value_raw = $reader->readString();
				$has_cached_value = true;
				continue;
			}
			if ($inside_cell && $type === 'inlineStr' && $reader->nodeType === XMLReader::ELEMENT && $reader->localName === 't') {
				$inline .= $reader->readString();
				$has_cached_value = true;
				continue;
			}
			if ($inside_cell && $reader->nodeType === XMLReader::END_ELEMENT && $reader->localName === 'c') {
				while (count($row) <= $column) {
					$row[] = '';
				}
				$value = self::xlsx_cell_value($value_raw, $inline, $type, $style, $shared_strings, $date_styles, $date_1904);
				if ($formula && !$has_cached_value) {
					$missing_formula_cache = true;
					$value = '';
				}
				$row[$column] = $value;
				if ($value !== '') {
					$last_value = max($last_value, $column);
				}
				$inside_cell = false;
				continue;
			}
			if ($inside_row && $reader->nodeType === XMLReader::END_ELEMENT && $reader->localName === 'row') {
				if ($last_value >= 0) {
					$rows[] = array_slice($row, 0, $last_value + 1);
				} elseif (!empty($rows)) {
					// A blank row inside the sheet keeps its place so row numbering matches the
					// workbook; blank rows before any data and trailing blanks are dropped.
					$rows[] = [];
				}
				$inside_row = false;
				if (!$count_only && count($rows) >= self::PARSE_ROWS) {
					$rows_truncated = true;
					break;
				}
				if ($count_only && !empty($rows)) {
					break;
				}
			}
		}
		$reader->close();
		$valid = empty(libxml_get_errors());
		libxml_clear_errors();
		libxml_use_internal_errors($previous);
		if (!$valid) {
			return ['rows' => [], 'warnings' => [], 'error' => __('An XLSX worksheet could not be parsed safely.', 'baratables')];
		}
		while (!empty($rows) && $rows[count($rows) - 1] === []) {
			array_pop($rows);
		}
		$warnings = [];
		if ($has_formula) {
			$warnings[] = __('Formula results were imported from cached values; spreadsheet formulas were not executed.', 'baratables');
		}
		if ($missing_formula_cache) {
			$warnings[] = __('Some formula cells had no cached result and were imported as blank cells.', 'baratables');
		}
		if ($has_merge) {
			$warnings[] = __('Merged spreadsheet cells were flattened into ordinary cells.', 'baratables');
		}
		return ['rows' => $rows, 'warnings' => $warnings, 'error' => '', 'truncated' => $rows_truncated];
	}

	private static function xlsx_cell_value(string $raw, string $inline, string $type, int $style, array $shared_strings, array $date_styles, bool $date_1904): string {
		if ($type === 'inlineStr') {
			return $inline;
		}
		if ($type === 's') {
			$index = (int) $raw;
			return isset($shared_strings[$index]) ? (string) $shared_strings[$index] : '';
		}
		if ($type === 'b') {
			return $raw === '1' ? 'true' : 'false';
		}
		if ($type === 'e') {
			return $raw;
		}
		if (($type === '' || $type === 'n') && $raw !== '' && is_numeric($raw) && isset($date_styles[$style])) {
			return self::excel_date((float) $raw, $date_1904, $date_styles[$style]);
		}
		return $raw;
	}

	/** @param array{date:bool,time:bool} $style */
	private static function excel_date(float $serial, bool $date_1904, array $style): string {
		$epoch_days = $date_1904 ? 24107 : 25569;
		$timestamp = (int) round(($serial - $epoch_days) * DAY_IN_SECONDS);
		if (!$date_1904 && !empty($style['date'])) {
			$whole_days = (int) floor($serial);
			if ($whole_days === 60) {
				$seconds = ((int) round(($serial - $whole_days) * DAY_IN_SECONDS)) % DAY_IN_SECONDS;
				$time = gmdate('H:i:s', $seconds < 0 ? $seconds + DAY_IN_SECONDS : $seconds);
				return !empty($style['time']) ? '1900-02-29 ' . $time : '1900-02-29';
			}
			if ($whole_days < 60) {
				$timestamp += DAY_IN_SECONDS;
			}
		}
		if (!empty($style['date']) && !empty($style['time'])) {
			return gmdate('Y-m-d H:i:s', $timestamp);
		}
		if (!empty($style['time'])) {
			$seconds = ((int) round($serial * DAY_IN_SECONDS)) % DAY_IN_SECONDS;
			$seconds = $seconds < 0 ? $seconds + DAY_IN_SECONDS : $seconds;
			return gmdate('H:i:s', $seconds);
		}
		return gmdate('Y-m-d', $timestamp);
	}

	private static function xlsx_column_index(string $letters): int {
		$index = 0;
		$length = strlen($letters);
		if ($length <= 0 || $length > 4) {
			return -1;
		}
		for ($i = 0; $i < $length; $i++) {
			$ord = ord($letters[$i]);
			if ($ord < 65 || $ord > 90) {
				return -1;
			}
			$index = ($index * 26) + ($ord - 64);
			if ($index > 16384) {
				return -1;
			}
		}
		return $index - 1;
	}

	/** @return array{tables:array[],message:string} */
	private static function parse_ods(ZipArchive $zip): array {
		$content = self::entry($zip, 'content.xml');
		if ($content === null) {
			return self::failure(__('The ODS file is missing its worksheet data or is damaged.', 'baratables'));
		}
		$previous = libxml_use_internal_errors(true);
		libxml_clear_errors();
		$reader = self::reader($content);
		if (!($reader instanceof XMLReader)) {
			libxml_clear_errors();
			libxml_use_internal_errors($previous);
			return self::failure(__('The ODS worksheet data could not be parsed safely.', 'baratables'));
		}
		$hidden_styles = [];
		$tables = [];
		$selected_table_found = false;
		$table_count = 0;
		$too_many_tables = false;
		$current_style = '';
		$current_style_depth = -1;
		$in_table = false;
		$table_visible = false;
		$table_depth = -1;
		$name = '';
		$rows = [];
		$has_formula = false;
		$missing_formula_cache = false;
		$has_merge = false;
		$rows_truncated = false;
		$count_only = false;
		$table_has_data = false;
		$in_row = false;
		$row_depth = -1;
		$row_repeat = 1;
		$row = [];
		$last_value = -1;
		$in_cell = false;
		$cell_depth = -1;
		$cell_repeat = 1;
		$cell_type = '';
		$cell_value = '';
		$cell_covered = false;
		$cell_formula = false;
		$cell_has_cache = false;
		$paragraphs = [];
		$paragraph = '';
		$in_paragraph = false;

		while ($reader->read()) {
			if ($reader->nodeType === XMLReader::ELEMENT) {
				if ($reader->namespaceURI === self::ODS_STYLE_NS && $reader->localName === 'style') {
					$family = (string) $reader->getAttributeNs('family', self::ODS_STYLE_NS);
					$current_style = $family === 'table' ? (string) $reader->getAttributeNs('name', self::ODS_STYLE_NS) : '';
					$current_style_depth = $reader->depth;
					if ($reader->isEmptyElement) {
						$current_style = '';
					}
					continue;
				}
				if ($current_style !== '' && $reader->namespaceURI === self::ODS_STYLE_NS && $reader->localName === 'table-properties') {
					$display = $reader->getAttributeNs('display', self::ODS_TABLE_NS);
					if ($display !== null && !BaraTables_Import_Util::to_bool($display, true)) {
						$hidden_styles[$current_style] = true;
					}
					continue;
				}
				if (!$in_table && $reader->namespaceURI === self::ODS_TABLE_NS && $reader->localName === 'table') {
					$table_count++;
					if ($table_count > self::MAX_WORKSHEETS) {
						$too_many_tables = true;
						break;
					}
					$in_table = true;
					$table_depth = $reader->depth;
					$name = sanitize_text_field((string) $reader->getAttributeNs('name', self::ODS_TABLE_NS));
					$visibility = strtolower((string) $reader->getAttributeNs('visibility', self::ODS_TABLE_NS));
					$style_name = (string) $reader->getAttributeNs('style-name', self::ODS_TABLE_NS);
					$table_visible = in_array($visibility, ['', 'visible'], true) && !isset($hidden_styles[$style_name]);
					$rows = [];
					$has_formula = false;
					$missing_formula_cache = false;
					$has_merge = false;
					$rows_truncated = false;
					$count_only = $selected_table_found;
					$table_has_data = false;
					if ($reader->isEmptyElement) {
						$in_table = false;
						$table_visible = false;
					}
					continue;
				}
				if ($in_table && $table_visible && !$in_row && $reader->namespaceURI === self::ODS_TABLE_NS && $reader->localName === 'table-row') {
					if (($count_only && $table_has_data) || (!$count_only && count($rows) >= self::PARSE_ROWS)) {
						$rows_truncated = true;
						continue;
					}
					$in_row = true;
					$row_depth = $reader->depth;
					$row_repeat = max(1, (int) $reader->getAttributeNs('number-rows-repeated', self::ODS_TABLE_NS));
					$row = [];
					$last_value = -1;
					if ($reader->isEmptyElement) {
						if (!$count_only) {
							self::ods_append_row($rows, [], $row_repeat, $rows_truncated);
						}
						$in_row = false;
					}
					continue;
				}
				if ($in_row && !$in_cell && $reader->namespaceURI === self::ODS_TABLE_NS && in_array($reader->localName, ['table-cell', 'covered-table-cell'], true)) {
					$in_cell = true;
					$cell_depth = $reader->depth;
					$cell_repeat = max(1, (int) $reader->getAttributeNs('number-columns-repeated', self::ODS_TABLE_NS));
					$cell_type = (string) $reader->getAttributeNs('value-type', self::ODS_OFFICE_NS);
					$cell_covered = $reader->localName === 'covered-table-cell';
					$cell_formula = (string) $reader->getAttributeNs('formula', self::ODS_TABLE_NS) !== '';
					$has_formula = $has_formula || $cell_formula;
					$has_merge = $has_merge
						|| (int) $reader->getAttributeNs('number-columns-spanned', self::ODS_TABLE_NS) > 1
						|| (int) $reader->getAttributeNs('number-rows-spanned', self::ODS_TABLE_NS) > 1;
					$cell_value = self::ods_attribute_value($reader, $cell_type);
					$cell_has_cache = self::ods_has_cached_attribute($reader, $cell_type);
					$paragraphs = [];
					$cell_text_bytes = 0;
					$paragraph = '';
					$in_paragraph = false;
					if ($reader->isEmptyElement) {
						if ($cell_formula && !$cell_has_cache) {
							$missing_formula_cache = true;
						}
						self::ods_append_cell($row, $last_value, $cell_covered ? '' : $cell_value, $cell_repeat);
						$in_cell = false;
					}
					continue;
				}
				if ($in_cell && $reader->namespaceURI === self::ODS_TEXT_NS && $reader->localName === 'p') {
					$paragraph = '';
					$in_paragraph = true;
					if ($reader->isEmptyElement) {
						$paragraphs[] = '';
						$in_paragraph = false;
					}
					continue;
				}
				if ($in_paragraph && $reader->namespaceURI === self::ODS_TEXT_NS && $reader->localName === 's') {
					$count = max(1, (int) $reader->getAttributeNs('c', self::ODS_TEXT_NS));
					// Bound the accumulated padding, not just one element: thousands of space-repeat
					// elements inside one paragraph can otherwise expand a small archive into an
					// unbounded allocation before any cell budget runs.
					$room = max(0, self::MAX_PARAGRAPH_BYTES - strlen($paragraph));
					if ($room > 0) {
						$paragraph .= str_repeat(' ', min($count, 1000, $room));
					}
					continue;
				}
				if ($in_paragraph && $reader->namespaceURI === self::ODS_TEXT_NS && $reader->localName === 'tab') {
					$paragraph .= "\t";
					continue;
				}
				if ($in_paragraph && $reader->namespaceURI === self::ODS_TEXT_NS && $reader->localName === 'line-break') {
					$paragraph .= "\n";
					continue;
				}
			}
			if ($in_paragraph && in_array($reader->nodeType, [XMLReader::TEXT, XMLReader::CDATA, XMLReader::SIGNIFICANT_WHITESPACE], true)) {
				$paragraph .= $reader->value;
				continue;
			}
			if ($reader->nodeType !== XMLReader::END_ELEMENT) {
				continue;
			}
			if ($in_paragraph && $reader->namespaceURI === self::ODS_TEXT_NS && $reader->localName === 'p') {
				// The per-paragraph cap bounds one element run; bound the accumulated cell too,
				// or thousands of individually legal paragraphs still amplify a small archive
				// into an unbounded cell before any row/column count budget runs.
				// The first paragraph is accepted as-is: it is already bounded by the per-paragraph
				// amplification cap and the raw entry size. The cumulative bound stops the many-
				// paragraph amplification that no other budget catches.
				$fits = $cell_text_bytes === 0
					|| $cell_text_bytes + 1 + strlen($paragraph) <= self::MAX_PARAGRAPH_BYTES;
				if ($fits) {
					$paragraphs[] = $paragraph;
					$cell_text_bytes += strlen($paragraph) + 1;
				}
				$in_paragraph = false;
				continue;
			}
			if ($in_cell && $reader->namespaceURI === self::ODS_TABLE_NS && in_array($reader->localName, ['table-cell', 'covered-table-cell'], true) && $reader->depth === $cell_depth) {
				if (!empty($paragraphs) || $in_paragraph) {
					$had_attribute_cache = $cell_has_cache;
					$cell_has_cache = true;
					if (in_array($cell_type, ['', 'string'], true) || !$had_attribute_cache) {
						$cell_value = implode("\n", $paragraphs);
					}
				}
				if ($cell_formula && !$cell_has_cache) {
					$missing_formula_cache = true;
					$cell_value = '';
				}
				self::ods_append_cell($row, $last_value, $cell_covered ? '' : $cell_value, $cell_repeat);
				$in_cell = false;
				continue;
			}
			if ($in_row && $reader->namespaceURI === self::ODS_TABLE_NS && $reader->localName === 'table-row' && $reader->depth === $row_depth) {
				$parsed_row = $last_value >= 0 ? array_slice($row, 0, $last_value + 1) : [];
				if ($count_only) {
					$table_has_data = $table_has_data || !self::row_is_empty($parsed_row);
				} else {
					self::ods_append_row($rows, $parsed_row, $row_repeat, $rows_truncated);
				}
				$in_row = false;
				continue;
			}
			if ($in_table && $reader->namespaceURI === self::ODS_TABLE_NS && $reader->localName === 'table' && $reader->depth === $table_depth) {
				if ($table_visible) {
					if ($count_only && $table_has_data) {
						$tables[] = BaraTables_Import_Builder::normalized($name, [''], [], true);
					}
					while (!empty($rows) && self::row_is_empty($rows[count($rows) - 1])) {
						array_pop($rows);
					}
					if (!$count_only && !empty($rows)) {
						$labels = array_shift($rows);
						$warnings = [];
						if ($has_formula) {
							$warnings[] = __('Formula results were imported from cached values; spreadsheet formulas were not executed.', 'baratables');
						}
						if ($missing_formula_cache) {
							$warnings[] = __('Some formula cells had no cached result and were imported as blank cells.', 'baratables');
						}
						if ($has_merge) {
							$warnings[] = __('Merged spreadsheet cells were flattened into ordinary cells.', 'baratables');
						}
						$tables[] = BaraTables_Import_Builder::normalized(
							$name !== '' ? $name : __('Imported worksheet', 'baratables'),
							$labels,
							$rows,
							true,
							[],
							$warnings,
							[],
							$rows_truncated
						);
						$selected_table_found = true;
					}
				}
				$in_table = false;
				$table_visible = false;
				continue;
			}
			if ($current_style !== '' && $reader->namespaceURI === self::ODS_STYLE_NS && $reader->localName === 'style' && $reader->depth === $current_style_depth) {
				$current_style = '';
			}
		}
		$reader->close();
		$valid = empty(libxml_get_errors());
		libxml_clear_errors();
		libxml_use_internal_errors($previous);
		if (!$valid || $too_many_tables) {
			return self::failure(__('The ODS worksheet data could not be parsed safely.', 'baratables'));
		}

		return empty($tables)
			? self::failure(__('The ODS file contained no visible worksheet with table data.', 'baratables'))
			: ['tables' => $tables, 'message' => ''];
	}

	private static function ods_append_cell(array &$row, int &$last_value, string $value, int $repeat): void {
		$repeat = min(max(1, $repeat), self::PARSE_COLUMNS - count($row));
		for ($i = 0; $i < $repeat && count($row) < self::PARSE_COLUMNS; $i++) {
			$row[] = $value;
			if ($value !== '') {
				$last_value = count($row) - 1;
			}
		}
	}

	private static function ods_append_row(array &$rows, array $row, int $repeat, bool &$truncated): void {
		if ($repeat > self::PARSE_ROWS - count($rows)) {
			$truncated = true;
		}
		$repeat = min(max(1, $repeat), self::PARSE_ROWS - count($rows));
		for ($i = 0; $i < $repeat && count($rows) < self::PARSE_ROWS; $i++) {
			$rows[] = $row;
		}
	}

	private static function ods_attribute_value(XMLReader $reader, string $type): string {
		if ($type === 'boolean') {
			$value = $reader->getAttributeNs('boolean-value', self::ODS_OFFICE_NS);
			return $value !== null && BaraTables_Import_Util::to_bool($value) ? 'true' : 'false';
		}
		if ($type === 'date') {
			return str_replace('T00:00:00', '', (string) $reader->getAttributeNs('date-value', self::ODS_OFFICE_NS));
		}
		if ($type === 'time') {
			return (string) $reader->getAttributeNs('time-value', self::ODS_OFFICE_NS);
		}
		if (in_array($type, ['float', 'currency', 'percentage'], true)) {
			return (string) $reader->getAttributeNs('value', self::ODS_OFFICE_NS);
		}
		$value = $reader->getAttributeNs('string-value', self::ODS_OFFICE_NS);
		return $value !== null ? $value : '';
	}

	private static function ods_has_cached_attribute(XMLReader $reader, string $type): bool {
		$attribute = '';
		if ($type === 'boolean') {
			$attribute = 'boolean-value';
		} elseif ($type === 'date') {
			$attribute = 'date-value';
		} elseif ($type === 'time') {
			$attribute = 'time-value';
		} elseif (in_array($type, ['float', 'currency', 'percentage'], true)) {
			$attribute = 'value';
		} elseif ($type === 'string') {
			$attribute = 'string-value';
		}
		return $attribute !== '' && $reader->getAttributeNs($attribute, self::ODS_OFFICE_NS) !== null;
	}

	private static function row_is_empty(array $row): bool {
		foreach ($row as $value) {
			if ((string) $value !== '') {
				return false;
			}
		}
		return true;
	}

	private static function entry(ZipArchive $zip, string $name, bool $optional = false): ?string {
		$index = $zip->locateName($name, ZipArchive::FL_NOCASE);
		if ($index === false) {
			return $optional ? '' : null;
		}
		$stat = $zip->statIndex((int) $index);
		$size = is_array($stat) && isset($stat['size']) ? (int) $stat['size'] : 0;
		if ($size < 0 || $size > self::MAX_XML_BYTES) {
			return null;
		}
		$raw = $zip->getFromIndex((int) $index);
		if (!is_string($raw) || strlen($raw) > self::MAX_XML_BYTES) {
			return null;
		}
		return $raw;
	}

	private static function normalize_xml(string $raw): ?string {
		$is_utf16 = strncmp($raw, "\xFF\xFE", 2) === 0 || strncmp($raw, "\xFE\xFF", 2) === 0;
		if ($is_utf16 && !function_exists('mb_convert_encoding')) {
			return null;
		}
		$normalized = BaraTables_Import_Util::normalize_text($raw);
		// NUL is forbidden in XML 1.0 and is also the telltale byte pattern of BOM-less
		// UTF-16. Reject it rather than letting a declaration evade the UTF-8 safety scan.
		if ($normalized === '' || strpos($normalized, "\0") !== false || stripos($normalized, '<!DOCTYPE') !== false || stripos($normalized, '<!ENTITY') !== false) {
			return null;
		}
		return $normalized;
	}

	private static function reader(string $raw): ?XMLReader {
		$normalized = self::normalize_xml($raw);
		if ($normalized === null) {
			return null;
		}
		$reader = new XMLReader();
		$flags = LIBXML_NONET | (defined('LIBXML_COMPACT') ? LIBXML_COMPACT : 0);
		return $reader->XML($normalized, null, $flags) ? $reader : null;
	}

	private static function finish_reader(XMLReader $reader, bool $previous): bool {
		$reader->close();
		$valid = empty(libxml_get_errors());
		libxml_clear_errors();
		libxml_use_internal_errors($previous);
		return $valid;
	}

	/** @return array{tables:array[],message:string} */
	private static function failure(string $message): array {
		return ['tables' => [], 'message' => $message];
	}
}
