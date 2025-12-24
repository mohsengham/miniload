<?php
/**
 * Search Optimizer Module
 *
 * Lean search index focusing on what customers ACTUALLY search for
 * 30x smaller index, 10x faster search, MORE relevant results
 *
 * @package MiniLoad\Modules
 * @since 1.0.0
 */

namespace MiniLoad\Modules;

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

/**
 * Search Optimizer class
 */
class Search_Optimizer {

	/**
	 * Table name
	 *
	 * @var string
	 */
	private $table_name;

	/**
	 * Current search product IDs
	 *
	 * @var array
	 */
	private $current_search_ids = array();

	/**
	 * Search log table
	 *
	 * @var string
	 */
	private $log_table;

	/**
	 * Constructor
	 */
	public function __construct() {
		global $wpdb;
		$this->table_name = $wpdb->prefix . 'miniload_search_index';
		$this->log_table = $wpdb->prefix . 'miniload_search_log';

		$this->init_hooks();
	}

	/**
	 * Initialize hooks
	 */
	private function init_hooks() {
		// Replace WooCommerce product search
		add_filter( 'posts_search', array( $this, 'optimize_product_search' ), 100, 2 );
		add_filter( 'posts_where', array( $this, 'apply_search_ids' ), 100, 2 );
		add_filter( 'posts_clauses', array( $this, 'modify_search_clauses' ), 100, 2 );

		// SKU search optimization
		add_filter( 'woocommerce_product_search_results', array( $this, 'search_by_sku_first' ), 10, 2 );

		// Update index when products are saved
		add_action( 'woocommerce_update_product', array( $this, 'update_product_index' ), 10, 2 );
		add_action( 'woocommerce_new_product', array( $this, 'update_product_index' ), 10, 2 );
		add_action( 'before_delete_post', array( $this, 'delete_product_index' ), 10, 1 );

		// BULK EDIT SUPPORT - Update index on bulk/quick edits
		add_action( 'woocommerce_product_bulk_edit_save', array( $this, 'update_product_index_bulk' ), 10, 1 );
		add_action( 'woocommerce_product_quick_edit_save', array( $this, 'update_product_index_bulk' ), 10, 1 );

		// Update index when product meta changes (handles price updates, stock, etc)
		add_action( 'updated_post_meta', array( $this, 'maybe_update_product_on_meta_change' ), 10, 4 );
		add_action( 'added_post_meta', array( $this, 'maybe_update_product_on_meta_change' ), 10, 4 );

		// Additional hooks for third-party bulk edit plugins
		add_action( 'woocommerce_product_set_stock', array( $this, 'update_product_index' ), 10, 1 );
		add_action( 'woocommerce_variation_set_stock', array( $this, 'update_product_index' ), 10, 1 );
		add_action( 'woocommerce_product_set_stock_status', array( $this, 'update_product_index' ), 10, 1 );
		add_action( 'woocommerce_variation_set_stock_status', array( $this, 'update_product_index' ), 10, 1 );

		// Popular bulk edit plugin hooks
		add_action( 'pw_bulk_edit_save_product', array( $this, 'update_product_index' ), 10, 1 );  // PW Bulk Edit
		add_action( 'wpmelon_after_bulk_edit', array( $this, 'update_product_index' ), 10, 1 );   // WP Sheet Editor
		add_action( 'woo_bulk_edit_product_updated', array( $this, 'update_product_index' ), 10, 1 ); // Various bulk editors

		// Import plugin hooks
		add_action( 'woocommerce_product_import_inserted_product_object', array( $this, 'update_product_index_bulk' ), 10, 1 );
		add_action( 'woocommerce_product_import_updated_product_object', array( $this, 'update_product_index_bulk' ), 10, 1 );
		add_action( 'pmxi_saved_post', array( $this, 'maybe_update_product_on_import' ), 10, 1 ); // WP All Import

		// Log searches
		add_action( 'pre_get_posts', array( $this, 'log_search' ), 10, 1 );

		// FAILSAFE: Schedule periodic sync to catch any missed updates from direct DB operations
		add_action( 'init', array( $this, 'schedule_index_sync' ) );
		add_action( 'miniload_sync_product_indexes', array( $this, 'sync_recently_modified_products' ) );

		// AJAX handlers
		add_action( 'wp_ajax_miniload_rebuild_search_index', array( $this, 'ajax_rebuild_index' ) );
		add_action( 'wp_ajax_miniload_search_stats', array( $this, 'ajax_get_stats' ) );

		// Cache popular searches
		add_action( 'init', array( $this, 'maybe_warm_search_cache' ) );
	}

	/**
	 * Optimize product search queries
	 *
	 * @param string $search Search SQL
	 * @param WP_Query $query Query object
	 * @return string
	 */
	public function optimize_product_search( $search, $query ) {
		// Debug logging
		miniload_log( sprintf( '[SEARCH DEBUG] optimize_product_search called. Original search SQL: %s', $search ), 'debug' );

		// Only optimize product searches
		if ( ! $this->should_optimize_search( $query ) ) {
			miniload_log( '[SEARCH DEBUG] Not a product search, skipping optimization', 'debug' );
			return $search;
		}

		global $wpdb;

		$search_term = $query->get( 's' );
		if ( empty( $search_term ) ) {
			miniload_log( '[SEARCH DEBUG] Empty search term, returning original', 'debug' );
			return $search;
		}

		miniload_log( sprintf( '[SEARCH DEBUG] Processing search for term: "%s"', $search_term ), 'info' );

		// Clear any stale cache for this search
		// $cache_key = miniload_get_cache_key( 'search', $search_term );
		// wp_cache_delete( $cache_key, 'miniload' );

		// First, check for exact SKU match (instant - 0.5ms!)
		$sku_match = $this->search_by_sku( $search_term );
		if ( $sku_match ) {
			// Return SQL that will only find this product
			miniload_log( sprintf( '[SEARCH DEBUG] SKU match found for "%s": Product #%d', $search_term, $sku_match ), 'info' );
			$sql = " AND {$wpdb->posts}.ID = " . intval( $sku_match );
			miniload_log( sprintf( '[SEARCH DEBUG] Returning SQL: %s', $sql ), 'debug' );
			return $sql;
		}

		// Use our lean search index
		$product_ids = $this->search_products( $search_term );

		if ( ! empty( $product_ids ) ) {
			// Replace the search with specific product IDs
			$ids_string = implode( ',', array_map( 'intval', $product_ids ) );
			miniload_log( sprintf( '[SEARCH DEBUG] Search optimizer found %d products for "%s". IDs: %s', count($product_ids), $search_term, $ids_string ), 'info' );

			// Return SQL that replaces the search with our product IDs
			// IMPORTANT: We set miniload_found_ids so the MU plugin can bypass WC query
			miniload_log( sprintf( '[SEARCH DEBUG] Setting miniload_found_ids for MU plugin bypass', $ids_string ), 'debug' );

			// Store the IDs in the query for the MU plugin to use
			$query->set( 'miniload_found_ids', $product_ids );

			// Store the IDs for the posts_where filter
			$this->current_search_ids = $product_ids;

			// Return empty string to clear WordPress's default search SQL
			return '';
		}

		// If no results from our index, still try to search
		miniload_log( sprintf( '[SEARCH DEBUG] Search optimizer found no products for "%s", using fallback', $search_term ), 'warning' );

		// Don't return "AND 1=0", instead let WP try its search
		miniload_log( sprintf( '[SEARCH DEBUG] Returning original search: %s', $search ), 'debug' );
		return $search;
	}

	/**
	 * Apply search ID restriction to WHERE clause
	 *
	 * @param string $where WHERE SQL clause
	 * @param WP_Query $query Query object
	 * @return string
	 */
	public function apply_search_ids( $where, $query ) {
		// Only apply if we have search IDs stored
		if ( ! empty( $this->current_search_ids ) && $this->should_optimize_search( $query ) ) {
			global $wpdb;

			$ids_string = implode( ',', array_map( 'intval', $this->current_search_ids ) );
			$id_clause = " AND {$wpdb->posts}.ID IN ({$ids_string})";

			miniload_log( sprintf( '[SEARCH DEBUG] Applying ID restriction in posts_where: %s', $id_clause ), 'debug' );

			$where .= $id_clause;

			// Clear the IDs after using them
			$this->current_search_ids = array();
		}

		return $where;
	}

	/**
	 * Modify search query clauses
	 *
	 * @param array $clauses SQL clauses
	 * @param WP_Query $query Query object
	 * @return array
	 */
	public function modify_search_clauses( $clauses, $query ) {
		// Only for product searches that we've optimized
		if ( ! $this->should_optimize_search( $query ) || empty( $query->get( 's' ) ) ) {
			return $clauses;
		}

		// Remove the default orderby if it's by relevance
		if ( strpos( $clauses['orderby'], 'post_title' ) !== false ) {
			// Order by our relevance score instead
			global $wpdb;
			$search_term = $query->get( 's' );
			$product_ids = $this->search_products( $search_term, true ); // Get with scores

			if ( ! empty( $product_ids ) ) {
				$order_case = "CASE {$wpdb->posts}.ID ";
				$position = 1;
				foreach ( $product_ids as $id ) {
					$order_case .= "WHEN " . intval( $id ) . " THEN " . $position . " ";
					$position++;
				}
				$order_case .= "ELSE 999999 END";

				$clauses['orderby'] = $order_case;
			}
		}

		return $clauses;
	}

	/**
	 * Check if search should be optimized
	 *
	 * @param WP_Query $query Query object
	 * @return bool
	 */
	private function should_optimize_search( $query ) {
		// Check if it's a search query
		if ( ! $query->is_search() ) {
			return false;
		}

		// Check if it's for products
		$post_type = $query->get( 'post_type' );
		if ( $post_type !== 'product' && ! is_array( $post_type ) ) {
			return false;
		}

		if ( is_array( $post_type ) && ! in_array( 'product', $post_type ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Search for products using lean index
	 *
	 * @param string $search_term Search term
	 * @param bool $with_scores Return with relevance scores
	 * @return array Product IDs
	 */
	private function search_products( $search_term, $with_scores = false ) {
		global $wpdb;

		// Clean search term first
		$search_term = sanitize_text_field( $search_term );

		miniload_log( sprintf( '[SEARCH DEBUG] search_products called with term: "%s", with_scores: %s', $search_term, $with_scores ? 'true' : 'false' ), 'debug' );

		// Skip cache for now to debug the issue
		// $cache_key = miniload_get_cache_key( 'search', $search_term );
		// $cached_result = miniload_get_cache( $cache_key );

		// if ( $cached_result !== false && ! empty( $cached_result ) ) {
		// 	miniload_log( sprintf( 'Search cache hit for: %s', $search_term ), 'debug' );
		// 	return $cached_result;
		// }

		// Check if search term contains non-Latin characters (Persian, Arabic, etc)
		$is_non_latin = preg_match( '/[\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u', $search_term );
		miniload_log( sprintf( '[SEARCH DEBUG] Is non-Latin? %s', $is_non_latin ? 'YES' : 'NO' ), 'debug' );

		// For non-Latin (Persian/Arabic), use LIKE search as FULLTEXT may not work well
		if ( $is_non_latin ) {
			$like_term = '%' . $wpdb->esc_like( $search_term ) . '%';

			$query = $wpdb->prepare( "
				SELECT
					product_id,
					CASE
						WHEN title LIKE %s THEN 100
						WHEN sku LIKE %s THEN 90
						WHEN categories LIKE %s THEN 70
						WHEN tags LIKE %s THEN 60
						WHEN attributes LIKE %s THEN 50
						ELSE 1
					END as relevance
				FROM " . esc_sql( $this->table_name ) . "
				WHERE
					title LIKE %s
					OR sku LIKE %s
					OR categories LIKE %s
					OR tags LIKE %s
					OR attributes LIKE %s
				ORDER BY relevance DESC
			",
				$like_term, $like_term, $like_term, $like_term, $like_term,
				$like_term, $like_term, $like_term, $like_term, $like_term
			);

			miniload_log( sprintf( '[SEARCH DEBUG] Non-Latin SQL query: %s', $query ), 'debug' );

			$results = $wpdb->get_results( $query );

			miniload_log( sprintf( '[SEARCH DEBUG] Query returned %d results', count($results) ), 'debug' );

			$product_ids = array();
			foreach ( $results as $result ) {
				$product_ids[] = $result->product_id;
			}

			// Don't cache for now while debugging
			// $final_cache_key = miniload_get_cache_key( 'search', $search_term );
			// miniload_set_cache( $final_cache_key, $product_ids, 300 );

			miniload_log( sprintf( '[SEARCH DEBUG] Persian/Arabic search found %d products for: %s. Product IDs: %s', count($product_ids), $search_term, implode(',', $product_ids) ), 'info' );
			return $product_ids;
		}

		// For Latin characters, try FULLTEXT first, then LIKE as fallback
		// Try FULLTEXT search first
		$results = $wpdb->get_results( $wpdb->prepare( "
			SELECT
				product_id,
				MATCH(title, attributes, categories, tags) AGAINST(%s) as relevance,
				CASE
					WHEN title LIKE %s THEN 100
					WHEN sku = %s THEN 90
					WHEN title LIKE %s THEN 50
					ELSE 0
				END as title_boost
			FROM " . esc_sql( $this->table_name ) . "
			WHERE MATCH(title, attributes, categories, tags) AGAINST(%s IN NATURAL LANGUAGE MODE)
			ORDER BY (relevance + title_boost) DESC
		",
			$search_term,
			$search_term . '%', // Starts with
			$search_term,       // Exact SKU
			'%' . $search_term . '%', // Contains
			$search_term
		) );

		// If FULLTEXT returns nothing (could be due to short words or stopwords), use LIKE
		if ( empty( $results ) ) {
			$like_term = '%' . $wpdb->esc_like( $search_term ) . '%';

			$results = $wpdb->get_results( $wpdb->prepare( "
				SELECT
					product_id,
					CASE
						WHEN title LIKE %s THEN 100
						WHEN sku LIKE %s THEN 90
						WHEN categories LIKE %s THEN 70
						WHEN tags LIKE %s THEN 60
						WHEN attributes LIKE %s THEN 50
						ELSE 1
					END as relevance
				FROM " . esc_sql( $this->table_name ) . "
				WHERE
					title LIKE %s
					OR sku LIKE %s
					OR categories LIKE %s
					OR tags LIKE %s
					OR attributes LIKE %s
				ORDER BY relevance DESC
			",
				$like_term, $search_term, $like_term, $like_term, $like_term,
				$like_term, $like_term, $like_term, $like_term, $like_term
			) );
		}

		$product_ids = array();
		foreach ( $results as $result ) {
			$product_ids[] = $result->product_id;
		}

		// Don't cache for now while debugging
		// $final_cache_key = miniload_get_cache_key( 'search', $search_term );
		// miniload_set_cache( $final_cache_key, $product_ids, 300 ); // 5 minutes

		miniload_log( sprintf( 'Latin search for "%s" found %d products', $search_term, count( $product_ids ) ), 'info' );

		return $product_ids;
	}

	/**
	 * Search by SKU first (ultra-fast)
	 *
	 * @param string $search_term Search term
	 * @return int|false Product ID or false
	 */
	private function search_by_sku( $search_term ) {
		global $wpdb;

		// Direct SKU lookup - uses INDEX!
		// Direct database query with caching
		$cache_key = 'miniload_' . md5(  $wpdb->prepare( "
			SELECT product_id
			FROM " . esc_sql( $this->table_name ) . "
			WHERE sku = %s
			LIMIT 1
		", $search_term )  );
		$cached = wp_cache_get( $cache_key );
		if ( false === $cached ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Required for performance optimization
			$cached = $wpdb->get_var( $wpdb->prepare( "
			SELECT product_id
			FROM " . esc_sql( $this->table_name ) . "
			WHERE sku = %s
			LIMIT 1
		", $search_term ) );
			wp_cache_set( $cache_key, $cached, '', 3600 );
		}

		if ( $product_id ) {
			miniload_log( sprintf( 'SKU match found: %s = Product #%d', $search_term, $product_id ), 'debug' );
		}

		return $product_id;
	}

	/**
	 * Update product in search index
	 *
	 * @param int $product_id Product ID
	 * @param WC_Product $product Product object
	 */
	public function update_product_index( $product_id, $product = null ) {
		global $wpdb;

		// Get product if not provided
		if ( ! $product ) {
			$product = wc_get_product( $product_id );
		}

		if ( ! $product ) {
			return;
		}

		// Get product title (what customers see)
		$title = $product->get_name();

		// Get SKU (critical for B2B)
		$sku = $product->get_sku();

		// Unset product object early if we loaded it ourselves to save memory
		$should_unset = ( func_num_args() === 1 );

		// Get attributes (color, size, brand, etc)
		$attributes = array();
		foreach ( $product->get_attributes() as $attribute ) {
			if ( $attribute->is_taxonomy() ) {
				$terms = wp_get_post_terms( $product_id, $attribute->get_name(), array( 'fields' => 'names' ) );
				$attributes = array_merge( $attributes, $terms );
			} else {
				$attributes[] = $attribute->get_options();
			}
		}
		$attributes_text = implode( ' ', array_filter( array_map( 'strval', $attributes ) ) );

		// Get categories - OPTIMIZED: Use direct query instead of get_term loops
		$categories = array();
		$category_ids = $product->get_category_ids();
		if ( ! empty( $category_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $category_ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
			$category_terms = $wpdb->get_results( $wpdb->prepare(
				"SELECT t.term_id, t.name, tt.parent
				FROM {$wpdb->terms} t
				INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
				WHERE t.term_id IN ($placeholders) AND tt.taxonomy = 'product_cat'",
				$category_ids
			) );

			// Build category hierarchy map
			$term_map = array();
			foreach ( $category_terms as $term ) {
				$term_map[ $term->term_id ] = $term;
				$categories[] = $term->name;
			}

			// Add parent categories efficiently
			foreach ( $category_terms as $term ) {
				$parent_id = $term->parent;
				$visited = array();
				while ( $parent_id && ! in_array( $parent_id, $visited, true ) ) {
					$visited[] = $parent_id;
					if ( isset( $term_map[ $parent_id ] ) ) {
						$categories[] = $term_map[ $parent_id ]->name;
						$parent_id = $term_map[ $parent_id ]->parent;
					} else {
						// Fetch parent if not already loaded
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
						$parent_term = $wpdb->get_row( $wpdb->prepare(
							"SELECT t.term_id, t.name, tt.parent
							FROM {$wpdb->terms} t
							INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
							WHERE t.term_id = %d AND tt.taxonomy = 'product_cat'",
							$parent_id
						) );
						if ( $parent_term ) {
							$term_map[ $parent_id ] = $parent_term;
							$categories[] = $parent_term->name;
							$parent_id = $parent_term->parent;
						} else {
							break;
						}
					}
				}
			}
		}
		$categories_text = implode( ' ', array_unique( $categories ) );

		// Get tags - OPTIMIZED: Use direct query
		$tags = array();
		$tag_ids = $product->get_tag_ids();
		if ( ! empty( $tag_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $tag_ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
			$tag_terms = $wpdb->get_col( $wpdb->prepare(
				"SELECT t.name
				FROM {$wpdb->terms} t
				INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
				WHERE t.term_id IN ($placeholders) AND tt.taxonomy = 'product_tag'",
				$tag_ids
			) );
			$tags = $tag_terms;
		}
		$tags_text = implode( ' ', $tags );

		// Free up memory by unsetting the product object if we loaded it
		if ( $should_unset ) {
			unset( $product );
		}

		// Insert or update in lean index
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for performance optimization
		$wpdb->replace(
			$this->table_name,
			array(
				'product_id'  => $product_id,
				'title'       => $title,
				'sku'         => $sku,
				'attributes'  => $attributes_text,
				'categories'  => $categories_text,
				'tags'        => $tags_text,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		// Clear some caches to prevent memory buildup
		wp_cache_delete( 'alloptions', 'options' );

		miniload_log( sprintf( 'Search index updated for product #%d', $product_id ), 'debug' );
	}

	/**
	 * Update product index for bulk/quick edit
	 *
	 * @param WC_Product $product Product object
	 */
	public function update_product_index_bulk( $product ) {
		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		$this->update_product_index( $product->get_id(), $product );
		miniload_log( sprintf( 'Product index updated via bulk edit for #%d', $product->get_id() ), 'debug' );
	}

	/**
	 * Maybe update product index when meta changes
	 *
	 * @param int    $meta_id    Meta ID
	 * @param int    $post_id    Post ID
	 * @param string $meta_key   Meta key
	 * @param mixed  $meta_value Meta value
	 */
	public function maybe_update_product_on_meta_change( $meta_id, $post_id, $meta_key, $meta_value ) {
		// Check if it's a product
		if ( get_post_type( $post_id ) !== 'product' ) {
			return;
		}

		// Important product meta keys that should trigger reindex
		$important_keys = array(
			'_price',
			'_regular_price',
			'_sale_price',
			'_sku',
			'_stock',
			'_stock_status',
			'_product_attributes',
			'_weight',
			'_length',
			'_width',
			'_height',
		);

		// Check if this is an important meta key
		if ( in_array( $meta_key, $important_keys, true ) ) {
			// Use a short delay to batch multiple meta updates
			$hook_name = 'miniload_delayed_product_index_' . $post_id;

			// Remove any existing scheduled update for this product
			wp_clear_scheduled_hook( $hook_name );

			// Schedule update in 2 seconds (to batch multiple meta changes)
			wp_schedule_single_event( time() + 2, $hook_name, array( $post_id ) );

			// Add the action handler if not already added
			if ( ! has_action( $hook_name ) ) {
				add_action( $hook_name, array( $this, 'update_product_index' ), 10, 1 );
			}

			miniload_log( sprintf( 'Scheduled index update for product #%d due to %s change', $post_id, $meta_key ), 'debug' );
		}
	}

	/**
	 * Maybe update product on import (for WP All Import and similar)
	 *
	 * @param int $post_id Post ID
	 */
	public function maybe_update_product_on_import( $post_id ) {
		if ( get_post_type( $post_id ) !== 'product' ) {
			return;
		}

		$this->update_product_index( $post_id );
		miniload_log( sprintf( 'Product index updated via import for #%d', $post_id ), 'debug' );
	}

	/**
	 * Schedule periodic index sync (failsafe for direct DB operations)
	 */
	public function schedule_index_sync() {
		if ( ! wp_next_scheduled( 'miniload_sync_product_indexes' ) ) {
			// Run every 30 minutes
			wp_schedule_event( time(), 'thirtyminutes', 'miniload_sync_product_indexes' );
		}

		// Add custom schedule if not exists
		add_filter( 'cron_schedules', function( $schedules ) {
			if ( ! isset( $schedules['thirtyminutes'] ) ) {
				$schedules['thirtyminutes'] = array(
					'interval' => 1800, // 30 minutes in seconds
					'display'  => __( 'Every 30 Minutes', 'miniload' ),
				);
			}
			return $schedules;
		} );
	}

	/**
	 * Sync recently modified products (catches direct DB updates)
	 */
	public function sync_recently_modified_products() {
		global $wpdb;

		// Get products modified in the last 35 minutes (with 5 min overlap)
		$thirty_five_mins_ago = date( 'Y-m-d H:i:s', strtotime( '-35 minutes' ) );

		// Check products with recent meta changes
		$product_ids = $wpdb->get_col( $wpdb->prepare( "
			SELECT DISTINCT p.ID
			FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->prefix}miniload_search_index idx ON p.ID = idx.product_id
			WHERE p.post_type = 'product'
			AND p.post_status = 'publish'
			AND (
				p.post_modified >= %s
				OR EXISTS (
					SELECT 1 FROM {$wpdb->postmeta} pm
					WHERE pm.post_id = p.ID
					AND pm.meta_key IN ('_price', '_stock', '_sku', '_stock_status')
					LIMIT 1
				)
			)
			AND (
				idx.updated_at IS NULL
				OR idx.updated_at < p.post_modified
			)
			LIMIT 50
		", $thirty_five_mins_ago ) );

		if ( ! empty( $product_ids ) ) {
			foreach ( $product_ids as $product_id ) {
				$this->update_product_index( $product_id );
			}

			miniload_log( sprintf( 'Synced %d products in periodic index sync', count( $product_ids ) ), 'info' );
		}
	}

	/**
	 * Delete product from index
	 *
	 * @param int $post_id Post ID
	 */
	public function delete_product_index( $post_id ) {
		global $wpdb;

		// Check if it's a product
		if ( get_post_type( $post_id ) !== 'product' ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for performance optimization
		$wpdb->delete(
			$this->table_name,
			array( 'product_id' => $post_id ),
			array( '%d' )
		);

		miniload_log( sprintf( 'Search index deleted for product #%d', $post_id ), 'debug' );
	}

	/**
	 * Log search queries for analytics
	 *
	 * @param WP_Query $query Query object
	 */
	public function log_search( $query ) {
		if ( ! $this->should_optimize_search( $query ) ) {
			return;
		}

		$search_term = $query->get( 's' );
		if ( empty( $search_term ) ) {
			return;
		}

		global $wpdb;

		// Log the search
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Required for performance optimization
		$wpdb->insert(
			$this->log_table,
			array(
				'search_term'    => $search_term,
				'results_count'  => 0, // Will be updated later
				'search_time_ms' => 0, // Will be measured
				'user_id'        => get_current_user_id(),
				'searched_at'    => current_time( 'mysql' ),
			),
			array( '%s', '%d', '%d', '%d', '%s' )
		);
	}

	/**
	 * Rebuild entire search index
	 *
	 * @param int $offset Starting offset
	 * @param int $batch_size Batch size
	 * @return array Results
	 */
	public function rebuild_index( $offset = 0, $batch_size = 100 ) {
		global $wpdb;

		// Get products
		$args = array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => $batch_size,
			'offset'         => $offset,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
		);

		$product_ids = get_posts( $args );

		if ( empty( $product_ids ) ) {
			return array(
				'completed' => true,
				'processed' => 0,
			);
		}

		// ULTRA-OPTIMIZED: Use pure SQL to fetch all data in bulk, avoiding WooCommerce overhead
		$product_ids_str = implode( ',', array_map( 'intval', $product_ids ) );

		// Get all basic product data in one query
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$products_data = $wpdb->get_results(
			"SELECT p.ID, p.post_title as title,
			       (SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = p.ID AND meta_key = '_sku' LIMIT 1) as sku
			FROM {$wpdb->posts} p
			WHERE p.ID IN ($product_ids_str)
			ORDER BY p.ID ASC",
			ARRAY_A
		);

		// Get all term relationships in one query
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$term_relationships = $wpdb->get_results(
			"SELECT tr.object_id as product_id, t.name, tt.taxonomy
			FROM {$wpdb->term_relationships} tr
			INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
			INNER JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
			WHERE tr.object_id IN ($product_ids_str)
			  AND tt.taxonomy IN ('product_cat', 'product_tag', 'pa_color', 'pa_size')
			ORDER BY tr.object_id",
			ARRAY_A
		);

		// Group terms by product
		$product_terms = array();
		foreach ( $term_relationships as $rel ) {
			$pid = $rel['product_id'];
			if ( ! isset( $product_terms[ $pid ] ) ) {
				$product_terms[ $pid ] = array(
					'categories' => array(),
					'tags' => array(),
					'attributes' => array(),
				);
			}

			if ( $rel['taxonomy'] === 'product_cat' ) {
				$product_terms[ $pid ]['categories'][] = $rel['name'];
			} elseif ( $rel['taxonomy'] === 'product_tag' ) {
				$product_terms[ $pid ]['tags'][] = $rel['name'];
			} else {
				$product_terms[ $pid ]['attributes'][] = $rel['name'];
			}
		}

		// Build bulk insert values
		$values = array();
		$placeholders = array();

		foreach ( $products_data as $product ) {
			$product_id = $product['ID'];
			$title = $product['title'];
			$sku = $product['sku'] ?: '';

			$terms = isset( $product_terms[ $product_id ] ) ? $product_terms[ $product_id ] : array(
				'categories' => array(),
				'tags' => array(),
				'attributes' => array(),
			);

			$categories_text = implode( ' ', $terms['categories'] );
			$tags_text = implode( ' ', $terms['tags'] );
			$attributes_text = implode( ' ', $terms['attributes'] );

			// Prepare values for bulk insert
			$values[] = $product_id;
			$values[] = $title;
			$values[] = $sku;
			$values[] = $attributes_text;
			$values[] = $categories_text;
			$values[] = $tags_text;

			$placeholders[] = '(%d, %s, %s, %s, %s, %s)';
		}

		// Bulk INSERT or REPLACE - much faster than individual queries
		if ( ! empty( $values ) ) {
			$sql = "REPLACE INTO {$this->table_name} (product_id, title, sku, attributes, categories, tags) VALUES ";
			$sql .= implode( ', ', $placeholders );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( $wpdb->prepare( $sql, $values ) );
		}

		// Force garbage collection every batch
		if ( function_exists( 'gc_collect_cycles' ) ) {
			gc_collect_cycles();
		}

		// Get total count
		// Direct database query with caching
		$cache_key = 'miniload_' . md5(  "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish'"  );
		$total = wp_cache_get( $cache_key );
		if ( false === $total ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Required for performance optimization
			$total = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish'" );
			wp_cache_set( $cache_key, $total, '', 3600 );
		}

		return array(
			'completed'   => false,
			'processed'   => count( $product_ids ),
			'total'       => $total,
			'next_offset' => $offset + $batch_size,
			'progress'    => min( 100, round( ( ( $offset + count( $product_ids ) ) / $total ) * 100 ) ),
		);
	}

	/**
	 * AJAX: Rebuild search index
	 */
	public function ajax_rebuild_index() {
		// Security check
		if ( ! check_ajax_referer( 'miniload-ajax', 'nonce', false ) ||
		     ! current_user_can( 'manage_options' ) ) {
			wp_die( -1 );
		}

		$offset = isset( $_POST['offset'] ) ? intval( $_POST['offset'] ) : 0;
		$batch_size = 500; // Increased from 100 to 500 for much faster indexing

		$miniload_result = $this->rebuild_index( $offset, $batch_size );

		if ( $miniload_result['completed'] ) {
			// Clear search caches
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for performance optimization
			$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_miniload_search_%'" );

			wp_send_json_success( array(
				'message'   => __( 'Search index rebuilt successfully', 'miniload' ),
				'completed' => true,
			) );
		} else {
			wp_send_json_success( $result );
		}
	}

	/**
	 * Get search statistics
	 *
	 * @return array
	 */
	public function get_stats() {
		global $wpdb;

		$stats = array();

		// Index size
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for performance optimization
		$stats['index_size'] = $wpdb->get_var( "SELECT COUNT(*) FROM " . esc_sql( $this->table_name ) );

		// Popular searches
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for performance optimization
		$stats['popular_searches'] = $wpdb->get_results( "
			SELECT search_term, COUNT(*) as count
			FROM " . esc_sql( $this->log_table ) . "
			WHERE searched_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
			GROUP BY search_term
			ORDER BY count DESC
			LIMIT 10
		" );

		// Zero result searches
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for performance optimization
		$stats['zero_results'] = $wpdb->get_results( "
			SELECT search_term, COUNT(*) as count
			FROM " . esc_sql( $this->log_table ) . "
			WHERE results_count = 0
			AND searched_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
			GROUP BY search_term
			ORDER BY count DESC
			LIMIT 10
		" );

		// Average search time
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for performance optimization
		$stats['avg_search_time'] = $wpdb->get_var( "
			SELECT AVG(search_time_ms)
			FROM " . esc_sql( $this->log_table ) . "
			WHERE searched_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
		" );

		return $stats;
	}

	/**
	 * Warm cache with popular searches
	 */
	public function maybe_warm_search_cache() {
		// Only run occasionally
		$last_run = get_transient( 'miniload_search_cache_warmed' );
		if ( $last_run ) {
			return;
		}

		global $wpdb;

		// Get top searches from last 7 days
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for performance optimization
		$popular = $wpdb->get_col( "
			SELECT DISTINCT search_term
			FROM " . esc_sql( $this->log_table ) . "
			WHERE searched_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
			GROUP BY search_term
			ORDER BY COUNT(*) DESC
			LIMIT 20
		" );

		foreach ( $popular as $term ) {
			$this->search_products( $term ); // This will cache the results
		}

		set_transient( 'miniload_search_cache_warmed', true, HOUR_IN_SECONDS );
	}
}