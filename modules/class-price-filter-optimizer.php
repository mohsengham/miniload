<?php
/**
 * Price Filter Optimizer Module
 *
 * Optimizes WooCommerce price filter widget queries
 * Caches min/max prices for search results
 *
 * @package MiniLoad
 * @subpackage Modules
 */

namespace MiniLoad\Modules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Price Filter Optimizer class
 */
class Price_Filter_Optimizer {

	/**
	 * Cache prefix
	 */
	private $cache_prefix = 'miniload_price_filter_';

	/**
	 * Cache duration (in seconds)
	 */
	private $cache_duration = 3600; // 1 hour

	/**
	 * Constructor
	 */
	public function __construct() {
		// Hook into WooCommerce price filter
		add_filter( 'woocommerce_price_filter_sql', array( $this, 'optimize_price_filter_query' ), 10, 3 );
		add_filter( 'woocommerce_get_filtered_price_results', array( $this, 'cache_price_results' ), 10, 2 );

		// Alternative approach - intercept the widget directly
		add_action( 'init', array( $this, 'setup_price_filter_cache' ), 20 );

		// Clear cache on product updates
		add_action( 'woocommerce_update_product', array( $this, 'clear_price_cache' ) );
		add_action( 'woocommerce_new_product', array( $this, 'clear_price_cache' ) );
		add_action( 'woocommerce_delete_product', array( $this, 'clear_price_cache' ) );

		// Add settings
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Setup price filter cache
	 */
	public function setup_price_filter_cache() {
		// Override the get_filtered_price method if possible
		if ( class_exists( 'WC_Widget_Price_Filter' ) ) {
			add_filter( 'woocommerce_price_filter_widget_min_amount', array( $this, 'get_cached_min_price' ) );
			add_filter( 'woocommerce_price_filter_widget_max_amount', array( $this, 'get_cached_max_price' ) );
		}
	}

	/**
	 * Get cached minimum price
	 */
	public function get_cached_min_price( $min_price ) {
		$cache_key = $this->get_price_cache_key( 'min' );
		$cached_price = get_transient( $cache_key );

		if ( false !== $cached_price ) {
			miniload_log( 'Price filter cache hit for min price', 'debug' );
			return $cached_price;
		}

		// Store the original price in cache
		set_transient( $cache_key, $min_price, $this->cache_duration );
		return $min_price;
	}

	/**
	 * Get cached maximum price
	 */
	public function get_cached_max_price( $max_price ) {
		$cache_key = $this->get_price_cache_key( 'max' );
		$cached_price = get_transient( $cache_key );

		if ( false !== $cached_price ) {
			miniload_log( 'Price filter cache hit for max price', 'debug' );
			return $cached_price;
		}

		// Store the original price in cache
		set_transient( $cache_key, $max_price, $this->cache_duration );
		return $max_price;
	}

	/**
	 * Optimize price filter query
	 */
	public function optimize_price_filter_query( $sql, $meta_table, $tax_query_sql ) {
		global $wpdb;

		// Check if this is a search query
		if ( is_search() ) {
			// Generate cache key based on search term and filters
			$search_term = get_search_query();
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Frontend price filter doesn't use nonces
			$cache_key = $this->cache_prefix . md5( $search_term . serialize( $_GET ) );

			// Try to get cached results
			$cached_results = get_transient( $cache_key );
			if ( false !== $cached_results ) {
				miniload_log( 'Price filter cache hit for search: ' . $search_term, 'debug' );

				// Return a simple query that returns the cached values
				return $wpdb->prepare(
					"SELECT %f as min_price, %f as max_price",
					$cached_results['min_price'],
					$cached_results['max_price']
				);
			}
		}

		// For non-cached queries, try to optimize the original query
		// If the query contains LIKE '%something%', try to use our search index
		if ( strpos( $sql, 'LIKE \'%' ) !== false && class_exists( '\MiniLoad\Modules\Ajax_Search_Pro' ) ) {
			return $this->optimize_with_search_index( $sql );
		}

		return $sql;
	}

	/**
	 * Optimize query using our search index
	 */
	private function optimize_with_search_index( $sql ) {
		global $wpdb;

		// Extract search term from the SQL
		preg_match( "/LIKE '%(.+?)%'/", $sql, $matches );
		if ( empty( $matches[1] ) ) {
			return $sql;
		}

		$search_term = $matches[1];
		$search_table = $wpdb->prefix . 'miniload_search_index';

		// Check if our search table exists
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Required for performance optimization
		$prepared_query = $wpdb->prepare( "SHOW TABLES LIKE %s", $search_table );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared above
		if ( $wpdb->get_var( $prepared_query ) !== $search_table ) {
			return $sql;
		}

		// Build optimized query using our search index
		$optimized_sql = "
			SELECT MIN(pm.min_price) as min_price, MAX(pm.max_price) as max_price
			FROM {$wpdb->prefix}wc_product_meta_lookup pm
			INNER JOIN {$search_table} ps ON pm.product_id = ps.product_id
			WHERE ps.search_text LIKE '%" . esc_sql( $search_term ) . "%'
			OR ps.sku LIKE '%" . esc_sql( $search_term ) . "%'
		";

		miniload_log( 'Optimized price filter query for: ' . $search_term, 'debug' );

		return $optimized_sql;
	}

	/**
	 * Cache price results
	 */
	public function cache_price_results( $results, $args ) {
		if ( is_search() ) {
			$search_term = get_search_query();
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Frontend price filter doesn't use nonces
			$cache_key = $this->cache_prefix . md5( $search_term . serialize( $_GET ) );

			// Cache the results
			set_transient( $cache_key, $results, $this->cache_duration );
			miniload_log( 'Cached price filter results for: ' . $search_term, 'debug' );
		}

		return $results;
	}

	/**
	 * Get price cache key
	 */
	private function get_price_cache_key( $type = 'both' ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Frontend price filter doesn't use nonces
		$key_parts = array(
			'type' => $type,
			'search' => is_search() ? get_search_query() : '',
			'category' => is_product_category() ? get_queried_object_id() : '',
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Frontend filter, used for cache key generation only
			'filters' => isset( $_GET['filter'] ) ? sanitize_text_field( wp_unslash( $_GET['filter'] ) ) : '',
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Frontend filter, used for cache key generation only
			'orderby' => isset( $_GET['orderby'] ) ? sanitize_text_field( wp_unslash( $_GET['orderby'] ) ) : ''
		);

		return $this->cache_prefix . md5( serialize( $key_parts ) );
	}

	/**
	 * Clear price cache
	 */
	public function clear_price_cache() {
		global $wpdb;

		// Clear all price filter caches
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cache clearing operation
		$escaped_prefix = esc_sql( $this->cache_prefix );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name and prefix are escaped with esc_sql
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_{$escaped_prefix}%'" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cache clearing operation
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name and prefix are escaped with esc_sql
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_{$escaped_prefix}%'" );

		miniload_log( 'Price filter cache cleared', 'info' );
	}

	/**
	 * Register settings
	 */
	public function register_settings() {
		register_setting(
			'miniload_settings',
			'miniload_price_filter_cache_duration',
			array(
				'type' => 'integer',
				'default' => 3600,
				'sanitize_callback' => 'absint'
			)
		);

		// Update cache duration from settings
		$this->cache_duration = get_option( 'miniload_price_filter_cache_duration', 3600 );
	}

	/**
	 * Get stats
	 */
	public function get_stats() {
		global $wpdb;

		$escaped_prefix = esc_sql( $this->cache_prefix );
		$stats = array(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Stats query
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name and prefix are escaped with esc_sql
			'cached_prices' => $wpdb->get_var( "
				SELECT COUNT(*)
				FROM {$wpdb->options}
				WHERE option_name LIKE '_transient_{$escaped_prefix}%'"
			),
			'cache_duration' => $this->cache_duration
		);

		return $stats;
	}
}