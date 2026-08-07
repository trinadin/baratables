<?php

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Field + taxonomy discovery for the post types, taxonomies and meta fields offered by the editor.
 */
final class BaraTables_Fields_Discovery {
	private BaraTables_Query_Sanitizer $query_sanitizer;

	public function __construct(BaraTables_Query_Sanitizer $query_sanitizer) {
		$this->query_sanitizer = $query_sanitizer;
	}
	public function get_supported_post_types(): array {
		$pts = get_post_types(['public' => true], 'objects');
		$out = [];
		foreach ($pts as $pt) {
			$out[$pt->name] = $pt->labels->singular_name;
		}
		return $out;
	}

	/**
	 * @param string $post_type
	 * @param array  $include_term_ids Term ids that must appear even if they fall outside the cap
	 *                                 (i.e. the terms already selected on this table).
	 */
	public function get_taxonomies_for_post_type(string $post_type, array $include_term_ids = []): array {
		$taxonomies = get_object_taxonomies($post_type, 'objects');
		$include_term_ids = array_values(array_unique(array_filter(array_map('intval', $include_term_ids))));
		// Resolve the already-selected ids once, for every taxonomy at a time. $include_term_ids is
		// a flat union across all taxonomies, so querying it per taxonomy meant one guaranteed-empty
		// term query for each taxonomy that does not own them.
		$selected_by_tax = [];
		if (!empty($include_term_ids)) {
			$selected_terms = get_terms([
				'hide_empty' => false,
				'include'    => $include_term_ids,
			]);
			if (!is_wp_error($selected_terms)) {
				foreach ($selected_terms as $selected_term) {
					$selected_by_tax[$selected_term->taxonomy][(int) $selected_term->term_id] = $selected_term->name;
				}
			}
		}
		$out = [];
		foreach ($taxonomies as $tax_obj) {
			if (!$tax_obj->show_ui) {
				continue;
			}
			// 'id=>name' returns a lightweight [id => name] map instead of hydrating a full WP_Term
			// object per term (the picker only needs id + name); much cheaper on large taxonomies.
			//
			// 'number' caps the fetch. This query was previously unbounded, so a site with a large
			// post_tag (tens of thousands of terms) fetched and rendered every one of them as a
			// chip -- megabytes of extra editor HTML and tens of thousands of DOM nodes on every
			// load of the table editor. Terms already selected on this table are fetched separately
			// and merged in, so a selection can never silently disappear because of the cap.
			//
			// 'hierarchical' => false is what actually makes 'number' a SQL LIMIT. WP_Term_Query
			// defaults it to true, and it skips the LIMIT entirely for a hierarchical taxonomy
			// (category, product_cat), applying 'number' with array_slice() only after selecting and
			// hydrating every row. Without this the cap did nothing at all for exactly the
			// taxonomies most likely to be huge. It changes no output here because 'hide_empty' is
			// false, which is the only thing the hierarchical path would have used it for.
			$terms = get_terms([
				'taxonomy'     => $tax_obj->name,
				'hide_empty'   => false,
				'hierarchical' => false,
				'fields'       => 'id=>name',
				'number'       => BaraTables_Service::MAX_TERM_PICKER_TERMS,
				'orderby'      => 'name',
				'order'        => 'ASC',
			]);
			$term_map = is_wp_error($terms) ? [] : $terms;
			$truncated = count($term_map) >= BaraTables_Service::MAX_TERM_PICKER_TERMS;

			if (!empty($selected_by_tax[$tax_obj->name])) {
				$term_map = $term_map + $selected_by_tax[$tax_obj->name];
			}

			$term_items = [];
			foreach ($term_map as $term_id => $term_name) {
				$term_items[] = [
					'id'   => (int) $term_id,
					'name' => $term_name,
				];
			}
			$out[] = [
				'slug'      => $tax_obj->name,
				'label'     => $tax_obj->labels->singular_name,
				'terms'     => $term_items,
				'truncated' => $truncated,
			];
		}

		usort($out, static function ($a, $b) {
			return strcasecmp((string) $a['label'], (string) $b['label']);
		});

		return $out;
	}

	public function get_taxonomies_for_post_types(array $post_types, array $include_term_ids = []): array {
		$post_types = $this->query_sanitizer->sanitize_public_post_types($post_types, true);
		$combined = [];
		foreach ($post_types as $pt) {
			$items = $this->get_taxonomies_for_post_type($pt, $include_term_ids);
			foreach ($items as $item) {
				$slug = $item['slug'];
				if (!isset($combined[$slug])) {
					$item['sources'] = [$pt];
					$combined[$slug] = $item;
				} else {
					$combined[$slug]['sources'][] = $pt;
				}
			}
		}
		$merged = array_values($combined);
		usort($merged, static function ($a, $b) {
			return strcasecmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? ''));
		});
		return $merged;
	}

	public function get_available_fields(string $post_type): array {
		$core_fields = [
			'ID'            => __('ID', 'baratables'),
			'post_title'    => __('Title', 'baratables'),
			'post_excerpt'  => __('Excerpt', 'baratables'),
			'post_content'  => __('Content', 'baratables'),
			'post_date'     => __('Published date', 'baratables'),
			'post_modified' => __('Modified date', 'baratables'),
			'post_author'   => __('Author', 'baratables'),
			'post_status'   => __('Status', 'baratables'),
			'permalink'     => __('Permalink', 'baratables'),
		];

		$wc_allowed_meta = [];
		if ($post_type === 'product' && class_exists('WooCommerce')) {
			$wc_allowed_meta = [
				'_price',
				'_regular_price',
				'_sale_price',
				'_sale_price_dates_from',
				'_sale_price_dates_to',
				'_sku',
				'_stock',
				'_stock_status',
				'_manage_stock',
				'_backorders',
				'total_sales',
				'_tax_class',
				'_weight',
				'_length',
				'_width',
				'_height',
				'_virtual',
				'_downloadable',
				'_product_image_gallery',
				'_thumbnail_id',
				'_product_url',
				'_button_text',
			];
		}

		$cache_key = 'available_meta_keys_' . md5($post_type);
		$meta_keys = wp_cache_get($cache_key, 'baratables');
		if (!is_array($meta_keys)) {
			$meta_key_map = [];
			$post_ids = get_posts([
				'post_type' => $post_type,
				'post_status' => 'any',
				'posts_per_page' => 50,
				'fields' => 'ids',
				'no_found_rows' => true,
				'orderby' => 'modified',
				'order' => 'DESC',
				// NOT 'update_post_meta_cache' => true: WP_Query's `fields => 'ids'` branch returns
				// before it primes anything (class-wp-query.php returns at the top of that branch,
				// ahead of every _prime_post_caches/update_post_caches call), so the flag was a
				// no-op and each get_post_meta() below was its own query -- 52 queries for 50 posts,
				// on every editor load and every field-refresh AJAX call. Prime explicitly instead.
				'update_post_term_cache' => false,
			]);
			if (!empty($post_ids)) {
				update_meta_cache('post', $post_ids);
			}
			foreach ($post_ids as $post_id) {
				$post_meta = get_post_meta((int) $post_id);
				if (!is_array($post_meta)) {
					continue;
				}
				foreach (array_keys($post_meta) as $meta_key) {
					$meta_key = (string) $meta_key;
					if ($meta_key === '' || strpos($meta_key, '_') === 0) {
						continue;
					}
					$meta_key_map[$meta_key] = true;
					if (count($meta_key_map) >= 50) {
						break 2;
					}
				}
			}
			$meta_keys = array_keys($meta_key_map);
			natcasesort($meta_keys);
			$meta_keys = array_values($meta_keys);
			wp_cache_set($cache_key, $meta_keys, 'baratables', 5 * MINUTE_IN_SECONDS);
		}

		$meta_keys = array_map('strval', (array) $meta_keys);

		if (!empty($wc_allowed_meta)) {
			$meta_keys = array_values(array_unique(array_merge($meta_keys, $wc_allowed_meta)));
		}
		natcasesort($meta_keys);
		$meta_keys = array_values($meta_keys);

		$tax_fields = [];
		$tax_objects = get_object_taxonomies($post_type, 'objects');
		foreach ($tax_objects as $tax_obj) {
			if (!$tax_obj->show_ui) {
				continue;
			}
			$tax_fields[$tax_obj->name] = $tax_obj->labels->singular_name;
		}

		return [
			'core' => $core_fields,
			'meta' => $meta_keys,
			'tax'  => $tax_fields,
		];
	}

	public function get_available_fields_for_post_types(array $post_types): array {
		$post_types = $this->query_sanitizer->sanitize_public_post_types($post_types, true);
		$core = [];
		$meta = [];
		$meta_sources = [];
		$tax = [];
		$tax_sources = [];
		foreach ($post_types as $idx => $pt) {
			$fields = $this->get_available_fields($pt);
			if ($idx === 0) {
				$core = $fields['core'];
			}
			foreach ($fields['meta'] as $meta_key) {
				$meta[] = $meta_key;
				$meta_sources[$meta_key][] = $pt;
			}
			foreach ($fields['tax'] as $slug => $label) {
				if (!isset($tax[$slug])) {
					$tax[$slug] = $label;
				}
				$tax_sources[$slug][] = $pt;
			}
		}
		$meta = array_values(array_unique($meta));
		return [
			'core' => $core,
			'meta' => $meta,
			'tax'  => $tax,
			'meta_sources' => $meta_sources,
			'tax_sources' => $tax_sources,
		];
	}

}
