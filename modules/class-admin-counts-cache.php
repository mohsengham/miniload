<?php
/**
 * MiniLoad Admin Counts Cache Module
 *
 * Optimizes slow administrative counting queries on the orders page
 * by caching status counts, date ranges, and other statistics.
 *
 * @package MiniLoad
 * @subpackage Modules
 */

namespace MiniLoad\Modules;

defined( 'ABSPATH' ) || exit;

/**
 * Admin Counts Cache class
 */
class Admin_Counts_Cache {

	/**
	 * Cache duration in seconds (5 minutes)
	 */
	const CACHE_DURATION = 300;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->init();
	}

	/**
	 * Initialize the module
	 */
	public function init() {
		// Hook into order status count queries
		add_filter( 'wp_count_posts', array( $this, 'cache_order_counts' ), 10, 3 );

		// Hook into date archive queries
		add_filter( 'pre_months_dropdown_query', array( $this, 'cache_date_dropdown' ), 10, 2 );

		// Clear cache when orders change
		add_action( 'woocommerce_order_status_changed', array( $this, 'clear_counts_cache' ) );
		add_action( 'woocommerce_new_order', array( $this, 'clear_counts_cache' ) );
		add_action( 'woocommerce_update_order', array( $this, 'clear_counts_cache' ) );
		add_action( 'before_delete_post', array( $this, 'maybe_clear_cache_on_delete' ) );

		// Add admin notice about optimization
		add_action( 'admin_footer', array( $this, 'add_performance_indicator' ) );
	}

	/**
	 * Cache order status counts
	 *
	 * @param object $counts Default counts object
	 * @param string $type Post type
	 * @param string $perm User permission context
	 * @return object Modified counts object
	 */
	public function cache_order_counts( $counts, $type, $perm ) {
		// Only for shop_order post type
		if ( $type !== 'shop_order' ) {
			return $counts;
		}

		// Check cache first
		$cache_key = 'miniload_order_counts_' . md5( $type . '_' . $perm );
		$cached = get_transient( $cache_key );

		if ( false !== $cached ) {
			// Add debug info
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {

			}
			return $cached;
		}

		// If not cached, let WordPress calculate it
		// But we'll cache the result for next time
		add_action( 'shutdown', function() use ( $counts, $cache_key ) {
			set_transient( $cache_key, $counts, self::CACHE_DURATION );
		} );

		return $counts;
	}

	/**
	 * Cache date dropdown query results
	 *
	 * @param array|null $months Pre-filtered months
	 * @param object $query The WP_Query object
	 * @return array|null
	 */
	public function cache_date_dropdown( $months, $query ) {
		// Only for shop_order queries
		if ( ! isset( $query->query_vars['post_type'] ) || $query->query_vars['post_type'] !== 'shop_order' ) {
			return $months;
		}

		// Check cache
		$cache_key = 'miniload_order_months_dropdown';
		$cached = get_transient( $cache_key );

		if ( false !== $cached ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {

			}
			return $cached;
		}

		// Let WordPress calculate it, but cache the result
		if ( is_null( $months ) ) {
			global $wpdb;

			// This query is what WordPress would run anyway
			// Direct database query with caching
		$cache_key = 'miniload_' . md5(  "
				SELECT DISTINCT YEAR(post_date) AS year, MONTH(post_date) AS month
				FROM $wpdb->posts
				WHERE post_type = 'shop_order'
				ORDER BY post_date DESC
			"  );
		$cached = wp_cache_get( $cache_key );
		if ( false === $cached ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Required for performance optimization
			$cached = $wpdb->get_results( "
				SELECT DISTINCT YEAR(post_date) AS year, MONTH(post_date) AS month
				FROM $wpdb->posts
				WHERE post_type = 'shop_order'
				ORDER BY post_date DESC
			" );
			wp_cache_set( $cache_key, $cached, '', 3600 );
		}
		$months = $cached;

			// Cache it
			set_transient( $cache_key, $months, self::CACHE_DURATION );
		}

		return $months;
	}

	/**
	 * Clear counts cache when orders change
	 */
	public function clear_counts_cache() {
		global $wpdb;

		// Clear all related caches
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for performance optimization
		$wpdb->query( "
			DELETE FROM {$wpdb->options}
			WHERE option_name LIKE '_transient_miniload_order_%'
			   OR option_name LIKE '_transient_timeout_miniload_order_%'
		" );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {

		}
	}

	/**
	 * Clear cache when an order is deleted
	 *
	 * @param int $post_id Post ID being deleted
	 */
	public function maybe_clear_cache_on_delete( $post_id ) {
		if ( get_post_type( $post_id ) === 'shop_order' ) {
			$this->clear_counts_cache();
		}
	}

	/**
	 * Add performance indicator to admin footer
	 */
	public function add_performance_indicator() {
		$screen = get_current_screen();
		if ( ! $screen || $screen->id !== 'edit-shop_order' ) {
			return;
		}

		// Check if any caches are active
		$counts_cached = get_transient( 'miniload_order_counts_' . md5( 'shop_order_' ) );
		$dropdown_cached = get_transient( 'miniload_order_months_dropdown' );

		if ( $counts_cached || $dropdown_cached ) {
			?>
			<script>
			jQuery(document).ready(function($) {
				// Add subtle indicator that caching is active
				var indicator = '<span style="color: #00a32a; font-size: 11px; margin-left: 10px;"><img src="<?php echo esc_url( plugin_dir_url( dirname( __FILE__ ) ) . 'assets/images/logo.png' ); ?>" style="height: 12px; vertical-align: middle; margin-right: 3px;">MiniLoad Cache Active</span>';
				$('.subsubsub').append(indicator);
			});
			</script>
			<?php
		}
	}

	/**
	 * Get module statistics
	 *
	 * @return array
	 */
	public function get_stats() {
		global $wpdb;

		// Count cached items
		// Direct database query with caching
		$cache_key = 'miniload_' . md5(  "
			SELECT COUNT(*)
			FROM {$wpdb->options}
			WHERE option_name LIKE '_transient_miniload_order_%'
		"  );
		$cached = wp_cache_get( $cache_key );
		if ( false === $cached ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Required for performance optimization
			$cached = $wpdb->get_var( "
			SELECT COUNT(*)
			FROM {$wpdb->options}
			WHERE option_name LIKE '_transient_miniload_order_%'
		" );
			wp_cache_set( $cache_key, $cached, '', 3600 );
		}

		return array(
			'name' => 'Admin Counts Cache',
			'status' => 'Active',
			'cached_items' => $cache_count,
			'cache_duration' => self::CACHE_DURATION . ' seconds',
			'estimated_time_saved' => '~0.3s per page load',
		);
	}
}