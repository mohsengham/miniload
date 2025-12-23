<?php
/**
 * Notification Counts Cache Module
 *
 * Caches admin notification counts to prevent constant database queries
 * SAFE: Read-only operation, only affects admin display
 *
 * @package MiniLoad
 * @subpackage Modules
 */

namespace MiniLoad\Modules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Notification Counts Cache class
 */
class Notification_Counts_Cache {

	/**
	 * Cache prefix
	 */
	private $cache_prefix = 'miniload_notif_';

	/**
	 * Cache durations for different notifications (in seconds)
	 */
	private $cache_durations = array(
		'pending_reviews'     => 300,   // 5 minutes
		'pending_orders'      => 60,    // 1 minute (more critical)
		'low_stock'          => 600,   // 10 minutes
		'out_of_stock'       => 600,   // 10 minutes
		'pending_products'   => 300,   // 5 minutes
		'failed_orders'      => 180,   // 3 minutes
		'on_hold_orders'     => 180,   // 3 minutes
		'processing_orders'  => 60,    // 1 minute
		'refund_requests'    => 180,   // 3 minutes
		'trash_products'     => 3600,  // 1 hour
	);

	/**
	 * Constructor
	 */
	public function __construct() {
		// Only run in admin
		if ( ! is_admin() ) {
			return;
		}

		// Hook into admin menu to cache notification counts
		add_filter( 'add_menu_classes', array( $this, 'cache_menu_counts' ), 10, 1 );
		add_filter( 'woocommerce_admin_menu_count', array( $this, 'get_cached_menu_count' ), 10, 2 );

		// Cache comment counts (for reviews)
		add_filter( 'wp_count_comments', array( $this, 'cache_comment_counts' ), 10, 2 );

		// Cache product counts
		add_filter( 'wp_count_posts', array( $this, 'cache_post_counts' ), 10, 3 );

		// WooCommerce specific counts
		add_filter( 'woocommerce_product_review_count', array( $this, 'get_cached_review_count' ), 10, 2 );
		add_filter( 'woocommerce_shipping_zone_count', array( $this, 'get_cached_shipping_count' ), 10, 1 );

		// Stock status counts
		add_action( 'admin_init', array( $this, 'register_stock_count_hooks' ) );

		// Order status counts - optimize the bubbles
		add_filter( 'woocommerce_order_count', array( $this, 'get_cached_order_count' ), 10, 2 );

		// Clear cache on relevant changes
		add_action( 'wp_insert_comment', array( $this, 'clear_review_cache' ) );
		add_action( 'woocommerce_new_order', array( $this, 'clear_order_cache' ) );
		add_action( 'woocommerce_order_status_changed', array( $this, 'clear_order_cache' ) );
		add_action( 'woocommerce_product_set_stock_status', array( $this, 'clear_stock_cache' ) );
		add_action( 'woocommerce_variation_set_stock_status', array( $this, 'clear_stock_cache' ) );

		// AJAX endpoint for getting all counts at once
		add_action( 'wp_ajax_miniload_get_notification_counts', array( $this, 'ajax_get_all_counts' ) );

		// Add to admin bar for quick view
		add_action( 'admin_bar_menu', array( $this, 'add_notification_summary' ), 100 );

		// Settings
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Get all notification counts at once
	 */
	public function get_all_notification_counts() {
		$counts = array();

		// Pending orders
		$counts['pending_orders'] = $this->get_cached_count( 'pending_orders', function() {
			return count( wc_get_orders( array( 'status' => 'pending', 'return' => 'ids', 'limit' => -1 ) ) );
		}, $this->cache_durations['pending_orders'] );

		// Processing orders
		$counts['processing_orders'] = $this->get_cached_count( 'processing_orders', function() {
			return count( wc_get_orders( array( 'status' => 'processing', 'return' => 'ids', 'limit' => -1 ) ) );
		}, $this->cache_durations['processing_orders'] );

		// On-hold orders
		$counts['on_hold_orders'] = $this->get_cached_count( 'on_hold_orders', function() {
			return count( wc_get_orders( array( 'status' => 'on-hold', 'return' => 'ids', 'limit' => -1 ) ) );
		}, $this->cache_durations['on_hold_orders'] );

		// Failed orders
		$counts['failed_orders'] = $this->get_cached_count( 'failed_orders', function() {
			return count( wc_get_orders( array( 'status' => 'failed', 'return' => 'ids', 'limit' => -1 ) ) );
		}, $this->cache_durations['failed_orders'] );

		// Pending reviews
		$counts['pending_reviews'] = $this->get_cached_count( 'pending_reviews', function() {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for performance optimization
			return $wpdb->get_var( "
				SELECT COUNT(*)
				FROM {$wpdb->comments}
				WHERE comment_type = 'review'
				AND comment_approved = '0'
			" );
		}, $this->cache_durations['pending_reviews'] );

		// Low stock products
		$counts['low_stock'] = $this->get_cached_count( 'low_stock', function() {
			global $wpdb;
			$low_stock_threshold = get_option( 'woocommerce_notify_low_stock_amount', 2 );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for performance optimization
			return $wpdb->get_var( $wpdb->prepare( "
				SELECT COUNT(DISTINCT p.ID)
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
				WHERE p.post_type IN ('product', 'product_variation')
				AND p.post_status = 'publish'
				AND pm.meta_key = '_stock'
				AND pm.meta_value > 0
				AND pm.meta_value <= %d
			", $low_stock_threshold ) );
		}, $this->cache_durations['low_stock'] );

		// Out of stock products
		$counts['out_of_stock'] = $this->get_cached_count( 'out_of_stock', function() {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for performance optimization
			return $wpdb->get_var( "
				SELECT COUNT(DISTINCT p.ID)
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
				WHERE p.post_type IN ('product', 'product_variation')
				AND p.post_status = 'publish'
				AND pm.meta_key = '_stock_status'
				AND pm.meta_value = 'outofstock'
			" );
		}, $this->cache_durations['out_of_stock'] );

		// Refund requests
		$counts['refund_requests'] = $this->get_cached_count( 'refund_requests', function() {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for performance optimization
			return $wpdb->get_var( "
				SELECT COUNT(*)
				FROM {$wpdb->posts}
				WHERE post_type = 'shop_order_refund'
				AND post_status = 'wc-pending'
			" );
		}, $this->cache_durations['refund_requests'] );

		// Pending products (drafts)
		$counts['pending_products'] = $this->get_cached_count( 'pending_products', function() {
			return wp_count_posts( 'product' )->draft;
		}, $this->cache_durations['pending_products'] );

		// Trashed products
		$counts['trash_products'] = $this->get_cached_count( 'trash_products', function() {
			return wp_count_posts( 'product' )->trash;
		}, $this->cache_durations['trash_products'] );

		// Calculate totals
		$counts['total_notifications'] = array_sum( array_filter( $counts, 'is_numeric' ) );
		$counts['requires_action'] = $counts['pending_orders'] + $counts['failed_orders'] +
		                             $counts['pending_reviews'] + $counts['refund_requests'];

		return $counts;
	}

	/**
	 * Generic cached count getter
	 */
	private function get_cached_count( $key, $callback, $duration = 300 ) {
		$cache_key = $this->cache_prefix . $key;
		$cached = get_transient( $cache_key );

		if ( false === $cached ) {
			$count = call_user_func( $callback );
			set_transient( $cache_key, $count, $duration );
			return $count;
		}

		return $cached;
	}

	/**
	 * Cache menu counts
	 */
	public function cache_menu_counts( $menu ) {
		// This runs when WordPress builds the admin menu
		// We can pre-cache counts here
		$this->get_all_notification_counts();
		return $menu;
	}

	/**
	 * Get cached menu count for WooCommerce
	 */
	public function get_cached_menu_count( $count, $type ) {
		$counts = $this->get_all_notification_counts();

		switch ( $type ) {
			case 'orders':
				return $counts['pending_orders'] + $counts['processing_orders'];
			case 'reviews':
				return $counts['pending_reviews'];
			case 'products':
				return $counts['low_stock'] + $counts['out_of_stock'];
			default:
				return $count;
		}
	}

	/**
	 * Cache comment counts
	 */
	public function cache_comment_counts( $counts, $post_id ) {
		// Only cache for all comments (post_id = 0)
		if ( $post_id !== 0 ) {
			return $counts;
		}

		$cache_key = $this->cache_prefix . 'comment_counts';
		$cached = get_transient( $cache_key );

		if ( false === $cached ) {
			set_transient( $cache_key, $counts, 300 );
			return $counts;
		}

		return $cached;
	}

	/**
	 * Cache post counts
	 */
	public function cache_post_counts( $counts, $type, $perm ) {
		if ( ! in_array( $type, array( 'product', 'shop_order' ) ) ) {
			return $counts;
		}

		$cache_key = $this->cache_prefix . 'post_counts_' . $type . '_' . $perm;
		$cached = get_transient( $cache_key );

		if ( false === $cached ) {
			set_transient( $cache_key, $counts, 300 );
			return $counts;
		}

		return $cached;
	}

	/**
	 * Get cached review count
	 */
	public function get_cached_review_count( $count, $product_id ) {
		return $this->get_cached_count( 'review_count_' . $product_id, function() use ( $count ) {
			return $count;
		}, 3600 );
	}

	/**
	 * Get cached shipping zone count
	 */
	public function get_cached_shipping_count( $count ) {
		return $this->get_cached_count( 'shipping_zones', function() use ( $count ) {
			return $count;
		}, 3600 );
	}

	/**
	 * Get cached order count by status
	 */
	public function get_cached_order_count( $count, $status ) {
		return $this->get_cached_count( 'order_status_' . $status, function() use ( $count ) {
			return $count;
		}, $this->cache_durations['pending_orders'] );
	}

	/**
	 * Register stock count hooks
	 */
	public function register_stock_count_hooks() {
		// Hook into stock status widget
		add_filter( 'woocommerce_admin_stock_html', array( $this, 'optimize_stock_widget' ), 10, 2 );
	}

	/**
	 * Optimize stock widget counts
	 */
	public function optimize_stock_widget( $html, $product ) {
		// Cache the stock status counts
		$this->get_cached_count( 'stock_widget_' . $product->get_id(), function() use ( $html ) {
			return $html;
		}, 600 );

		return $html;
	}

	/**
	 * Add notification summary to admin bar
	 */
	public function add_notification_summary( $wp_admin_bar ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$counts = $this->get_all_notification_counts();

		// Only show if there are notifications
		if ( $counts['requires_action'] > 0 ) {
			$title = sprintf(
				'<span class="ab-icon dashicons dashicons-bell"></span> <span class="ab-label">%d</span>',
				$counts['requires_action']
			);

			$wp_admin_bar->add_menu( array(
				'id'     => 'miniload-notifications',
				'parent' => 'top-secondary',
				'title'  => $title,
				'href'   => admin_url( 'edit.php?post_type=shop_order' ),
				'meta'   => array(
					'class' => $counts['requires_action'] > 5 ? 'miniload-urgent' : '',
					'title' => 'Notifications requiring action'
				)
			) );

			// Add submenu items
			if ( $counts['pending_orders'] > 0 ) {
				$wp_admin_bar->add_menu( array(
					'id'     => 'miniload-notif-orders',
					'parent' => 'miniload-notifications',
					'title'  => sprintf( 'Pending Orders (%d)', $counts['pending_orders'] ),
					'href'   => admin_url( 'edit.php?post_type=shop_order&post_status=wc-pending' )
				) );
			}

			if ( $counts['pending_reviews'] > 0 ) {
				$wp_admin_bar->add_menu( array(
					'id'     => 'miniload-notif-reviews',
					'parent' => 'miniload-notifications',
					'title'  => sprintf( 'Pending Reviews (%d)', $counts['pending_reviews'] ),
					'href'   => admin_url( 'edit-comments.php?comment_status=moderated&comment_type=review' )
				) );
			}

			if ( $counts['low_stock'] > 0 ) {
				$wp_admin_bar->add_menu( array(
					'id'     => 'miniload-notif-stock',
					'parent' => 'miniload-notifications',
					'title'  => sprintf( 'Low Stock (%d)', $counts['low_stock'] ),
					'href'   => admin_url( 'admin.php?page=wc-reports&tab=stock&report=low_in_stock' )
				) );
			}
		}
	}

	/**
	 * AJAX handler for getting all counts
	 */
	public function ajax_get_all_counts() {
		check_ajax_referer( 'miniload_notifications' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die();
		}

		$counts = $this->get_all_notification_counts();

		// Add cache info
		$counts['cached_at'] = time();
		$counts['cache_duration'] = $this->cache_durations;

		wp_send_json_success( $counts );
	}

	/**
	 * Clear review-related cache
	 */
	public function clear_review_cache() {
		delete_transient( $this->cache_prefix . 'pending_reviews' );
		delete_transient( $this->cache_prefix . 'comment_counts' );
	}

	/**
	 * Clear order-related cache
	 */
	public function clear_order_cache() {
		$order_keys = array(
			'pending_orders',
			'processing_orders',
			'on_hold_orders',
			'failed_orders',
			'refund_requests'
		);

		foreach ( $order_keys as $key ) {
			delete_transient( $this->cache_prefix . $key );
		}

		// Clear post counts
		delete_transient( $this->cache_prefix . 'post_counts_shop_order_readable' );
		delete_transient( $this->cache_prefix . 'post_counts_shop_order_editable' );
	}

	/**
	 * Clear stock-related cache
	 */
	public function clear_stock_cache() {
		delete_transient( $this->cache_prefix . 'low_stock' );
		delete_transient( $this->cache_prefix . 'out_of_stock' );
	}

	/**
	 * Register settings
	 */
	public function register_settings() {
		foreach ( $this->cache_durations as $type => $duration ) {
			register_setting(
				'miniload_settings',
				'miniload_notification_cache_' . $type,
				array(
					'type' => 'integer',
					'default' => $duration,
					'sanitize_callback' => 'absint'
				)
			);
		}
	}

	/**
	 * Get cache statistics
	 */
	public function get_stats() {
		$counts = $this->get_all_notification_counts();

		return array(
			'total_notifications' => $counts['total_notifications'],
			'requires_action' => $counts['requires_action'],
			'cache_hit_rate' => $this->calculate_hit_rate(),
			'counts' => $counts
		);
	}

	/**
	 * Calculate cache hit rate
	 */
	private function calculate_hit_rate() {
		global $wpdb;

		// Direct database query with caching
		$cache_key = 'miniload_' . md5(  "
			SELECT COUNT(*)
			FROM {$wpdb->options}
			WHERE option_name LIKE '_transient_" . esc_sql( $this->cache_prefix ) . "%'
		"  );
		$cached = wp_cache_get( $cache_key );
		if ( false === $cached ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Required for performance optimization
			$cached = $wpdb->get_var( "
			SELECT COUNT(*)
			FROM {$wpdb->options}
			WHERE option_name LIKE '_transient_" . esc_sql( $this->cache_prefix ) . "%'
		" );
			wp_cache_set( $cache_key, $cached, '', 3600 );
		}

		// Direct database query with caching
		$cache_key = 'miniload_' . md5(  "
			SELECT COUNT(*)
			FROM {$wpdb->options}
			WHERE option_name LIKE '_transient_timeout_{$this->cache_prefix}%'
			AND option_value < UNIX_TIMESTAMP()
		"  );
		$cached = wp_cache_get( $cache_key );
		if ( false === $cached ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Required for performance optimization
			$cached = $wpdb->get_var( "
			SELECT COUNT(*)
			FROM {$wpdb->options}
			WHERE option_name LIKE '_transient_timeout_" . esc_sql( $this->cache_prefix ) . "%'
			AND option_value < UNIX_TIMESTAMP()
		" );
			wp_cache_set( $cache_key, $cached, '', 3600 );
		}

		if ( $total > 0 ) {
			return round( ( ( $total - $expired ) / $total ) * 100, 2 ) . '%';
		}

		return '0%';
	}
}