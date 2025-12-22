<?php
/**
 * Pagination Optimizer Module
 *
 * Eliminates SQL_CALC_FOUND_ROWS which causes full table scans
 * Uses fast COUNT queries or estimation for total rows
 *
 * @package MiniLoad\Modules
 * @since 1.0.0
 */

namespace MiniLoad\Modules;

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

/**
 * Pagination Optimizer class
 */
class Pagination_Optimizer {

	/**
	 * Total count cache
	 *
	 * @var array
	 */
	private $count_cache = array();

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->init_hooks();
	}

	/**
	 * Initialize hooks
	 */
	private function init_hooks() {
		// Remove SQL_CALC_FOUND_ROWS from product queries
		add_filter( 'posts_clauses', array( $this, 'remove_found_rows' ), 100, 2 );
		add_filter( 'posts_request', array( $this, 'remove_found_rows_from_query' ), 100, 2 );

		// Provide alternative count method
		add_filter( 'found_posts', array( $this, 'get_found_posts' ), 10, 2 );

		// Cache invalidation
		add_action( 'woocommerce_update_product', array( $this, 'invalidate_count_cache' ) );
		add_action( 'woocommerce_new_product', array( $this, 'invalidate_count_cache' ) );
		add_action( 'before_delete_post', array( $this, 'invalidate_count_cache_for_post' ) );

		// Admin bar info
		add_action( 'admin_bar_menu', array( $this, 'add_admin_bar_info' ), 200 );
	}

	/**
	 * Remove SQL_CALC_FOUND_ROWS from queries
	 *
	 * @param array $clauses SQL clauses
	 * @param WP_Query $query Query object
	 * @return array
	 */
	public function remove_found_rows( $clauses, $query ) {
		// Only for product queries
		if ( ! $this->should_optimize_pagination( $query ) ) {
			return $clauses;
		}

		// Check if query has SQL_CALC_FOUND_ROWS
		if ( strpos( $clauses['fields'], 'SQL_CALC_FOUND_ROWS' ) !== false ) {
			// Remove it
			$clauses['fields'] = str_replace( 'SQL_CALC_FOUND_ROWS', '', $clauses['fields'] );

			// Mark query as optimized
			$query->set( 'miniload_optimized_pagination', true );

			miniload_log( 'Removed SQL_CALC_FOUND_ROWS from product query', 'debug' );
		}

		return $clauses;
	}

	/**
	 * Remove SQL_CALC_FOUND_ROWS from final query string
	 *
	 * @param string $request SQL query
	 * @param WP_Query $query Query object
	 * @return string
	 */
	public function remove_found_rows_from_query( $request, $query ) {
		// Check if this is a query we should optimize
		if ( ! $this->should_optimize_pagination( $query ) ) {
			// But still check if it was marked as optimized (backward compatibility)
			if ( ! $query->get( 'miniload_optimized_pagination' ) ) {
				return $request;
			}
		}

		// Remove SQL_CALC_FOUND_ROWS if present
		if ( strpos( $request, 'SQL_CALC_FOUND_ROWS' ) !== false ) {
			$request = str_replace( 'SQL_CALC_FOUND_ROWS', '', $request );

			// Mark query as optimized
			$query->set( 'miniload_optimized_pagination', true );

			miniload_log( 'Removed SQL_CALC_FOUND_ROWS from query via posts_request filter', 'debug' );
		}

		return $request;
	}

	/**
	 * Get found posts count using fast method
	 *
	 * @param int $found_posts Original found posts count
	 * @param WP_Query $query Query object
	 * @return int
	 */
	public function get_found_posts( $found_posts, $query ) {
		// Only for optimized queries
		if ( ! $query->get( 'miniload_optimized_pagination' ) ) {
			return $found_posts;
		}

		// Generate cache key for this count query
		$cache_key = $this->get_count_cache_key( $query );

		// Check memory cache first
		if ( isset( $this->count_cache[ $cache_key ] ) ) {
			miniload_log( sprintf( 'Count cache hit (memory): %s', $cache_key ), 'debug' );
			return $this->count_cache[ $cache_key ];
		}

		// Check persistent cache
		$cached_count = get_transient( 'miniload_count_' . $cache_key );
		if ( $cached_count !== false ) {
			$this->count_cache[ $cache_key ] = $cached_count;
			miniload_log( sprintf( 'Count cache hit (transient): %s', $cache_key ), 'debug' );
			return $cached_count;
		}

		// Calculate count using fast method
		$count = $this->calculate_found_posts( $query );

		// Cache the result
		$this->count_cache[ $cache_key ] = $count;
		set_transient( 'miniload_count_' . $cache_key, $count, 300 ); // 5 minutes

		miniload_log( sprintf( 'Calculated count: %d for key %s', $count, $cache_key ), 'debug' );

		return $count;
	}

	/**
	 * Calculate found posts using fast COUNT query
	 *
	 * @param WP_Query $query Query object
	 * @return int
	 */
	private function calculate_found_posts( $query ) {
		global $wpdb;

		$post_type = $query->get( 'post_type' );

		// For order queries with search, we already have the IDs!
		if ( $post_type === 'shop_order' && $query->is_search() ) {
			// The search has already limited to specific IDs
			// Just count them from the WHERE clause
			$request = $query->request;
			if ( preg_match( '/wp_posts\.ID IN \(([^)]+)\)/', $request, $matches ) ) {
				$ids = explode( ',', $matches[1] );
				return count( $ids );
			}
		}

		// For simple product queries, use direct count
		if ( $post_type === 'product' && $this->is_simple_product_query( $query ) ) {
			return $this->get_simple_product_count( $query );
		}

		// For complex queries, build optimized COUNT query
		$count_query = $this->build_count_query( $query );

		if ( $count_query ) {
			$start = microtime( true );
			// Direct database query with caching
		$cache_key = 'miniload_' . md5(  $count_query  );
		$cached = wp_cache_get( $cache_key );
		if ( false === $cached ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Required for performance optimization
			$cached = $wpdb->get_var( $wpdb->prepare( "%s", $count_query ) );
			wp_cache_set( $cache_key, $cached, '', 3600 );
		}
		$total_products = $cached;
			$time = ( microtime( true ) - $start ) * 1000;

			miniload_log( sprintf( 'Count query executed in %.2fms', $time ), 'debug' );

			return intval( $count );
		}

		// Fallback: use the actual post count * estimated pages
		$posts_per_page = $query->get( 'posts_per_page' );
		$current_count = $query->post_count;

		// If we got a full page, estimate there are more
		if ( $current_count == $posts_per_page ) {
			// Use a reasonable estimate
			return $this->estimate_total_posts( $query );
		}

		return $current_count;
	}

	/**
	 * Check if query is simple enough for direct count
	 *
	 * @param WP_Query $query Query object
	 * @return bool
	 */
	private function is_simple_product_query( $query ) {
		// Check for complex meta queries
		$meta_query = $query->get( 'meta_query' );
		if ( ! empty( $meta_query ) && count( $meta_query ) > 2 ) {
			return false;
		}

		// Check for complex tax queries
		$tax_query = $query->get( 'tax_query' );
		if ( ! empty( $tax_query ) && count( $tax_query ) > 2 ) {
			return false;
		}

		// Check for search
		if ( ! empty( $query->get( 's' ) ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Get simple product count
	 *
	 * @param WP_Query $query Query object
	 * @return int
	 */
	private function get_simple_product_count( $query ) {
		global $wpdb;

		$where = "WHERE post_type = 'product' AND post_status = 'publish'";

		// Add taxonomy filters if present
		$tax_query = $query->get( 'tax_query' );
		if ( ! empty( $tax_query ) ) {
			foreach ( $tax_query as $tax ) {
				if ( ! is_array( $tax ) ) continue;

				if ( isset( $tax['taxonomy'] ) && isset( $tax['terms'] ) ) {
					$term_ids = array_map( 'intval', (array) $tax['terms'] );
					$term_list = implode( ',', $term_ids );

					$where .= $wpdb->prepare( "
						AND ID IN (
							SELECT object_id
							FROM {$wpdb->term_relationships}
							WHERE term_taxonomy_id IN (%s)
						)
					", $term_list );
				}
			}
		}

		$count_query = "
			SELECT COUNT(*)
			FROM {$wpdb->posts}
			{$where}
		";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for performance optimization
		return intval( $wpdb->get_var( $wpdb->prepare( "%s", $count_query ) ) );
	}

	/**
	 * Build optimized COUNT query
	 *
	 * @param WP_Query $query Query object
	 * @return string|false
	 */
	private function build_count_query( $query ) {
		global $wpdb;

		$post_type = $query->get( 'post_type' );

		// Start with basic count
		$count_query = "SELECT COUNT(DISTINCT {$wpdb->posts}.ID) FROM {$wpdb->posts}";

		// Add necessary joins
		$join = '';

		// Add taxonomy joins if needed (for products)
		$tax_query = $query->get( 'tax_query' );
		if ( ! empty( $tax_query ) && $post_type === 'product' ) {
			$join .= " LEFT JOIN {$wpdb->term_relationships} ON ({$wpdb->posts}.ID = {$wpdb->term_relationships}.object_id)";
		}

		// Build WHERE clause
		$where = " WHERE 1=1";

		// Handle different post types
		if ( $post_type === 'shop_order' ) {
			$where .= " AND {$wpdb->posts}.post_type = 'shop_order'";
			// Get all order statuses
			$post_status = $query->get( 'post_status' );
			if ( is_array( $post_status ) ) {
				$statuses = array_map( 'esc_sql', $post_status );
				$where .= " AND {$wpdb->posts}.post_status IN ('" . implode( "','", $statuses ) . "')";
			} elseif ( ! empty( $post_status ) ) {
				$where .= $wpdb->prepare( " AND {$wpdb->posts}.post_status = %s", $post_status );
			}
		} else {
			$where .= " AND {$wpdb->posts}.post_type = 'product'";
			// Handle different product visibility
			$post_status = $query->get( 'post_status' );
			if ( is_array( $post_status ) ) {
				$statuses = array_map( 'esc_sql', $post_status );
				$where .= " AND {$wpdb->posts}.post_status IN ('" . implode( "','", $statuses ) . "')";
			} elseif ( ! empty( $post_status ) ) {
				$where .= $wpdb->prepare( " AND {$wpdb->posts}.post_status = %s", $post_status );
			} else {
				$where .= " AND ({$wpdb->posts}.post_status = 'publish' OR {$wpdb->posts}.post_status = 'private')";
			}
		}

		// Add search condition if present
		$search = $query->get( 's' );
		if ( ! empty( $search ) ) {
			$where .= $wpdb->prepare( " AND {$wpdb->posts}.post_title LIKE %s", '%' . $search . '%' );
		}

		// Add taxonomy conditions (for products)
		if ( ! empty( $tax_query ) && $post_type === 'product' ) {
			foreach ( $tax_query as $tax ) {
				if ( ! is_array( $tax ) ) continue;

				if ( isset( $tax['taxonomy'] ) && isset( $tax['terms'] ) ) {
					$term_ids = array_map( 'intval', (array) $tax['terms'] );
					$where .= " AND {$wpdb->term_relationships}.term_taxonomy_id IN (" . implode( ',', $term_ids ) . ")";
				}
			}
		}

		return $count_query . $join . $where;
	}

	/**
	 * Estimate total posts for complex queries
	 *
	 * @param WP_Query $query Query object
	 * @return int
	 */
	private function estimate_total_posts( $query ) {
		global $wpdb;

		// Get base product count
		// Direct database query with caching
		$cache_key = 'miniload_' . md5(  "
			SELECT COUNT(*)
			FROM {$wpdb->posts}
			WHERE post_type = 'product'
			AND post_status = 'publish'
		"  );
		$cached = wp_cache_get( $cache_key );
		if ( false === $cached ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Required for performance optimization
			$cached = $wpdb->get_var( "
			SELECT COUNT(*)
			FROM {$wpdb->posts}
			WHERE post_type = 'product'
			AND post_status = 'publish'
		" );
			wp_cache_set( $cache_key, $cached, '', 3600 );
		}

		// Apply reduction factor based on filters
		$reduction_factor = 1.0;

		// If search query, reduce by 80%
		if ( ! empty( $query->get( 's' ) ) ) {
			$reduction_factor *= 0.2;
		}

		// If taxonomy filter, reduce by 50% per taxonomy
		$tax_query = $query->get( 'tax_query' );
		if ( ! empty( $tax_query ) ) {
			$reduction_factor *= pow( 0.5, count( $tax_query ) );
		}

		// If meta query, reduce by 60% per condition
		$meta_query = $query->get( 'meta_query' );
		if ( ! empty( $meta_query ) ) {
			$reduction_factor *= pow( 0.4, count( $meta_query ) );
		}

		$estimated = intval( $total_products * $reduction_factor );

		// Ensure minimum based on current results
		$current_count = $query->post_count;
		$posts_per_page = $query->get( 'posts_per_page' );
		$paged = max( 1, $query->get( 'paged' ) );

		$minimum = ( $paged - 1 ) * $posts_per_page + $current_count;

		return max( $estimated, $minimum );
	}

	/**
	 * Check if pagination should be optimized
	 *
	 * @param WP_Query $query Query object
	 * @return bool
	 */
	private function should_optimize_pagination( $query ) {
		// Optimize both product AND order queries
		$post_type = $query->get( 'post_type' );

		// Check for product or shop_order post types
		$valid_types = array( 'product', 'shop_order' );

		if ( is_string( $post_type ) ) {
			if ( ! in_array( $post_type, $valid_types ) ) {
				return false;
			}
		} elseif ( is_array( $post_type ) ) {
			$has_valid = false;
			foreach ( $valid_types as $type ) {
				if ( in_array( $type, $post_type ) ) {
					$has_valid = true;
					break;
				}
			}
			if ( ! $has_valid ) {
				return false;
			}
		} else {
			return false;
		}

		// Don't optimize single queries
		if ( $query->is_single() ) {
			return false;
		}

		// Check if pagination is needed
		if ( $query->get( 'nopaging' ) ) {
			return false;
		}

		// ALWAYS optimize in admin for orders
		if ( is_admin() && $post_type === 'shop_order' ) {
			return true;
		}

		// ALWAYS optimize frontend product queries (shop, category, etc)
		if ( ! is_admin() && $post_type === 'product' ) {
			return true;
		}

		// For AJAX requests, optimize both products and orders
		if ( miniload_is_ajax() && in_array( $post_type, array( 'product', 'shop_order' ) ) ) {
			return true;
		}

		// Don't optimize other admin queries
		if ( is_admin() && ! miniload_is_ajax() ) {
			return false;
		}

		return true;
	}

	/**
	 * Get count cache key
	 *
	 * @param WP_Query $query Query object
	 * @return string
	 */
	private function get_count_cache_key( $query ) {
		$key_parts = array(
			'post_type' => $query->get( 'post_type' ),
			's'         => $query->get( 's' ),
			'tax_query' => $query->get( 'tax_query' ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Part of cache key generation
			'meta_query' => $query->get( 'meta_query' ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Part of cache key generation
		);

		return 'pc_' . md5( serialize( $key_parts ) );
	}

	/**
	 * Invalidate count cache
	 */
	public function invalidate_count_cache() {
		global $wpdb;

		// Clear all pagination count caches
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for performance optimization
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_miniload_count_%'" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for performance optimization
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_miniload_count_%'" );

		// Clear memory cache
		$this->count_cache = array();

		miniload_log( 'Pagination count cache invalidated', 'debug' );
	}

	/**
	 * Invalidate count cache for post deletion
	 *
	 * @param int $post_id Post ID
	 */
	public function invalidate_count_cache_for_post( $post_id ) {
		if ( get_post_type( $post_id ) === 'product' ) {
			$this->invalidate_count_cache();
		}
	}

	/**
	 * Add admin bar info
	 *
	 * @param WP_Admin_Bar $admin_bar Admin bar object
	 */
	public function add_admin_bar_info( $admin_bar ) {
		if ( ! current_user_can( 'manage_options' ) || is_admin() ) {
			return;
		}

		// Check if we optimized any queries on this page
		global $wp_query;
		if ( $wp_query && $wp_query->get( 'miniload_optimized_pagination' ) ) {
			$admin_bar->add_menu( array(
				'id'    => 'miniload-pagination-info',
				'title' => '<span style="color: #47d147;"><img src="' . plugin_dir_url( dirname( __FILE__ ) ) . 'assets/images/logo.png" style="height: 14px; vertical-align: middle; margin-right: 3px;">Pagination Optimized</span>',
				'meta'  => array(
					'title' => __( 'SQL_CALC_FOUND_ROWS removed by MiniLoad', 'miniload' ),
				),
			) );
		}
	}

	/**
	 * Get statistics
	 *
	 * @return array
	 */
	public function get_stats() {
		global $wpdb;

		$stats = array();

		// Count cached entries
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for performance optimization
		$stats['cached_counts'] = $wpdb->get_var( "
			SELECT COUNT(*)
			FROM {$wpdb->options}
			WHERE option_name LIKE '_transient_miniload_count_%'
		" );

		// Memory cache size
		$stats['memory_cache_size'] = count( $this->count_cache );

		// Estimate time saved (SQL_CALC_FOUND_ROWS typically adds 200-500ms)
		$stats['estimated_time_saved'] = $stats['cached_counts'] * 350; // ms

		return $stats;
	}
}