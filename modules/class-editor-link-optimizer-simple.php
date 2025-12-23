<?php
/**
 * MiniLoad Editor Link Optimizer (Simplified)
 * Enhances WordPress editor link search with product results
 *
 * @package MiniLoad
 * @since 1.0.0
 */

namespace MiniLoad\Modules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Editor_Link_Optimizer {

	/**
	 * Constructor
	 */
	public function __construct() {
		// Only run if enabled
		if ( ! get_option( 'miniload_editor_link_enabled', false ) ) {
			return;
		}

		// Hook into WordPress's link query
		add_filter( 'wp_link_query', array( $this, 'add_product_results' ), 10, 2 );

		// Optimize the query args
		add_filter( 'wp_link_query_args', array( $this, 'optimize_query_args' ) );
	}

	/**
	 * Add product results to link search
	 */
	public function add_product_results( $results, $query ) {
		// Only process if there's a search term
		if ( empty( $query['s'] ) || ! class_exists( 'WooCommerce' ) ) {
			return $results;
		}

		global $wpdb;
		$search_term = $query['s'];
		$search_table = $wpdb->prefix . 'miniload_search_index';
		$escaped_search_table = esc_sql( $search_table );

		// Check if our search table exists
		// Direct database query with caching
		$cache_key = 'miniload_' . md5(  "SHOW TABLES LIKE '$search_table'"  );
		$cached = wp_cache_get( $cache_key );
		if ( false === $cached ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Required for performance optimization
			$cached = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $search_table ) );
			wp_cache_set( $cache_key, $cached, '', 3600 );
		}
		$table_exists = $cached;
		if ( ! $table_exists ) {
			return $results;
		}

		// Search products using our indexed table
		// Direct database query with caching
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is escaped with esc_sql
		$cache_key = 'miniload_' . md5(  $wpdb->prepare( "
			SELECT
				p.ID,
				p.post_title,
				p.post_type
			FROM {$wpdb->posts} p
			INNER JOIN {$escaped_search_table} ps ON p.ID = ps.product_id
			WHERE p.post_status = 'publish'
			AND p.post_type = 'product'
			AND (
				p.post_title LIKE %s
				OR ps.sku = %s
				OR MATCH(ps.search_text) AGAINST(%s IN BOOLEAN MODE)
			)
			ORDER BY
				CASE
					WHEN ps.sku = %s THEN 1
					WHEN p.post_title = %s THEN 2
					WHEN p.post_title LIKE %s THEN 3
					ELSE 4
				END
			LIMIT 10
		",
			'%' . $wpdb->esc_like( $search_term ) . '%',
			$search_term,
			$search_term,
			$search_term,
			$search_term,
			$search_term . '%'
		)  );
		$cached = wp_cache_get( $cache_key );
		if ( false === $cached ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is properly escaped above
			$cached = $wpdb->get_results( $wpdb->prepare( "
			SELECT
				p.ID,
				p.post_title,
				p.post_type
			FROM {$wpdb->posts} p
			INNER JOIN {$escaped_search_table} ps ON p.ID = ps.product_id
			WHERE p.post_status = 'publish'
			AND p.post_type = 'product'
			AND (
				p.post_title LIKE %s
				OR ps.sku = %s
				OR MATCH(ps.search_text) AGAINST(%s IN BOOLEAN MODE)
			)
			ORDER BY
				CASE
					WHEN ps.sku = %s THEN 1
					WHEN p.post_title = %s THEN 2
					WHEN p.post_title LIKE %s THEN 3
					ELSE 4
				END
			LIMIT 10
		",
			'%' . $wpdb->esc_like( $search_term ) . '%',
			$search_term,
			$search_term,
			$search_term,
			$search_term,
			$search_term . '%'
		) );
			wp_cache_set( $cache_key, $cached, '', 3600 );
		}

		// Format product results for link dialog
		if ( $product_results ) {
			$formatted_products = array();

			foreach ( $product_results as $product ) {
				$product_obj = wc_get_product( $product->ID );
				if ( ! $product_obj ) {
					continue;
				}

				$sku = $product_obj->get_sku();

				// Build info string
				$info_parts = array( 'Product' );
				if ( $sku ) {
					$info_parts[] = $sku;
				}

				$formatted_products[] = array(
					'ID' => $product->ID,
					'title' => $product->post_title,
					'permalink' => get_permalink( $product->ID ),
					'info' => implode( ' | ', $info_parts )
				);
			}

			// Prepend products to existing results
			if ( ! empty( $formatted_products ) ) {
				// Remove any duplicate products from existing results
				$product_ids = wp_list_pluck( $formatted_products, 'ID' );
				$filtered_results = array();

				foreach ( $results as $result ) {
					if ( ! in_array( $miniload_result['ID'], $product_ids ) ) {
						$filtered_results[] = $miniload_result;
					}
				}

				// Combine: products first, then other results
				$results = array_merge( $formatted_products, $filtered_results );

				// Limit total results
				$results = array_slice( $results, 0, 20 );
			}
		}

		return $results;
	}

	/**
	 * Optimize query arguments
	 */
	public function optimize_query_args( $args ) {
		// Limit results for better performance
		$args['posts_per_page'] = 10;

		// Skip counting for pagination
		$args['no_found_rows'] = true;

		// Skip meta caching
		$args['update_post_meta_cache'] = false;
		$args['update_post_term_cache'] = false;

		return $args;
	}
}