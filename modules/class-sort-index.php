<?php
/**
 * Sort Index Module
 *
 * Denormalized sort tables for instant product sorting
 * Eliminates expensive postmeta JOINs and CAST operations
 *
 * @package MiniLoad\Modules
 * @since 1.0.0
 */

namespace MiniLoad\Modules;

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

/**
 * Sort Index class
 */
class Sort_Index {

	/**
	 * Table name
	 *
	 * @var string
	 */
	private $table_name;

	/**
	 * Constructor
	 */
	public function __construct() {
		global $wpdb;
		$this->table_name = $wpdb->prefix . 'miniload_sort_index';

		$this->init_hooks();
	}

	/**
	 * Initialize hooks
	 */
	private function init_hooks() {
		// DISABLED - Breaking WooCommerce sort widget
		return;

		// Hook into WooCommerce product queries
		add_filter( 'posts_clauses', array( $this, 'optimize_product_queries' ), 100, 2 );

		// Update index when products are saved
		add_action( 'woocommerce_update_product', array( $this, 'update_product_index' ), 10, 2 );
		add_action( 'woocommerce_new_product', array( $this, 'update_product_index' ), 10, 2 );
		add_action( 'before_delete_post', array( $this, 'delete_product_index' ), 10, 1 );

		// Bulk index rebuild
		add_action( 'wp_ajax_miniload_rebuild_sort_index', array( $this, 'ajax_rebuild_index' ) );

		// Schedule periodic sync
		add_action( 'miniload_sync_sort_index', array( $this, 'sync_index' ) );

		if ( ! wp_next_scheduled( 'miniload_sync_sort_index' ) ) {
			wp_schedule_event( time(), 'hourly', 'miniload_sync_sort_index' );
		}
	}

	/**
	 * Optimize product queries by using sort index
	 *
	 * @param array $clauses SQL clauses
	 * @param WP_Query $query Query object
	 * @return array
	 */
	public function optimize_product_queries( $clauses, $query ) {
		// Only optimize product queries
		if ( ! $this->should_optimize_query( $query ) ) {
			return $clauses;
		}

		global $wpdb;

		// Get orderby parameter
		$orderby = $query->get( 'orderby' );

		// Map WooCommerce orderby to our index columns
		$sort_mapping = array(
			'price'      => 'price',
			'_price'     => 'price',
			'date'       => 'date_created',
			'modified'   => 'date_modified',
			'popularity' => 'total_sales',
			'rating'     => 'average_rating',
			'menu_order' => 'menu_order',
		);

		if ( ! isset( $sort_mapping[ $orderby ] ) ) {
			return $clauses;
		}

		$sort_column = $sort_mapping[ $orderby ];
		$order = $query->get( 'order', 'ASC' );

		// Join with our sort index table
		$clauses['join'] .= " LEFT JOIN " . esc_sql( $this->table_name ) . " miniload_sort ON {$wpdb->posts}.ID = miniload_sort.product_id";

		// Replace the orderby clause
		$clauses['orderby'] = "miniload_sort.{$sort_column} {$order}";

		// Log the optimization
		miniload_log( sprintf( 'Sort query optimized: %s %s', $sort_column, $order ), 'debug' );

		return $clauses;
	}

	/**
	 * Check if query should be optimized
	 *
	 * @param WP_Query $query Query object
	 * @return bool
	 */
	private function should_optimize_query( $query ) {
		// Check if it's a product query
		if ( $query->get( 'post_type' ) !== 'product' ) {
			return false;
		}

		// Check if it's main query or archive query
		if ( ! $query->is_main_query() && ! $query->is_archive() && ! $query->is_search() ) {
			// Also check for WooCommerce shortcodes
			if ( ! doing_action( 'woocommerce_shortcode_products_query' ) ) {
				return false;
			}
		}

		// Check if sorting is applied
		$orderby = $query->get( 'orderby' );
		if ( empty( $orderby ) || $orderby === 'rand' ) {
			return false;
		}

		return true;
	}

	/**
	 * Update product in sort index
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

		// Prepare data for index
		$data = array(
			'product_id'      => $product_id,
			'price'          => $product->get_price( 'edit' ),
			'regular_price'  => $product->get_regular_price( 'edit' ),
			'sale_price'     => $product->get_sale_price( 'edit' ),
			'total_sales'    => $product->get_total_sales( 'edit' ),
			'average_rating' => $product->get_average_rating(),
			'review_count'   => $product->get_review_count(),
			'stock_quantity' => $product->get_stock_quantity(),
			'is_featured'    => $product->is_featured() ? 1 : 0,
			'is_on_sale'     => $product->is_on_sale() ? 1 : 0,
			'menu_order'     => $product->get_menu_order(),
			'date_created'   => $product->get_date_created() ? $product->get_date_created()->format( 'Y-m-d H:i:s' ) : current_time( 'mysql' ),
			'date_modified'  => $product->get_date_modified() ? $product->get_date_modified()->format( 'Y-m-d H:i:s' ) : current_time( 'mysql' ),
		);

		// Handle variable products - use min price
		if ( $product->is_type( 'variable' ) ) {
			$prices = $product->get_variation_prices();
			$data['price'] = ! empty( $prices['price'] ) ? min( $prices['price'] ) : 0;
			$data['regular_price'] = ! empty( $prices['regular_price'] ) ? min( $prices['regular_price'] ) : 0;
			$data['sale_price'] = ! empty( $prices['sale_price'] ) ? min( array_filter( $prices['sale_price'] ) ) : null;
		}

		// Sanitize numeric values
		foreach ( array( 'price', 'regular_price', 'sale_price', 'total_sales', 'average_rating', 'stock_quantity' ) as $key ) {
			if ( isset( $data[ $key ] ) && $data[ $key ] === '' ) {
				$data[ $key ] = null;
			}
		}

		// Insert or update
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for performance optimization
		$wpdb->replace(
			$this->table_name,
			$data,
			array(
				'%d', // product_id
				'%f', // price
				'%f', // regular_price
				'%f', // sale_price
				'%d', // total_sales
				'%f', // average_rating
				'%d', // review_count
				'%d', // stock_quantity
				'%d', // is_featured
				'%d', // is_on_sale
				'%d', // menu_order
				'%s', // date_created
				'%s', // date_modified
			)
		);

		miniload_log( sprintf( 'Sort index updated for product #%d', $product_id ), 'debug' );
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

		miniload_log( sprintf( 'Sort index deleted for product #%d', $post_id ), 'debug' );
	}

	/**
	 * Rebuild entire sort index
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

		// Process each product
		foreach ( $product_ids as $product_id ) {
			$this->update_product_index( $product_id );
		}

		// Get total count
		// Direct database query with caching
		$cache_key = 'miniload_' . md5(  "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish'"  );
		$cached = wp_cache_get( $cache_key );
		if ( false === $cached ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Required for performance optimization
			$cached = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish'" );
			wp_cache_set( $cache_key, $cached, '', 3600 );
		}

		return array(
			'completed' => false,
			'processed' => count( $product_ids ),
			'total'     => $total,
			'next_offset' => $offset + $batch_size,
			'progress'  => min( 100, round( ( ( $offset + count( $product_ids ) ) / $total ) * 100 ) ),
		);
	}

	/**
	 * AJAX: Rebuild sort index
	 */
	public function ajax_rebuild_index() {
		// Security check
		if ( ! check_ajax_referer( 'miniload-ajax', 'nonce', false ) ||
		     ! current_user_can( 'manage_options' ) ) {
			wp_die( -1 );
		}

		$offset = isset( $_POST['offset'] ) ? intval( $_POST['offset'] ) : 0;
		$batch_size = 100;

		$miniload_result = $this->rebuild_index( $offset, $batch_size );

		if ( $miniload_result['completed'] ) {
			// Clear any product query caches
			miniload_clear_all_caches();

			wp_send_json_success( array(
				'message' => __( 'Sort index rebuilt successfully', 'miniload' ),
				'completed' => true,
			) );
		} else {
			wp_send_json_success( $result );
		}
	}

	/**
	 * Sync index with database
	 */
	public function sync_index() {
		global $wpdb;

		// Find products not in index
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for performance optimization
		$missing = $wpdb->get_col( "
			SELECT p.ID
			FROM {$wpdb->posts} p
			LEFT JOIN " . esc_sql( $this->table_name ) . " idx ON p.ID = idx.product_id
			WHERE p.post_type = 'product'
			AND p.post_status = 'publish'
			AND idx.product_id IS NULL
			LIMIT 50
		" );

		foreach ( $missing as $product_id ) {
			$this->update_product_index( $product_id );
		}

		// Find outdated entries
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for performance optimization
		$outdated = $wpdb->get_col( "
			SELECT idx.product_id
			FROM " . esc_sql( $this->table_name ) . " idx
			LEFT JOIN {$wpdb->posts} p ON idx.product_id = p.ID
			WHERE p.ID IS NULL
			OR p.post_status != 'publish'
			LIMIT 50
		" );

		foreach ( $outdated as $product_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for performance optimization
			$wpdb->delete(
				$this->table_name,
				array( 'product_id' => $product_id ),
				array( '%d' )
			);
		}
	}

	/**
	 * Get index statistics
	 *
	 * @return array
	 */
	public function get_stats() {
		global $wpdb;

		$stats = array();

		// Total products in index
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for performance optimization
		$stats['indexed'] = $wpdb->get_var( "SELECT COUNT(*) FROM " . esc_sql( $this->table_name ) );

		// Total products in database
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for performance optimization
		$stats['total'] = $wpdb->get_var( "
			SELECT COUNT(*)
			FROM {$wpdb->posts}
			WHERE post_type = 'product'
			AND post_status = 'publish'
		" );

		// Index coverage
		$stats['coverage'] = $stats['total'] > 0 ? round( ( $stats['indexed'] / $stats['total'] ) * 100, 2 ) : 0;

		// Average values
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for performance optimization
		$averages = $wpdb->get_row( "
			SELECT
				AVG(price) as avg_price,
				AVG(total_sales) as avg_sales,
				AVG(average_rating) as avg_rating
			FROM " . esc_sql( $this->table_name ) . "
		" );

		$stats['averages'] = $averages;

		return $stats;
	}
}