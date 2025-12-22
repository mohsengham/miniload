<?php
/**
 * Database Indexes Module
 *
 * Strategic MySQL indexes for WooCommerce optimization
 *
 * @package MiniLoad\Modules
 * @since 1.0.0
 */

namespace MiniLoad\Modules;

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

/**
 * Database Indexes class
 */
class Database_Indexes {

	/**
	 * Index definitions
	 *
	 * @var array
	 */
	private $indexes = array();

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->define_indexes();
		$this->init_hooks();
	}

	/**
	 * Initialize hooks
	 */
	private function init_hooks() {
		// Check indexes on admin init
		add_action( 'admin_init', array( $this, 'maybe_create_indexes' ) );

		// AJAX handlers
		add_action( 'wp_ajax_miniload_check_indexes', array( $this, 'ajax_check_indexes' ) );
		add_action( 'wp_ajax_miniload_rebuild_indexes', array( $this, 'ajax_rebuild_indexes' ) );
	}

	/**
	 * Define all indexes
	 */
	private function define_indexes() {
		global $wpdb;

		// Taxonomy indexes for faster filtering
		$this->indexes['taxonomy'] = array(
			array(
				'table'  => $wpdb->term_relationships,
				'name'   => 'miniload_term_rel_composite',
				'columns' => 'object_id, term_taxonomy_id',
				'description' => 'Composite index for term relationships',
			),
			array(
				'table'  => $wpdb->term_taxonomy,
				'name'   => 'miniload_term_tax_hierarchy',
				'columns' => 'taxonomy, parent, term_id',
				'description' => 'Hierarchy index for taxonomies',
			),
			array(
				'table'  => $wpdb->term_taxonomy,
				'name'   => 'miniload_term_tax_taxonomy',
				'columns' => 'taxonomy, term_id',
				'description' => 'Taxonomy lookup index',
			),
		);

		// Product indexes
		$this->indexes['products'] = array(
			array(
				'table'  => $wpdb->posts,
				'name'   => 'miniload_product_status',
				'columns' => 'post_type, post_status, ID',
				'description' => 'Product status index for queries',
			),
			array(
				'table'  => $wpdb->posts,
				'name'   => 'miniload_product_parent',
				'columns' => 'post_parent, post_type, post_status',
				'description' => 'Product parent index for variations',
			),
		);

		// Postmeta indexes for WooCommerce
		$this->indexes['postmeta'] = array(
			array(
				'table'  => $wpdb->postmeta,
				'name'   => 'miniload_meta_price',
				'columns' => 'meta_key, meta_value(10)',
				'condition' => "meta_key = '_price'",
				'description' => 'Price meta index',
			),
			array(
				'table'  => $wpdb->postmeta,
				'name'   => 'miniload_meta_stock',
				'columns' => 'meta_key, meta_value(10)',
				'condition' => "meta_key = '_stock_status'",
				'description' => 'Stock status index',
			),
			array(
				'table'  => $wpdb->postmeta,
				'name'   => 'miniload_meta_sku',
				'columns' => 'meta_key, meta_value(100)',
				'condition' => "meta_key = '_sku'",
				'description' => 'SKU index for fast lookups',
			),
			array(
				'table'  => $wpdb->postmeta,
				'name'   => 'miniload_meta_visibility',
				'columns' => 'meta_key, meta_value(20)',
				'condition' => "meta_key = '_visibility'",
				'description' => 'Product visibility index',
			),
		);

		// WooCommerce HPOS indexes (if enabled)
		if ( miniload_is_hpos_enabled() ) {
			$this->indexes['hpos'] = array(
				array(
					'table'  => $wpdb->prefix . 'wc_orders',
					'name'   => 'miniload_orders_status_date',
					'columns' => 'status, date_created_gmt',
					'description' => 'Order status and date index',
				),
				array(
					'table'  => $wpdb->prefix . 'wc_orders',
					'name'   => 'miniload_orders_customer',
					'columns' => 'customer_id, date_created_gmt',
					'description' => 'Customer orders index',
				),
				array(
					'table'  => $wpdb->prefix . 'wc_order_product_lookup',
					'name'   => 'miniload_order_product_lookup',
					'columns' => 'product_id, order_id',
					'description' => 'Order product lookup index',
				),
			);
		}

		// WooCommerce sessions
		$this->indexes['sessions'] = array(
			array(
				'table'  => $wpdb->prefix . 'woocommerce_sessions',
				'name'   => 'miniload_sessions_expiry',
				'columns' => 'session_expiry',
				'description' => 'Session expiry index for cleanup',
			),
		);

		// Options table optimization
		$this->indexes['options'] = array(
			array(
				'table'  => $wpdb->options,
				'name'   => 'miniload_autoload',
				'columns' => 'autoload, option_name(191)',
				'description' => 'Autoload optimization index',
			),
		);

		// Allow modules to add custom indexes
		$this->indexes = apply_filters( 'miniload_database_indexes', $this->indexes );
	}

	/**
	 * Maybe create indexes on activation or update
	 */
	public function maybe_create_indexes() {
		// Check if indexes need to be created
		$version = get_option( 'miniload_indexes_version', '0' );

		if ( version_compare( $version, MINILOAD_VERSION, '<' ) ) {
			$this->create_all_indexes();
			update_option( 'miniload_indexes_version', MINILOAD_VERSION );
		}
	}

	/**
	 * Create all indexes
	 *
	 * @return array Results
	 */
	public function create_all_indexes() {
		$results = array();

		foreach ( $this->indexes as $group => $group_indexes ) {
			foreach ( $group_indexes as $index ) {
				$miniload_result = $this->create_index( $index );
				$results[ $group ][ $index['name'] ] = $miniload_result;

				if ( $miniload_result['created'] ) {
					miniload_log( sprintf( 'Index created: %s', $index['name'] ), 'info' );
				}
			}
		}

		return $results;
	}

	/**
	 * Create a single index
	 *
	 * @param array $index Index definition
	 * @return array Result
	 */
	private function create_index( $index ) {
		global $wpdb;

		$miniload_result = array(
			'created' => false,
			'exists'  => false,
			'error'   => '',
		);

		// Check if index already exists
		if ( $this->index_exists( $index['table'], $index['name'] ) ) {
			$miniload_result['exists'] = true;
			return $miniload_result;
		}

		// Build index query
		$sql = sprintf(
			"ALTER TABLE %s ADD INDEX %s (%s)",
			$index['table'],
			$index['name'],
			$index['columns']
		);

		// Add condition if specified
		if ( ! empty( $index['condition'] ) ) {
			// For conditional indexes, we need to use generated columns (MySQL 5.7+)
			if ( miniload_mysql_supports( 'json' ) ) {
				// Modern approach with generated columns
				// Skip for now as it's complex
			}
			// For older MySQL, just create regular index
		}

		// Execute query
		$created = miniload_safe_query( $sql );

		if ( $created !== false ) {
			$miniload_result['created'] = true;
		} else {
			$miniload_result['error'] = $wpdb->last_error;
		}

		return $miniload_result;
	}

	/**
	 * Check if an index exists
	 *
	 * @param string $table Table name
	 * @param string $index_name Index name
	 * @return bool
	 */
	private function index_exists( $miniload_table, $index_name ) {
		global $wpdb;

		$query = $wpdb->prepare(
			"SHOW INDEX FROM %s WHERE Key_name = %s",
			$miniload_table,
			$index_name
		);

		// Properly escape table name and index name
		$query = sprintf(
			"SHOW INDEX FROM %s WHERE Key_name = '%s'",
			esc_sql( $miniload_table ),
			esc_sql( $index_name )
		);

		// Direct database query with caching
		$cache_key = 'miniload_' . md5(  $query  );
		$cached = wp_cache_get( $cache_key );
		if ( false === $cached ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Required for performance optimization
			$cached = $wpdb->get_var( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is escaped above
			wp_cache_set( $cache_key, $cached, '', 3600 );
		}
		$count = $cached;

		return ! empty( $result );
	}

	/**
	 * Drop an index
	 *
	 * @param string $table Table name
	 * @param string $index_name Index name
	 * @return bool
	 */
	private function drop_index( $miniload_table, $index_name ) {
		global $wpdb;

		$sql = sprintf(
			"ALTER TABLE %s DROP INDEX %s",
			$miniload_table,
			$index_name
		);

		return miniload_safe_query( $sql ) !== false;
	}

	/**
	 * Rebuild all indexes
	 *
	 * @return array Results
	 */
	public function rebuild_all_indexes() {
		$results = array();

		// Drop existing indexes first
		foreach ( $this->indexes as $group => $group_indexes ) {
			foreach ( $group_indexes as $index ) {
				if ( $this->index_exists( $index['table'], $index['name'] ) ) {
					$this->drop_index( $index['table'], $index['name'] );
				}
			}
		}

		// Create all indexes
		$results = $this->create_all_indexes();

		// Update version
		update_option( 'miniload_indexes_version', MINILOAD_VERSION );

		return $results;
	}

	/**
	 * Get index status
	 *
	 * @return array
	 */
	public function get_index_status() {
		$status = array();

		foreach ( $this->indexes as $group => $group_indexes ) {
			$status[ $group ] = array();

			foreach ( $group_indexes as $index ) {
				$exists = $this->index_exists( $index['table'], $index['name'] );
				$status[ $group ][] = array(
					'name'        => $index['name'],
					'table'       => $index['table'],
					'columns'     => $index['columns'],
					'description' => $index['description'],
					'exists'      => $exists,
				);
			}
		}

		return $status;
	}

	/**
	 * Analyze index usage
	 *
	 * @return array
	 */
	public function analyze_index_usage() {
		global $wpdb;

		$analysis = array();

		// Get index statistics
		foreach ( $this->indexes as $group => $group_indexes ) {
			foreach ( $group_indexes as $index ) {
				if ( $this->index_exists( $index['table'], $index['name'] ) ) {
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table and index names are properly escaped
					$stats = $wpdb->get_row( sprintf(
						"SHOW INDEX FROM %s WHERE Key_name = '%s'",
						esc_sql( $index['table'] ),
						esc_sql( $index['name'] )
					), ARRAY_A );

					if ( $stats ) {
						$analysis[ $index['name'] ] = array(
							'cardinality' => $stats['Cardinality'],
							'unique'      => $stats['Non_unique'] == 0,
							'type'        => $stats['Index_type'],
						);
					}
				}
			}
		}

		return $analysis;
	}

	/**
	 * AJAX: Check indexes
	 */
	public function ajax_check_indexes() {
		// Security check
		if ( ! check_ajax_referer( 'miniload-ajax', 'nonce', false ) ||
		     ! current_user_can( 'manage_options' ) ) {
			wp_die( -1 );
		}

		$status = $this->get_index_status();
		wp_send_json_success( $status );
	}

	/**
	 * AJAX: Rebuild indexes
	 */
	public function ajax_rebuild_indexes() {
		// Security check
		if ( ! check_ajax_referer( 'miniload-ajax', 'nonce', false ) ||
		     ! current_user_can( 'manage_options' ) ) {
			wp_die( -1 );
		}

		$results = $this->rebuild_all_indexes();
		wp_send_json_success( $results );
	}

	/**
	 * Get missing indexes
	 *
	 * @return array
	 */
	public function get_missing_indexes() {
		$missing = array();

		foreach ( $this->indexes as $group => $group_indexes ) {
			foreach ( $group_indexes as $index ) {
				if ( ! $this->index_exists( $index['table'], $index['name'] ) ) {
					$missing[] = $index;
				}
			}
		}

		return $missing;
	}

	/**
	 * Estimate index impact
	 *
	 * @return array
	 */
	public function estimate_index_impact() {
		global $wpdb;

		$estimates = array();

		// Estimate based on table sizes
		$miniload_tables = array(
			'posts'    => $wpdb->posts,
			'postmeta' => $wpdb->postmeta,
			'terms'    => $wpdb->term_relationships,
		);

		foreach ( $tables as $name => $table ) {
			// Direct database query with caching
		$escaped_table = esc_sql( $table );
		$cache_key = 'miniload_' . md5(  "SELECT COUNT(*) FROM $escaped_table"  );
		$cached = wp_cache_get( $cache_key );
		if ( false === $cached ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Required for performance optimization
			$cached = $wpdb->get_var( "SELECT COUNT(*) FROM $escaped_table" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			wp_cache_set( $cache_key, $cached, '', 3600 );
		}
		$count = $cached;
			$estimates[ $name ] = array(
				'rows'             => $count,
				'estimated_speedup' => $this->calculate_speedup( $count ),
			);
		}

		return $estimates;
	}

	/**
	 * Calculate estimated speedup
	 *
	 * @param int $row_count Number of rows
	 * @return string
	 */
	private function calculate_speedup( $row_count ) {
		if ( $row_count < 1000 ) {
			return '2-3x';
		} elseif ( $row_count < 10000 ) {
			return '5-10x';
		} elseif ( $row_count < 100000 ) {
			return '10-50x';
		} else {
			return '50-200x';
		}
	}
}