<?php

/**
 * The linked-chart recovery journal.
 *
 * A cancelled table-ID change leaves linked charts pointing at the failed ID; the
 * save path queues them here and the next admin request retries the rollback.
 * Idempotent: a chart an administrator has deliberately re-pointed since is kept.
 */

if (!defined('ABSPATH')) {
	exit;
}

final class BaraTables_Chart_Link_Recovery {

	public const CHART_LINK_RECOVERY_OPTION = 'btbl_chart_link_recovery';

	/** Persist a retry journal for chart-link rollbacks that could not finish in the save request. */
	public static function queue(array $chart_ids, string $failed_id, string $restore_id): ?WP_Error {
		$pending = get_option(self::CHART_LINK_RECOVERY_OPTION, []);
		$pending = is_array($pending) ? $pending : [];
		foreach (array_unique(array_map('intval', $chart_ids)) as $chart_id) {
			if ($chart_id <= 0) {
				continue;
			}
			$pending[$chart_id] = ['from' => $failed_id, 'to' => $restore_id];
		}
		if (empty($pending)) {
			return null;
		}
		if (!add_option(self::CHART_LINK_RECOVERY_OPTION, $pending, '', 'no')) {
			update_option(self::CHART_LINK_RECOVERY_OPTION, $pending);
		}
		return get_option(self::CHART_LINK_RECOVERY_OPTION, []) === $pending
			? null
			: new WP_Error('baratables_chart_recovery_not_queued', __('WordPress could not queue the linked-chart recovery.', 'baratables'));
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
		foreach ($pending as $chart_id => $repair) {
			$chart_id = (int) $chart_id;
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

		if (empty($remaining)) {
			delete_option(self::CHART_LINK_RECOVERY_OPTION);
			$stored = get_option(self::CHART_LINK_RECOVERY_OPTION, null);
			$error = $stored === null ? null : new WP_Error('baratables_chart_recovery_journal_not_cleared', __('WordPress could not clear the completed linked-chart recovery.', 'baratables'));
		} else {
			update_option(self::CHART_LINK_RECOVERY_OPTION, $remaining);
			$error = get_option(self::CHART_LINK_RECOVERY_OPTION, []) === $remaining
				? null
				: new WP_Error('baratables_chart_recovery_journal_not_saved', __('WordPress could not update the linked-chart recovery journal.', 'baratables'));
		}
		return ['recovered' => $recovered, 'remaining' => count($remaining), 'error' => $error];
	}

}
