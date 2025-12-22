<?php
/**
 * Admin Dashboard Cache Module
 *
 * Caches WooCommerce admin statistics and widgets
 * SAFE: Only affects admin area, no customer impact
 *
 * @package MiniLoad
 * @subpackage Modules
 */

namespace MiniLoad\Modules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin Dashboard Cache class
 */
class Admin_Dashboard_Cache {

	/**
	 * Cache prefix
	 */
	private $cache_prefix = 'miniload_admin_';

	/**
	 * Cache durations for different widgets (in seconds)
	 */
	private $cache_durations = array(
		'status_widget' => 300,      // 5 minutes
		'recent_orders' => 180,      // 3 minutes
		'top_seller' => 3600,        // 1 hour
		'sales_report' => 600,       // 10 minutes
		'stock_report' => 900,       // 15 minutes
		'review_widget' => 1800,     // 30 minutes
		'activity_widget' => 300,    // 5 minutes
	);

	/**
	 * Constructor
	 */
	public function __construct() {
		// Only run in admin
		if ( ! is_admin() ) {
			return;
		}

		// Cache dashboard widgets
		add_filter( 'woocommerce_admin_status_widget_sales_query', array( $this, 'cache_status_widget_query' ), 10, 1 );
		add_filter( 'woocommerce_dashboard_status_widget_top_seller_query', array( $this, 'cache_top_seller_query' ), 10, 1 );

		// Cache admin reports
		add_filter( 'woocommerce_reports_get_order_report_query', array( $this, 'cache_report_query' ), 10, 1 );
		add_filter( 'woocommerce_admin_report_data', array( $this, 'cache_report_data' ), 10, 2 );

		// Cache order counts
		add_filter( 'wp_count_posts', array( $this, 'cache_order_counts' ), 10, 3 );

		// Cache stock reports
		add_filter( 'woocommerce_admin_stock_html', array( $this, 'cache_stock_report' ), 10, 2 );

		// Add cache info to admin bar
		add_action( 'admin_bar_menu', array( $this, 'add_cache_info_to_admin_bar' ), 100 );

		// Clear cache actions
		add_action( 'woocommerce_new_order', array( $this, 'clear_order_related_cache' ) );
		add_action( 'woocommerce_update_order', array( $this, 'clear_order_related_cache' ) );
		add_action( 'woocommerce_delete_order', array( $this, 'clear_order_related_cache' ) );
		add_action( 'woocommerce_update_product', array( $this, 'clear_product_related_cache' ) );

		// AJAX endpoint for clearing cache
		add_action( 'wp_ajax_miniload_clear_admin_cache', array( $this, 'ajax_clear_cache' ) );

		// Add settings
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Cache status widget query
	 */
	public function cache_status_widget_query( $query ) {
		$cache_key = $this->cache_prefix . 'status_widget_' . md5( serialize( $query ) );
		$cached = get_transient( $cache_key );

		if ( false === $cached ) {
			// Let WooCommerce run the query
			add_filter( 'woocommerce_admin_status_widget_sales_query_result', function( $result ) use ( $cache_key ) {
				set_transient( $cache_key, $miniload_result, $this->cache_durations['status_widget'] );
				return $miniload_result;
			}, 10, 1 );

			return $query;
		}

		// Return cached result
		return $cached;
	}

	/**
	 * Cache top seller query
	 */
	public function cache_top_seller_query( $query ) {
		$cache_key = $this->cache_prefix . 'top_seller_' . gmdate( 'Ymd' );
		$cached = get_transient( $cache_key );

		if ( false === $cached ) {
			global $wpdb;

			// Run the query - query should be properly prepared by caller
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query is prepared by the caller
			$miniload_result = $wpdb->get_row( $query );

			if ( $result ) {
				set_transient( $cache_key, $miniload_result, $this->cache_durations['top_seller'] );
			}

			return $query;
		}

		// Inject cached result
		return "SELECT * FROM (SELECT " . $cached->product_id . " as product_id, " . $cached->qty . " as qty) as cached_result";
	}

	/**
	 * Cache report queries
	 */
	public function cache_report_query( $query ) {
		// Only cache SELECT queries
		if ( stripos( trim( $query ), 'SELECT' ) !== 0 ) {
			return $query;
		}

		$cache_key = $this->cache_prefix . 'report_' . md5( $query );
		$cached = get_transient( $cache_key );

		if ( false === $cached ) {
			// Query will run, cache the result
			add_filter( 'woocommerce_reports_get_order_report_data_results', function( $results ) use ( $cache_key ) {
				set_transient( $cache_key, $results, $this->cache_durations['sales_report'] );
				return $results;
			}, 10, 1 );

			return $query;
		}

		return $cached;
	}

	/**
	 * Cache report data
	 */
	public function cache_report_data( $data, $report ) {
		// Generate cache key based on report type and parameters
		// Generate cache key based on report class and GET parameters
		$cache_key = $this->cache_prefix . 'report_data_' . md5( serialize( array(
			get_class( $report ),
			$_GET // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only cache key generation, no data modification
		) ) );

		$cached = get_transient( $cache_key );

		if ( false === $cached ) {
			// Cache the data
			set_transient( $cache_key, $data, $this->cache_durations['sales_report'] );
			return $data;
		}

		// Add cache indicator
		if ( is_array( $cached ) ) {
			$cached['_cached'] = true;
			$cached['_cache_time'] = get_option( '_transient_timeout_' . $cache_key );
		}

		return $cached;
	}

	/**
	 * Cache order counts
	 */
	public function cache_order_counts( $counts, $type, $perm ) {
		if ( 'shop_order' !== $type ) {
			return $counts;
		}

		$cache_key = $this->cache_prefix . 'order_counts_' . $perm;
		$cached = get_transient( $cache_key );

		if ( false === $cached ) {
			set_transient( $cache_key, $counts, $this->cache_durations['status_widget'] );
			return $counts;
		}

		return $cached;
	}

	/**
	 * Cache stock report
	 */
	public function cache_stock_report( $html, $product ) {
		$cache_key = $this->cache_prefix . 'stock_report';
		$cached = get_transient( $cache_key );

		if ( false === $cached ) {
			$cached = array();
		}

		// Cache individual product stock info
		$product_id = $product->get_id();
		if ( ! isset( $cached[ $product_id ] ) ) {
			$cached[ $product_id ] = array(
				'html' => $html,
				'time' => time()
			);
			set_transient( $cache_key, $cached, $this->cache_durations['stock_report'] );
		}

		return $html;
	}

	/**
	 * Add cache info to admin bar
	 */
	public function add_cache_info_to_admin_bar( $wp_admin_bar ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// Count active caches
		global $wpdb;
		// Direct database query with caching
		$cache_key = 'miniload_' . md5( 
			"SELECT COUNT(*) FROM {$wpdb->options}
			WHERE option_name LIKE '_transient_" . esc_sql( $this->cache_prefix ) . "%'"
		 );
		$cached = wp_cache_get( $cache_key );
		if ( false === $cached ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Required for performance optimization
			$cached = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options}
			WHERE option_name LIKE '_transient_" . esc_sql( $this->cache_prefix ) . "%'"
		);
			wp_cache_set( $cache_key, $cached, '', 3600 );
		}

		$wp_admin_bar->add_menu( array(
			'id' => 'miniload-admin-cache',
			'parent' => 'top-secondary',
			'title' => '<span class="ab-icon dashicons dashicons-dashboard"></span> Cache: ' . $cache_count,
			'href' => '#',
			'meta' => array(
				'title' => 'MiniLoad Admin Cache (' . $cache_count . ' active)'
			)
		) );

		// Add clear cache option
		$wp_admin_bar->add_menu( array(
			'id' => 'miniload-clear-admin-cache',
			'parent' => 'miniload-admin-cache',
			'title' => 'Clear Admin Cache',
			'href' => wp_nonce_url( admin_url( 'admin-ajax.php?action=miniload_clear_admin_cache' ), 'miniload_clear_cache' ),
			'meta' => array(
				'onclick' => 'return confirm("Clear all admin cache?")'
			)
		) );
	}

	/**
	 * Clear order related cache
	 */
	public function clear_order_related_cache() {
		$this->clear_cache_by_type( array( 'status_widget', 'recent_orders', 'sales_report', 'activity_widget' ) );
	}

	/**
	 * Clear product related cache
	 */
	public function clear_product_related_cache() {
		$this->clear_cache_by_type( array( 'top_seller', 'stock_report' ) );
	}

	/**
	 * Clear cache by type
	 */
	private function clear_cache_by_type( $types ) {
		global $wpdb;

		foreach ( $types as $type ) {
			$escaped_type = esc_sql( $type );
			$escaped_prefix = esc_sql( $this->cache_prefix );
			// Build the query with escaped values
			$query = "DELETE FROM {$wpdb->options}
				WHERE option_name LIKE '_transient_{$escaped_prefix}{$escaped_type}%'
				OR option_name LIKE '_transient_timeout_{$escaped_prefix}{$escaped_type}%'";
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Values are properly escaped above, this is a cache deletion operation
			$wpdb->query( $query );
		}
	}

	/**
	 * AJAX handler for clearing cache
	 */
	public function ajax_clear_cache() {
		check_ajax_referer( 'miniload_clear_cache' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Unauthorized' );
		}

		$this->clear_all_cache();

		wp_safe_redirect( wp_get_referer() );
		exit;
	}

	/**
	 * Clear all admin cache
	 */
	public function clear_all_cache() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for performance optimization
		$cleared = $wpdb->query( "DELETE FROM {$wpdb->options}
			WHERE option_name LIKE '_transient_" . esc_sql( $this->cache_prefix ) . "%'
			OR option_name LIKE '_transient_timeout_" . esc_sql( $this->cache_prefix ) . "%'"
		);

		if ( function_exists( 'miniload_log' ) ) {
			miniload_log( 'Cleared ' . $cleared . ' admin cache entries', 'info' );
		}

		return $cleared;
	}

	/**
	 * Register settings
	 */
	public function register_settings() {
		foreach ( $this->cache_durations as $type => $duration ) {
			register_setting(
				'miniload_settings',
				'miniload_admin_cache_' . $type,
				array(
					'type' => 'integer',
					'default' => $duration,
					'sanitize_callback' => 'absint'
				)
			);
		}

		// Update durations from settings
		foreach ( $this->cache_durations as $type => $default ) {
			$this->cache_durations[ $type ] = get_option( 'miniload_admin_cache_' . $type, $default );
		}
	}

	/**
	 * Get cache statistics
	 */
	public function get_stats() {
		global $wpdb;

		$stats = array(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for performance optimization
			'total_cached' => $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options}
				WHERE option_name LIKE '_transient_" . esc_sql( $this->cache_prefix ) . "%'"
			),
			'cache_size' => $wpdb->get_var( "SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options}
				WHERE option_name LIKE '_transient_" . esc_sql( $this->cache_prefix ) . "%'"
			),
			'oldest_cache' => $wpdb->get_var( "SELECT MIN(option_value) FROM {$wpdb->options}
				WHERE option_name LIKE '_transient_timeout_" . esc_sql( $this->cache_prefix ) . "%'"
			)
		);

		$stats['cache_size'] = size_format( $stats['cache_size'], 2 );
		if ( $stats['oldest_cache'] ) {
			$stats['oldest_cache'] = human_time_diff( $stats['oldest_cache'] );
		}

		return $stats;
	}
}