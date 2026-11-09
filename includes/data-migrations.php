<?php

/**
 * The permanent admin-side data upgrades.
 *
 * Durable, idempotent definition migrations that run under admin_init; one
 * autoloaded schema gate is written only after every table succeeds, so a failed
 * upgrade retries on the next request.
 */

if (!defined('ABSPATH')) {
	exit;
}

final class BaraTables_Data_Migrations {

	public const AUTO_LABEL_MIGRATION_OPTION = 'btbl_auto_label_migrated';
	public const LABEL_I18N_MIGRATION_OPTION = 'btbl_label_i18n_migrated';
	public const DATA_SCHEMA_OPTION = 'btbl_data_schema_version';
	// Generation 1 could be stamped after an unchecked write. Revisit it once through the
	// verified persistence path; only generation 2 proves the complete site scan succeeded.
	private const DATA_MIGRATION_GENERATION = 2;
	/**
	 * The English label defaults that the table-option schema carried before 1.2.3, and that the
	 * editor therefore baked into its input fields as a VALUE and persisted on every save. Any
	 * table saved under 1.0.0-1.2.2 has these literals stored, which is why those six controls
	 * rendered in English on localized sites no matter what the translation said.
	 *
	 * These are deliberately hardcoded English, never __(). The stored value is the untranslated
	 * literal, so the comparison must be too -- running these through __() on a German site would
	 * compare "Suchen:" against a stored "Search:" and match nothing.
	 *
	 * Every published tag (1.0.0 through 1.2.2) shipped exactly this set with exactly these
	 * strings -- verified against baratables-svn/tags/* -- so there is no second generation of
	 * literals to recognise. The other nine frontend_label_defaults() keys always defaulted to
	 * '' in the schema, were never baked in, and so need no migration.
	 */
	private const LEGACY_ENGLISH_LABEL_DEFAULTS = [
		'searchText'           => 'Search:',
		'lengthMenuPrefix'     => 'Show',
		'lengthMenuSuffix'     => 'entries',
		'filtersTitleText'     => 'Filters',
		'searchColumnsLabel'   => 'Columns',
		'searchColumnsHeading' => 'Search in',
	];

	/**
	 * One-time upgrade: clear stored control labels that are byte-identical to the English
	 * defaults the pre-1.2.3 editor baked into its own fields, so localize_frontend_table_labels()
	 * can substitute the translated string instead.
	 *
	 * Blanking the schema defaults in 1.2.3 only fixes tables saved AFTER the upgrade; every table
	 * that already exists still holds the English literal, and a stored value always wins over the
	 * translation. Without this pass the i18n fix reaches no existing table on any site.
	 *
	 * On an English site this is output-identical: the value removed and the value substituted in
	 * its place are the same string. A translated site gets the translation, which is the point.
	 * The one behaviour change worth naming: an admin who deliberately typed the English word back
	 * in is indistinguishable from the baked default, so their table starts following the locale
	 * too -- correct for the overwhelming majority, and re-typing it is not how you pin a label
	 * anyway (a value that differs by even one character is left untouched).
	 *
	 * Idempotent and gated by an option; safe to call on every admin load.
	 */
	public static function run(): ?WP_Error {
		if ((int) get_option(self::DATA_SCHEMA_OPTION, 0) >= self::DATA_MIGRATION_GENERATION) {
			return null;
		}

		$add_auto_labels = (int) get_option(self::AUTO_LABEL_MIGRATION_OPTION, 0) < self::DATA_MIGRATION_GENERATION;
		$clear_legacy_labels = (int) get_option(self::LABEL_I18N_MIGRATION_OPTION, 0) < self::DATA_MIGRATION_GENERATION;
		foreach (($add_auto_labels || $clear_legacy_labels) ? self::get_migration_table_ids() : [] as $id) {
			$defn = get_post_meta((int) $id, BaraTables_Repository::META_KEY, true);
			if (!is_array($defn)) {
				continue;
			}
			$changed = false;
			if ($add_auto_labels && !empty($defn['columns']) && is_array($defn['columns'])) {
				foreach ($defn['columns'] as &$column) {
					if (!is_array($column) || array_key_exists('auto_label', $column)) {
						continue;
					}
					$column['auto_label'] = self::legacy_column_is_auto($column);
					$changed = true;
				}
				unset($column);
			}
			if ($clear_legacy_labels && !empty($defn['table_options']) && is_array($defn['table_options'])) {
				foreach (self::LEGACY_ENGLISH_LABEL_DEFAULTS as $key => $legacy_english) {
					if (!array_key_exists($key, $defn['table_options'])) {
						continue;
					}
					// Compare the raw stored scalar. No trim() and no case folding: a label the admin
					// altered even by a space is theirs, and clearing it would discard their wording.
					$stored = $defn['table_options'][$key];
					if (!is_string($stored) || $stored !== $legacy_english) {
						continue;
					}
					$defn['table_options'][$key] = '';
					$changed = true;
				}
			}
			if ($changed) {
				$error = BaraTables_Base_Repository::persist_definition((int) $id, BaraTables_Repository::META_KEY, $defn);
				if ($error) {
					return $error;
				}
			}
		}

		update_option(self::DATA_SCHEMA_OPTION, self::DATA_MIGRATION_GENERATION, true);
		if ((int) get_option(self::DATA_SCHEMA_OPTION, 0) !== self::DATA_MIGRATION_GENERATION) {
			return new WP_Error('baratables_migration_not_completed', __('WordPress could not record that the BaraTables data upgrade completed.', 'baratables'));
		}
		delete_option(self::AUTO_LABEL_MIGRATION_OPTION);
		delete_option(self::LABEL_I18N_MIGRATION_OPTION);
		return null;
	}

	/** @return int[] Every table status, including Trash, auto-drafts and custom workflow states. */
	private static function get_migration_table_ids(): array {
		$ids = array_map('intval', get_posts([
			'post_type' => BaraTables_Repository::CPT,
			'post_status' => array_values(get_post_stati()),
			'numberposts' => -1, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- A migration cannot mark itself complete until every table was visited.
			'fields' => 'ids',
			'no_found_rows' => true,
		]));
		if (!empty($ids)) {
			_prime_post_caches($ids, false, true);
		}
		return $ids;
	}

	private static function legacy_column_is_auto(array $column): bool {
		if (($column['source'] ?? '') !== 'custom') {
			return false;
		}
		$key = (string) ($column['key'] ?? '');
		if (!preg_match('/^col_(\d+)$/', $key, $match)) {
			return false;
		}
		$label = (string) ($column['label'] ?? '');
		return $label === '' || $label === sprintf('Column %d', (int) $match[1]);
	}

}
