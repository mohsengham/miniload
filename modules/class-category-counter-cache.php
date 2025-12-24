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
	 * Batch loaded category counts
	 */
	private $batch_cache = array();

	/**
	 * Track if we've batch loaded for this request
	 */
	private $batch_loaded = false;

	/**
	 * Constructor
	 */
	public function __construct() {
		global $wpdb;
		$this->table_name = $wpdb->prefix . 'miniload_category_counts';

		// Create table if needed
		add_action( 'init', array( $this, 'maybe_create_table' ) );

		// Reset batch cache for each request
		add_action( 'wp', array( $this, 'reset_batch_cache' ) );

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

		// Check if table exists using the correct variable
		if ( $cached !== $this->table_name ) {
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

			// Don't populate immediately - let it happen via scheduled event
			// $this->update_all_counts(); // Commented out - too expensive to run on init
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

		// Get all term IDs including children
		$term_ids = get_term_children( $term_id, 'product_cat' );
		$term_ids[] = $term_id;
		$term_ids = array_map( 'intval', $term_ids );
		$term_ids_str = implode( ',', $term_ids );

		// Count all products in category using direct SQL (much faster than WP_Query)
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Required for performance optimization
		$product_count = $wpdb->get_var( "
			SELECT COUNT(DISTINCT p.ID)
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
			INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
			WHERE p.post_type = 'product'
			AND p.post_status = 'publish'
			AND tt.term_id IN ({$term_ids_str})
		" );

		// Count visible products (in stock, not hidden) using direct SQL
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Required for performance optimization
		$visible_count = $wpdb->get_var( "
			SELECT COUNT(DISTINCT p.ID)
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
			INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
			LEFT JOIN {$wpdb->postmeta} pm_vis ON p.ID = pm_vis.post_id AND pm_vis.meta_key = '_visibility'
			LEFT JOIN {$wpdb->postmeta} pm_stock ON p.ID = pm_stock.post_id AND pm_stock.meta_key = '_stock_status'
			WHERE p.post_type = 'product'
			AND p.post_status = 'publish'
			AND tt.term_id IN ({$term_ids_str})
			AND (pm_vis.meta_value IN ('catalog', 'visible') OR pm_vis.meta_value IS NULL)
			AND (pm_stock.meta_value = 'instock' OR pm_stock.meta_value IS NULL)
		" );

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

		// Update term counts from batch cache or WordPress cache ONLY
		foreach ( $terms as &$term ) {
			if ( ! is_object( $term ) || ! isset( $term->term_id ) ) {
				continue;
			}

			// Check batch cache first
			if ( isset( $this->batch_cache[ $term->term_id ] ) ) {
				$cached = $this->batch_cache[ $term->term_id ];
				$term->count = $cached->product_count;
				$term->visible_count = $cached->visible_count;
				continue;
			}

			// Check WordPress object cache
			$cache_key = 'miniload_cat_count_' . $term->term_id;
			$cached = wp_cache_get( $cache_key, 'miniload' );

			if ( false !== $cached ) {
				$term->count = $cached->product_count;
				$term->visible_count = $cached->visible_count;
			}
		}

		return $terms;
	}

	/**
	 * Reset batch cache for new page load
	 */
	public function reset_batch_cache() {
		$this->batch_cache = array();
		$this->batch_loaded = false;
	}

	/**
	 * Batch load all category counts in one query
	 */
	private function batch_load_category_counts() {
		// Mark as loaded to prevent multiple calls
		$this->batch_loaded = true;

		global $wpdb;

		// Get all product category term IDs - NO LIMIT
		$terms = get_terms( array(
			'taxonomy' => 'product_cat',
			'hide_empty' => false,
			'fields' => 'ids',
		) );

		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			return;
		}

		// Batch load all counts in ONE query
		$ids_placeholder = implode( ',', array_fill( 0, count( $terms ), '%d' ) );

		$query = $wpdb->prepare(
			"SELECT term_id, product_count, visible_count
			FROM " . esc_sql( $this->table_name ) . "
			WHERE term_id IN ($ids_placeholder)
			AND last_updated > DATE_SUB(NOW(), INTERVAL %d SECOND)",
			array_merge( $terms, array( $this->cache_duration ) )
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results( $query );

		// Store in batch cache AND WordPress object cache
		foreach ( $results as $row ) {
			$this->batch_cache[ $row->term_id ] = $row;
			$cache_key = 'miniload_cat_count_' . $row->term_id;
			wp_cache_set( $cache_key, $row, 'miniload', 300 );
		}

		if ( ! empty( $results ) ) {
			miniload_log( sprintf( '[Category Counts] Batch loaded %d category counts in 1 query', count( $results ) ), 'info' );
		}
	}

	/**
	 * Get cached count HTML for subcategories
	 */
	public function get_cached_count_html( $html, $category ) {
		// Check batch cache first
		if ( isset( $this->batch_cache[ $category->term_id ] ) ) {
			$cached = $this->batch_cache[ $category->term_id ];
			$count = apply_filters( 'miniload_subcategory_count_html_count', $cached->visible_count, $category );
			return ' <mark class="count">(' . esc_html( $count ) . ')</mark>';
		}

		// Check WordPress object cache
		$cache_key = 'miniload_cat_count_' . $category->term_id;
		$cached = wp_cache_get( $cache_key, 'miniload' );

		if ( false !== $cached ) {
			$count = apply_filters( 'miniload_subcategory_count_html_count', $cached->visible_count, $category );
			return ' <mark class="count">(' . esc_html( $count ) . ')</mark>';
		}

		// No cache - just return original HTML
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