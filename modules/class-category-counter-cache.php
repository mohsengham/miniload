<?php
/**
 * Category Product Counter Cache Module
 *
 * Pre-counts products in categories for faster display
 * SAFE: Read-only operation, no business logic impact
 *
 * @package MiniLoad
 * @subpackage Modules
 */

namespace MiniLoad\Modules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Category Counter Cache class
 */
class Category_Counter_Cache {

	/**
	 * Cache table name
	 */
	private $table_name;

	/**
	 * Cache duration in seconds (1 hour default)
	 */
	private $cache_duration = 3600;

	/**
	 * Constructor
	 */
	public function __construct() {
		global $wpdb;
		$this->table_name = $wpdb->prefix . 'miniload_category_counts';

		// Create table if needed
		add_action( 'init', array( $this, 'maybe_create_table' ) );

		// Hook into term count updates
		add_filter( 'get_terms', array( $this, 'inject_cached_counts' ), 10, 2 );
		add_filter( 'woocommerce_subcategory_count_html', array( $this, 'get_cached_count_html' ), 10, 2 );

		// Schedule regular updates
		add_action( 'init', array( $this, 'schedule_count_updates' ) );
		add_action( 'miniload_update_category_counts', array( $this, 'update_all_counts' ) );

		// Update counts when products change
		add_action( 'woocommerce_update_product', array( $this, 'update_related_category_counts' ), 10, 1 );
		add_action( 'trashed_post', array( $this, 'update_related_category_counts' ), 10, 1 );
		add_action( 'untrashed_post', array( $this, 'update_related_category_counts' ), 10, 1 );

		// Admin settings
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Maybe create the cache table
	 */
	public function maybe_create_table() {
		global $wpdb;

		// Direct database query with caching
		$cache_key = 'miniload_' . md5(  "SHOW TABLES LIKE '{$this->table_name}'"  );
		$cached = wp_cache_get( $cache_key );
		if ( false === $cached ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Required for performance optimization
			$cached = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $this->table_name ) );
			wp_cache_set( $cache_key, $cached, '', 3600 );
		}

		if ( ! isset( $table_exists ) || $table_exists !== $this->table_name ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';

			$charset_collate = $wpdb->get_charset_collate();

			$sql = "CREATE TABLE {$this->table_name} (
				term_id bigint(20) NOT NULL,
				product_count int(11) NOT NULL DEFAULT 0,
				visible_count int(11) NOT NULL DEFAULT 0,
				last_updated datetime NOT NULL,
				PRIMARY KEY (term_id),
				KEY idx_last_updated (last_updated)
			) $charset_collate;";

			dbDelta( $sql );

			// Initial population
			$this->update_all_counts();
		}
	}

	/**
	 * Schedule regular count updates
	 */
	public function schedule_count_updates() {
		if ( ! wp_next_scheduled( 'miniload_update_category_counts' ) ) {
			wp_schedule_event( time(), 'hourly', 'miniload_update_category_counts' );
		}
	}

	/**
	 * Update all category counts
	 */
	public function update_all_counts() {
		global $wpdb;

		// Get all product categories
		$categories = get_terms( array(
			'taxonomy' => 'product_cat',
			'hide_empty' => false,
			'fields' => 'ids'
		) );

		if ( ! is_wp_error( $categories ) && ! empty( $categories ) ) {
			foreach ( $categories as $cat_id ) {
				$this->update_single_category_count( $cat_id );
			}
		}

		// Log update
		if ( function_exists( 'miniload_log' ) ) {
			miniload_log( 'Updated all category counts', 'info' );
		}
	}

	/**
	 * Update single category count
	 */
	public function update_single_category_count( $term_id ) {
		global $wpdb;

		// Count all products in category (including children categories)
		$args = array(
			'post_type' => 'product',
			'post_status' => 'publish',
			'posts_per_page' => -1,
			'fields' => 'ids',
			'tax_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Required for category filtering
				array(
					'taxonomy' => 'product_cat',
					'field' => 'term_id',
					'terms' => $term_id,
					'include_children' => true
				)
			)
		);

		$query = new \WP_Query( $args );
		$product_count = $query->found_posts;

		// Count visible products (in stock, not hidden)
		$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Required for stock/visibility filtering
			'relation' => 'AND',
			array(
				'key' => '_visibility',
				'value' => array( 'catalog', 'visible' ),
				'compare' => 'IN'
			),
			array(
				'relation' => 'OR',
				array(
					'key' => '_stock_status',
					'value' => 'instock'
				),
				array(
					'key' => '_stock_status',
					'compare' => 'NOT EXISTS'
				)
			)
		);

		$query = new \WP_Query( $args );
		$visible_count = $query->found_posts;

		// Update or insert cache
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for performance optimization
		$wpdb->replace(
			$this->table_name,
			array(
				'term_id' => $term_id,
				'product_count' => $product_count,
				'visible_count' => $visible_count,
				'last_updated' => current_time( 'mysql' )
			),
			array( '%d', '%d', '%d', '%s' )
		);
	}

	/**
	 * Update category counts when product changes
	 */
	public function update_related_category_counts( $product_id ) {
		// Check if it's a product
		if ( get_post_type( $product_id ) !== 'product' ) {
			return;
		}

		// Get product categories
		$categories = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) );

		if ( ! is_wp_error( $categories ) && ! empty( $categories ) ) {
			foreach ( $categories as $cat_id ) {
				$this->update_single_category_count( $cat_id );

				// Also update parent categories
				$parents = get_ancestors( $cat_id, 'product_cat' );
				foreach ( $parents as $parent_id ) {
					$this->update_single_category_count( $parent_id );
				}
			}
		}
	}

	/**
	 * Inject cached counts into term objects
	 */
	public function inject_cached_counts( $terms, $taxonomies ) {
		// Only for product categories
		if ( ! in_array( 'product_cat', (array) $taxonomies ) ) {
			return $terms;
		}

		// Skip in admin to avoid conflicts
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $terms;
		}

		global $wpdb;

		// Get all term IDs
		$term_ids = array();
		foreach ( $terms as $term ) {
			if ( is_object( $term ) && isset( $term->term_id ) ) {
				$term_ids[] = $term->term_id;
			}
		}

		if ( empty( $term_ids ) ) {
			return $terms;
		}

		// Fetch cached counts
		// Direct database query with caching
		$cache_key = 'miniload_' . md5(  $wpdb->prepare(
			"SELECT term_id, product_count, visible_count
			FROM " . esc_sql( $this->table_name ) . "
			WHERE term_id IN (" . implode( ',', array_fill( 0, count( $term_ids ), '%d' ) ) . ")
			AND last_updated > DATE_SUB(NOW(), INTERVAL %d SECOND)",
			array_merge( $term_ids, array( $this->cache_duration ) )
		), OBJECT_K  );
		$cached = wp_cache_get( $cache_key );
		if ( false === $cached ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Required for performance optimization
			$cached = $wpdb->get_results( $wpdb->prepare(
			"SELECT term_id, product_count, visible_count
			FROM " . esc_sql( $this->table_name ) . "
			WHERE term_id IN (" . implode( ',', array_fill( 0, count( $term_ids ), '%d' ) ) . ")
			AND last_updated > DATE_SUB(NOW(), INTERVAL %d SECOND)",
			array_merge( $term_ids, array( $this->cache_duration ) )
		), OBJECT_K );
			wp_cache_set( $cache_key, $cached, '', 3600 );
		}

		// Update term counts
		foreach ( $terms as &$term ) {
			if ( is_object( $term ) && isset( $cached_counts[ $term->term_id ] ) ) {
				$term->count = $cached_counts[ $term->term_id ]->product_count;
				// Add custom property for visible count
				$term->visible_count = $cached_counts[ $term->term_id ]->visible_count;
			}
		}

		return $terms;
	}

	/**
	 * Get cached count HTML for subcategories
	 */
	public function get_cached_count_html( $html, $category ) {
		global $wpdb;

		// Try to get cached count
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for performance optimization
		$cached = $wpdb->get_row( $wpdb->prepare(
			"SELECT product_count, visible_count
			FROM " . esc_sql( $this->table_name ) . "
			WHERE term_id = %d
			AND last_updated > DATE_SUB(NOW(), INTERVAL %d SECOND)",
			$category->term_id,
			$this->cache_duration
		) );

		if ( $cached ) {
			$count = apply_filters( 'miniload_subcategory_count_html_count', $cached->visible_count, $category );
			return ' <mark class="count">(' . esc_html( $count ) . ')</mark>';
		}

		// Fallback to original
		return $html;
	}

	/**
	 * Register settings
	 */
	public function register_settings() {
		register_setting(
			'miniload_settings',
			'miniload_category_cache_duration',
			array(
				'type' => 'integer',
				'default' => 3600,
				'sanitize_callback' => 'absint'
			)
		);

		// Update cache duration from settings
		$duration = get_option( 'miniload_category_cache_duration', 3600 );
		$this->cache_duration = max( 300, $duration ); // Minimum 5 minutes
	}

	/**
	 * Get stats for admin
	 */
	public function get_stats() {
		global $wpdb;

		$stats = array(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for performance optimization
			'total_cached' => $wpdb->get_var( "SELECT COUNT(*) FROM " . esc_sql( $this->table_name ) ),
			'last_update' => $wpdb->get_var( "SELECT MAX(last_updated) FROM " . esc_sql( $this->table_name ) . "" ),
			'cache_size' => $wpdb->get_var( "SELECT ROUND(((data_length + index_length) / 1024 / 1024), 2) AS size FROM information_schema.TABLES WHERE table_schema = DATABASE() AND table_name = '" . esc_sql( $this->table_name ) . "'" )
		);

		return $stats;
	}
}