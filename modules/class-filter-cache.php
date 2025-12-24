<?php
/**
 * Filter Cache Module
 *
 * Denormalized filter values for instant filter queries
 * Pre-calculates min/max prices, available attributes, stock status
 *
 * @package MiniLoad\Modules
 * @since 1.0.0
 */

namespace MiniLoad\Modules;

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

/**
 * Filter Cache class
 */
class Filter_Cache {

	/**
	 * Table name
	 *
	 * @var string
	 */
	private $table_name;

	/**
	 * Track if filter is already applied
	 *
	 * @var bool
	 */
	private $filter_applied = false;

	/**
	 * Cached product IDs for current request
	 *
	 * @var array|null
	 */
	private $current_filter_ids = null;

	/**
	 * Constructor
	 */
	public function __construct() {
		global $wpdb;
		$this->table_name = $wpdb->prefix . 'miniload_filter_cache';

		$this->init_hooks();
	}

	/**
	 * Initialize hooks
	 */
	private function init_hooks() {
		// Hook into WooCommerce filter queries
		add_filter( 'woocommerce_product_query_meta_query', array( $this, 'optimize_meta_filters' ), 100, 2 );
		add_filter( 'woocommerce_product_query_tax_query', array( $this, 'optimize_tax_filters' ), 100, 2 );

		// Price filter optimization
		add_filter( 'woocommerce_price_filter_widget_min_amount', array( $this, 'get_cached_min_price' ) );
		add_filter( 'woocommerce_price_filter_widget_max_amount', array( $this, 'get_cached_max_price' ) );

		// Layered nav counts optimization
		add_filter( 'woocommerce_layered_nav_count', array( $this, 'get_cached_term_count' ), 10, 3 );

		// Update cache when products change
		add_action( 'woocommerce_update_product', array( $this, 'invalidate_product_filters' ), 10, 2 );
		add_action( 'woocommerce_new_product', array( $this, 'invalidate_product_filters' ), 10, 2 );
		add_action( 'before_delete_post', array( $this, 'invalidate_filters_for_post' ), 10, 1 );

		// Rebuild cache
		add_action( 'wp_ajax_miniload_rebuild_filter_cache', array( $this, 'ajax_rebuild_cache' ) );

		// Warm cache periodically
		add_action( 'miniload_warm_filter_cache', array( $this, 'warm_cache' ) );
		if ( ! wp_next_scheduled( 'miniload_warm_filter_cache' ) ) {
			wp_schedule_event( time(), 'hourly', 'miniload_warm_filter_cache' );
		}
	}

	/**
	 * Optimize meta query filters
	 *
	 * @param array $meta_query Meta query
	 * @param WC_Query $wc_query WC Query object
	 * @return array
	 */
	public function optimize_meta_filters( $meta_query, $wc_query ) {
		// Prevent multiple filter applications in the same request
		if ( $this->filter_applied ) {
			return $meta_query;
		}

		// Check if we have cached filter data
		$cache_key = $this->get_filter_cache_key( $meta_query );
		$cached = $this->get_cached_filter_results( $cache_key );

		if ( $cached !== false ) {
			// Store IDs for use in filter
			$this->current_filter_ids = isset( $cached['product_ids'] ) ? $cached['product_ids'] : array();
			$this->filter_applied = true;

			// Add filter using a named method
			add_filter( 'posts_where', array( $this, 'apply_cached_filter_ids' ), 100 );

			miniload_log( sprintf( 'Filter cache hit: %s', $cache_key ), 'debug' );

			// Return empty meta query since we're using IDs directly
			return array();
		}

		return $meta_query;
	}

	/**
	 * Apply cached filter IDs to query
	 *
	 * @param string $where Where clause
	 * @return string
	 */
	public function apply_cached_filter_ids( $where ) {
		global $wpdb;

		if ( ! empty( $this->current_filter_ids ) ) {
			$ids = implode( ',', array_map( 'intval', $this->current_filter_ids ) );
			$where .= " AND {$wpdb->posts}.ID IN ({$ids})";
		}

		// Remove filter after applying to prevent multiple applications
		remove_filter( 'posts_where', array( $this, 'apply_cached_filter_ids' ), 100 );
		$this->filter_applied = false;

		return $where;
	}

	/**
	 * Optimize taxonomy filters
	 *
	 * @param array $tax_query Tax query
	 * @param WC_Query $wc_query WC Query object
	 * @return array
	 */
	public function optimize_tax_filters( $tax_query, $wc_query ) {
		// Prevent multiple filter applications in the same request
		if ( $this->filter_applied ) {
			return $tax_query;
		}

		// For simple taxonomy queries, use our optimized lookup
		if ( $this->is_simple_tax_query( $tax_query ) ) {
			$product_ids = $this->get_products_by_tax_fast( $tax_query );

			if ( $product_ids !== false ) {
				// Store IDs for use in filter
				$this->current_filter_ids = $product_ids;
				$this->filter_applied = true;

				// Add filter using the same named method
				add_filter( 'posts_where', array( $this, 'apply_cached_filter_ids' ), 100 );

				// Return empty tax query
				return array();
			}
		}

		return $tax_query;
	}

	/**
	 * Get cached minimum price
	 *
	 * @param float $min_price Current min price
	 * @return float
	 */
	public function get_cached_min_price( $min_price ) {
		$cached = get_transient( 'miniload_min_price' );

		if ( $cached === false ) {
			global $wpdb;

			// Get from our sort index (super fast!)
			// Direct database query with caching
		$cache_key = 'miniload_' . md5(  "
				SELECT MIN(price)
				FROM {$wpdb->prefix}miniload_sort_index
				WHERE price > 0
			"  );
		$cached = wp_cache_get( $cache_key );
		if ( false === $cached ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Required for performance optimization
			$cached = $wpdb->get_var( "
				SELECT MIN(price)
				FROM {$wpdb->prefix}miniload_sort_index
				WHERE price > 0
			" );
			wp_cache_set( $cache_key, $cached, '', 3600 );
		}

			set_transient( 'miniload_min_price', $cached, 3600 ); // 1 hour
		}

		return $cached !== null ? $cached : $min_price;
	}

	/**
	 * Get cached maximum price
	 *
	 * @param float $max_price Current max price
	 * @return float
	 */
	public function get_cached_max_price( $max_price ) {
		$cached = get_transient( 'miniload_max_price' );

		if ( $cached === false ) {
			global $wpdb;

			// Get from our sort index (super fast!)
			// Direct database query with caching
		$cache_key = 'miniload_' . md5(  "
				SELECT MAX(price)
				FROM {$wpdb->prefix}miniload_sort_index
			"  );
		$cached = wp_cache_get( $cache_key );
		if ( false === $cached ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Required for performance optimization
			$cached = $wpdb->get_var( "
				SELECT MAX(price)
				FROM {$wpdb->prefix}miniload_sort_index
			" );
			wp_cache_set( $cache_key, $cached, '', 3600 );
		}

			set_transient( 'miniload_max_price', $cached, 3600 ); // 1 hour
		}

		return $cached !== null ? $cached : $max_price;
	}

	/**
	 * Get cached term count for layered nav
	 *
	 * @param int $count Current count
	 * @param object $term Term object
	 * @param string $taxonomy Taxonomy name
	 * @return int
	 */
	public function get_cached_term_count( $count, $term, $taxonomy ) {
		// Handle different types of $term parameter
		if ( is_numeric( $term ) ) {
			// If term is passed as ID, get the term object
			$term = get_term( $term, $taxonomy );
		} elseif ( is_string( $term ) ) {
			// If term is passed as slug, get the term object
			$term = get_term_by( 'slug', $term, $taxonomy );
		}

		// Ensure $term is an object with the properties we need
		if ( ! is_object( $term ) || ! isset( $term->term_id ) || ! isset( $term->term_taxonomy_id ) ) {
			return $count;
		}

		$transient_key = 'miniload_term_count_' . $taxonomy . '_' . $term->term_id;
		$cached_count = get_transient( $transient_key );

		if ( $cached_count === false ) {
			global $wpdb;

			// Fast count using direct query
			// Direct database query with caching
			$wp_cache_key = 'miniload_' . md5( $wpdb->prepare( "
				SELECT COUNT(DISTINCT p.ID)
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
				WHERE p.post_type = 'product'
				AND p.post_status = 'publish'
				AND tr.term_taxonomy_id = %d
			", $term->term_taxonomy_id ) );

			$cached_count = wp_cache_get( $wp_cache_key );
			if ( false === $cached_count ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Required for performance optimization
				$cached_count = $wpdb->get_var( $wpdb->prepare( "
					SELECT COUNT(DISTINCT p.ID)
					FROM {$wpdb->posts} p
					INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
					WHERE p.post_type = 'product'
					AND p.post_status = 'publish'
					AND tr.term_taxonomy_id = %d
				", $term->term_taxonomy_id ) );
				wp_cache_set( $wp_cache_key, $cached_count, '', 3600 );
			}

			set_transient( $transient_key, $cached_count, 3600 ); // 1 hour
		}

		return intval( $cached_count );
	}

	/**
	 * Check if tax query is simple enough to optimize
	 *
	 * @param array $tax_query Tax query
	 * @return bool
	 */
	private function is_simple_tax_query( $tax_query ) {
		if ( empty( $tax_query ) || ! is_array( $tax_query ) ) {
			return false;
		}

		// Check if it's a simple single taxonomy query
		$non_relation_items = array_filter( $tax_query, function( $item ) {
			return is_array( $item ) && isset( $item['taxonomy'] );
		});

		return count( $non_relation_items ) <= 2;
	}

	/**
	 * Get products by taxonomy fast
	 *
	 * @param array $tax_query Tax query
	 * @return array|false
	 */
	private function get_products_by_tax_fast( $tax_query ) {
		global $wpdb;

		$cache_key = 'tax_' . md5( serialize( $tax_query ) );
		$cached = get_transient( 'miniload_filter_' . $cache_key );

		if ( $cached !== false ) {
			return $cached;
		}

		// Build optimized query
		$queries = array();

		foreach ( $tax_query as $query ) {
			if ( ! is_array( $query ) || ! isset( $query['taxonomy'] ) ) {
				continue;
			}

			$terms = (array) $query['terms'];
			$terms = array_map( 'intval', $terms );

			if ( empty( $terms ) ) {
				continue;
			}

			$operator = isset( $query['operator'] ) ? $query['operator'] : 'IN';

			if ( $operator === 'IN' ) {
				$term_placeholders = implode( ',', array_fill( 0, count( $terms ), '%d' ) );
				$prepare_values = array( $query['taxonomy'] );
				foreach ( $terms as $term ) {
					$prepare_values[] = intval( $term );
				}
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query uses dynamic placeholders
				$queries[] = $wpdb->prepare( "
					SELECT DISTINCT object_id
					FROM {$wpdb->term_relationships} tr
					INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
					WHERE tt.taxonomy = %s
					AND tr.term_taxonomy_id IN ($term_placeholders)
				", $prepare_values );
			}
		}

		if ( empty( $queries ) ) {
			return false;
		}

		// Combine queries
		$relation = isset( $tax_query['relation'] ) ? $tax_query['relation'] : 'AND';

		if ( $relation === 'OR' && count( $queries ) > 1 ) {
			$sql = "SELECT DISTINCT object_id FROM (" . implode( ' UNION ', $queries ) . ") as combined";
		} else {
			// AND relation - need intersection
			if ( count( $queries ) === 1 ) {
				$sql = $queries[0];
			} else {
				$sql = $queries[0];
				for ( $i = 1; $i < count( $queries ); $i++ ) {
					$sql = "SELECT object_id FROM ({$sql}) t1 WHERE object_id IN ({$queries[$i]})";
				}
			}
		}

		// Add product post type filter
		$sql = "
			SELECT DISTINCT p.ID
			FROM ({$sql}) as tax_results
			INNER JOIN {$wpdb->posts} p ON tax_results.object_id = p.ID
			WHERE p.post_type = 'product'
			AND p.post_status = 'publish'
		";

		// Direct database query - SQL is already prepared above
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql is built from prepared queries
		$product_ids = $wpdb->get_col( $sql );

		// Cache results
		set_transient( 'miniload_filter_' . $cache_key, $product_ids, 300 ); // 5 minutes

		return $product_ids;
	}

	/**
	 * Get filter cache key
	 *
	 * @param mixed $filter_data Filter data
	 * @return string
	 */
	private function get_filter_cache_key( $filter_data ) {
		return 'fc_' . md5( serialize( $filter_data ) );
	}

	/**
	 * Get cached filter results
	 *
	 * @param string $cache_key Cache key
	 * @return mixed
	 */
	private function get_cached_filter_results( $cache_key ) {
		global $wpdb;

		// Try memory cache first
		$cached = wp_cache_get( $cache_key, 'miniload_filters' );
		if ( $cached !== false ) {
			return $cached;
		}

		// Try database cache
		// Direct database query with caching
		$db_cache_key = 'miniload_' . md5( $wpdb->prepare( "
			SELECT filter_data
			FROM " . esc_sql( $this->table_name ) . "
			WHERE cache_key = %s
			AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
		", $cache_key ) );
		$db_cached = wp_cache_get( $db_cache_key );
		if ( false === $db_cached ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Required for performance optimization
			$db_cached = $wpdb->get_var( $wpdb->prepare( "
				SELECT filter_data
				FROM " . esc_sql( $this->table_name ) . "
				WHERE cache_key = %s
				AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
			", $cache_key ) );
			wp_cache_set( $db_cache_key, $db_cached, '', 3600 );
		}
		if ( $db_cached ) {
			$data = maybe_unserialize( $db_cached );
			wp_cache_set( $cache_key, $data, 'miniload_filters', 300 );
			return $data;
		}

		return false;
	}

	/**
	 * Store filter results in cache
	 *
	 * @param string $cache_key Cache key
	 * @param array $data Data to cache
	 */
	private function store_filter_cache( $cache_key, $data ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for performance optimization
		$wpdb->replace(
			$this->table_name,
			array(
				'cache_key'   => $cache_key,
				'filter_data' => maybe_serialize( $data ),
				'created_at'  => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s' )
		);

		wp_cache_set( $cache_key, $data, 'miniload_filters', 300 );
	}

	/**
	 * Warm filter cache with common queries
	 */
	public function warm_cache() {
		global $wpdb;

		// Get popular categories
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for performance optimization
		$popular_categories = $wpdb->get_col( "
			SELECT tt.term_id
			FROM {$wpdb->term_taxonomy} tt
			WHERE tt.taxonomy = 'product_cat'
			AND tt.count > 10
			ORDER BY tt.count DESC
			LIMIT 20
		" );

		foreach ( $popular_categories as $cat_id ) {
			// Pre-cache products in this category
			$tax_query = array(
				array(
					'taxonomy' => 'product_cat',
					'terms'    => array( $cat_id ),
					'operator' => 'IN',
				),
			);

			$this->get_products_by_tax_fast( $tax_query );
		}

		// Pre-cache price ranges
		$this->get_cached_min_price( 0 );
		$this->get_cached_max_price( 999999 );

		// Get popular attributes
		$attributes = wc_get_attribute_taxonomies();
		foreach ( $attributes as $attribute ) {
			$taxonomy = 'pa_' . $attribute->attribute_name;

			// Get popular terms
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for performance optimization
			$terms = $wpdb->get_col( $wpdb->prepare( "
				SELECT tt.term_id
				FROM {$wpdb->term_taxonomy} tt
				WHERE tt.taxonomy = %s
				AND tt.count > 5
				ORDER BY tt.count DESC
				LIMIT 10
			", $taxonomy ) );

			foreach ( $terms as $term_id ) {
				// Pre-cache count
				$term = get_term( $term_id, $taxonomy );
				if ( $term ) {
					$this->get_cached_term_count( 0, $term, $taxonomy );
				}
			}
		}

		miniload_log( 'Filter cache warmed', 'debug' );
	}

	/**
	 * Invalidate filter cache for product
	 *
	 * @param int $product_id Product ID
	 * @param WC_Product $product Product object
	 */
	public function invalidate_product_filters( $product_id, $product = null ) {
		// Clear price caches
		delete_transient( 'miniload_min_price' );
		delete_transient( 'miniload_max_price' );

		// Clear term count caches for this product's terms
		if ( ! $product ) {
			$product = wc_get_product( $product_id );
		}

		if ( $product ) {
			// Clear category caches
			$cat_ids = $product->get_category_ids();
			foreach ( $cat_ids as $cat_id ) {
				delete_transient( 'miniload_term_count_product_cat_' . $cat_id );
			}

			// Clear attribute caches
			$attributes = $product->get_attributes();
			foreach ( $attributes as $attribute ) {
				if ( $attribute->is_taxonomy() ) {
					$terms = wp_get_post_terms( $product_id, $attribute->get_name(), array( 'fields' => 'ids' ) );
					foreach ( $terms as $term_id ) {
						delete_transient( 'miniload_term_count_' . $attribute->get_name() . '_' . $term_id );
					}
				}
			}
		}

		// Clear general filter caches
		$this->clear_filter_caches();

		miniload_log( sprintf( 'Filter cache invalidated for product #%d', $product_id ), 'debug' );
	}

	/**
	 * Invalidate filters for post deletion
	 *
	 * @param int $post_id Post ID
	 */
	public function invalidate_filters_for_post( $post_id ) {
		if ( get_post_type( $post_id ) === 'product' ) {
			$this->invalidate_product_filters( $post_id );
		}
	}

	/**
	 * Clear filter caches
	 */
	private function clear_filter_caches() {
		global $wpdb;

		// Clear transients
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for performance optimization
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_miniload_filter_%'" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for performance optimization
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_miniload_filter_%'" );

		// Clear cache table
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for performance optimization
		$wpdb->query( "DELETE FROM " . esc_sql( $this->table_name ) . " WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 DAY)" );

		// Clear object cache
		wp_cache_flush_group( 'miniload_filters' );
	}

	/**
	 * AJAX: Rebuild filter cache
	 */
	public function ajax_rebuild_cache() {
		// Security check
		if ( ! check_ajax_referer( 'miniload-ajax', 'nonce', false ) ||
		     ! current_user_can( 'manage_options' ) ) {
			wp_die( -1 );
		}

		// Clear all caches
		$this->clear_filter_caches();

		// Warm cache
		$this->warm_cache();

		wp_send_json_success( array(
			'message' => __( 'Filter cache rebuilt successfully', 'miniload' ),
		) );
	}

	/**
	 * Get statistics
	 *
	 * @return array
	 */
	public function get_stats() {
		global $wpdb;

		$stats = array();

		// Cache size
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for performance optimization
		$stats['cache_entries'] = $wpdb->get_var( "SELECT COUNT(*) FROM " . esc_sql( $this->table_name ) );

		// Cache hit rate (estimated)
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for performance optimization
		$stats['transient_caches'] = $wpdb->get_var( "
			SELECT COUNT(*)
			FROM {$wpdb->options}
			WHERE option_name LIKE '_transient_miniload_filter_%'
		" );

		// Average cache age
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for performance optimization
		$stats['avg_cache_age'] = $wpdb->get_var( "
			SELECT AVG(TIMESTAMPDIFF(MINUTE, created_at, NOW()))
			FROM " . esc_sql( $this->table_name ) . "
		" );

		return $stats;
	}
}