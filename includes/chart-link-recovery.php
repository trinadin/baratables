<?php

/**
 * The linked-chart recovery journal.
 *
 * A cancelled table-ID change leaves linked charts pointing at the failed ID; the
 * save path queues them here and the next admin request retries the rollback.
 * Idempotent: a chart an administrator has deliberately re-pointed since is kept.
 *
 * The journal is one option row, so two requests can write it at the same time.
 * Every write therefore merges inside update_option(): the value another request
 * stored in the meantime is read again and kept, so a queued entry is never lost
 * to a concurrent retry. Two requests that queue an entry at the very same
 * moment can still race on the row's creation, so the row is created with
 * add_option(), which refuses to write over a row that already exists.
 */

if (!defined('ABSPATH')) {
	exit;
}

final class BaraTables_Chart_Link_Recovery {

	public const CHART_LINK_RECOVERY_OPTION = 'btbl_chart_link_recovery';

	/** Persist a retry journal for chart-link rollbacks that could not finish in the save request. */
	public static function queue(array $chart_ids, string $failed_id, string $restore_id): ?WP_Error {
		$entries = [];
		foreach (array_unique(array_map('intval', $chart_ids)) as $chart_id) {
			if ($chart_id <= 0) {
				continue;
			}
			$entries[$chart_id] = ['from' => $failed_id, 'to' => $restore_id];
		}
		if (empty($entries)) {
			return null;
		}

		self::write_merged($entries);

		if (!self::contains_entries(self::current_entries(), $entries)) {
			return new WP_Error('baratables_chart_recovery_not_queued', __('WordPress could not queue the linked-chart recovery.', 'baratables'));
		}
		return null;
	}

	/**
	 * Retry every pending chart-link rollback. The operation is idempotent and preserves a chart
	 * that an administrator has deliberately pointed somewhere else since the failed rename.
	 *
	 * @return array{recovered:int,remaining:int,error:?WP_Error}
	 */
	public static function retry(): array {
		$pending = get_option(self::CHART_LINK_RECOVERY_OPTION, []);
		if (!is_array($pending) || empty($pending)) {
			return ['recovered' => 0, 'remaining' => 0, 'error' => null];
		}
		$recovered = 0;
		$remaining = [];
		$processed = [];
		foreach ($pending as $chart_id => $repair) {
			$chart_id = (int) $chart_id;
			// The entry this pass handled. It is kept so a different entry for the
			// same chart -- queued while this pass was running -- is not mistaken
			// for the one that was just processed.
			$processed[$chart_id] = $repair;
			$chart = get_post_meta($chart_id, BaraTables_Chart_Repository::META_KEY, true);
			$from = is_array($repair) ? (string) ($repair['from'] ?? '') : '';
			$to = is_array($repair) ? (string) ($repair['to'] ?? '') : '';
			if (!is_array($chart) || $from === '' || $to === '') {
				$recovered++;
				continue;
			}
			$current = (string) ($chart['table_id'] ?? '');
			if ($current === $to || $current !== $from) {
				$recovered++;
				continue;
			}
			$chart['table_id'] = $to;
			if (BaraTables_Base_Repository::persist_definition($chart_id, BaraTables_Chart_Repository::META_KEY, $chart)) {
				$remaining[$chart_id] = $repair;
				continue;
			}
			$recovered++;
		}

		// The write-back merges: an entry another request queued after this one read
		// the journal is kept, so a concurrent queue() is never lost to this retry.
		$merge = static function ($value, $old_value) use ($remaining, $processed) {
			return self::merge_remaining($remaining, $processed, is_array($value) ? $value : []);
		};
		add_filter('pre_update_option_' . self::CHART_LINK_RECOVERY_OPTION, $merge, 10, 2);
		$error = null;
		try {
			if (empty($remaining) && empty(self::queued_after($processed))) {
				$error = self::clear_journal($processed);
			} else {
				update_option(self::CHART_LINK_RECOVERY_OPTION, $remaining, false);
				$error = self::contains_entries(self::current_entries(), $remaining)
					? null
					: new WP_Error('baratables_chart_recovery_journal_not_saved', __('WordPress could not update the linked-chart recovery journal.', 'baratables'));
			}
		} finally {
			remove_filter('pre_update_option_' . self::CHART_LINK_RECOVERY_OPTION, $merge, 10);
		}
		// The entries still awaiting recovery are the ones the journal holds now: the
		// entries this pass could not repair plus the ones another request queued
		// while it was running.
		return ['recovered' => $recovered, 'remaining' => count(self::current_entries()), 'error' => $error];
	}

	/**
	 * Merge entries into the journal and write it.
	 *
	 * The merge runs inside update_option(), so the journal another request stored
	 * while this one was working is read again and kept instead of overwritten.
	 *
	 * @param array<int,array{from:string,to:string}> $entries The entries to add.
	 */
	private static function write_merged(array $entries): void {
		$merge = static function ($value, $old_value) use ($entries) {
			// array_replace(), not array_merge(): the journal is keyed by post ID, and
			// array_merge() renumbers integer keys, which would lose the chart each
			// entry belongs to.
			$merged = array_replace(self::current_entries(), is_array($value) ? $value : []);
			return array_replace($merged, $entries);
		};
		add_filter('pre_update_option_' . self::CHART_LINK_RECOVERY_OPTION, $merge, 10, 2);
		try {
			// The row may not exist yet. add_option() refuses to write an option that
			// already exists, so a row another request created in the meantime is not
			// overwritten -- the write below merges into it instead. Its "does not
			// exist" cache entry is cleared first, so that check reads the database
			// instead of trusting a cache entry that still says the row is absent.
			wp_cache_delete('notoptions', 'options');
			add_option(
				self::CHART_LINK_RECOVERY_OPTION,
				array_replace(self::current_entries(), $entries),
				'',
				false
			);
			update_option(self::CHART_LINK_RECOVERY_OPTION, $entries, false);
			if (!self::contains_entries(self::current_entries(), $entries)) {
				// Our entries are not in the journal: another request wrote over
				// them. Write once more, now that the row is there.
				update_option(self::CHART_LINK_RECOVERY_OPTION, $entries, false);
			}
		} finally {
			remove_filter('pre_update_option_' . self::CHART_LINK_RECOVERY_OPTION, $merge, 10);
		}
	}

	/**
	 * The journal as it is stored right now.
	 *
	 * The option cache is dropped first, so the read sees what another request
	 * committed instead of what this request cached before that request wrote.
	 */
	private static function current_entries(): array {
		wp_cache_delete(self::CHART_LINK_RECOVERY_OPTION, 'options');
		wp_cache_delete('notoptions', 'options');
		$current = get_option(self::CHART_LINK_RECOVERY_OPTION, []);
		return is_array($current) ? $current : [];
	}

	/**
	 * The journal entries that were queued after this pass read the journal.
	 *
	 * @param array<int,mixed> $processed The entries this pass handled.
	 * @return array<int,mixed>
	 */
	private static function queued_after(array $processed): array {
		$queued = [];
		foreach (self::current_entries() as $chart_id => $repair) {
			$id = (int) $chart_id;
			if (!isset($processed[$id]) || $processed[$id] !== $repair) {
				$queued[$chart_id] = $repair;
			}
		}
		return $queued;
	}

	/**
	 * The journal this pass wants to keep: the entries it could not repair, plus
	 * every entry another request queued after this pass read the journal.
	 *
	 * @param array<int,mixed> $remaining The entries this pass could not repair.
	 * @param array<int,mixed> $processed The entries this pass handled.
	 * @param array<int,mixed> $incoming The value the pending update carries.
	 * @return array<int,mixed>
	 */
	private static function merge_remaining(array $remaining, array $processed, array $incoming = []): array {
		$merged = $remaining;
		foreach ($incoming as $chart_id => $repair) {
			$merged[$chart_id] = $repair;
		}
		foreach (self::current_entries() as $chart_id => $repair) {
			$id = (int) $chart_id;
			if (!isset($processed[$id]) || $processed[$id] !== $repair) {
				$merged[$chart_id] = $repair;
			}
		}
		return $merged;
	}

	/**
	 * Remove a journal whose entries were all recovered.
	 *
	 * An entry another request queued while this one was working is kept: the
	 * journal is captured before the row is removed, and a captured entry is
	 * written back afterwards.
	 *
	 * @param array<int,mixed> $processed The entries this pass handled.
	 */
	private static function clear_journal(array $processed): ?WP_Error {
		$preserved = [];
		$capture = static function ($option) use (&$preserved, $processed) {
			if ($option !== self::CHART_LINK_RECOVERY_OPTION) {
				return;
			}
			foreach (self::current_entries() as $chart_id => $repair) {
				$id = (int) $chart_id;
				if (!isset($processed[$id]) || $processed[$id] !== $repair) {
					$preserved[$chart_id] = $repair;
				}
			}
		};
		add_action('delete_option', $capture, 10, 1);
		try {
			delete_option(self::CHART_LINK_RECOVERY_OPTION);
		} finally {
			remove_action('delete_option', $capture, 10);
		}
		if (!empty($preserved)) {
			// An entry was queued while the journal was being cleared: put it back
			// instead of losing it.
			self::write_merged($preserved);
		}
		$stored = get_option(self::CHART_LINK_RECOVERY_OPTION, null);
		if ($stored === null || $stored === false) {
			return null;
		}
		$stored = is_array($stored) ? $stored : [];
		return self::same_entries($stored, $preserved)
			? null
			: new WP_Error('baratables_chart_recovery_journal_not_cleared', __('WordPress could not clear the completed linked-chart recovery.', 'baratables'));
	}

	/**
	 * Whether every expected entry is stored with the expected value.
	 *
	 * @param array<int,mixed> $current The stored journal.
	 * @param array<int,mixed> $expected The entries that have to be there.
	 */
	private static function contains_entries(array $current, array $expected): bool {
		foreach ($expected as $chart_id => $entry) {
			if (!array_key_exists($chart_id, $current) || $current[$chart_id] !== $entry) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether two journals hold the same entries.
	 *
	 * @param array<int,mixed> $current The stored journal.
	 * @param array<int,mixed> $expected The entries that have to be there.
	 */
	private static function same_entries(array $current, array $expected): bool {
		return self::contains_entries($current, $expected) && self::contains_entries($expected, $current);
	}

}
