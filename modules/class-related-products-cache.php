<?php
/**
 * Related Products Cache Module
 *
 * Caches related, upsell, and cross-sell product IDs
 * SAFE: Only caches IDs, WooCommerce handles display
 *
 * @package MiniLoad
 * @subpackage Modules
 */

namespace MiniLoad\Modules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Related Products Cache class
 */
class Related_Products_Cache {

	/**
	 * Cache duration in seconds (24 hours default)
	 */
	private $cache_duration = 86400;

	/**
	 * Cache prefix
	 */
	private $cache_prefix = 'miniload_related_';

	/**
	 * Constructor
	 */
	public function __construct() {
		// Cache related products
		add_filter( 'woocommerce_related_products', array( $this, 'get_cached_related_products' ), 10, 3 );

		// Cache upsells
		add_filter( 'woocommerce_product_upsell_ids', array( $this, 'get_cached_upsells' ), 10, 2 );

		// Cache cross-sells
		add_filter( 'woocommerce_product_cross_sell_ids', array( $this, 'get_cached_cross_sells' ), 10, 2 );

		// Cache recently viewed
		add_filter( 'woocommerce_recently_viewed_products_widget_query_args', array( $this, 'optimize_recently_viewed' ), 10, 1 );

		// Clear cache when product is updated
		add_action( 'woocommerce_update_product', array( $this, 'clear_product_cache' ), 10, 1 );
		add_action( 'woocommerce_new_product', array( $this, 'clear_related_cache' ), 10, 1 );
		add_action( 'before_delete_post', array( $this, 'clear_product_cache' ), 10, 1 );

		// Clear cache when product relationships change
		add_action( 'woocommerce_product_set_upsells', array( $this, 'clear_product_cache' ), 10, 1 );
		add_action( 'woocommerce_product_set_cross_sells', array( $this, 'clear_product_cache' ), 10, 1 );

		// Admin settings
		add_action( 'admin_init', array( $this, 'register_settings' ) );

		// CLI commands
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'miniload warm-related', array( $this, 'cli_warm_cache' ) );
		}
	}

	/**
	 * Get cached related products
	 */
	public function get_cached_related_products( $related_products, $product_id, $args ) {
		$cache_key = $this->cache_prefix . 'related_' . $product_id . '_' . md5( serialize( $args ) );
		$cached = get_transient( $cache_key );

		if ( false === $cached ) {
			// Let WooCommerce calculate related products
			// But cache the result for next time
			if ( ! empty( $related_products ) ) {
				set_transient( $cache_key, $related_products, $this->cache_duration );
			}
			return $related_products;
		}

		// Return cached IDs
		return $cached;
	}

	/**
	 * Get cached upsells
	 */
	public function get_cached_upsells( $upsell_ids, $product ) {
		if ( ! is_object( $product ) ) {
			return $upsell_ids;
		}

		$product_id = $product->get_id();
		$cache_key = $this->cache_prefix . 'upsells_' . $product_id;
		$cached = get_transient( $cache_key );

		if ( false === $cached ) {
			// Cache the upsell IDs
			set_transient( $cache_key, $upsell_ids, $this->cache_duration );
			return $upsell_ids;
		}

		return $cached;
	}

	/**
	 * Get cached cross-sells
	 */
	public function get_cached_cross_sells( $cross_sell_ids, $product ) {
		if ( ! is_object( $product ) ) {
			return $cross_sell_ids;
		}

		$product_id = $product->get_id();
		$cache_key = $this->cache_prefix . 'cross_sells_' . $product_id;
		$cached = get_transient( $cache_key );

		if ( false === $cached ) {
			// Cache the cross-sell IDs
			set_transient( $cache_key, $cross_sell_ids, $this->cache_duration );
			return $cross_sell_ids;
		}

		return $cached;
	}

	/**
	 * Optimize recently viewed products query
	 */
	public function optimize_recently_viewed( $query_args ) {
		// Cache the recently viewed for the session
		$viewed_cookies = ! empty( sanitize_text_field( wp_unslash( $_COOKIE['woocommerce_recently_viewed'] ) ) ) ? wp_parse_id_list( (array) explode( '|', wp_unslash( sanitize_text_field( wp_unslash( $_COOKIE['woocommerce_recently_viewed'] ) ) ) ) ) : array();

		if ( ! empty( $viewed_cookies ) ) {
			$cache_key = $this->cache_prefix . 'recently_viewed_' . md5( serialize( $viewed_cookies ) );
			$cached = get_transient( $cache_key );

			if ( false === $cached ) {
				// Cache for 1 hour
				set_transient( $cache_key, $query_args, 3600 );
			} else {
				$query_args = $cached;
			}
		}

		return $query_args;
	}

	/**
	 * Clear cache for a specific product
	 */
	public function clear_product_cache( $product_id ) {
		// Clear all cache types for this product
		delete_transient( $this->cache_prefix . 'related_' . $product_id );
		delete_transient( $this->cache_prefix . 'upsells_' . $product_id );
		delete_transient( $this->cache_prefix . 'cross_sells_' . $product_id );

		// Clear cache for products that might be related to this one
		$this->clear_related_cache( $product_id );
	}

	/**
	 * Clear cache for products related to a given product
	 */
	public function clear_related_cache( $product_id ) {
		global $wpdb;

		// Find products that might have this product as related
		// This is approximate - we clear cache for products in same categories
		$categories = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) );

		if ( ! empty( $categories ) ) {
			$products = get_posts( array(
				'post_type' => 'product',
				'posts_per_page' => 100,
				'fields' => 'ids',
				'tax_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Required for finding related products by category
					array(
						'taxonomy' => 'product_cat',
						'field' => 'term_id',
						'terms' => $categories
					)
				)
			) );

			foreach ( $products as $related_product_id ) {
				delete_transient( $this->cache_prefix . 'related_' . $related_product_id );
			}
		}
	}

	/**
	 * Warm the cache (pre-generate cache for all products)
	 */
	public function warm_cache() {
		$products = get_posts( array(
			'post_type' => 'product',
			'posts_per_page' => -1,
			'fields' => 'ids',
			'post_status' => 'publish'
		) );

		$warmed = 0;

		foreach ( $products as $product_id ) {
			$product = wc_get_product( $product_id );

			if ( ! $product ) {
				continue;
			}

			// Warm related products
			$related = wc_get_related_products( $product_id );
			if ( ! empty( $related ) ) {
				$cache_key = $this->cache_prefix . 'related_' . $product_id;
				set_transient( $cache_key, $related, $this->cache_duration );
				$warmed++;
			}

			// Warm upsells
			$upsells = $product->get_upsell_ids();
			if ( ! empty( $upsells ) ) {
				$cache_key = $this->cache_prefix . 'upsells_' . $product_id;
				set_transient( $cache_key, $upsells, $this->cache_duration );
				$warmed++;
			}

			// Warm cross-sells
			$cross_sells = $product->get_cross_sell_ids();
			if ( ! empty( $cross_sells ) ) {
				$cache_key = $this->cache_prefix . 'cross_sells_' . $product_id;
				set_transient( $cache_key, $cross_sells, $this->cache_duration );
				$warmed++;
			}
		}

		return $warmed;
	}

	/**
	 * WP-CLI command to warm cache
	 */
	public function cli_warm_cache() {
		\WP_CLI::log( 'Starting related products cache warming...' );

		$start = microtime( true );
		$warmed = $this->warm_cache();
		$time = round( microtime( true ) - $start, 2 );

		\WP_CLI::success( "Warmed {$warmed} related product caches in {$time} seconds" );
	}

	/**
	 * Register settings
	 */
	public function register_settings() {
		register_setting(
			'miniload_settings',
			'miniload_related_cache_duration',
			array(
				'type' => 'integer',
				'default' => 86400,
				'sanitize_callback' => 'absint'
			)
		);

		// Update cache duration from settings
		$duration = get_option( 'miniload_related_cache_duration', 86400 );
		$this->cache_duration = max( 3600, $duration ); // Minimum 1 hour
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
			'cache_types' => array(
				'related' => $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options}
					WHERE option_name LIKE '_transient_" . esc_sql( $this->cache_prefix ) . "related_%'"
				),
				'upsells' => $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options}
					WHERE option_name LIKE '_transient_" . esc_sql( $this->cache_prefix ) . "upsells_%'"
				),
				'cross_sells' => $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options}
					WHERE option_name LIKE '_transient_" . esc_sql( $this->cache_prefix ) . "cross_sells_%'"
				)
			)
		);

		return $stats;
	}
}