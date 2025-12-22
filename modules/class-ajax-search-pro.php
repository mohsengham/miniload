<?php
/**
 * AJAX Search Pro Module
 *
 * Ultimate AJAX search with instant results, better than FiboSearch!
 * Features: Live search, suggestions, typo correction, analytics
 *
 * @package MiniLoad
 * @subpackage Modules
 */

namespace MiniLoad\Modules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AJAX Search Pro class
 */
class Ajax_Search_Pro {

	/**
	 * Search table name
	 */
	private $search_table;

	/**
	 * Analytics table name
	 */
	private $analytics_table;

	/**
	 * Suggestions table name
	 */
	private $suggestions_table;

	/**
	 * Minimum characters for search
	 */
	private $min_chars = 2;

	/**
	 * Max results to show
	 */
	private $max_results = 10;

	/**
	 * Constructor
	 */
	public function __construct() {
		global $wpdb;
		$this->search_table = $wpdb->prefix . 'miniload_product_search';
		$this->analytics_table = $wpdb->prefix . 'miniload_search_analytics';
		$this->suggestions_table = $wpdb->prefix . 'miniload_search_suggestions';

		// Load settings from options
		$this->min_chars = absint( get_option( 'miniload_search_min_chars', 3 ) );
		$this->max_results = absint( get_option( 'miniload_search_results_count', 8 ) );

		// Initialize
		add_action( 'init', array( $this, 'init' ) );

		// AJAX endpoints
		add_action( 'wp_ajax_miniload_ajax_search', array( $this, 'handle_ajax_search' ) );
		add_action( 'wp_ajax_nopriv_miniload_ajax_search', array( $this, 'handle_ajax_search' ) );

		// Admin AJAX search (Alt+K)
		add_action( 'wp_ajax_miniload_admin_search', array( $this, 'handle_admin_search' ) );
		add_action( 'wp_ajax_miniload_admin_tabbed_search', array( $this, 'handle_admin_tabbed_search' ) );

		// Assets
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

		// Add search box to site
		add_action( 'wp_footer', array( $this, 'add_search_modal' ) );
		add_action( 'admin_footer', array( $this, 'add_admin_search_modal' ) );

		// Track searches
		add_action( 'wp_ajax_miniload_track_search', array( $this, 'track_search' ) );
		add_action( 'wp_ajax_nopriv_miniload_track_search', array( $this, 'track_search' ) );

		// Async search tracking for performance
		add_action( 'miniload_track_search_event', array( $this, 'track_search_query' ), 10, 2 );

		// Settings
		add_action( 'admin_init', array( $this, 'register_settings' ) );

		// Search index rebuilding
		add_action( 'wp_ajax_miniload_rebuild_search_index', array( $this, 'ajax_rebuild_search_index' ) );

		// Database tables
		add_action( 'init', array( $this, 'maybe_create_tables' ) );
	}

	/**
	 * Initialize
	 */
	public function init() {
		// Add search box shortcode
		add_shortcode( 'miniload_search', array( $this, 'render_search_box' ) );

		// Replace default WordPress search
		add_filter( 'get_search_form', array( $this, 'override_search_form' ), 999 );
	}

	/**
	 * Maybe create tables
	 */
	public function maybe_create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// Analytics table
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is a class property constructed from $wpdb->prefix
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$this->analytics_table}'" ) !== $this->analytics_table ) {
			$charset_collate = $wpdb->get_charset_collate();

			$sql = "CREATE TABLE {$this->analytics_table} (
				id bigint(20) NOT NULL AUTO_INCREMENT,
				search_term varchar(255) NOT NULL,
				results_count int(11) DEFAULT 0,
				clicked_product_id bigint(20) DEFAULT NULL,
				user_id bigint(20) DEFAULT NULL,
				session_id varchar(100),
				search_time datetime NOT NULL,
				PRIMARY KEY (id),
				KEY idx_search_term (search_term),
				KEY idx_search_time (search_time),
				KEY idx_user_id (user_id)
			) $charset_collate;";

			dbDelta( $sql );
		}

		// Suggestions table
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is a class property constructed from $wpdb->prefix
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$this->suggestions_table}'" ) !== $this->suggestions_table ) {
			$charset_collate = $wpdb->get_charset_collate();

			$sql = "CREATE TABLE {$this->suggestions_table} (
				id bigint(20) NOT NULL AUTO_INCREMENT,
				term varchar(255) NOT NULL,
				corrected_term varchar(255),
				popularity int(11) DEFAULT 1,
				last_searched datetime,
				PRIMARY KEY (id),
				UNIQUE KEY idx_term (term),
				KEY idx_popularity (popularity),
				FULLTEXT KEY term_fulltext (term, corrected_term)
			) $charset_collate;";

			dbDelta( $sql );
		}
	}

	/**
	 * Handle AJAX search
	 */
	public function handle_ajax_search() {
		// Verify nonce
		check_ajax_referer( 'miniload_search_nonce', 'nonce' );

		if ( ! isset( $_POST['term'] ) ) {
			wp_send_json_error( 'Search term is required' );
		}

		$search_term = sanitize_text_field( wp_unslash( $_POST['term'] ) );
		$search_type = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : 'product';

		// Allow max_results override from AJAX request
		if ( isset( $_POST['max_results'] ) && is_numeric( $_POST['max_results'] ) ) {
			$this->max_results = min( absint( $_POST['max_results'] ), 50 ); // Cap at 50 for performance
		}

		// Use mb_strlen for multi-byte character support (Persian, Arabic, etc.)
		if ( mb_strlen( $search_term, 'UTF-8' ) < $this->min_chars ) {
			wp_send_json_error( 'Minimum ' . $this->min_chars . ' characters required' );
		}

		$start = microtime( true );

		// Try to get from cache first for speed
		$cache_key = 'miniload_ajax_' . md5( $search_term . '_' . $search_type );
		$cached = wp_cache_get( $cache_key, 'miniload_search' );

		if ( false !== $cached ) {
			// Add fresh search time
			$cached['search_time'] = round( ( microtime( true ) - $start ) * 1000, 2 ) . ' (cached)';
			wp_send_json_success( $cached );
			return;
		}

		// Get search results
		$results = $this->perform_search( $search_term, $search_type );

		// Get suggestions if no results
		$suggestions = array();
		if ( empty( $results['products'] ) ) {
			$suggestions = $this->get_suggestions( $search_term );
		}

		// Track search asynchronously for better performance
		if ( ! wp_next_scheduled( 'miniload_track_search_event' ) ) {
			wp_schedule_single_event( time(), 'miniload_track_search_event', array( $search_term, count( $results['products'] ) ) );
		}

		$search_time = round( ( microtime( true ) - $start ) * 1000, 2 );

		// Calculate total results count (use real total for products if available)
		$total_count = 0;

		// Use the actual total products count if available, otherwise count the displayed items
		if ( isset( $results['total_products'] ) && $results['total_products'] > 0 ) {
			$total_count += $results['total_products'];
		} elseif ( ! empty( $results['products'] ) ) {
			$total_count += count( $results['products'] );
		}

		if ( ! empty( $results['categories'] ) ) {
			$total_count += count( $results['categories'] );
		}
		if ( ! empty( $results['tags'] ) ) {
			$total_count += count( $results['tags'] );
		}
		if ( ! empty( $results['posts'] ) ) {
			$total_count += count( $results['posts'] );
		}

		$response = array(
			'results' => $results,
			'suggestions' => $suggestions,
			'search_time' => $search_time,
			'total_count' => $total_count,
			'total_products' => isset( $results['total_products'] ) ? $results['total_products'] : 0,
			'term' => $search_term
		);

		// Cache for 5 minutes
		wp_cache_set( $cache_key, $response, 'miniload_search', 300 );

		wp_send_json_success( $response );
	}

	/**
	 * Perform search
	 */
	private function perform_search( $term, $type = 'product' ) {
		global $wpdb;

		$results = array(
			'products' => array(),
			'categories' => array(),
			'tags' => array(),
			'posts' => array()
		);

		// Search products using our optimized index with improved performance
		if ( $type === 'all' || $type === 'product' ) {
			// First, get the total count for accurate display
			// Direct database query with caching
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be parameterized
		$cache_key = 'miniload_' . md5(  $wpdb->prepare( "
				SELECT COUNT(DISTINCT p.product_id)
				FROM {$this->search_table} p
				INNER JOIN {$wpdb->posts} post ON p.product_id = post.ID
					AND post.post_status = 'publish'
				WHERE
					(MATCH(p.search_text) AGAINST(%s IN BOOLEAN MODE)
					OR p.sku = %s
					OR post.post_title LIKE %s)
			", $term, $term, $term . '%' )  );
		$cached = wp_cache_get( $cache_key );
		if ( false === $cached ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Required for performance optimization
			// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is a class property constructed from $wpdb->prefix
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be parameterized
			$cached = $wpdb->get_var( $wpdb->prepare( "
				SELECT COUNT(DISTINCT p.product_id)
				FROM {$this->search_table} p
				INNER JOIN {$wpdb->posts} post ON p.product_id = post.ID
					AND post.post_status = 'publish'
				WHERE
					(MATCH(p.search_text) AGAINST(%s IN BOOLEAN MODE)
					OR p.sku = %s
					OR post.post_title LIKE %s)
			", $term, $term, $term . '%' ) );
			wp_cache_set( $cache_key, $cached, '', 3600 );
		}

			// Store total count for later use
			$results['total_products'] = intval( $cached );

			// Use SQL_CALC_FOUND_ROWS alternative for better performance
			// Direct database query with caching
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be parameterized
		$cache_key = 'miniload_' . md5(  $wpdb->prepare( "
				SELECT SQL_NO_CACHE
					p.product_id,
					p.sku,
					post.post_title as title,
					post.post_excerpt as excerpt,
					pm1.meta_value as price,
					pm2.meta_value as sale_price,
					pm3.meta_value as image_id,
					MATCH(p.search_text) AGAINST(%s IN BOOLEAN MODE) as relevance
				FROM {$this->search_table} p
				INNER JOIN {$wpdb->posts} post ON p.product_id = post.ID
					AND post.post_status = 'publish'
				LEFT JOIN {$wpdb->postmeta} pm1 ON p.product_id = pm1.post_id
					AND pm1.meta_key = '_price'
				LEFT JOIN {$wpdb->postmeta} pm2 ON p.product_id = pm2.post_id
					AND pm2.meta_key = '_sale_price'
				LEFT JOIN {$wpdb->postmeta} pm3 ON p.product_id = pm3.post_id
					AND pm3.meta_key = '_thumbnail_id'
				WHERE
					(MATCH(p.search_text) AGAINST(%s IN BOOLEAN MODE)
					OR p.sku = %s
					OR post.post_title LIKE %s)
				ORDER BY
					CASE
						WHEN p.sku = %s THEN 1
						WHEN post.post_title = %s THEN 2
						WHEN post.post_title LIKE %s THEN 3
						ELSE 4
					END,
					relevance DESC
				LIMIT %d
			",
			$term,
			$term,
			$term,
			$term . '%',
			$term,
			$term,
			$term . '%',
			$this->max_results
			)  );
		$cached = wp_cache_get( $cache_key );
		if ( false === $cached ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Required for performance optimization
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is a class property constructed from $wpdb->prefix
			$cached = $wpdb->get_results( $wpdb->prepare( "
				SELECT SQL_NO_CACHE
					p.product_id,
					p.sku,
					post.post_title as title,
					post.post_excerpt as excerpt,
					pm1.meta_value as price,
					pm2.meta_value as sale_price,
					pm3.meta_value as image_id,
					MATCH(p.search_text) AGAINST(%s IN BOOLEAN MODE) as relevance
				FROM {$this->search_table} p
				INNER JOIN {$wpdb->posts} post ON p.product_id = post.ID
					AND post.post_status = 'publish'
				LEFT JOIN {$wpdb->postmeta} pm1 ON p.product_id = pm1.post_id
					AND pm1.meta_key = '_price'
				LEFT JOIN {$wpdb->postmeta} pm2 ON p.product_id = pm2.post_id
					AND pm2.meta_key = '_sale_price'
				LEFT JOIN {$wpdb->postmeta} pm3 ON p.product_id = pm3.post_id
					AND pm3.meta_key = '_thumbnail_id'
				WHERE
					(MATCH(p.search_text) AGAINST(%s IN BOOLEAN MODE)
					OR p.sku = %s
					OR post.post_title LIKE %s)
				ORDER BY
					CASE
						WHEN p.sku = %s THEN 1
						WHEN post.post_title = %s THEN 2
						WHEN post.post_title LIKE %s THEN 3
						ELSE 4
					END,
					relevance DESC
				LIMIT %d
			",
			$term,
			$term,
			$term,
			$term . '%',
			$term,
			$term,
			$term . '%',
			$this->max_results
			) );
			wp_cache_set( $cache_key, $cached, '', 3600 );
		}

			foreach ( $cached as $product ) {
				$image_url = '';
				if ( $product->image_id ) {
					$image_url = wp_get_attachment_image_url( $product->image_id, 'thumbnail' );
				}

				$results['products'][] = array(
					'id' => $product->product_id,
					'title' => $product->title,
					'excerpt' => wp_trim_words( $product->excerpt, 15 ),
					'price' => wc_price( $product->price ),
					'sale_price' => $product->sale_price ? wc_price( $product->sale_price ) : '',
					'sku' => $product->sku,
					'url' => get_permalink( $product->product_id ),
					'image' => $image_url,
					'relevance' => $product->relevance
				);
			}
		}

		// Search categories
		if ( $type === 'all' || $type === 'category' ) {
			$categories = get_terms( array(
				'taxonomy' => 'product_cat',
				'name__like' => $term,
				'number' => 5,
				'hide_empty' => true
			) );

			foreach ( $categories as $cat ) {
				$results['categories'][] = array(
					'id' => $cat->term_id,
					'title' => $cat->name,
					'count' => $cat->count,
					'url' => get_term_link( $cat )
				);
			}
		}

		// Search tags
		if ( $type === 'all' || $type === 'tag' ) {
			$tags = get_terms( array(
				'taxonomy' => 'product_tag',
				'name__like' => $term,
				'number' => 5,
				'hide_empty' => true
			) );

			foreach ( $tags as $tag ) {
				$results['tags'][] = array(
					'id' => $tag->term_id,
					'title' => $tag->name,
					'count' => $tag->count,
					'url' => get_term_link( $tag )
				);
			}
		}

		// Search posts/pages
		if ( $type === 'all' || $type === 'post' ) {
			$posts = get_posts( array(
				's' => $term,
				'post_type' => array( 'post', 'page' ),
				'posts_per_page' => 3,
				'post_status' => 'publish'
			) );

			foreach ( $posts as $post ) {
				$results['posts'][] = array(
					'id' => $post->ID,
					'title' => $post->post_title,
					'type' => $post->post_type,
					'url' => get_permalink( $post->ID )
				);
			}
		}

		return $results;
	}

	/**
	 * Get search suggestions
	 */
	private function get_suggestions( $term ) {
		global $wpdb;

		$suggestions = array();

		// Get popular searches similar to this term
		// Direct database query with caching
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is a class property
		$cache_key = 'miniload_' . md5(  $wpdb->prepare( "
			SELECT term, popularity
			FROM {$this->suggestions_table}
			WHERE term LIKE %s
			OR MATCH(term, corrected_term) AGAINST(%s IN BOOLEAN MODE)
			ORDER BY popularity DESC
			LIMIT 5
		", '%' . $wpdb->esc_like( $term ) . '%', $term )  );
		$cached = wp_cache_get( $cache_key );
		if ( false === $cached ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Required for performance optimization
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is a class property constructed from $wpdb->prefix
			$cached = $wpdb->get_results( $wpdb->prepare( "
			SELECT term, popularity
			FROM {$this->suggestions_table}
			WHERE term LIKE %s
			OR MATCH(term, corrected_term) AGAINST(%s IN BOOLEAN MODE)
			ORDER BY popularity DESC
			LIMIT 5
		", '%' . $wpdb->esc_like( $term ) . '%', $term ) );
			wp_cache_set( $cache_key, $cached, '', 3600 );
		}

		foreach ( $cached as $suggestion ) {
			$suggestions[] = array(
				'term' => $suggestion->term,
				'popularity' => $suggestion->popularity
			);
		}

		// Typo correction using Levenshtein distance
		if ( empty( $suggestions ) ) {
			$suggestions = $this->get_typo_corrections( $term );
		}

		return $suggestions;
	}

	/**
	 * Get typo corrections
	 */
	private function get_typo_corrections( $term ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for performance optimization
		// Get all product titles
		$titles = $wpdb->get_col( "
			SELECT DISTINCT post_title
			FROM {$wpdb->posts}
			WHERE post_type = 'product'
			AND post_status = 'publish'
			LIMIT 1000
		" );

		$corrections = array();

		foreach ( $titles as $title ) {
			$words = explode( ' ', strtolower( $title ) );
			foreach ( $words as $word ) {
				if ( strlen( $word ) > 3 ) {
					$distance = levenshtein( strtolower( $term ), $word );
					if ( $distance <= 2 && $distance > 0 ) {
						$corrections[ $word ] = $distance;
					}
				}
			}
		}

		asort( $corrections );
		$corrections = array_slice( $corrections, 0, 3, true );

		$suggestions = array();
		foreach ( $corrections as $word => $distance ) {
			$suggestions[] = array(
				'term' => $word,
				'correction' => true
			);
		}

		return $suggestions;
	}

	/**
	 * Track search query
	 */
	public function track_search_query( $term, $results_count ) {
		global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Required for performance optimization

		$wpdb->insert(
			$this->analytics_table,
			array(
				'search_term' => $term,
				'results_count' => $results_count,
				'user_id' => get_current_user_id(),
				'session_id' => session_id() ?: wp_generate_uuid4(),
				'search_time' => current_time( 'mysql' )
			),
			array( '%s', '%d', '%d', '%s', '%s' )
		);

		// Update suggestions table
		// Direct database query with caching
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is a class property
		$cache_key = 'miniload_' . md5(  $wpdb->prepare(
			"SELECT popularity FROM {$this->suggestions_table} WHERE term = %s",
			$term
		)  );
		$cached = wp_cache_get( $cache_key );
		if ( false === $cached ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Required for performance optimization
			// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is a class property constructed from $wpdb->prefix
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is a class property
			$cached = $wpdb->get_var( $wpdb->prepare(
			"SELECT popularity FROM {$this->suggestions_table} WHERE term = %s",
			$term
		) );
			wp_cache_set( $cache_key, $cached, '', 3600 );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Required for performance optimization
		if ( $cached ) {
			$wpdb->update(
				$this->suggestions_table,
				array(
					'popularity' => $cached + 1,
					'last_searched' => current_time( 'mysql' )
				),
				array( 'term' => $term ),
				array( '%d', '%s' ),
				array( '%s' )
			);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Required for performance optimization
		} else {
			$wpdb->insert(
				$this->suggestions_table,
				array(
					'term' => $term,
					'popularity' => 1,
					'last_searched' => current_time( 'mysql' )
				),
				array( '%s', '%d', '%s' )
			);
		}
	}

	/**
	 * Track search click
	 */
	public function track_search() {
		check_ajax_referer( 'miniload_search_nonce', 'nonce' );

		if ( ! isset( $_POST['term'] ) || ! isset( $_POST['product_id'] ) ) {
			wp_send_json_error( 'Missing required parameters' );
		}

		$search_term = sanitize_text_field( wp_unslash( $_POST['term'] ) );
		$product_id = intval( $_POST['product_id'] );

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for performance optimization
		// Update last search with clicked product
		$wpdb->update(
			$this->analytics_table,
			array( 'clicked_product_id' => $product_id ),
			array(
				'search_term' => $search_term,
				'user_id' => get_current_user_id()
			),
			array( '%d' ),
			array( '%s', '%d' )
		);

		wp_send_json_success();
	}

	/**
	 * Handle admin search (Alt+K) - DEPRECATED
	 */
	public function handle_admin_search() {
		// Redirect to new tabbed search
		$this->handle_admin_tabbed_search();
	}

	/**
	 * Handle tabbed admin search - MantiLoad style
	 */
	public function handle_admin_tabbed_search() {
		check_ajax_referer( 'miniload_admin_search_nonce', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( 'Permission denied' );
		}

		if ( ! isset( $_POST['term'] ) ) {
			wp_send_json_error( 'Search term is required' );
		}

		$search_term = sanitize_text_field( wp_unslash( $_POST['term'] ) );
		$tab = isset( $_POST['tab'] ) ? sanitize_text_field( wp_unslash( $_POST['tab'] ) ) : 'products';
		$results = array();

		global $wpdb;

		switch ( $tab ) {
			case 'products':
				$results = $this->search_products_admin( $search_term );
				break;
			case 'posts':
				$results = $this->search_posts_admin( $search_term );
				break;
			case 'orders':
				$results = $this->search_orders_admin( $search_term );
				break;
			case 'customers':
				$results = $this->search_customers_admin( $search_term );
				break;
		}

		wp_send_json_success( array(
			'results' => $results,
			'term' => $search_term,
			'tab' => $tab
		) );
	}

	/**
	 * Search products for admin
	 */
	private function search_products_admin( $term ) {
		global $wpdb;
		$results = array();

		// Search products by title, SKU, or ID
		// Direct database query with caching
		$cache_key = 'miniload_' . md5(  $wpdb->prepare( "
			SELECT DISTINCT p.ID, p.post_title, p.post_status, p.post_type
			FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
			WHERE p.post_type IN ('product', 'product_variation')
			AND p.post_status NOT IN ('trash', 'auto-draft')
			AND (
				p.post_title LIKE %s
				OR p.ID = %d
				OR (pm.meta_key = '_sku' AND pm.meta_value LIKE %s)
			)
			ORDER BY p.post_modified DESC
			LIMIT 20
		", '%' . $wpdb->esc_like( $term ) . '%', absint( $term ), '%' . $wpdb->esc_like( $term ) . '%' )  );
		$cached = wp_cache_get( $cache_key );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Required for performance optimization
		if ( false === $cached ) {
			$cached = $wpdb->get_results( $wpdb->prepare( "
			SELECT DISTINCT p.ID, p.post_title, p.post_status, p.post_type
			FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
			WHERE p.post_type IN ('product', 'product_variation')
			AND p.post_status NOT IN ('trash', 'auto-draft')
			AND (
				p.post_title LIKE %s
				OR p.ID = %d
				OR (pm.meta_key = '_sku' AND pm.meta_value LIKE %s)
			)
			ORDER BY p.post_modified DESC
			LIMIT 20
		", '%' . $wpdb->esc_like( $term ) . '%', absint( $term ), '%' . $wpdb->esc_like( $term ) . '%' ) );
			wp_cache_set( $cache_key, $cached, '', 3600 );
		}

		foreach ( $cached as $product ) {
			$product_obj = wc_get_product( $product->ID );
			if ( ! $product_obj ) continue;

			// Get product image
			$image_id = $product_obj->get_image_id();
			$image = $image_id ? wp_get_attachment_image_url( $image_id, 'thumbnail' ) : '';

			// Get price
			$price = $product_obj->get_price_html();

			// Get stock status
			$stock_status = $product_obj->is_in_stock() ? 'In Stock' : 'Out of Stock';

			// Get SKU
			$sku = $product_obj->get_sku();

			$results[] = array(
				'title' => $product->post_title,
				'meta' => sprintf( 'SKU: %s • %s • %s',
					$sku ?: 'N/A',
					wp_strip_all_tags( $price ?: 'Price not set' ),
					$stock_status
				),
				'url' => get_edit_post_link( $product->ID, 'raw' ),
				'permalink' => get_permalink( $product->ID ),
				'id' => $product->ID,
				'image' => $image,
				'status' => $product->post_status
			);
		}

		return $results;
	}

	/**
	 * Search posts and pages for admin
	 */
	private function search_posts_admin( $term ) {
		global $wpdb;
		$results = array();

		// Direct database query with caching
		$cache_key = 'miniload_' . md5(  $wpdb->prepare( "
			SELECT ID, post_title, post_type, post_status, post_author, post_date
			FROM {$wpdb->posts}
			WHERE post_type IN ('post', 'page')
			AND post_status NOT IN ('trash', 'auto-draft')
			AND (
				post_title LIKE %s
				OR post_content LIKE %s
				OR ID = %d
			)
			ORDER BY post_modified DESC
			LIMIT 20
		", '%' . $wpdb->esc_like( $term ) . '%', '%' . $wpdb->esc_like( $term ) . '%', absint( $term ) )  );
		$cached = wp_cache_get( $cache_key );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Required for performance optimization
		if ( false === $cached ) {
			$cached = $wpdb->get_results( $wpdb->prepare( "
			SELECT ID, post_title, post_type, post_status, post_author, post_date
			FROM {$wpdb->posts}
			WHERE post_type IN ('post', 'page')
			AND post_status NOT IN ('trash', 'auto-draft')
			AND (
				post_title LIKE %s
				OR post_content LIKE %s
				OR ID = %d
			)
			ORDER BY post_modified DESC
			LIMIT 20
		", '%' . $wpdb->esc_like( $term ) . '%', '%' . $wpdb->esc_like( $term ) . '%', absint( $term ) ) );
			wp_cache_set( $cache_key, $cached, '', 3600 );
		}

		foreach ( $cached as $post ) {
			$author = get_userdata( $post->post_author );
			$date = human_time_diff( strtotime( $post->post_date ), current_time( 'timestamp' ) ) . ' ago';

			$results[] = array(
				'title' => $post->post_title,
				'meta' => sprintf( '%s • By %s • %s',
					ucfirst( $post->post_type ),
					$author ? $author->display_name : 'Unknown',
					$date
				),
				'url' => get_edit_post_link( $post->ID, 'raw' ),
				'permalink' => get_permalink( $post->ID ),
				'id' => $post->ID,
				'type' => $post->post_type,
				'status' => $post->post_status
			);
		}

		return $results;
	}

	/**
	 * Search orders for admin
	 */
	private function search_orders_admin( $term ) {
		global $wpdb;
		$results = array();

		// Search by order ID, customer email, or billing name
		// Direct database query with caching
		$cache_key = 'miniload_' . md5(  $wpdb->prepare( "
			SELECT DISTINCT p.ID, p.post_status, p.post_date
			FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
			WHERE p.post_type = 'shop_order'
			AND p.post_status != 'trash'
			AND (
				p.ID = %d
				OR (pm.meta_key = '_billing_email' AND pm.meta_value LIKE %s)
				OR (pm.meta_key = '_billing_first_name' AND pm.meta_value LIKE %s)
				OR (pm.meta_key = '_billing_last_name' AND pm.meta_value LIKE %s)
			)
			ORDER BY p.post_date DESC
			LIMIT 20
		", absint( $term ), '%' . $wpdb->esc_like( $term ) . '%', '%' . $wpdb->esc_like( $term ) . '%', '%' . $wpdb->esc_like( $term ) . '%' )  );
		$cached = wp_cache_get( $cache_key );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Required for performance optimization
		if ( false === $cached ) {
			$cached = $wpdb->get_results( $wpdb->prepare( "
			SELECT DISTINCT p.ID, p.post_status, p.post_date
			FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
			WHERE p.post_type = 'shop_order'
			AND p.post_status != 'trash'
			AND (
				p.ID = %d
				OR (pm.meta_key = '_billing_email' AND pm.meta_value LIKE %s)
				OR (pm.meta_key = '_billing_first_name' AND pm.meta_value LIKE %s)
				OR (pm.meta_key = '_billing_last_name' AND pm.meta_value LIKE %s)
			)
			ORDER BY p.post_date DESC
			LIMIT 20
		", absint( $term ), '%' . $wpdb->esc_like( $term ) . '%', '%' . $wpdb->esc_like( $term ) . '%', '%' . $wpdb->esc_like( $term ) . '%' ) );
			wp_cache_set( $cache_key, $cached, '', 3600 );
		}

		foreach ( $cached as $order_post ) {
			$order = wc_get_order( $order_post->ID );
			if ( ! $order ) continue;

			$customer_name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
			$date = human_time_diff( strtotime( $order_post->post_date ), current_time( 'timestamp' ) ) . ' ago';
			$total = $order->get_formatted_order_total();

			$results[] = array(
				'title' => 'Order #' . $order->get_order_number(),
				'meta' => sprintf( '%s • %s • %s',
					trim( $customer_name ) ?: $order->get_billing_email(),
					$total,
					$date
				),
				'url' => $order->get_edit_order_url(),
				'id' => $order_post->ID,
				'status' => $order->get_status(),
				'badge' => $order->get_item_count() . ' items'
			);
		}

		return $results;
	}

	/**
	 * Search customers for admin
	 */
	private function search_customers_admin( $term ) {
		$results = array();

		// Search users with customer role
		$users = get_users( array(
			'search' => '*' . $term . '*',
			'role__in' => array( 'customer', 'administrator', 'shop_manager' ),
			'number' => 20
		) );

		foreach ( $users as $user ) {
			// Get customer orders count and total spent
			$customer = new \WC_Customer( $user->ID );
			$order_count = $customer->get_order_count();
			$total_spent = $customer->get_total_spent();

			$results[] = array(
				'title' => $user->display_name ?: $user->user_login,
				'meta' => sprintf( '%s • %d orders • Spent %s',
					$user->user_email,
					$order_count,
					wc_price( $total_spent )
				),
				'url' => get_edit_user_link( $user->ID ),
				'id' => $user->ID,
				'avatar' => get_avatar_url( $user->ID, array( 'size' => 48 ) ),
				'badge' => implode( ', ', $user->roles )
			);
		}

		return $results;
	}

	/**
	 * Render search box
	 */
	public function render_search_box( $atts = array() ) {
		// Comprehensive shortcode parameters - use saved settings as defaults
		$atts = shortcode_atts( array(
			// Basic settings - from saved options
			'placeholder' => get_option( 'miniload_search_placeholder', __( 'Search products...', 'miniload' ) ),
			'style' => 'default', // default, minimal, modern, floating, icon-only, mobile-first
			'ajax' => true,
			'live' => true, // Live search as you type

			// Display options - from saved options
			'categories' => (bool) get_option( 'miniload_show_categories', '0' ), // From settings
			'category_dropdown' => (bool) get_option( 'miniload_show_categories', '0' ), // From settings
			'show_submit' => get_option( 'miniload_search_icon_position', 'show' ) === 'show',
			'submit_position' => get_option( 'miniload_search_icon_position', 'show' ), // Simplified: show or hide
			'show_popular' => false, // Hidden by default
			'show_suggestions' => true,
			'show_price' => (bool) get_option( 'miniload_show_price', '1' ), // From settings
			'show_image' => (bool) get_option( 'miniload_show_image', '1' ), // From settings
			'show_sku' => false,
			'show_description' => true,
			'show_in_stock' => false,

			// Search behavior - from saved options
			'min_chars' => absint( get_option( 'miniload_search_min_chars', 3 ) ),
			'max_results' => absint( get_option( 'miniload_search_results_count', 8 ) ),
			'delay' => absint( get_option( 'miniload_search_delay', 300 ) ), // Debounce delay in ms
			'search_by_sku' => true,
			'search_by_title' => true,
			'search_by_content' => true,
			'search_by_excerpt' => true,
			'search_by_tag' => false,

			// Layout settings
			'layout' => 'list', // list, grid, compact
			'width' => '', // Empty for default, or specific width like '500px', '100%', '80%'
			'max_width' => '700px', // Maximum width of search box
			'results_width' => 'auto', // auto, full, custom
			'results_position' => 'below', // below, overlay, modal
			'mobile_fullscreen' => true,

			// Icon trigger settings
			'icon_only' => false,
			'icon_position' => 'right', // left, right, center
			'icon_size' => 'medium', // small, medium, large
			'icon_color' => '',
			'icon_bg_color' => '',

			// Floating button settings
			'floating' => false,
			'floating_position' => 'bottom-right', // bottom-right, bottom-left, top-right, top-left
			'floating_offset_x' => '20',
			'floating_offset_y' => '20',

			// Advanced settings
			'highlight_terms' => true,
			'close_on_click' => true,
			'keyboard_nav' => true,
			'autofocus' => false,
			'clear_button' => true,
			'voice_search' => false, // Future feature

			// Custom CSS classes
			'wrapper_class' => '',
			'input_class' => '',
			'results_class' => ''
		), $atts );

		// Convert string booleans to actual booleans
		$boolean_fields = array('ajax', 'live', 'categories', 'category_dropdown', 'show_submit', 'show_popular',
			'show_suggestions', 'show_price', 'show_image', 'show_sku', 'show_description', 'show_in_stock',
			'search_by_sku', 'search_by_title', 'search_by_content', 'search_by_excerpt', 'search_by_tag',
			'mobile_fullscreen', 'icon_only', 'floating', 'highlight_terms', 'close_on_click',
			'keyboard_nav', 'autofocus', 'clear_button', 'voice_search');

		foreach ($boolean_fields as $field) {
			if (isset($atts[$field])) {
				if (is_string($atts[$field])) {
					$atts[$field] = filter_var($atts[$field], FILTER_VALIDATE_BOOLEAN);
				}
			}
		}

		// Generate unique ID for this search instance
		$search_id = 'miniload-search-' . wp_rand( 1000, 9999 );

		ob_start();

		// Icon-only mode (search icon that opens search box)
		if ( $atts['icon_only'] ) {
			?>
			<div class="miniload-search-icon-wrapper <?php echo esc_attr( $atts['wrapper_class'] ); ?>"
			     data-search-id="<?php echo esc_attr( $search_id ); ?>"
			     data-icon-position="<?php echo esc_attr( $atts['icon_position'] ); ?>"
			     data-icon-size="<?php echo esc_attr( $atts['icon_size'] ); ?>">
				<button type="button" class="miniload-search-icon-trigger"
				        aria-label="<?php esc_attr_e( 'Open search', 'miniload' ); ?>"
				        style="<?php echo $atts['icon_color'] ? 'color:' . esc_attr( $atts['icon_color'] ) . ';' : ''; ?>
				               <?php echo $atts['icon_bg_color'] ? 'background-color:' . esc_attr( $atts['icon_bg_color'] ) . ';' : ''; ?>">
					<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
						<circle cx="11" cy="11" r="8"/>
						<path d="m21 21-4.35-4.35"/>
					</svg>
				</button>
			</div>
			<?php
		}

		// Floating button mode
		if ( $atts['floating'] ) {
			?>
			<div class="miniload-search-floating-button"
			     data-position="<?php echo esc_attr( $atts['floating_position'] ); ?>"
			     style="<?php
			        $position_parts = explode( '-', $atts['floating_position'] );
			        echo esc_attr( $position_parts[0] ) . ':' . esc_attr( $atts['floating_offset_y'] ) . 'px;';
			        echo esc_attr( $position_parts[1] ) . ':' . esc_attr( $atts['floating_offset_x'] ) . 'px;';
			     ?>">
				<button type="button" class="miniload-floating-trigger"
				        aria-label="<?php esc_attr_e( 'Search', 'miniload' ); ?>">
					<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor">
						<circle cx="11" cy="11" r="8"/>
						<path d="m21 21-4.35-4.35"/>
					</svg>
					<span class="miniload-floating-text"><?php esc_html_e( 'Search', 'miniload' ); ?></span>
				</button>
			</div>
			<?php
		}
		?>

		<div id="<?php echo esc_attr( $search_id ); ?>"
		     class="miniload-search-wrapper <?php echo esc_attr( $atts['wrapper_class'] ); ?> <?php echo $atts['icon_only'] || $atts['floating'] ? 'miniload-search-hidden' : ''; ?> <?php echo ( $atts['categories'] && $atts['category_dropdown'] ) ? 'has-categories' : ''; ?>"
		     data-style="<?php echo esc_attr( $atts['style'] ); ?>"
		     data-layout="<?php echo esc_attr( $atts['layout'] ); ?>"
		     data-mobile-fullscreen="<?php echo $atts['mobile_fullscreen'] ? 'true' : 'false'; ?>"
		     data-results-position="<?php echo esc_attr( $atts['results_position'] ); ?>"
		     style="<?php
		         echo $atts['width'] ? 'width:' . esc_attr( $atts['width'] ) . ';' : '';
		         echo $atts['max_width'] ? 'max-width:' . esc_attr( $atts['max_width'] ) . ';' : '';
		     ?>">

			<?php if ( $atts['style'] === 'mobile-first' || $atts['mobile_fullscreen'] ) : ?>
				<div class="miniload-mobile-header">
					<button type="button" class="miniload-mobile-back" aria-label="<?php esc_attr_e( 'Close search', 'miniload' ); ?>">
						<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor">
							<path d="M19 12H5M5 12l7-7m-7 7l7 7"/>
						</svg>
					</button>
					<div class="miniload-mobile-title"><?php esc_html_e( 'Search', 'miniload' ); ?></div>
				</div>
			<?php endif; ?>

			<form class="miniload-search-form" action="<?php echo esc_url( home_url( '/' ) ); ?>" method="get">
				<div class="miniload-search-input-wrapper" data-submit-position="<?php echo esc_attr( $atts['submit_position'] ); ?>">

					<?php // Always render LEFT button - CSS will control visibility ?>
					<button type="submit" class="miniload-search-submit miniload-search-submit-left">
						<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor">
							<circle cx="11" cy="11" r="8"/>
							<path d="m21 21-4.35-4.35"/>
						</svg>
						<span class="miniload-search-submit-text"><?php esc_html_e( 'Search', 'miniload' ); ?></span>
					</button>

					<?php // Voice search button ?>
					<?php if ( $atts['voice_search'] ) : ?>
						<button type="button" class="miniload-voice-search" aria-label="<?php esc_attr_e( 'Voice search', 'miniload' ); ?>">
							<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor">
								<path d="M12 1v6m0 0v6m0-6h6m-6 0H6"/>
								<rect x="9" y="1" width="6" height="10" rx="3"/>
								<path d="M5 10a7 7 0 0014 0M12 18v4"/>
							</svg>
						</button>
					<?php endif; ?>

					<?php // Category dropdown - now BEFORE input field ?>
					<?php if ( $atts['categories'] && $atts['category_dropdown'] ) : ?>
						<select name="product_cat" class="miniload-search-category">
							<option value=""><?php esc_html_e( 'All Categories', 'miniload' ); ?></option>
							<?php
							$categories = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => true ) );
							foreach ( $categories as $cat ) {
								echo '<option value="' . esc_attr( $cat->slug ) . '">' . esc_html( $cat->name ) . '</option>';
							}
							?>
						</select>
					<?php endif; ?>

					<?php // Main search input ?>
					<input type="text"
					       name="s"
					       class="miniload-search-input <?php echo esc_attr( $atts['input_class'] ); ?>"
					       placeholder="<?php echo esc_attr( $atts['placeholder'] ); ?>"
					       autocomplete="off"
					       data-ajax="<?php echo $atts['ajax'] ? 'true' : 'false'; ?>"
					       data-live="<?php echo $atts['live'] ? 'true' : 'false'; ?>"
					       data-min-chars="<?php echo esc_attr( $atts['min_chars'] ); ?>"
					       data-max-results="<?php echo esc_attr( $atts['max_results'] ); ?>"
					       data-delay="<?php echo esc_attr( $atts['delay'] ); ?>"
					       data-search-settings="<?php echo esc_attr( json_encode( array(
					           'search_by_sku' => $atts['search_by_sku'],
					           'search_by_title' => $atts['search_by_title'],
					           'search_by_content' => $atts['search_by_content'],
					           'search_by_excerpt' => $atts['search_by_excerpt'],
					           'search_by_tag' => $atts['search_by_tag']
					       ) ) ); ?>"
					       <?php echo $atts['autofocus'] ? 'autofocus' : ''; ?>>

					<input type="hidden" name="post_type" value="product">

					<?php // Clear button - positioned after input ?>
					<?php if ( $atts['clear_button'] ) : ?>
						<button type="button" class="miniload-search-clear" aria-label="<?php esc_attr_e( 'Clear search', 'miniload' ); ?>">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
								<circle cx="12" cy="12" r="10"/>
								<line x1="15" y1="9" x2="9" y2="15"/>
								<line x1="9" y1="9" x2="15" y2="15"/>
							</svg>
						</button>
					<?php endif; ?>

					<?php // Always render RIGHT button - CSS will control visibility ?>
					<button type="submit" class="miniload-search-submit miniload-search-submit-right">
						<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor">
							<circle cx="11" cy="11" r="8"/>
							<path d="m21 21-4.35-4.35"/>
						</svg>
						<span class="miniload-search-submit-text"><?php esc_html_e( 'Search', 'miniload' ); ?></span>
					</button>
				</div>

				<div class="miniload-search-results <?php echo esc_attr( $atts['results_class'] ); ?>"
				     style="display:none;"
				     data-show-price="<?php echo $atts['show_price'] ? 'true' : 'false'; ?>"
				     data-show-image="<?php echo $atts['show_image'] ? 'true' : 'false'; ?>"
				     data-show-sku="<?php echo $atts['show_sku'] ? 'true' : 'false'; ?>"
				     data-show-description="<?php echo $atts['show_description'] ? 'true' : 'false'; ?>"
				     data-show-in-stock="<?php echo $atts['show_in_stock'] ? 'true' : 'false'; ?>"
				     data-highlight-terms="<?php echo $atts['highlight_terms'] ? 'true' : 'false'; ?>">
				</div>

				<?php if ( $atts['show_popular'] ) : ?>
					<div class="miniload-popular-searches" style="display:none;">
						<h4><?php esc_html_e( 'Popular Searches', 'miniload' ); ?></h4>
						<div class="miniload-popular-tags"></div>
					</div>
				<?php endif; ?>
			</form>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Override default search form
	 */
	public function override_search_form( $form ) {
		if ( get_option( 'miniload_replace_search', true ) ) {
			return $this->render_search_box();
		}
		return $form;
	}

	/**
	 * Enqueue frontend assets
	 */
	public function enqueue_frontend_assets() {
		wp_enqueue_style(
			'miniload-ajax-search',
			MINILOAD_PLUGIN_URL . 'assets/css/ajax-search.css',
			array(),
			MINILOAD_VERSION . '.4.0'
		);

		// Additional fixes for theme compatibility
		wp_enqueue_style(
			'miniload-ajax-search-fixes',
			MINILOAD_PLUGIN_URL . 'assets/css/ajax-search-fixes.css',
			array( 'miniload-ajax-search' ),
			MINILOAD_VERSION . '.4.0'
		);

		wp_enqueue_script(
			'miniload-ajax-search',
			MINILOAD_PLUGIN_URL . 'assets/js/ajax-search.js',
			array( 'jquery' ),
			MINILOAD_VERSION . '.4.0',
			true
		);

		wp_localize_script( 'miniload-ajax-search', 'miniload_ajax_search', array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'miniload_search_nonce' ),
			'min_chars' => $this->min_chars,
			'max_results' => $this->max_results,
			'search_delay' => absint( get_option( 'miniload_search_delay', 300 ) ),
			'show_price' => get_option( 'miniload_show_price', '1' ),
			'show_image' => get_option( 'miniload_show_image', '1' ),
			'show_categories_results' => get_option( 'miniload_show_categories_results', '1' ),
			'placeholder' => get_option( 'miniload_search_placeholder', __( 'Search products...', 'miniload' ) ),
			'searching' => __( 'Searching...', 'miniload' ),
			'no_results' => __( 'No results found', 'miniload' ),
			'view_all' => __( 'View all results', 'miniload' )
		) );

		// Add critical inline CSS to fix spacing issues
		$font_style = get_option( 'miniload_font_style', 'inherit' );
		$font_family_css = '';

		if ( $font_style === 'system' ) {
			$font_family_css = 'font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif !important;';
		}
		// If 'inherit' or anything else, don't set font-family, let it inherit from theme

		$inline_css = '
			.miniload-search-wrapper {' .
				( $font_family_css ? "\n\t\t\t\t" . $font_family_css : '' ) . '
			}
			.miniload-search-input-wrapper {
				padding: 3px !important;
				justify-content: flex-start !important;
				position: relative !important;
			}
			.miniload-search-input {
				flex: 1 1 auto !important;
				padding: 12px 20px !important;
				' . ( $font_style === 'inherit' ? 'font-family: inherit !important;' : '' ) . '
			}
			/* Input padding adjustments for clear button */
			.miniload-search-input-wrapper.has-clear-button .miniload-search-input {
				padding-right: 45px !important; /* Default space for clear button */
			}
			/* When search button is on right, need more padding */
			.miniload-search-input-wrapper[data-submit-position="right"].has-clear-button .miniload-search-input {
				padding-right: 75px !important; /* Space for clear (30px) + search button (45px) */
			}
			/* When both buttons */
			.miniload-search-input-wrapper[data-submit-position="both"].has-clear-button .miniload-search-input {
				padding-right: 75px !important; /* Space for clear + right button */
			}
			.miniload-search-submit {
				flex: 0 0 44px !important;
				width: 44px !important;
				height: 44px !important;
				padding: 0 !important;
				margin: 3px !important;
			}
			/* Hide right button when position is left only */
			.miniload-search-input-wrapper[data-submit-position="left"] .miniload-search-submit-right {
				display: none !important;
			}
			/* Hide left button when position is right only */
			.miniload-search-input-wrapper[data-submit-position="right"] .miniload-search-submit-left {
				display: none !important;
			}
			/* Hide both buttons when position is hide or none */
			.miniload-search-input-wrapper[data-submit-position="hide"] .miniload-search-submit,
			.miniload-search-input-wrapper[data-submit-position="none"] .miniload-search-submit {
				display: none !important;
			}
			/* Clear button - absolutely positioned to avoid text overlap */
			.miniload-search-clear {
				position: absolute !important;
				top: 50% !important;
				transform: translateY(-50%) !important;
				display: none !important;
				background: transparent !important;
				border: none !important;
				padding: 4px !important;
				width: 24px !important;
				height: 24px !important;
				cursor: pointer !important;
				color: #999 !important;
				z-index: 10 !important; /* Higher z-index to stay above text */
				border-radius: 50% !important;
			}
			/* Position clear button based on search button position */
			.miniload-search-input-wrapper[data-submit-position="right"] .miniload-search-clear {
				right: 48px !important; /* Position before the right search button (44px button + 4px gap) */
			}
			.miniload-search-input-wrapper[data-submit-position="both"] .miniload-search-clear {
				right: 48px !important; /* Same as right position */
			}
			.miniload-search-input-wrapper[data-submit-position="left"] .miniload-search-clear,
			.miniload-search-input-wrapper[data-submit-position="none"] .miniload-search-clear,
			.miniload-search-input-wrapper[data-submit-position="hide"] .miniload-search-clear {
				right: 12px !important; /* Position at the right edge when no right button */
			}
			.miniload-search-clear.visible {
				display: flex !important;
				align-items: center !important;
				justify-content: center !important;
			}
			.miniload-search-clear svg {
				width: 16px !important;
				height: 16px !important;
			}
			.miniload-search-clear:hover {
				background: rgba(0, 0, 0, 0.05) !important;
				color: #e91e63 !important;
				transform: translateY(-50%) scale(1.1) !important;
			}
		';
		wp_add_inline_style( 'miniload-ajax-search', $inline_css );
	}

	/**
	 * Enqueue admin assets
	 */
	public function enqueue_admin_assets() {
		wp_enqueue_style(
			'miniload-admin-search',
			MINILOAD_PLUGIN_URL . 'assets/css/admin-search.css',
			array(),
			MINILOAD_VERSION . '.10.0'
		);

		wp_enqueue_script(
			'miniload-admin-search',
			MINILOAD_PLUGIN_URL . 'assets/js/admin-search.js',
			array( 'jquery' ),
			MINILOAD_VERSION . '.10.0',
			true
		);

		wp_localize_script( 'miniload-admin-search', 'miniload_admin_search', array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'miniload_admin_search_nonce' ),
			'searching' => __( 'Searching...', 'miniload' ),
			'placeholder' => __( 'Search everything... (products, orders, users)', 'miniload' ),
			'shortcut' => __( 'Press Alt+K to search', 'miniload' )
		) );
	}

	/**
	 * Add search modal to frontend
	 */
	public function add_search_modal() {
		if ( get_option( 'miniload_enable_search_modal', true ) ) {
			?>
			<div id="miniload-search-modal" class="miniload-search-modal" style="display:none;">
				<div class="miniload-search-modal-content">
					<button class="miniload-search-close">&times;</button>
					<?php echo wp_kses_post( $this->render_search_box( array( 'style' => 'modal' ) ) ); ?>
				</div>
			</div>
			<?php
		}
	}

	/**
	 * Add admin search modal
	 */
	public function add_admin_search_modal() {
		// Modal is created entirely via JavaScript now
		// Just add inline styles to ensure proper rendering
		?>
		<style>
			/* Modern inline styles - Clean monochrome design */
			#miniload-admin-search-modal {
				background: rgba(0, 0, 0, 0.85) !important;
				backdrop-filter: blur(10px) !important;
			}

			#miniload-admin-search-modal .miniload-admin-search-content {
				width: 90% !important;
				max-width: 720px !important; /* 20% smaller - was 900px */
				margin: 10vh auto 0 !important;
				background: #ffffff !important;
				border-radius: 16px !important;
				box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3) !important;
				overflow: hidden !important;
				border: none !important;
			}

			/* Clean monochrome header */
			#miniload-admin-search-modal .miniload-admin-search-header {
				display: flex !important;
				justify-content: space-between !important;
				align-items: center !important;
				padding: 20px 24px !important;
				background: #1a1a1a !important;
				border: none !important;
			}

			#miniload-admin-search-modal .miniload-admin-search-header h2 {
				color: #fff !important;
				margin: 0 !important;
				font-size: 18px !important;
				font-weight: 600 !important;
				letter-spacing: -0.5px !important;
			}

			#miniload-admin-search-modal .miniload-admin-close-btn {
				background: rgba(255, 255, 255, 0.1) !important;
				border: none !important;
				color: #fff !important;
				cursor: pointer !important;
				width: 32px !important;
				height: 32px !important;
				border-radius: 8px !important;
				font-size: 18px !important;
				display: flex !important;
				align-items: center !important;
				justify-content: center !important;
				transition: all 0.2s ease !important;
			}

			#miniload-admin-search-modal .miniload-admin-close-btn:hover {
				background: rgba(255, 255, 255, 0.2) !important;
				transform: rotate(90deg) !important;
			}

			/* Clean modern tabs - monochrome */
			#miniload-admin-search-modal .miniload-admin-search-tabs {
				display: flex !important;
				background: #f5f5f5 !important;
				padding: 10px !important;
				gap: 6px !important;
				border: none !important;
				border-bottom: none !important;
			}

			#miniload-admin-search-modal .miniload-admin-tab {
				flex: 1 !important;
				display: flex !important;
				align-items: center !important;
				justify-content: center !important;
				gap: 6px !important;
				padding: 10px 16px !important;
				background: transparent !important;
				border: none !important;
				border-radius: 8px !important;
				color: #666 !important;
				font-size: 13px !important;
				font-weight: 500 !important;
				cursor: pointer !important;
				transition: all 0.2s ease !important;
				position: relative !important;
				overflow: hidden !important;
			}

			#miniload-admin-search-modal .miniload-admin-tab:hover:not(.active) {
				background: #e0e0e0 !important;
				color: #000 !important;
			}

			#miniload-admin-search-modal .miniload-admin-tab.active {
				background: #2c2c2c !important;
				color: #fff !important;
				font-weight: 600 !important;
				box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2) !important;
			}

			#miniload-admin-search-modal .miniload-admin-tab .dashicons {
				font-size: 18px !important;
				line-height: 1 !important;
			}

			/* Clean search input */
			#miniload-admin-search-modal .miniload-admin-search-input-wrapper {
				padding: 20px 24px !important;
				background: #fafafa !important;
				border: none !important;
				position: relative !important;
			}

			#miniload-admin-search-modal #miniload-admin-search-input {
				width: 100% !important;
				padding: 12px 20px 12px 42px !important;
				font-size: 15px !important;
				border: 1px solid #d0d0d0 !important;
				border-radius: 8px !important;
				background: #fff !important;
				transition: all 0.2s ease !important;
			}

			#miniload-admin-search-modal #miniload-admin-search-input:focus {
				border-color: #333 !important;
				box-shadow: 0 0 0 2px rgba(0, 0, 0, 0.1) !important;
				outline: none !important;
			}

			/* ESC hint removed */

			/* Modern results - monochrome */
			#miniload-admin-search-modal #miniload-admin-search-results {
				background: #fff !important;
				padding: 10px !important;
				max-height: 320px !important;
				overflow-y: auto !important;
			}

			#miniload-admin-search-modal .miniload-admin-result {
				padding: 12px 14px !important;
				margin-bottom: 6px !important;
				border-radius: 8px !important;
				border: 1px solid transparent !important;
				background: #fff !important;
				transition: all 0.15s ease !important;
			}

			#miniload-admin-search-modal .miniload-admin-result:hover {
				background: #f8f8f8 !important;
				border-color: #e0e0e0 !important;
				transform: translateX(4px) !important;
			}

			#miniload-admin-search-modal .miniload-result-icon {
				width: 42px !important;
				height: 42px !important;
				background: #f5f5f5 !important;
				border-radius: 8px !important;
				border: 1px solid #e0e0e0 !important;
			}

			/* Copy button styling - monochrome */
			#miniload-admin-search-modal .miniload-copy-link {
				background: #f0f0f0 !important;
				border: 1px solid #d0d0d0 !important;
				border-radius: 6px !important;
				padding: 6px 10px !important;
				color: #333 !important;
				font-weight: 500 !important;
				font-size: 12px !important;
				transition: all 0.2s ease !important;
			}

			#miniload-admin-search-modal .miniload-copy-link:hover {
				background: #333 !important;
				border-color: #333 !important;
				color: #fff !important;
				transform: translateY(-1px) !important;
			}

			#miniload-admin-search-modal .miniload-copy-link.copied {
				background: #000 !important;
				border-color: #000 !important;
				color: #fff !important;
			}

			/* View all results link - monochrome */
			#miniload-admin-search-modal .miniload-view-all {
				display: block !important;
				text-align: center !important;
				padding: 12px !important;
				margin: 8px 10px !important;
				background: #f5f5f5 !important;
				border: 1px solid #d0d0d0 !important;
				border-radius: 8px !important;
				color: #333 !important;
				font-weight: 600 !important;
				font-size: 14px !important;
				text-decoration: none !important;
				transition: all 0.2s ease !important;
			}

			#miniload-admin-search-modal .miniload-view-all:hover {
				background: #2c2c2c !important;
				border-color: #2c2c2c !important;
				color: #fff !important;
				transform: translateY(-1px) !important;
			}

			/* Modern footer - monochrome */
			#miniload-admin-search-modal .miniload-admin-search-footer {
				padding: 14px 24px !important;
				background: #f8f8f8 !important;
				border: none !important;
				border-top: 1px solid #e0e0e0 !important;
			}

			/* Remove all borders from results */
			#miniload-admin-search-modal .miniload-admin-results-list {
				border: none !important;
			}

			#miniload-admin-search-modal * {
				border-color: transparent !important;
			}

			#miniload-admin-search-modal input:focus,
			#miniload-admin-search-modal button:focus {
				outline: none !important;
			}

			/* Smooth scrollbar - monochrome */
			#miniload-admin-search-modal #miniload-admin-search-results::-webkit-scrollbar {
				width: 8px !important;
			}

			#miniload-admin-search-modal #miniload-admin-search-results::-webkit-scrollbar-track {
				background: #f0f0f0 !important;
				border-radius: 4px !important;
			}

			#miniload-admin-search-modal #miniload-admin-search-results::-webkit-scrollbar-thumb {
				background: #999 !important;
				border-radius: 4px !important;
			}

			#miniload-admin-search-modal #miniload-admin-search-results::-webkit-scrollbar-thumb:hover {
				background: #666 !important;
			}

			/* Additional size reduction */
			#miniload-admin-search-modal .miniload-admin-search-content {
				max-height: 75vh !important;
			}

			#miniload-admin-search-modal .miniload-result-title {
				font-size: 14px !important;
				color: #1a1a1a !important;
			}

			#miniload-admin-search-modal .miniload-result-meta {
				font-size: 12px !important;
				color: #666 !important;
			}

			/* Status badges - monochrome */
			#miniload-admin-search-modal .miniload-status {
				background: #e0e0e0 !important;
				color: #333 !important;
				font-size: 10px !important;
				padding: 3px 6px !important;
			}

			#miniload-admin-search-modal .miniload-badge {
				background: #333 !important;
				color: #fff !important;
				font-size: 10px !important;
				padding: 3px 8px !important;
			}
		</style>
		<?php
	}

	/**
	 * AJAX handler to rebuild search index
	 */
	public function ajax_rebuild_search_index() {
		// Check nonce
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'miniload_rebuild_search' ) ) {
			wp_send_json_error( 'Invalid nonce' );
		}

		// Check permissions
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
		}

		// Load the indexer
		require_once MINILOAD_PLUGIN_DIR . 'modules/class-search-indexer.php';
		$miniload_indexer = new \MiniLoad\Modules\Search_Indexer();

		// Rebuild the index
		$miniload_result = $miniload_indexer->rebuild_index();

		if ( $miniload_result['success'] ) {
			wp_send_json_success( $miniload_result );
		} else {
			wp_send_json_error( $miniload_result['message'] );
		}
	}

	/**
	 * Register settings
	 */
	public function register_settings() {
		// Settings are now registered in main plugin file under 'miniload_search_settings' group
		// Keeping this method for backward compatibility
	}

	/**
	 * Get search analytics
	 */
	public function get_analytics() {
		global $wpdb;

		return array(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for performance optimization
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is a class property constructed from $wpdb->prefix
			'popular_searches' => $wpdb->get_results( "
				SELECT term, popularity
				FROM {$this->suggestions_table}
				ORDER BY popularity DESC
				LIMIT 10
			" ),
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is a class property constructed from $wpdb->prefix
			'recent_searches' => $wpdb->get_results( "
				SELECT search_term, results_count, search_time
				FROM {$this->analytics_table}
				ORDER BY search_time DESC
				LIMIT 10
			" ),
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is a class property constructed from $wpdb->prefix
			'no_results_searches' => $wpdb->get_results( "
				SELECT search_term, COUNT(*) as count
				FROM {$this->analytics_table}
				WHERE results_count = 0
				GROUP BY search_term
				ORDER BY count DESC
				LIMIT 10
			" )
		);
	}
}