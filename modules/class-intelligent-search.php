<?php
/**
 * MiniLoad Intelligent Search Module
 *
 * Smart field selection based on search term characteristics
 *
 * @package MiniLoad\Modules
 * @since 1.0.0
 */

namespace MiniLoad\Modules;

defined( 'ABSPATH' ) || exit;

/**
 * Intelligent Search class
 */
class Intelligent_Search {

	/**
	 * Constructor
	 */
	public function __construct() {
		// Hook into the search query modification
		add_filter( 'miniload_order_search_fields', array( $this, 'optimize_search_fields' ), 10, 2 );
	}

	/**
	 * Optimize search fields based on search term
	 *
	 * @param array $fields Fields to search
	 * @param string $search_term The search term
	 * @return array Optimized fields
	 */
	public function optimize_search_fields( $fields, $search_term ) {
		// Analyze search term characteristics
		$term_analysis = $this->analyze_search_term( $search_term );

		// Smart field selection
		$optimized_fields = array();

		// Always search these text fields
		$optimized_fields[] = 'customer_name';
		$optimized_fields[] = 'customer_email';
		$optimized_fields[] = 'product_names';
		$optimized_fields[] = 'billing_company';

		// Only search phone if term looks like a phone number
		if ( $term_analysis['has_numbers'] && ! $term_analysis['has_letters'] ) {
			$optimized_fields[] = 'billing_phone';
		}

		// Only search order_number if term is numeric or looks like an order ID
		if ( $term_analysis['is_numeric'] || $term_analysis['looks_like_order_id'] ) {
			$optimized_fields[] = 'order_number';
		}

		// Only search SKU if term looks like a SKU (mix of letters/numbers, no spaces)
		if ( $term_analysis['looks_like_sku'] ) {
			$optimized_fields[] = 'sku_list';
		}

		// Add address fields for text searches
		if ( $term_analysis['has_letters'] && ! $term_analysis['is_email'] ) {
			$optimized_fields[] = 'billing_address';
			$optimized_fields[] = 'shipping_address';
		}

		return $optimized_fields;
	}

	/**
	 * Analyze search term characteristics
	 *
	 * @param string $search_term
	 * @return array
	 */
	private function analyze_search_term( $search_term ) {
		$analysis = array(
			'is_numeric' => false,
			'has_numbers' => false,
			'has_letters' => false,
			'is_email' => false,
			'looks_like_phone' => false,
			'looks_like_order_id' => false,
			'looks_like_sku' => false,
			'is_persian' => false,
			'is_english' => false,
		);

		// Check if purely numeric
		$analysis['is_numeric'] = ctype_digit( $search_term );

		// Check for numbers
		$analysis['has_numbers'] = (bool) preg_match( '/\d/', $search_term );

		// Check for letters (any Unicode letter)
		$analysis['has_letters'] = (bool) preg_match( '/\p{L}/u', $search_term );

		// Check if it's an email
		$analysis['is_email'] = filter_var( $search_term, FILTER_VALIDATE_EMAIL ) !== false;

		// Check if it looks like a phone number (mostly digits, maybe + or -)
		$analysis['looks_like_phone'] = preg_match( '/^[\d\s\-\+\(\)]+$/', $search_term ) && strlen( $search_term ) >= 7;

		// Check if it looks like an order ID (numeric or #numeric)
		$analysis['looks_like_order_id'] = $analysis['is_numeric'] || preg_match( '/^#?\d+$/', $search_term );

		// Check if it looks like a SKU (alphanumeric, no spaces, may have dashes/underscores)
		$analysis['looks_like_sku'] = preg_match( '/^[A-Za-z0-9\-_]+$/', $search_term ) &&
		                               $analysis['has_numbers'] &&
		                               $analysis['has_letters'] &&
		                               strpos( $search_term, ' ' ) === false;

		// Check if Persian text
		$analysis['is_persian'] = (bool) preg_match( '/[\x{0600}-\x{06FF}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u', $search_term );

		// Check if English text
		$analysis['is_english'] = (bool) preg_match( '/[a-zA-Z]/', $search_term );

		return $analysis;
	}

	/**
	 * Get search query based on term analysis
	 *
	 * @param string $search_term
	 * @param string $table_name
	 * @return string SQL WHERE clause
	 */
	public function get_optimized_search_query( $search_term, $table_name ) {
		global $wpdb;

		$analysis = $this->analyze_search_term( $search_term );
		$conditions = array();
		$like_term = '%' . $wpdb->esc_like( $search_term ) . '%';

		// Exact match on order number if numeric
		if ( $analysis['is_numeric'] || $analysis['looks_like_order_id'] ) {
			$clean_id = str_replace( '#', '', $search_term );
			$conditions[] = $wpdb->prepare( "" . esc_sql( $table_name ) . ".order_number = %s", $clean_id );
		}

		// Phone search only for phone-like terms
		if ( $analysis['looks_like_phone'] ) {
			// Clean the phone number for better matching
			$clean_phone = preg_replace( '/[^\d]/', '', $search_term );
			$conditions[] = $wpdb->prepare( "" . esc_sql( $table_name ) . ".billing_phone LIKE %s", '%' . $wpdb->esc_like( $clean_phone ) . '%' );
		}

		// Email search
		if ( $analysis['is_email'] ) {
			$conditions[] = $wpdb->prepare( "" . esc_sql( $table_name ) . ".customer_email = %s", $search_term );
		} elseif ( strpos( $search_term, '@' ) !== false ) {
			// Partial email match
			$conditions[] = $wpdb->prepare( "" . esc_sql( $table_name ) . ".customer_email LIKE %s", $like_term );
		}

		// Name search for text terms
		if ( $analysis['has_letters'] && ! $analysis['is_email'] ) {
			$conditions[] = $wpdb->prepare( "" . esc_sql( $table_name ) . ".customer_name LIKE %s", $like_term );
			$conditions[] = $wpdb->prepare( "" . esc_sql( $table_name ) . ".product_names LIKE %s", $like_term );

			// Only search addresses for Persian/English text
			if ( $analysis['is_persian'] || $analysis['is_english'] ) {
				$conditions[] = $wpdb->prepare( "" . esc_sql( $table_name ) . ".billing_address LIKE %s", $like_term );
				$conditions[] = $wpdb->prepare( "" . esc_sql( $table_name ) . ".billing_company LIKE %s", $like_term );
			}
		}

		// SKU search for SKU-like terms
		if ( $analysis['looks_like_sku'] ) {
			$conditions[] = $wpdb->prepare( "" . esc_sql( $table_name ) . ".sku_list LIKE %s", $like_term );
		}

		// If no specific conditions, fall back to searchable_text
		if ( empty( $conditions ) ) {
			$conditions[] = $wpdb->prepare( "" . esc_sql( $table_name ) . ".searchable_text LIKE %s", $like_term );
		}

		return '(' . implode( ' OR ', $conditions ) . ')';
	}

	/**
	 * Get statistics about search optimization
	 *
	 * @return array
	 */
	public function get_stats() {
		return array(
			'name' => 'Intelligent Search',
			'status' => 'Active',
			'description' => 'Smart field selection based on search term analysis',
			'features' => array(
				'Numeric detection for order/phone searches',
				'Email pattern recognition',
				'SKU pattern matching',
				'Persian/English text detection',
				'Reduced unnecessary field searches'
			),
		);
	}
}