<?php
/**
 * MiniLoad Search Indexer
 * Builds and maintains the product search index
 *
 * @package MiniLoad
 * @since 1.0.0
 */

namespace MiniLoad\Modules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Search_Indexer {

	/**
	 * Search table name
	 */
	private $search_table;

	/**
	 * Constructor
	 */
	public function __construct() {
		global $wpdb;
		$this->search_table = $wpdb->prefix . 'miniload_product_search';

		// Hook to update index when products are saved
		add_action( 'save_post_product', array( $this, 'update_product_index' ), 10, 2 );
		add_action( 'before_delete_post', array( $this, 'remove_product_index' ) );
		add_action( 'woocommerce_product_set_stock_status', array( $this, 'update_product_index_by_id' ) );
		add_action( 'woocommerce_variation_set_stock_status', array( $this, 'update_product_index_by_id' ) );
	}

	/**
	 * Build search text for a product
	 *
	 * @param int $product_id
	 * @return string
	 */
	private function build_search_text( $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return '';
		}

		$search_parts = array();

		// Always include title
		$search_parts[] = $product->get_name();

		// Always include SKU
		if ( $product->get_sku() ) {
			$search_parts[] = $product->get_sku();
		}

		// Always include short description
		if ( $product->get_short_description() ) {
			$search_parts[] = wp_strip_all_tags( $product->get_short_description() );
		}

		// Optionally include full content/description
		$include_content = get_option( 'miniload_search_in_content', true );
		if ( $include_content && $product->get_description() ) {
			$search_parts[] = wp_strip_all_tags( $product->get_description() );
		}

		// Include categories
		$categories = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'names' ) );
		if ( ! is_wp_error( $categories ) && ! empty( $categories ) ) {
			$search_parts = array_merge( $search_parts, $categories );
		}

		// Include tags
		$tags = wp_get_post_terms( $product_id, 'product_tag', array( 'fields' => 'names' ) );
		if ( ! is_wp_error( $tags ) && ! empty( $tags ) ) {
			$search_parts = array_merge( $search_parts, $tags );
		}

		// Include attributes
		$attributes = $product->get_attributes();
		foreach ( $attributes as $attribute ) {
			if ( $attribute->is_taxonomy() ) {
				$terms = wp_get_post_terms( $product_id, $attribute->get_name(), array( 'fields' => 'names' ) );
				if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
					$search_parts = array_merge( $search_parts, $terms );
				}
			} else {
				$values = $attribute->get_options();
				if ( ! empty( $values ) ) {
					$search_parts = array_merge( $search_parts, $values );
				}
			}
		}

		// For variable products, include variation data
		if ( $product->is_type( 'variable' ) ) {
			$variations = $product->get_available_variations();
			foreach ( $variations as $variation ) {
				if ( ! empty( $variation['sku'] ) ) {
					$search_parts[] = $variation['sku'];
				}
				// Include variation attributes
				foreach ( $variation['attributes'] as $attr_value ) {
					if ( ! empty( $attr_value ) ) {
						$search_parts[] = $attr_value;
					}
				}
			}
		}

		// Join all parts with space
		$search_text = implode( ' ', $search_parts );

		// Clean up
		$search_text = preg_replace( '/\s+/', ' ', $search_text );
		$search_text = trim( $search_text );

		return $search_text;
	}

	/**
	 * Update product in search index
	 *
	 * @param int $post_id
	 * @param WP_Post $post
	 */
	public function update_product_index( $post_id, $post = null ) {
		// Skip auto-saves and revisions
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( $post && $post->post_status !== 'publish' ) {
			$this->remove_product_index( $post_id );
			return;
		}

		$this->index_product( $post_id );
	}

	/**
	 * Update product index by ID only
	 *
	 * @param int $product_id
	 */
	public function update_product_index_by_id( $product_id ) {
		$this->index_product( $product_id );
	}

	/**
	 * Index a single product
	 *
	 * @param int $product_id
	 * @return bool
	 */
	public function index_product( $product_id ) {
		global $wpdb;

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return false;
		}

		// Build search text
		$search_text = $this->build_search_text( $product_id );
		$sku = $product->get_sku();

		// Insert or update
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for performance optimization
		$miniload_result = $wpdb->replace(
			$this->search_table,
			array(
				'product_id' => $product_id,
				'search_text' => $search_text,
				'sku' => $sku
			),
			array( '%d', '%s', '%s' )
		);

		return $result !== false;
	}

	/**
	 * Remove product from search index
	 *
	 * @param int $post_id
	 */
	public function remove_product_index( $post_id ) {
		global $wpdb;

		if ( get_post_type( $post_id ) !== 'product' ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for performance optimization
		$wpdb->delete(
			$this->search_table,
			array( 'product_id' => $post_id ),
			array( '%d' )
		);
	}

	/**
	 * Rebuild entire search index
	 *
	 * @param int $limit Limit number of products to index (0 = all)
	 * @return array Results
	 */
	public function rebuild_index( $limit = 0 ) {
		global $wpdb;

		$start_time = microtime( true );

		// Clear existing index
		$wpdb->query( "TRUNCATE TABLE " . esc_sql( $this->search_table ) );

		// Get all published products
		$args = array(
			'post_type' => 'product',
			'post_status' => 'publish',
			'posts_per_page' => $limit > 0 ? $limit : -1,
			'fields' => 'ids'
		);

		$product_ids = get_posts( $args );
		$total = count( $product_ids );
		$indexed = 0;
		$failed = 0;

		foreach ( $product_ids as $product_id ) {
			if ( $this->index_product( $product_id ) ) {
				$indexed++;
			} else {
				$failed++;
			}

			// Allow other processes to run
			if ( $indexed % 100 === 0 ) {
				wp_cache_flush();
			}
		}

		$time_taken = round( microtime( true ) - $start_time, 2 );

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: %1$d: number of successfully indexed products, %2$d: number of failed products, %3$s: time taken in seconds */
				__( 'Indexed %1$d products successfully, %2$d failed. Time: %3$s seconds', 'miniload' ),
				$indexed,
				$failed,
				$time_taken
			),
			'indexed' => $indexed,
			'failed' => $failed,
			'total' => $total,
			'time' => $time_taken
		);
	}

	/**
	 * Get index statistics
	 *
	 * @return array
	 */
	public function get_stats() {
		global $wpdb;

		// Direct database query with caching
		$cache_key = 'miniload_' . md5(  "SELECT COUNT(*) FROM " . esc_sql( $this->search_table ) . ""  );
		$cached = wp_cache_get( $cache_key );
		if ( false === $cached ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Required for performance optimization
			$cached = $wpdb->get_var( "SELECT COUNT(*) FROM " . esc_sql( $this->search_table ) );
			wp_cache_set( $cache_key, $cached, '', 3600 );
		}
		// Direct database query with caching
		$cache_key = 'miniload_' . md5(  "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish'"  );
		$cached = wp_cache_get( $cache_key );
		if ( false === $cached ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Required for performance optimization
			$cached = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish'" );
			wp_cache_set( $cache_key, $cached, '', 3600 );
		}

		// Check if content indexing is enabled
		$include_content = get_option( 'miniload_search_in_content', true );

		// Get average search text length
		// Direct database query with caching
		$cache_key = 'miniload_' . md5(  "SELECT AVG(CHAR_LENGTH(search_text)) FROM " . esc_sql( $this->search_table ) . ""  );
		$cached = wp_cache_get( $cache_key );
		if ( false === $cached ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Required for performance optimization
			$cached = $wpdb->get_var( "SELECT AVG(CHAR_LENGTH(search_text)) FROM " . esc_sql( $this->search_table ) . "" );
			wp_cache_set( $cache_key, $cached, '', 3600 );
		}

		return array(
			'indexed' => $indexed_count,
			'total' => $total_products,
			'coverage' => $total_products > 0 ? round( ( $indexed_count / $total_products ) * 100, 1 ) : 0,
			'include_content' => $include_content,
			'avg_text_length' => round( $avg_length )
		);
	}
}

// Initialize if in admin
if ( is_admin() ) {
	new Search_Indexer();
}