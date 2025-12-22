<?php
/**
 * MiniLoad Query Optimizer
 * Safely optimizes WordPress queries on archive and search pages
 *
 * @package MiniLoad
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MiniLoad_Query_Optimizer {

	/**
	 * Constructor
	 */
	public function __construct() {
		// Only optimize non-product archive queries
		add_action( 'pre_get_posts', array( $this, 'optimize_blog_queries' ), 999 );

		// Remove post content from blog archives only
		add_filter( 'posts_fields', array( $this, 'optimize_blog_fields' ), 10, 2 );
	}

	/**
	 * Optimize blog/post queries only (not WooCommerce)
	 */
	public function optimize_blog_queries( $query ) {
		// Skip admin queries
		if ( is_admin() || ! $query->is_main_query() ) {
			return;
		}

		// Skip WooCommerce queries entirely
		if ( class_exists( 'WooCommerce' ) ) {
			if ( is_shop() || is_product_category() || is_product_tag() || is_product() ) {
				return;
			}
		}

		// Skip if querying products
		$post_type = $query->get( 'post_type' );
		if ( $post_type === 'product' || ( is_array( $post_type ) && in_array( 'product', $post_type ) ) ) {
			return;
		}

		// Only optimize blog post/page archives
		if ( $query->is_home() || $query->is_category() || $query->is_tag() || $query->is_author() || $query->is_date() ) {
			// Skip counting rows for better performance on first page
			if ( ! $query->get( 'paged' ) || $query->get( 'paged' ) == 1 ) {
				$query->set( 'no_found_rows', true );
			}
		}
	}

	/**
	 * Remove post_content from blog archive queries only
	 */
	public function optimize_blog_fields( $fields, $query ) {
		// Skip if in admin
		if ( is_admin() ) {
			return $fields;
		}

		// Skip if not main query
		if ( ! $query->is_main_query() ) {
			return $fields;
		}

		// Skip ALL WooCommerce related pages
		if ( class_exists( 'WooCommerce' ) ) {
			if ( is_shop() || is_product_category() || is_product_tag() || is_product() ) {
				return $fields;
			}
		}

		// Skip if querying products
		$post_type = $query->get( 'post_type' );
		if ( $post_type === 'product' || ( is_array( $post_type ) && in_array( 'product', $post_type ) ) ) {
			return $fields;
		}

		// Only optimize on blog archives where content isn't typically shown
		if ( $query->is_home() || $query->is_category() || $query->is_tag() || $query->is_author() || $query->is_date() || $query->is_archive() ) {
			global $wpdb;

			// Make sure we're not on a product archive
			if ( ! is_post_type_archive( 'product' ) && ! is_tax( get_object_taxonomies( 'product' ) ) ) {
				// Safely remove post_content only
				if ( strpos( $fields, 'post_content' ) !== false ) {
					$fields = str_replace( ", {$wpdb->posts}.post_content", '', $fields );
					$fields = str_replace( "{$wpdb->posts}.post_content,", '', $fields );
				}
			}
		}

		return $fields;
	}
}

// Initialize the query optimizer
new MiniLoad_Query_Optimizer();