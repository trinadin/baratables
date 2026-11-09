<?php

/**
 * The Additional CSS scoper, extracted from BaraTables_Service.
 *
 * Scope user-authored Additional CSS to one table by prefixing the wrapper
 * selector onto every top-level rule; malformed CSS passes through as-is.
 */

if (!defined('ABSPATH')) {
	exit;
}

final class BaraTables_Css_Scoper {

	/** The element the scope is rooted on; user selectors may name it themselves. */
	private const WRAPPER_SELECTOR = '.btbl-table-wrapper';

	/**
	 * Scope user-authored Additional CSS to one table. The Design tab invites plain
	 * selectors ("td { ... }"), so every top-level rule gets the table's wrapper scope
	 * prefixed onto each of its selectors, and @media/@supports bodies are scoped too,
	 * while @keyframes steps and selector-less at-rules (@font-face, @import) pass through
	 * untouched. An exact match on the table's own base id scopes the rules to that
	 * table, matching the Copy CSS convention. Best effort by design: malformed CSS
	 * passes through as-is, and sanitize_custom_css() has already stripped anything
	 * that could escape the sink.
	 */
	public static function scope_table_css(string $css, string $table_id): string {
		// Same normalization the front end applies to the base id it prints on the wrapper, so
		// the selector and the attribute can never disagree.
		$table_id = sanitize_html_class(sanitize_title($table_id));
		if ($table_id === '' || trim($css) === '') {
			return $css;
		}
		// An EXACT match on the base id. A prefix match ("[data-table-id^=...]") also matched a
		// sibling table whose slug merely started with the same string -- table "sales" styled
		// table "sales-2024" on the same page.
		$scope = self::WRAPPER_SELECTOR . '[data-btbl-table-id="' . $table_id . '"]';
		$index = 0;
		return self::scope_css_segment($css, $index, $scope, true);
	}

	/** Copies a `/* ... *\/` comment verbatim; the index moves past it. */
	private static function copy_css_comment(string $css, int &$index): string {
		$end = strpos($css, '*/', $index + 2);
		$end = $end === false ? strlen($css) - 1 : $end + 1;
		$comment = substr($css, $index, $end - $index + 1);
		$index = $end + 1;
		return $comment;
	}

	/** Copies a quoted string verbatim, honoring backslash escapes; the index moves past it. */
	private static function copy_css_string(string $css, int &$index): string {
		$quote = $css[$index];
		$length = strlen($css);
		$j = $index + 1;
		while ($j < $length) {
			if ($css[$j] === '\\') {
				$j += 2;
				continue;
			}
			if ($css[$j] === $quote) {
				break;
			}
			$j++;
		}
		$j = min($j + 1, $length);
		$fragment = substr($css, $index, $j - $index);
		$index = $j;
		return $fragment;
	}

	/**
	 * One nesting level of the CSS scoper. Reads preludes and blocks until the enclosing
	 * block's closing brace (left unconsumed for the caller) or the end of input.
	 */
	private static function scope_css_segment(string $css, int &$index, string $scope, bool $scoping): string {
		$out = '';
		$length = strlen($css);
		while ($index < $length) {
			if ($css[$index] === '}') {
				return $out;
			}
			if (substr($css, $index, 2) === '/*') {
				$out .= self::copy_css_comment($css, $index);
				continue;
			}
			$prelude = '';
			$closed = false;
			while ($index < $length) {
				$char = $css[$index];
				if ($char === '"' || $char === "'") {
					$prelude .= self::copy_css_string($css, $index);
					continue;
				}
				if (substr($css, $index, 2) === '/*') {
					$prelude .= self::copy_css_comment($css, $index);
					continue;
				}
				if ($char === '{') {
					$index++;
					$closed = true;
					break;
				}
				if ($char === ';') {
					// Semicolon-terminated prelude: an @import or similar; emit verbatim.
					$out .= $prelude . $char;
					$prelude = '';
					$index++;
					continue;
				}
				if ($char === '}') {
					$out .= $prelude;
					$prelude = '';
					return $out;
				}
				$prelude .= $char;
				$index++;
			}
			if ($prelude === '' && !$closed) {
				break;
			}
			$is_at_rule = preg_match('/^\s*@/', $prelude) === 1;
			$at_name = $is_at_rule && preg_match('/@([-a-z]+)/i', $prelude, $m) ? strtolower($m[1]) : '';
			// Emitted blocks normalize to "prelude { body }" regardless of the input's
			// spacing, so the printed CSS reads consistently.
			$prelude = trim($prelude);
			if ($at_name === 'keyframes' || $at_name === '-webkit-keyframes') {
				// Animation steps are percentage selectors; copying the body verbatim keeps
				// them that way.
				$body = self::scope_css_segment($css, $index, $scope, false);
				self::consume_css_brace($css, $index);
				$out .= $prelude . ' {' . $body . '}';
			} elseif ($is_at_rule) {
				// @media, @supports and friends re-nest rules: scope their contents.
				$body = self::scope_css_segment($css, $index, $scope, $scoping);
				self::consume_css_brace($css, $index);
				$out .= $prelude . ' {' . $body . '}';
			} elseif ($scoping) {
				$out .= self::scope_selector_list($prelude, $scope) . ' {' . self::copy_css_block($css, $index) . '}';
			} else {
				$out .= $prelude . ' {' . self::copy_css_block($css, $index) . '}';
			}
		}
		return $out;
	}

	/**
	 * Consumes the closing brace the segment reader stopped at.
	 *
	 * The segment reader leaves its own block's closing brace for the caller. Leaving
	 * that brace in place made the enclosing loop read it as the end of its own block,
	 * which ended the scope there: every rule after an @media, @supports or
	 * @keyframes block was dropped.
	 */
	private static function consume_css_brace(string $css, int &$index): void {
		$length = strlen($css);
		if ($index < $length && $css[$index] === '}') {
			$index++;
		}
	}

	/** Copies a declaration block verbatim (strings honored) through its closing brace. */
	private static function copy_css_block(string $css, int &$index): string {
		$body = '';
		$length = strlen($css);
		while ($index < $length) {
			$char = $css[$index];
			if ($char === '"' || $char === "'") {
				$body .= self::copy_css_string($css, $index);
				continue;
			}
			if ($char === '}') {
				$index++;
				return $body;
			}
			$body .= $char;
			$index++;
		}
		return $body;
	}

	/** Prefixes every selector in a comma list (paren- and string-aware) with the scope. */
	private static function scope_selector_list(string $prelude, string $scope): string {
		$selectors = [];
		$current = '';
		$depth = 0;
		$length = strlen($prelude);
		for ($i = 0; $i < $length; $i++) {
			$char = $prelude[$i];
			if ($char === '"' || $char === "'") {
				$current .= self::copy_css_string($prelude, $i);
				continue;
			}
			if ($char === '(') {
				$depth++;
			} elseif ($char === ')') {
				$depth = max(0, $depth - 1);
			} elseif ($char === ',' && $depth === 0) {
				$selectors[] = $current;
				$current = '';
				continue;
			}
			$current .= $char;
		}
		$selectors[] = $current;
		$scoped = [];
		foreach ($selectors as $selector) {
			$selector = trim($selector);
			if ($selector !== '') {
				$scoped[] = self::scope_selector($selector, $scope);
			}
		}
		return $scoped === [] ? $scope : implode(', ', $scoped);
	}

	/**
	 * Scopes one selector to the table.
	 *
	 * A selector that already names the wrapper is scoped by swapping that name for
	 * the scoped one. Prefixing it instead would ask for a wrapper inside the
	 * wrapper, which never exists, so the rule could never match.
	 */
	private static function scope_selector(string $selector, string $scope): string {
		$wrapper = self::WRAPPER_SELECTOR;
		if (strpos($selector, $wrapper) !== 0) {
			return $scope . ' ' . $selector;
		}
		$rest = substr($selector, strlen($wrapper));
		// A different class that merely starts with the same name.
		if ($rest !== '' && preg_match('/^[A-Za-z0-9_-]/', $rest) === 1) {
			return $scope . ' ' . $selector;
		}
		return $scope . $rest;
	}

}
