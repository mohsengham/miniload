<?php
/**
 * Review Stats Cache Module
 *
 * Pre-calculates and caches product review statistics
 * SAFE: Display only, doesn't affect review submission
 *
 * @package MiniLoad
 * @subpackage Modules
 */

namespace MiniLoad\Modules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Review Stats Cache class
 */
class Review_Stats_Cache {

	/**
	 * Cache table name
	 */
	private $table_name;

	/**
	 * Cache duration in seconds
	 */
	private $cache_duration = 3600;

	/**
	 * Constructor
	 */
	public function __construct() {
		global $wpdb;
		$this->table_name = $wpdb->prefix . 'miniload_review_stats';

		// Create table if needed
		add_action( 'init', array( $this, 'maybe_create_table' ) );

		// Hook into review display
		add_filter( 'woocommerce_product_get_average_rating', array( $this, 'get_cached_average_rating' ), 10, 2 );
		add_filter( 'woocommerce_product_get_review_count', array( $this, 'get_cached_review_count' ), 10, 2 );
		add_filter( 'woocommerce_product_get_rating_counts', array( $this, 'get_cached_rating_counts' ), 10, 2 );

		// Update cache when reviews change
		add_action( 'comment_post', array( $this, 'update_review_stats_on_new_review' ), 10, 3 );
		add_action( 'edit_comment', array( $this, 'update_review_stats_on_edit' ), 10, 2 );
		add_action( 'deleted_comment', array( $this, 'update_review_stats_on_delete' ), 10, 2 );
		add_action( 'trash_comment', array( $this, 'update_review_stats_on_delete' ), 10, 2 );
		add_action( 'untrash_comment', array( $this, 'update_review_stats_on_untrash' ), 10, 2 );
		add_action( 'wp_set_comment_status', array( $this, 'update_review_stats_on_status_change' ), 10, 2 );

		// Schedule batch updates
		add_action( 'init', array( $this, 'schedule_batch_update' ) );
		add_action( 'miniload_update_review_stats', array( $this, 'batch_update_all_stats' ) );

		// Admin settings
		add_action( 'admin_init', array( $this, 'register_settings' ) );

		// CLI commands
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'miniload update-reviews', array( $this, 'cli_update_stats' ) );
		}
	}

	/**
	 * Maybe create the stats table
	 */
	public function maybe_create_table() {
		global $wpdb;

		// Direct database query with caching
		$cache_key = 'miniload_' . md5(  "SHOW TABLES LIKE '{$this->table_name}'"  );
		$table_exists = wp_cache_get( $cache_key );
		if ( false === $table_exists ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Required for performance optimization
			$table_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $this->table_name ) );
			wp_cache_set( $cache_key, $table_exists, '', 3600 );
		}

		if ( $table_exists !== $this->table_name ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';

			$charset_collate = $wpdb->get_charset_collate();

			$sql = "CREATE TABLE {$this->table_name} (
				product_id bigint(20) NOT NULL,
				average_rating decimal(3,2) DEFAULT 0.00,
				review_count int(11) DEFAULT 0,
				rating_1_count int(11) DEFAULT 0,
				rating_2_count int(11) DEFAULT 0,
				rating_3_count int(11) DEFAULT 0,
				rating_4_count int(11) DEFAULT 0,
				rating_5_count int(11) DEFAULT 0,
				last_updated datetime NOT NULL,
				PRIMARY KEY (product_id),
				KEY idx_average_rating (average_rating),
				KEY idx_review_count (review_count),
				KEY idx_last_updated (last_updated)
			) $charset_collate;";

			dbDelta( $sql );

			// Initial population
			$this->batch_update_all_stats();
		}
	}

	/**
	 * Get cached average rating
	 */
	public function get_cached_average_rating( $rating, $product ) {
		// Skip if in admin and editing
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $rating;
		}

		$product_id = $product->get_id();
		$cached = $this->get_cached_stats( $product_id );

		if ( $cached && isset( $cached->average_rating ) ) {
			return floatval( $cached->average_rating );
		}

		// Calculate and cache if not found
		$this->update_single_product_stats( $product_id );

		return $rating;
	}

	/**
	 * Get cached review count
	 */
	public function get_cached_review_count( $count, $product ) {
		// Skip if in admin and editing
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $count;
		}

		$product_id = $product->get_id();
		$cached = $this->get_cached_stats( $product_id );

		if ( $cached && isset( $cached->review_count ) ) {
			return intval( $cached->review_count );
		}

		// Calculate and cache if not found
		$this->update_single_product_stats( $product_id );

		return $count;
	}

	/**
	 * Get cached rating counts
	 */
	public function get_cached_rating_counts( $counts, $product ) {
		// Skip if in admin and editing
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $counts;
		}

		$product_id = $product->get_id();
		$cached = $this->get_cached_stats( $product_id );

		if ( $cached ) {
			return array(
				1 => intval( $cached->rating_1_count ),
				2 => intval( $cached->rating_2_count ),
				3 => intval( $cached->rating_3_count ),
				4 => intval( $cached->rating_4_count ),
				5 => intval( $cached->rating_5_count )
			);
		}

		// Calculate and cache if not found
		$this->update_single_product_stats( $product_id );

		return $counts;
	}

	/**
	 * Get cached stats from database
	 */
	private function get_cached_stats( $product_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for performance optimization
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM " . esc_sql( $this->table_name ) . "
			WHERE product_id = %d
			AND last_updated > DATE_SUB(NOW(), INTERVAL %d SECOND)",
			$product_id,
			$this->cache_duration
		) );
	}

	/**
	 * Update stats for single product
	 */
	public function update_single_product_stats( $product_id ) {
		global $wpdb;

		// Get all approved reviews for this product
		$reviews = get_comments( array(
			'post_id' => $product_id,
			'status' => 'approve',
			'type' => 'review',
			'meta_key' => 'rating', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Required for review ratings
			'meta_value' => array( 1, 2, 3, 4, 5 ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Required for review ratings
			'meta_compare' => 'IN'
		) );

		// Calculate stats
		$total = 0;
		$count = 0;
		$rating_counts = array( 1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0 );

		foreach ( $reviews as $review ) {
			$rating = get_comment_meta( $review->comment_ID, 'rating', true );
			if ( $rating ) {
				$rating = intval( $rating );
				$total += $rating;
				$count++;
				if ( isset( $rating_counts[ $rating ] ) ) {
					$rating_counts[ $rating ]++;
				}
			}
		}

		$average = $count > 0 ? round( $total / $count, 2 ) : 0;

		// Update or insert into cache table
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for performance optimization
		$wpdb->replace(
			$this->table_name,
			array(
				'product_id' => $product_id,
				'average_rating' => $average,
				'review_count' => $count,
				'rating_1_count' => $rating_counts[1],
				'rating_2_count' => $rating_counts[2],
				'rating_3_count' => $rating_counts[3],
				'rating_4_count' => $rating_counts[4],
				'rating_5_count' => $rating_counts[5],
				'last_updated' => current_time( 'mysql' )
			),
			array( '%d', '%f', '%d', '%d', '%d', '%d', '%d', '%d', '%s' )
		);

		// Clear product transients
		wc_delete_product_transients( $product_id );
	}

	/**
	 * Update stats when new review is posted
	 */
	public function update_review_stats_on_new_review( $comment_id, $approved, $commentdata ) {
		if ( isset( $commentdata['comment_type'] ) && 'review' === $commentdata['comment_type'] ) {
			$product_id = $commentdata['comment_post_ID'];
			$this->update_single_product_stats( $product_id );
		}
	}

	/**
	 * Update stats when review is edited
	 */
	public function update_review_stats_on_edit( $comment_id, $data ) {
		$comment = get_comment( $comment_id );
		if ( $comment && 'review' === $comment->comment_type ) {
			$this->update_single_product_stats( $comment->comment_post_ID );
		}
	}

	/**
	 * Update stats when review is deleted
	 */
	public function update_review_stats_on_delete( $comment_id, $comment ) {
		if ( $comment && 'review' === $comment->comment_type ) {
			$this->update_single_product_stats( $comment->comment_post_ID );
		}
	}

	/**
	 * Update stats when review is untrashed
	 */
	public function update_review_stats_on_untrash( $comment_id, $comment ) {
		if ( $comment && 'review' === $comment->comment_type ) {
			$this->update_single_product_stats( $comment->comment_post_ID );
		}
	}

	/**
	 * Update stats when review status changes
	 */
	public function update_review_stats_on_status_change( $comment_id, $status ) {
		$comment = get_comment( $comment_id );
		if ( $comment && 'review' === $comment->comment_type ) {
			$this->update_single_product_stats( $comment->comment_post_ID );
		}
	}

	/**
	 * Schedule batch update
	 */
	public function schedule_batch_update() {
		if ( ! wp_next_scheduled( 'miniload_update_review_stats' ) ) {
			wp_schedule_event( time(), 'daily', 'miniload_update_review_stats' );
		}
	}

	/**
	 * Batch update all product stats
	 */
	public function batch_update_all_stats() {
		$products = get_posts( array(
			'post_type' => 'product',
			'posts_per_page' => -1,
			'fields' => 'ids',
			'post_status' => 'publish'
		) );

		$updated = 0;

		foreach ( $products as $product_id ) {
			// Check if product has reviews
			$review_count = get_comments( array(
				'post_id' => $product_id,
				'type' => 'review',
				'count' => true,
				'status' => 'approve'
			) );

			if ( $review_count > 0 ) {
				$this->update_single_product_stats( $product_id );
				$updated++;
			}
		}

		if ( function_exists( 'miniload_log' ) ) {
			miniload_log( 'Updated review stats for ' . $updated . ' products', 'info' );
		}

		return $updated;
	}

	/**
	 * WP-CLI command to update stats
	 */
	public function cli_update_stats() {
		\WP_CLI::log( 'Starting review stats update...' );

		$start = microtime( true );
		$updated = $this->batch_update_all_stats();
		$time = round( microtime( true ) - $start, 2 );

		\WP_CLI::success( "Updated review stats for {$updated} products in {$time} seconds" );
	}

	/**
	 * Register settings
	 */
	public function register_settings() {
		register_setting(
			'miniload_settings',
			'miniload_review_cache_duration',
			array(
				'type' => 'integer',
				'default' => 3600,
				'sanitize_callback' => 'absint'
			)
		);

		// Update cache duration from settings
		$duration = get_option( 'miniload_review_cache_duration', 3600 );
		$this->cache_duration = max( 600, $duration ); // Minimum 10 minutes
	}

	/**
	 * Get statistics
	 */
	public function get_stats() {
		global $wpdb;

		$stats = array(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for performance optimization
			'total_cached' => $wpdb->get_var( "SELECT COUNT(*) FROM " . esc_sql( $this->table_name ) ),
			'products_with_reviews' => $wpdb->get_var( "SELECT COUNT(*) FROM " . esc_sql( $this->table_name ) . " WHERE review_count > 0" ),
			'average_rating_overall' => $wpdb->get_var( "SELECT AVG(average_rating) FROM " . esc_sql( $this->table_name ) . " WHERE review_count > 0" ),
			'total_reviews' => $wpdb->get_var( "SELECT SUM(review_count) FROM " . esc_sql( $this->table_name ) . "" ),
			'last_update' => $wpdb->get_var( "SELECT MAX(last_updated) FROM " . esc_sql( $this->table_name ) . "" )
		);

		$stats['average_rating_overall'] = round( $stats['average_rating_overall'], 2 );

		return $stats;
	}
}