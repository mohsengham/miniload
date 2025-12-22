<?php
/**
 * MiniLoad Editor Link Optimizer
 * Intercepts WordPress editor link search to use our optimized product search
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
	 * Product search table
	 */
	private $search_table;

	/**
	 * Constructor
	 */
	public function __construct() {
		global $wpdb;
		$this->search_table = $wpdb->prefix . 'miniload_product_search';

		// Only run if enabled
		if ( ! get_option( 'miniload_editor_link_enabled', false ) ) {
			return;
		}

		// Filter the link query for products
		add_filter( 'wp_link_query_args', array( $this, 'optimize_link_query_args' ), 10, 1 );
		add_filter( 'wp_link_query', array( $this, 'replace_with_product_search' ), 10, 2 );

		// Add JavaScript to enhance editor experience
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_editor_enhancements' ) );
	}


	/**
	 * Check if we should search products
	 */
	private function should_search_products( $search_term ) {
		// Search products if:
		// 1. WooCommerce is active
		// 2. User has capability to edit products
		// 3. Search term doesn't explicitly exclude products

		if ( ! class_exists( 'WooCommerce' ) ) {
			return false;
		}

		if ( ! current_user_can( 'edit_products' ) ) {
			return false;
		}

		// Allow filtering
		return apply_filters( 'miniload_link_search_products', true, $search_term );
	}

	/**
	 * Search products using our optimized index
	 */
	private function search_products_for_links( $search_term ) {
		global $wpdb;

		// Use our FULLTEXT indexed product search table
		// Direct database query with caching
		$cache_key = 'miniload_' . md5(  $wpdb->prepare( "
			SELECT
				p.product_id as ID,
				post.post_title as title,
				post.post_type,
				post.post_status,
				MATCH(p.search_text) AGAINST(%s IN BOOLEAN MODE) as relevance
			FROM " . esc_sql( $this->search_table ) . " p
			INNER JOIN {$wpdb->posts} post ON p.product_id = post.ID
			WHERE
				post.post_status = 'publish'
				AND (
					MATCH(p.search_text) AGAINST(%s IN BOOLEAN MODE)
					OR p.sku = %s
					OR post.post_title LIKE %s
				)
			ORDER BY
				CASE
					WHEN p.sku = %s THEN 1
					WHEN post.post_title = %s THEN 2
					WHEN post.post_title LIKE %s THEN 3
					ELSE 4
				END,
				relevance DESC
			LIMIT 20
		",
			$search_term,
			$search_term,
			$search_term,
			'%' . $wpdb->esc_like( $search_term ) . '%',
			$search_term,
			$search_term,
			$search_term . '%'
		)  );
		$cached = wp_cache_get( $cache_key );
		if ( false === $cached ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Required for performance optimization
			$cached = $wpdb->get_results( $wpdb->prepare( "
			SELECT
				p.product_id as ID,
				post.post_title as title,
				post.post_type,
				post.post_status,
				MATCH(p.search_text) AGAINST(%s IN BOOLEAN MODE) as relevance
			FROM " . esc_sql( $this->search_table ) . " p
			INNER JOIN {$wpdb->posts} post ON p.product_id = post.ID
			WHERE
				post.post_status = 'publish'
				AND (
					MATCH(p.search_text) AGAINST(%s IN BOOLEAN MODE)
					OR p.sku = %s
					OR post.post_title LIKE %s
				)
			ORDER BY
				CASE
					WHEN p.sku = %s THEN 1
					WHEN post.post_title = %s THEN 2
					WHEN post.post_title LIKE %s THEN 3
					ELSE 4
				END,
				relevance DESC
			LIMIT 20
		",
			$search_term,
			$search_term,
			$search_term,
			'%' . $wpdb->esc_like( $search_term ) . '%',
			$search_term,
			$search_term,
			$search_term . '%'
		) );
			wp_cache_set( $cache_key, $cached, '', 3600 );
		}

		$link_results = array();

		foreach ( $results as $product ) {
			// Get product object for price info
			$product_obj = wc_get_product( $product->ID );
			if ( ! $product_obj ) {
				continue;
			}

			$price_html = $product_obj->get_price_html();
			$sku = $product_obj->get_sku();

			// Format for WordPress link dialog
			$link_results[] = array(
				'ID' => $product->ID,
				'title' => $product->title,
				'permalink' => get_permalink( $product->ID ),
				'info' => sprintf(
					'Product%s%s',
					$sku ? ' (SKU: ' . $sku . ')' : '',
					$price_html ? ' - ' . wp_strip_all_tags( $price_html ) : ''
				)
			);
		}

		// Also search regular posts/pages if less than 10 product results
		if ( count( $link_results ) < 10 ) {
			$post_results = $this->search_posts_for_links( $search_term, 10 - count( $link_results ) );
			$link_results = array_merge( $link_results, $post_results );
		}

		return $link_results;
	}

	/**
	 * Search regular posts/pages
	 */
	private function search_posts_for_links( $search_term, $limit = 10 ) {
		global $wpdb;

		// Direct search in posts table for non-products
		// Direct database query with caching
		$cache_key = 'miniload_' . md5(  $wpdb->prepare( "
			SELECT
				ID,
				post_title as title,
				post_type,
				post_status
			FROM {$wpdb->posts}
			WHERE
				post_status = 'publish'
				AND post_type IN ('post', 'page')
				AND (
					post_title LIKE %s
					OR post_content LIKE %s
				)
			ORDER BY
				CASE
					WHEN post_title = %s THEN 1
					WHEN post_title LIKE %s THEN 2
					ELSE 3
				END
			LIMIT %d
		",
			'%' . $wpdb->esc_like( $search_term ) . '%',
			'%' . $wpdb->esc_like( $search_term ) . '%',
			$search_term,
			$search_term . '%',
			$limit
		)  );
		$cached = wp_cache_get( $cache_key );
		if ( false === $cached ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Required for performance optimization
			$cached = $wpdb->get_results( $wpdb->prepare( "
			SELECT
				ID,
				post_title as title,
				post_type,
				post_status
			FROM {$wpdb->posts}
			WHERE
				post_status = 'publish'
				AND post_type IN ('post', 'page')
				AND (
					post_title LIKE %s
					OR post_content LIKE %s
				)
			ORDER BY
				CASE
					WHEN post_title = %s THEN 1
					WHEN post_title LIKE %s THEN 2
					ELSE 3
				END
			LIMIT %d
		",
			'%' . $wpdb->esc_like( $search_term ) . '%',
			'%' . $wpdb->esc_like( $search_term ) . '%',
			$search_term,
			$search_term . '%',
			$limit
		) );
			wp_cache_set( $cache_key, $cached, '', 3600 );
		}

		$link_results = array();

		foreach ( $results as $post ) {
			$link_results[] = array(
				'ID' => $post->ID,
				'title' => $post->title,
				'permalink' => get_permalink( $post->ID ),
				'info' => ucfirst( $post->post_type )
			);
		}

		return $link_results;
	}

	/**
	 * Optimize link query arguments
	 */
	public function optimize_link_query_args( $args ) {
		// Reduce the number of results for faster queries
		if ( ! isset( $args['posts_per_page'] ) ) {
			$args['posts_per_page'] = 20;
		}

		// Skip unnecessary data
		$args['no_found_rows'] = true;
		$args['update_post_meta_cache'] = false;
		$args['update_post_term_cache'] = false;

		return $args;
	}

	/**
	 * Replace WordPress link query with our optimized search
	 */
	public function replace_with_product_search( $results, $query ) {
		// Only modify if searching
		if ( empty( $query['s'] ) ) {
			return $results;
		}

		// Check if we should search products
		if ( ! $this->should_search_products( $query['s'] ) ) {
			return $results;
		}

		// Get our optimized results
		$product_results = $this->search_products_for_links( $query['s'] );

		// If we have product results, prepend them to the results
		if ( ! empty( $product_results ) ) {
			// Get existing IDs to avoid duplicates
			$existing_ids = wp_list_pluck( $product_results, 'ID' );

			// Add non-duplicate existing results after our products
			foreach ( $results as $result ) {
				if ( ! in_array( $miniload_result['ID'], $existing_ids ) ) {
					$product_results[] = $miniload_result;
				}
			}

			return array_slice( $product_results, 0, 20 );
		}

		return $results;
	}

	/**
	 * Enqueue editor enhancements
	 */
	public function enqueue_editor_enhancements( $hook ) {
		// Only on post edit screens
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ) ) ) {
			return;
		}

		// Add inline script to show product indicator
		wp_add_inline_script( 'wp-link', '
			jQuery(document).ready(function($) {
				// Add product search hint to link dialog
				$(document).on("wp-link-open", function() {
					var $searchField = $("#wp-link-search");
					if ($searchField.length && !$searchField.data("miniload-enhanced")) {
						$searchField.attr("placeholder", "Search posts, pages, or products...");
						$searchField.data("miniload-enhanced", true);

						// Add info text
						if (!$("#miniload-link-info").length) {
							$searchField.after("<p id=\"miniload-link-info\" style=\"margin: 5px 0; color: #666; font-size: 12px;\">✓ MiniLoad: Product search optimized with FULLTEXT index</p>");
						}
					}
				});
			});
		' );
	}

	/**
	 * Get stats
	 */
	public function get_stats() {
		global $wpdb;

		// Direct database query with caching
		$cache_key = 'miniload_' . md5(  "SELECT COUNT(*) FROM " . esc_sql( $this->search_table ) . ""  );
		$cached = wp_cache_get( $cache_key );
		if ( false === $cached ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Required for performance optimization
			$cached = $wpdb->get_var( "SELECT COUNT(*) FROM " . esc_sql( $this->search_table ) );
			wp_cache_set( $cache_key, $cached, '', 3600 );
		}
		// Direct database query with caching
		$cache_key = 'miniload_' . md5(  "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish'"  );
		$cached = wp_cache_get( $cache_key );
		if ( false === $cached ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Required for performance optimization
			$cached = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish'" );
			wp_cache_set( $cache_key, $cached, '', 3600 );
		}

		return array(
			'indexed_products' => $indexed_products,
			'total_products' => $total_products,
			'coverage' => $total_products > 0 ? round( ( $indexed_products / $total_products ) * 100, 1 ) : 0,
			'status' => $indexed_products > 0 ? 'active' : 'inactive'
		);
	}
}