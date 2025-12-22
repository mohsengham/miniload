<?php
/**
 * Frontend Counts Cache Module
 *
 * Caches cart count and wishlist count that appear on every page
 * SAFE: Read-only operations, doesn't affect cart/wishlist functionality
 *
 * @package MiniLoad
 * @subpackage Modules
 */

namespace MiniLoad\Modules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Frontend Counts Cache class
 */
class Frontend_Counts_Cache {

	/**
	 * Cache prefix
	 */
	private $cache_prefix = 'miniload_fc_';

	/**
	 * Cache durations (in seconds)
	 */
	private $cache_durations = array(
		'cart_count' => 300,      // 5 minutes for cart
		'wishlist_count' => 600,  // 10 minutes for wishlist
		'compare_count' => 900,   // 15 minutes for compare
		'mini_cart' => 180,       // 3 minutes for mini cart HTML
	);

	/**
	 * Constructor
	 */
	public function __construct() {
		// Cart count caching
		add_filter( 'woocommerce_cart_contents_count', array( $this, 'get_cached_cart_count' ), 10, 1 );

		// Cart fragments (AJAX mini cart)
		add_filter( 'woocommerce_add_to_cart_fragments', array( $this, 'cache_cart_fragments' ), 10, 1 );

		// Wishlist count caching (Woodmart theme)
		$this->init_woodmart_wishlist_cache();

		// YITH Wishlist support (if installed)
		add_filter( 'yith_wcwl_count_products', array( $this, 'get_cached_yith_wishlist_count' ), 10, 1 );

		// Cache clearing actions
		add_action( 'woocommerce_add_to_cart', array( $this, 'clear_cart_cache' ) );
		add_action( 'woocommerce_cart_item_removed', array( $this, 'clear_cart_cache' ) );
		add_action( 'woocommerce_cart_item_restored', array( $this, 'clear_cart_cache' ) );
		add_action( 'woocommerce_after_cart_item_quantity_update', array( $this, 'clear_cart_cache' ) );
		add_action( 'woocommerce_cart_emptied', array( $this, 'clear_cart_cache' ) );
		add_action( 'woocommerce_applied_coupon', array( $this, 'clear_cart_cache' ) );
		add_action( 'woocommerce_removed_coupon', array( $this, 'clear_cart_cache' ) );

		// Wishlist clearing (Woodmart)
		add_action( 'woodmart_wishlist_add_product', array( $this, 'clear_wishlist_cache' ) );
		add_action( 'woodmart_wishlist_remove_product', array( $this, 'clear_wishlist_cache' ) );

		// Session-based caching for non-logged users
		add_action( 'init', array( $this, 'init_session_cache' ) );

		// AJAX endpoints for counts
		add_action( 'wp_ajax_miniload_get_counts', array( $this, 'ajax_get_counts' ) );
		add_action( 'wp_ajax_nopriv_miniload_get_counts', array( $this, 'ajax_get_counts' ) );

		// Optimize cart calculations
		add_filter( 'woocommerce_cart_ready_to_calc_shipping', array( $this, 'optimize_cart_calculations' ), 10, 1 );

		// Settings
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Initialize Woodmart wishlist cache
	 */
	private function init_woodmart_wishlist_cache() {
		// Hook into Woodmart wishlist count
		add_filter( 'woodmart_wishlist_count', array( $this, 'get_cached_woodmart_wishlist_count' ), 10, 1 );

		// Hook into the wishlist display
		add_filter( 'woodmart_wishlist_number', array( $this, 'get_cached_woodmart_wishlist_count' ), 10, 1 );

		// AJAX response optimization
		add_filter( 'woodmart_wishlist_fragments', array( $this, 'optimize_wishlist_fragments' ), 10, 1 );
	}

	/**
	 * Initialize session-based cache
	 */
	public function init_session_cache() {
		if ( ! is_admin() && ! defined( 'DOING_AJAX' ) ) {
			// Start session if not started
			if ( ! session_id() && ! headers_sent() ) {
				session_start();
			}
		}
	}

	/**
	 * Get cached cart count
	 */
	public function get_cached_cart_count( $count ) {
		// Don't cache in admin or during checkout
		if ( is_admin() || is_checkout() ) {
			return $count;
		}

		$cache_key = $this->get_cart_cache_key();
		$cached = $this->get_cache( $cache_key );

		if ( false === $cached ) {
			// Cache the count
			$this->set_cache( $cache_key, $count, $this->cache_durations['cart_count'] );
			return $count;
		}

		return $cached;
	}

	/**
	 * Get cached Woodmart wishlist count
	 */
	public function get_cached_woodmart_wishlist_count( $count ) {
		// Skip caching in admin
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $count;
		}

		$cache_key = $this->get_wishlist_cache_key( 'woodmart' );
		$cached = $this->get_cache( $cache_key );

		if ( false === $cached ) {
			// Get actual count if not provided
			if ( $count === null ) {
				$count = $this->calculate_woodmart_wishlist_count();
			}

			$this->set_cache( $cache_key, $count, $this->cache_durations['wishlist_count'] );
			return $count;
		}

		return $cached;
	}

	/**
	 * Calculate Woodmart wishlist count
	 */
	private function calculate_woodmart_wishlist_count() {
		global $wpdb;

		if ( is_user_logged_in() ) {
			$user_id = get_current_user_id();
			// Direct database query with caching
		$cache_key = 'miniload_' . md5(  $wpdb->prepare(
				"SELECT COUNT(DISTINCT product_id)
				FROM {$wpdb->prefix}woodmart_wishlist_products wp
				INNER JOIN {$wpdb->prefix}woodmart_wishlists w ON wp.wishlist_id = w.ID
				WHERE w.user_id = %d",
				$user_id
			)  );
		$cached = wp_cache_get( $cache_key );
		if ( false === $cached ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Required for performance optimization
			$cached = $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(DISTINCT product_id)
				FROM {$wpdb->prefix}woodmart_wishlist_products wp
				INNER JOIN {$wpdb->prefix}woodmart_wishlists w ON wp.wishlist_id = w.ID
				WHERE w.user_id = %d",
				$user_id
			) );
			wp_cache_set( $cache_key, $cached, '', 3600 );
		}
		} else {
			// For guests, use session/cookie
			$wishlist_key = $this->get_guest_wishlist_key();
			if ( $wishlist_key ) {
				// Direct database query with caching
		$cache_key = 'miniload_' . md5(  $wpdb->prepare(
					"SELECT COUNT(DISTINCT product_id)
					FROM {$wpdb->prefix}woodmart_wishlist_products wp
					INNER JOIN {$wpdb->prefix}woodmart_wishlists w ON wp.wishlist_id = w.ID
					WHERE w.wishlist_key = %s",
					$wishlist_key
				)  );
		$cached = wp_cache_get( $cache_key );
		if ( false === $cached ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Required for performance optimization
			$cached = $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(DISTINCT product_id)
					FROM {$wpdb->prefix}woodmart_wishlist_products wp
					INNER JOIN {$wpdb->prefix}woodmart_wishlists w ON wp.wishlist_id = w.ID
					WHERE w.wishlist_key = %s",
					$wishlist_key
				) );
			wp_cache_set( $cache_key, $cached, '', 3600 );
		}
			} else {
				$count = 0;
			}
		}

		return intval( $count );
	}

	/**
	 * Get cached YITH wishlist count
	 */
	public function get_cached_yith_wishlist_count( $count ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $count;
		}

		$cache_key = $this->get_wishlist_cache_key( 'yith' );
		$cached = $this->get_cache( $cache_key );

		if ( false === $cached ) {
			$this->set_cache( $cache_key, $count, $this->cache_durations['wishlist_count'] );
			return $count;
		}

		return $cached;
	}

	/**
	 * Cache cart fragments
	 */
	public function cache_cart_fragments( $fragments ) {
		// Cache the entire fragments array for quick retrieval
		$cache_key = $this->cache_prefix . 'fragments_' . $this->get_user_identifier();
		$this->set_cache( $cache_key, $fragments, $this->cache_durations['mini_cart'] );

		return $fragments;
	}

	/**
	 * Optimize wishlist fragments
	 */
	public function optimize_wishlist_fragments( $fragments ) {
		// Cache wishlist fragments
		$cache_key = $this->cache_prefix . 'wish_fragments_' . $this->get_user_identifier();
		$cached = $this->get_cache( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$this->set_cache( $cache_key, $fragments, $this->cache_durations['wishlist_count'] );
		return $fragments;
	}

	/**
	 * Optimize cart calculations
	 */
	public function optimize_cart_calculations( $ready ) {
		// Skip unnecessary calculations on non-cart/checkout pages
		if ( ! is_cart() && ! is_checkout() && ! wp_doing_ajax() ) {
			return false;
		}

		return $ready;
	}

	/**
	 * Get cart cache key
	 */
	private function get_cart_cache_key() {
		$user_id = $this->get_user_identifier();
		$cart_hash = $this->get_cart_hash();

		return $this->cache_prefix . 'cart_count_' . $user_id . '_' . $cart_hash;
	}

	/**
	 * Get wishlist cache key
	 */
	private function get_wishlist_cache_key( $type = 'woodmart' ) {
		$user_id = $this->get_user_identifier();
		return $this->cache_prefix . 'wishlist_' . $type . '_' . $user_id;
	}

	/**
	 * Get user identifier
	 */
	private function get_user_identifier() {
		if ( is_user_logged_in() ) {
			return 'user_' . get_current_user_id();
		}

		// For guests, use session ID or cookie
		if ( WC()->session ) {
			return 'guest_' . WC()->session->get_customer_id();
		}

		return 'guest_' . md5( ( isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '' ) . ( isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '' ) );
	}

	/**
	 * Get cart hash for cache validation
	 */
	private function get_cart_hash() {
		if ( WC()->cart ) {
			$cart_contents = WC()->cart->get_cart_contents();
			return md5( serialize( array_keys( $cart_contents ) ) );
		}

		return 'empty';
	}

	/**
	 * Get guest wishlist key
	 */
	private function get_guest_wishlist_key() {
		if ( isset( $_COOKIE['woodmart_wishlist_key'] ) ) {
			return sanitize_text_field( wp_unslash( $_COOKIE['woodmart_wishlist_key'] ) );
		}

		if ( isset( $_SESSION['woodmart_wishlist_key'] ) ) {
			return sanitize_text_field( $_SESSION['woodmart_wishlist_key'] );
		}

		return false;
	}

	/**
	 * Get cache
	 */
	private function get_cache( $key ) {
		// Try transient first (database cache)
		$cached = get_transient( $key );

		if ( false === $cached && isset( $_SESSION['miniload_cache'][ $key ] ) ) {
			// Fallback to session cache
			$cached = sanitize_text_field( $_SESSION['miniload_cache'][ $key ] );
		}

		return $cached;
	}

	/**
	 * Set cache
	 */
	private function set_cache( $key, $value, $duration ) {
		// Set transient
		set_transient( $key, $value, $duration );

		// Also set in session for quick access
		if ( isset( $_SESSION ) ) {
			if ( ! isset( $_SESSION['miniload_cache'] ) ) {
				$_SESSION['miniload_cache'] = array();
			}
			$_SESSION['miniload_cache'][ $key ] = $value;
		}
	}

	/**
	 * Clear cart cache
	 */
	public function clear_cart_cache() {
		$cache_key = $this->get_cart_cache_key();
		delete_transient( $cache_key );

		// Clear fragments cache
		$fragments_key = $this->cache_prefix . 'fragments_' . $this->get_user_identifier();
		delete_transient( $fragments_key );

		// Clear session cache
		if ( isset( $_SESSION['miniload_cache'] ) ) {
			unset( $_SESSION['miniload_cache'][ $cache_key ] );
			unset( $_SESSION['miniload_cache'][ $fragments_key ] );
		}
	}

	/**
	 * Clear wishlist cache
	 */
	public function clear_wishlist_cache() {
		$woodmart_key = $this->get_wishlist_cache_key( 'woodmart' );
		$yith_key = $this->get_wishlist_cache_key( 'yith' );

		delete_transient( $woodmart_key );
		delete_transient( $yith_key );

		// Clear fragments
		$fragments_key = $this->cache_prefix . 'wish_fragments_' . $this->get_user_identifier();
		delete_transient( $fragments_key );

		// Clear session
		if ( isset( $_SESSION['miniload_cache'] ) ) {
			unset( $_SESSION['miniload_cache'][ $woodmart_key ] );
			unset( $_SESSION['miniload_cache'][ $yith_key ] );
		}
	}

	/**
	 * AJAX handler to get all counts
	 */
	public function ajax_get_counts() {
		$counts = array();

		// Get cart count
		if ( WC()->cart ) {
			$counts['cart'] = $this->get_cached_cart_count( WC()->cart->get_cart_contents_count() );
		} else {
			$counts['cart'] = 0;
		}

		// Get wishlist count
		$counts['wishlist'] = $this->get_cached_woodmart_wishlist_count( null );

		// Add cache info
		$counts['cached'] = true;
		$counts['cache_time'] = time();

		wp_send_json_success( $counts );
	}

	/**
	 * Register settings
	 */
	public function register_settings() {
		foreach ( $this->cache_durations as $type => $duration ) {
			register_setting(
				'miniload_settings',
				'miniload_frontend_cache_' . $type,
				array(
					'type' => 'integer',
					'default' => $duration,
					'sanitize_callback' => 'absint'
				)
			);
		}
	}

	/**
	 * Get statistics
	 */
	public function get_stats() {
		global $wpdb;

		$stats = array(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for performance optimization
			'cart_caches' => $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options}
				WHERE option_name LIKE '_transient_" . esc_sql( $this->cache_prefix ) . "cart_%'"
			),
			'wishlist_caches' => $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options}
				WHERE option_name LIKE '_transient_" . esc_sql( $this->cache_prefix ) . "wishlist_%'"
			),
			'fragment_caches' => $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options}
				WHERE option_name LIKE '_transient_" . esc_sql( $this->cache_prefix ) . "fragments_%'"
			)
		);

		return $stats;
	}
}