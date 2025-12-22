<?php
/**
 * Query Cache Module
 *
 * Intelligent caching of WooCommerce product queries
 * Caches expensive database results while allowing dynamic elements to remain fresh
 *
 * @package MiniLoad\Modules
 * @since 1.0.0
 */

namespace MiniLoad\Modules;

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

/**
 * Query Cache class
 */
class Query_Cache {

	/**
	 * Cache group
	 *
	 * @var string
	 */
	private $cache_group = 'miniload_query';

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
		// Hook into pre_get_posts to check cache before query runs
		add_action( 'pre_get_posts', array( $this, 'maybe_serve_from_cache' ), 1 );

		// Hook into posts results to cache them
		add_filter( 'posts_results', array( $this, 'maybe_cache_results' ), 100, 2 );

		// Invalidation hooks
		add_action( 'woocommerce_update_product', array( $this, 'invalidate_product_cache' ), 10, 1 );
		add_action( 'woocommerce_new_product', array( $this, 'invalidate_product_cache' ), 10, 1 );
		add_action( 'before_delete_post', array( $this, 'invalidate_product_cache' ), 10, 1 );
		add_action( 'edited_product_cat', array( $this, 'invalidate_category_cache' ), 10, 1 );
		add_action( 'edited_product_tag', array( $this, 'invalidate_tag_cache' ), 10, 1 );

		// Clear cache action
		add_action( 'miniload_clear_all_caches', array( $this, 'clear_all_cache' ) );

		// Admin bar cache clear
		add_action( 'admin_bar_menu', array( $this, 'add_admin_bar_button' ), 100 );
		add_action( 'admin_post_miniload_clear_query_cache', array( $this, 'handle_admin_bar_clear' ) );
	}

	/**
	 * Check if query results can be served from cache
	 *
	 * @param WP_Query $query Query object
	 */
	public function maybe_serve_from_cache( $query ) {
		// Only cache product queries
		if ( ! $this->should_cache_query( $query ) ) {
			return;
		}

		// Generate cache key
		$cache_key = $this->generate_cache_key( $query );

		// Try to get from cache
		$cached = $this->get_cached_results( $cache_key );

		if ( $cached !== false ) {
			// We have cached results!
			miniload_log( sprintf( 'Query cache hit: %s', $cache_key ), 'debug' );

			// Set the query results
			$query->posts = $cached['posts'];
			$query->post_count = $cached['post_count'];
			$query->found_posts = $cached['found_posts'];
			$query->max_num_pages = $cached['max_num_pages'];

			// Tell WordPress to skip the database query
			$query->set( 'posts_per_page', 0 );
			$query->set( 'no_found_rows', true );

			// Add filter to return our cached posts
			add_filter( 'posts_pre_query', function( $posts, $q ) use ( $cached, $query ) {
				if ( $q === $query ) {
					return $cached['posts'];
				}
				return $posts;
			}, 10, 2 );

			// Track cache hit
			$this->track_cache_hit( $cache_key );
		}
	}

	/**
	 * Cache query results
	 *
	 * @param array $posts Posts array
	 * @param WP_Query $query Query object
	 * @return array
	 */
	public function maybe_cache_results( $posts, $query ) {
		// Only cache if appropriate
		if ( ! $this->should_cache_query( $query ) ) {
			return $posts;
		}

		// Don't cache if we already served from cache
		if ( $query->get( 'posts_per_page' ) === 0 ) {
			return $posts;
		}

		// Generate cache key
		$cache_key = $this->generate_cache_key( $query );

		// Prepare data to cache
		$cache_data = array(
			'posts'         => $posts,
			'post_count'    => $query->post_count,
			'found_posts'   => $query->found_posts,
			'max_num_pages' => $query->max_num_pages,
			'cached_at'     => time(),
			'query_vars'    => $this->get_cacheable_query_vars( $query ),
		);

		// Store in cache
		$this->set_cached_results( $cache_key, $cache_data );

		miniload_log( sprintf( 'Query cached: %s (%d posts)', $cache_key, count( $posts ) ), 'debug' );

		return $posts;
	}

	/**
	 * Check if query should be cached
	 *
	 * @param WP_Query $query Query object
	 * @return bool
	 */
	private function should_cache_query( $query ) {
		// Don't cache in admin
		if ( is_admin() && ! miniload_is_ajax() ) {
			return false;
		}

		// Don't cache if user is logged in (personalized content)
		if ( is_user_logged_in() && ! miniload_is_ajax() ) {
			return false;
		}

		// Only cache product queries
		$post_type = $query->get( 'post_type' );
		if ( $post_type !== 'product' && ! is_array( $post_type ) ) {
			return false;
		}

		if ( is_array( $post_type ) && ! in_array( 'product', $post_type ) ) {
			return false;
		}

		// Don't cache single product queries
		if ( $query->is_single() ) {
			return false;
		}

		// Don't cache random queries
		if ( $query->get( 'orderby' ) === 'rand' ) {
			return false;
		}

		// Check if it's a main query or important query
		if ( ! $query->is_main_query() &&
		     ! $query->is_archive() &&
		     ! $query->is_search() &&
		     ! doing_action( 'woocommerce_shortcode_products_query' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Generate cache key for query
	 *
	 * @param WP_Query $query Query object
	 * @return string
	 */
	private function generate_cache_key( $query ) {
		// Get important query variables
		$key_parts = array(
			'post_type'      => $query->get( 'post_type' ),
			'posts_per_page' => $query->get( 'posts_per_page' ),
			'paged'          => $query->get( 'paged', 1 ),
			'orderby'        => $query->get( 'orderby' ),
			'order'          => $query->get( 'order' ),
			's'              => $query->get( 's' ),
			'meta_query'     => $query->get( 'meta_query' ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Part of cache key generation
			'tax_query'      => $query->get( 'tax_query' ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Part of cache key generation
		);

		// Add WooCommerce specific variables
		if ( function_exists( 'WC' ) && isset( WC()->query ) ) {
			$key_parts['product_cat'] = $query->get( 'product_cat' );
			$key_parts['product_tag'] = $query->get( 'product_tag' );
		}

		// Generate hash
		$key = 'qc_' . md5( serialize( $key_parts ) );

		return $key;
	}

	/**
	 * Get cacheable query variables
	 *
	 * @param WP_Query $query Query object
	 * @return array
	 */
	private function get_cacheable_query_vars( $query ) {
		return array(
			'post_type'      => $query->get( 'post_type' ),
			'posts_per_page' => $query->get( 'posts_per_page' ),
			'paged'          => $query->get( 'paged', 1 ),
			'orderby'        => $query->get( 'orderby' ),
			'order'          => $query->get( 'order' ),
		);
	}

	/**
	 * Get cached results
	 *
	 * @param string $cache_key Cache key
	 * @return mixed
	 */
	private function get_cached_results( $cache_key ) {
		// Try transient first
		$cached = get_transient( 'miniload_' . $cache_key );

		if ( $cached !== false ) {
			// Check if cache is still valid
			$ttl = miniload_get_option( 'cache_ttl', 300 );
			if ( ( time() - $cached['cached_at'] ) < $ttl ) {
				return $cached;
			}
		}

		return false;
	}

	/**
	 * Set cached results
	 *
	 * @param string $cache_key Cache key
	 * @param array $data Data to cache
	 */
	private function set_cached_results( $cache_key, $data ) {
		$ttl = miniload_get_option( 'cache_ttl', 300 );
		set_transient( 'miniload_' . $cache_key, $data, $ttl );
	}

	/**
	 * Invalidate product-related caches
	 *
	 * @param int $product_id Product ID
	 */
	public function invalidate_product_cache( $product_id ) {
		// Clear all query caches when a product changes
		// In production, this could be more selective
		$this->clear_all_cache();

		miniload_log( sprintf( 'Query cache invalidated for product #%d', $product_id ), 'debug' );
	}

	/**
	 * Invalidate category-related caches
	 *
	 * @param int $term_id Term ID
	 */
	public function invalidate_category_cache( $term_id ) {
		$this->clear_all_cache();
		miniload_log( sprintf( 'Query cache invalidated for category #%d', $term_id ), 'debug' );
	}

	/**
	 * Invalidate tag-related caches
	 *
	 * @param int $term_id Term ID
	 */
	public function invalidate_tag_cache( $term_id ) {
		$this->clear_all_cache();
		miniload_log( sprintf( 'Query cache invalidated for tag #%d', $term_id ), 'debug' );
	}

	/**
	 * Clear all query caches
	 */
	public function clear_all_cache() {
		global $wpdb;

		// Delete all MiniLoad query cache transients
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for performance optimization
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_miniload_qc_%'" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for performance optimization
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_miniload_qc_%'" );

		// Reset cache stats
		delete_option( 'miniload_cache_stats' );

		miniload_log( 'All query caches cleared', 'info' );
	}

	/**
	 * Track cache hit
	 *
	 * @param string $cache_key Cache key
	 */
	private function track_cache_hit( $cache_key ) {
		$stats = get_option( 'miniload_cache_stats', array() );

		if ( ! isset( $stats['hits'] ) ) {
			$stats['hits'] = 0;
		}
		$stats['hits']++;

		if ( ! isset( $stats['hit_keys'] ) ) {
			$stats['hit_keys'] = array();
		}
		if ( ! isset( $stats['hit_keys'][ $cache_key ] ) ) {
			$stats['hit_keys'][ $cache_key ] = 0;
		}
		$stats['hit_keys'][ $cache_key ]++;

		update_option( 'miniload_cache_stats', $stats, false );
	}

	/**
	 * Get cache statistics
	 *
	 * @return array
	 */
	public function get_stats() {
		global $wpdb;

		$stats = get_option( 'miniload_cache_stats', array() );

		// Count cached queries
		// Direct database query with caching
		$cache_key = 'miniload_' . md5(  "
			SELECT COUNT(*)
			FROM {$wpdb->options}
			WHERE option_name LIKE '_transient_miniload_qc_%'
		"  );
		$cached = wp_cache_get( $cache_key );
		if ( false === $cached ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Required for performance optimization
			$cached = $wpdb->get_var( "
			SELECT COUNT(*)
			FROM {$wpdb->options}
			WHERE option_name LIKE '_transient_miniload_qc_%'
		" );
			wp_cache_set( $cache_key, $cached, '', 3600 );
		}

		$stats['cached_queries'] = $cache_count;

		// Calculate hit rate
		if ( isset( $stats['hits'] ) && isset( $stats['misses'] ) ) {
			$total = $stats['hits'] + $stats['misses'];
			$stats['hit_rate'] = $total > 0 ? round( ( $stats['hits'] / $total ) * 100, 2 ) : 0;
		}

		// Get top cached queries
		if ( isset( $stats['hit_keys'] ) ) {
			arsort( $stats['hit_keys'] );
			$stats['top_queries'] = array_slice( $stats['hit_keys'], 0, 10, true );
		}

		return $stats;
	}

	/**
	 * Add admin bar button for cache clearing
	 *
	 * @param WP_Admin_Bar $admin_bar Admin bar object
	 */
	public function add_admin_bar_button( $admin_bar ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$admin_bar->add_menu( array(
			'id'    => 'miniload-clear-cache',
			'title' => __( 'Clear MiniLoad Cache', 'miniload' ),
			'href'  => wp_nonce_url( admin_url( 'admin-post.php?action=miniload_clear_query_cache' ), 'miniload_clear_cache' ),
			'meta'  => array(
				'title' => __( 'Clear all MiniLoad query caches', 'miniload' ),
			),
		) );
	}

	/**
	 * Handle admin bar cache clear
	 */
	public function handle_admin_bar_clear() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied', 'miniload' ) );
		}

		check_admin_referer( 'miniload_clear_cache' );

		$this->clear_all_cache();

		// Redirect back with success message
		wp_safe_redirect( add_query_arg( array(
			'miniload_cache_cleared' => '1',
		), wp_get_referer() ) );
		exit;
	}
}