<?php
/**
 * Order Search Optimizer Module
 *
 * Ultra-fast order search using trigram indexing
 * Transforms order search from O(n) to O(log n) complexity
 * Inspired by Fast Woo Order Lookup approach
 *
 * @package MiniLoad\Modules
 * @since 1.0.0
 */

namespace MiniLoad\Modules;

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

/**
 * Order Search Optimizer class
 */
class Order_Search_Optimizer {

	/**
	 * Table names
	 *
	 * @var string
	 */
	private $order_index_table;
	private $trigram_table;

	/**
	 * Constructor
	 */
	public function __construct() {
		global $wpdb;
		$this->order_index_table = $wpdb->prefix . 'miniload_order_index';
		$this->trigram_table = $wpdb->prefix . 'miniload_order_trigrams';

		$this->init_hooks();

		// Create tables on admin_init to avoid issues
		add_action( 'admin_init', array( $this, 'maybe_create_tables' ) );

		// Debug: Log that the module is loaded
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'MiniLoad: Order_Search_Optimizer module loaded' );
		}
	}

	/**
	 * Get search limit from settings
	 *
	 * @return int
	 */
	private function get_search_limit() {
		// Use direct option as per admin settings page
		return absint( get_option( 'miniload_order_search_limit', 5000 ) );
	}

	/**
	 * Override the final limit before wc_get_orders() is called
	 *
	 * @param array $query_args Query arguments
	 * @return array
	 */
	public function override_final_limit( $query_args ) {
		// Override limit ONLY for search queries
		if ( ! empty( $query_args['s'] ) ) {
			$query_args['limit'] = $this->get_search_limit();
		}
		return $query_args;
	}

	/**
	 * Initialize hooks
	 */
	private function init_hooks() {
		// Replace WooCommerce order search
		add_filter( 'woocommerce_shop_order_search_fields', array( $this, 'override_search_fields' ), 100 );
		add_filter( 'posts_search', array( $this, 'optimize_order_search' ), 100, 2 );
		add_filter( 'posts_clauses', array( $this, 'modify_order_search_clauses' ), 100, 2 );

		// HPOS search limit override - hook into the FINAL query args filter before wc_get_orders()
		add_filter( 'woocommerce_order_list_table_prepare_items_query_args', array( $this, 'override_final_limit' ), 999 );
		add_filter( 'woocommerce_shop_order_list_table_prepare_items_query_args', array( $this, 'override_final_limit' ), 999 );

		// Index orders when created/updated
		add_action( 'woocommerce_new_order', array( $this, 'index_order' ), 10, 2 );
		add_action( 'woocommerce_update_order', array( $this, 'index_order' ), 10, 2 );
		add_action( 'before_delete_post', array( $this, 'delete_order_index' ), 10, 1 );

		// HPOS compatibility
		add_action( 'woocommerce_after_order_object_save', array( $this, 'index_hpos_order' ), 10, 2 );

		// BULK ACTIONS SUPPORT - Update index when order meta changes
		add_action( 'updated_post_meta', array( $this, 'maybe_update_order_on_meta_change' ), 10, 4 );
		add_action( 'added_post_meta', array( $this, 'maybe_update_order_on_meta_change' ), 10, 4 );

		// Handle order status changes (including bulk status changes)
		add_action( 'woocommerce_order_status_changed', array( $this, 'update_order_on_status_change' ), 10, 4 );

		// Admin AJAX
		add_action( 'wp_ajax_miniload_rebuild_order_index', array( $this, 'ajax_rebuild_index' ) );
	}

	/**
	 * Create database tables
	 */
	public function maybe_create_tables() {
		global $wpdb;

		// Check if tables exist
		// Direct database query with caching
		$cache_key = 'miniload_' . md5(  "SHOW TABLES LIKE '{$this->order_index_table}'"  );
		$cached = wp_cache_get( $cache_key );
		if ( false === $cached ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Required for performance optimization
			$cached = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $this->order_index_table ) );
			wp_cache_set( $cache_key, $cached, '', 3600 );
		}
		$table_exists = $cached;
		if ( $table_exists ) {
			return;
		}

		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

		$charset_collate = $wpdb->get_charset_collate();

		// Order index table - stores searchable data
		$sql = "CREATE TABLE IF NOT EXISTS {$this->order_index_table} (
			order_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
			order_number VARCHAR(100),
			customer_email VARCHAR(100),
			customer_name VARCHAR(200),
			billing_phone VARCHAR(50),
			billing_company VARCHAR(100),
			billing_address TEXT,
			shipping_address TEXT,
			payment_method VARCHAR(50),
			order_status VARCHAR(50),
			order_total DECIMAL(10,2),
			order_date DATETIME,
			customer_note TEXT,
			sku_list TEXT,
			product_names TEXT,
			searchable_text TEXT,
			INDEX idx_order_number (order_number),
			INDEX idx_customer_email (customer_email),
			INDEX idx_billing_phone (billing_phone),
			INDEX idx_order_date (order_date),
			INDEX idx_order_status (order_status),
			FULLTEXT idx_searchable (searchable_text)
		) $charset_collate ENGINE=InnoDB;";

		dbDelta( $sql );

		// Trigram index table for ultra-fast partial matching
		$sql = "CREATE TABLE IF NOT EXISTS {$this->trigram_table} (
			id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			order_id BIGINT UNSIGNED NOT NULL,
			trigram CHAR(3) NOT NULL,
			field_type VARCHAR(50) NOT NULL,
			position INT UNSIGNED,
			INDEX idx_trigram (trigram, field_type),
			INDEX idx_order (order_id),
			UNIQUE KEY unique_trigram (order_id, trigram, field_type, position)
		) $charset_collate ENGINE=InnoDB;";

		dbDelta( $sql );
	}

	/**
	 * Optimize order search queries
	 *
	 * @param string $search Search SQL
	 * @param WP_Query $query Query object
	 * @return string
	 */
	public function optimize_order_search( $search, $query ) {
		// Only optimize order searches
		if ( ! $this->should_optimize_order_search( $query ) ) {
			return $search;
		}

		global $wpdb;

		$search_term = $query->get( 's' );
		if ( empty( $search_term ) ) {
			return $search;
		}

		// Use trigram search for fast partial matching
		$order_ids = $this->search_orders_by_trigram( $search_term );

		if ( $order_ids === false ) {
			// Fallback to FULLTEXT search
			$order_ids = $this->search_orders_fulltext( $search_term );
		}

		if ( ! empty( $order_ids ) ) {
			// Replace search with specific order IDs
			$ids_string = implode( ',', array_map( 'intval', $order_ids ) );
			return " AND {$wpdb->posts}.ID IN ({$ids_string})";
		}

		// No results found
		return " AND 1=0";
	}

	/**
	 * Search orders using trigram index
	 *
	 * @param string $search_term Search term
	 * @return array|false Order IDs or false if not enough trigrams
	 */
	private function search_orders_by_trigram( $search_term ) {
		global $wpdb;

		// Clean search term
		$search_term = strtolower( trim( $search_term ) );

		// Need at least 3 characters for trigram search
		if ( strlen( $search_term ) < 3 ) {
			return false;
		}

		// Generate trigrams from search term
		$trigrams = $this->generate_trigrams( $search_term );

		if ( empty( $trigrams ) ) {
			return false;
		}

		// Build SQL for trigram matching
		$trigram_conditions = array();
		foreach ( $trigrams as $trigram ) {
			$trigram_conditions[] = $wpdb->prepare( "trigram = %s", $trigram );
		}

		// Find orders that have ALL trigrams (intersection)
		$escaped_table = esc_sql( $this->trigram_table );
		$trigram_count = absint( count( $trigrams ) );

		$sql = "
			SELECT order_id, COUNT(DISTINCT trigram) as match_count
			FROM {$escaped_table}
			WHERE " . implode( ' OR ', $trigram_conditions ) . "
			GROUP BY order_id
			HAVING match_count = {$trigram_count}
			ORDER BY match_count DESC
			LIMIT " . $this->get_search_limit() . "
		";

		// Direct database query with caching
		$cache_key = 'miniload_' . md5( $sql );
		$cached = wp_cache_get( $cache_key );
		if ( false === $cached ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Required for performance optimization
			$cached = $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Conditions are prepared above
			wp_cache_set( $cache_key, $cached, '', 300 ); // 5 minute cache for search results
		}
		$results = $cached;

		miniload_log( sprintf( 'Trigram search for "%s" found %d orders', $search_term, count( $results ) ), 'debug' );

		return $results;
	}

	/**
	 * Search orders using FULLTEXT index
	 *
	 * @param string $search_term Search term
	 * @return array Order IDs
	 */
	private function search_orders_fulltext( $search_term ) {
		global $wpdb;

		// Escape table name once for all queries
		$escaped_table = esc_sql( $this->order_index_table );

		// Try exact matches first
		// Direct database query with caching
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be parameterized, escaped with esc_sql
		$exact_query = $wpdb->prepare( "
			SELECT order_id
			FROM {$escaped_table}
			WHERE order_number = %s
			   OR customer_email = %s
			   OR billing_phone = %s
			LIMIT " . $this->get_search_limit() . "
		", $search_term, $search_term, $search_term );
		$cache_key = 'miniload_' . md5( $exact_query );
		$cached = wp_cache_get( $cache_key );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Required for performance optimization
		if ( false === $cached ) {
			$cached = $wpdb->get_col( $exact_query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared above
			wp_cache_set( $cache_key, $cached, '', 300 ); // 5 minute cache
		}
		$exact_results = $cached;

		if ( ! empty( $exact_results ) ) {
			return $exact_results;
		}

		// Use FULLTEXT search
		// Direct database query with caching
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be parameterized, escaped with esc_sql
		$fulltext_query = $wpdb->prepare( "
			SELECT order_id
			FROM {$escaped_table}
			WHERE MATCH(searchable_text) AGAINST(%s IN NATURAL LANGUAGE MODE)
			LIMIT " . $this->get_search_limit() . "
		", $search_term );
		$cache_key = 'miniload_' . md5( $fulltext_query );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Required for performance optimization
		$cached = wp_cache_get( $cache_key );
		if ( false === $cached ) {
			$cached = $wpdb->get_col( $fulltext_query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared above
			wp_cache_set( $cache_key, $cached, '', 300 ); // 5 minute cache
		}
		$results = $cached;

		// If no results, try LIKE for partial matches
		if ( empty( $results ) ) {
			$like_term = '%' . $wpdb->esc_like( $search_term ) . '%';
			// Direct database query with caching
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be parameterized, escaped with esc_sql
			$like_query = $wpdb->prepare( "
				SELECT order_id
				FROM {$escaped_table}
				WHERE searchable_text LIKE %s
				LIMIT " . $this->get_search_limit() . "
			", $like_term );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Required for performance optimization
			$cache_key = 'miniload_' . md5( $like_query );
			$cached = wp_cache_get( $cache_key );
			if ( false === $cached ) {
				$cached = $wpdb->get_col( $like_query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared above
				wp_cache_set( $cache_key, $cached, '', 300 ); // 5 minute cache
			}
			$results = $cached;
		}

		miniload_log( sprintf( 'Fulltext search for "%s" found %d orders', $search_term, count( $results ) ), 'debug' );

		return $results;
	}

	/**
	 * Index an order
	 *
	 * @param int $order_id Order ID
	 * @param WC_Order $order Order object
	 */
	public function index_order( $order_id, $order = null ) {
		if ( ! $order ) {
			$order = wc_get_order( $order_id );
		}

		if ( ! $order ) {
			return;
		}

		$this->index_order_data( $order );
	}

	/**
	 * Index HPOS order
	 *
	 * @param WC_Order $order Order object
	 * @param object $data_store Data store object
	 */
	public function index_hpos_order( $order, $data_store ) {
		$this->index_order_data( $order );
	}

	/**
	 * Index order data
	 *
	 * @param WC_Order $order Order object
	 */
	private function index_order_data( $order ) {
		global $wpdb;

		$order_id = $order->get_id();

		// Collect order data
		$customer_name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		if ( empty( $customer_name ) ) {
			$customer_name = trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() );
		}

		// Get product SKUs and names
		$skus = array();
		$product_names = array();
		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			if ( $product ) {
				if ( $product->get_sku() ) {
					$skus[] = $product->get_sku();
				}
				$product_names[] = $item->get_name();
			}
		}

		// Build billing address
		$billing_address = implode( ' ', array_filter( array(
			$order->get_billing_address_1(),
			$order->get_billing_address_2(),
			$order->get_billing_city(),
			$order->get_billing_state(),
			$order->get_billing_postcode(),
			$order->get_billing_country(),
		) ) );

		// Build shipping address
		$shipping_address = implode( ' ', array_filter( array(
			$order->get_shipping_address_1(),
			$order->get_shipping_address_2(),
			$order->get_shipping_city(),
			$order->get_shipping_state(),
			$order->get_shipping_postcode(),
			$order->get_shipping_country(),
		) ) );

		// Create searchable text combining all fields
		$searchable_parts = array_filter( array(
			$order->get_order_number(),
			$customer_name,
			$order->get_billing_email(),
			$order->get_billing_phone(),
			$order->get_billing_company(),
			$billing_address,
			$shipping_address,
			$order->get_payment_method_title(),
			implode( ' ', $skus ),
			implode( ' ', $product_names ),
			$order->get_customer_note(),
		) );

		$searchable_text = implode( ' ', $searchable_parts );

		// Insert or update in order index
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Data modification doesn't need caching
		$wpdb->replace(
			$this->order_index_table,
			array(
				'order_id'         => $order_id,
				'order_number'     => $order->get_order_number(),
				'customer_email'   => $order->get_billing_email(),
				'customer_name'    => $customer_name,
				'billing_phone'    => $order->get_billing_phone(),
				'billing_company'  => $order->get_billing_company(),
				'billing_address'  => $billing_address,
				'shipping_address' => $shipping_address,
				'payment_method'   => $order->get_payment_method(),
				'order_status'     => $order->get_status(),
				'order_total'      => $order->get_total(),
				'order_date'       => $order->get_date_created() ? $order->get_date_created()->format( 'Y-m-d H:i:s' ) : current_time( 'mysql' ),
				'customer_note'    => $order->get_customer_note(),
				'sku_list'         => implode( ' ', $skus ),
				'product_names'    => implode( ' ', $product_names ),
				'searchable_text'  => $searchable_text,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s' )
		);

		// Index trigrams for ultra-fast search
		$this->index_order_trigrams( $order_id, $searchable_parts );

		miniload_log( sprintf( 'Order #%d indexed for fast search', $order_id ), 'debug' );
	}

	/**
	 * Index order trigrams
	 *
	 * @param int $order_id Order ID
	 * @param array $searchable_parts Searchable text parts
	 */
	private function index_order_trigrams( $order_id, $searchable_parts ) {
		global $wpdb;

		// Delete existing trigrams for this order
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Data modification doesn't need caching
		$wpdb->delete( $this->trigram_table, array( 'order_id' => $order_id ), array( '%d' ) );

		$field_types = array(
			'order_number',
			'email',
			'name',
			'phone',
			'sku',
			'product',
			'address',
		);

		// Index each part with appropriate field type
		foreach ( $searchable_parts as $index => $text ) {
			if ( empty( $text ) ) {
				continue;
			}

			// Determine field type based on position
			$field_type = $field_types[ min( $index, count( $field_types ) - 1 ) ];

			// Generate trigrams
			$trigrams = $this->generate_trigrams( $text );

			// Insert trigrams
			foreach ( $trigrams as $position => $trigram ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Data modification doesn't need caching
				$wpdb->insert(
					$this->trigram_table,
					array(
						'order_id'   => $order_id,
						'trigram'    => $trigram,
						'field_type' => $field_type,
						'position'   => $position,
					),
					array( '%d', '%s', '%s', '%d' )
				);
			}
		}
	}

	/**
	 * Generate trigrams from text
	 *
	 * @param string $text Text to process
	 * @return array Trigrams
	 */
	private function generate_trigrams( $text ) {
		$text = strtolower( trim( $text ) );
		$trigrams = array();

		// Pad with spaces for edge trigrams
		$text = '  ' . $text . '  ';

		$length = strlen( $text );
		for ( $i = 0; $i <= $length - 3; $i++ ) {
			$trigram = substr( $text, $i, 3 );
			if ( strlen( $trigram ) === 3 ) {
				$trigrams[] = $trigram;
			}
		}

		return array_unique( $trigrams );
	}

	/**
	 * Check if order search should be optimized
	 *
	 * @param WP_Query $query Query object
	 * @return bool
	 */
	private function should_optimize_order_search( $query ) {
		// Check if it's admin
		if ( ! is_admin() ) {
			return false;
		}

		// Check if it's a search
		if ( ! $query->is_search() ) {
			return false;
		}

		// Check post type
		$post_type = $query->get( 'post_type' );
		if ( $post_type !== 'shop_order' ) {
			return false;
		}

		return true;
	}

	/**
	 * Override search fields
	 *
	 * @param array $search_fields Current search fields
	 * @return array
	 */
	public function override_search_fields( $search_fields ) {
		// Return empty array to prevent default search
		return array();
	}

	/**
	 * Modify order search clauses
	 *
	 * @param array $clauses SQL clauses
	 * @param WP_Query $query Query object
	 * @return array
	 */
	public function modify_order_search_clauses( $clauses, $query ) {
		if ( ! $this->should_optimize_order_search( $query ) ) {
			return $clauses;
		}

		// Remove expensive JOINs if present
		if ( strpos( $clauses['join'], 'postmeta' ) !== false ) {
			// Remove postmeta JOINs for search
			$clauses['join'] = preg_replace( '/LEFT JOIN[^)]+postmeta[^)]+\)/i', '', $clauses['join'] );
		}

		return $clauses;
	}

	/**
	 * Delete order from index
	 *
	 * @param int $post_id Post ID
	 */
	/**
	 * Maybe update order index when meta changes (for bulk actions)
	 *
	 * @param int    $meta_id    Meta ID
	 * @param int    $post_id    Post ID
	 * @param string $meta_key   Meta key
	 * @param mixed  $meta_value Meta value
	 */
	public function maybe_update_order_on_meta_change( $meta_id, $post_id, $meta_key, $meta_value ) {
		// Check if it's an order
		if ( get_post_type( $post_id ) !== 'shop_order' ) {
			return;
		}

		// Important order meta keys that should trigger reindex
		$important_keys = array(
			'_billing_email',
			'_billing_phone',
			'_billing_first_name',
			'_billing_last_name',
			'_order_total',
			'_customer_user',
			'_order_key',
			'_billing_company',
			'_shipping_first_name',
			'_shipping_last_name',
			'_shipping_company',
		);

		// Check if this is an important meta key
		if ( in_array( $meta_key, $important_keys, true ) ) {
			// Use a short delay to batch multiple meta updates
			$hook_name = 'miniload_delayed_order_index_' . $post_id;

			// Remove any existing scheduled update for this order
			wp_clear_scheduled_hook( $hook_name );

			// Schedule update in 2 seconds (to batch multiple meta changes)
			wp_schedule_single_event( time() + 2, $hook_name, array( $post_id ) );

			// Add the action handler if not already added
			if ( ! has_action( $hook_name ) ) {
				add_action( $hook_name, array( $this, 'index_order' ), 10, 1 );
			}

			miniload_log( sprintf( 'Scheduled index update for order #%d due to %s change', $post_id, $meta_key ), 'debug' );
		}
	}

	/**
	 * Update order index when status changes (handles bulk status changes)
	 *
	 * @param int    $order_id   Order ID
	 * @param string $old_status Old status
	 * @param string $new_status New status
	 * @param object $order      Order object
	 */
	public function update_order_on_status_change( $order_id, $old_status, $new_status, $order = null ) {
		// Index the order with new status
		if ( $order && is_object( $order ) ) {
			$this->index_order( $order_id, $order );
		} else {
			$this->index_order( $order_id );
		}

		miniload_log( sprintf( 'Order #%d index updated: status changed from %s to %s', $order_id, $old_status, $new_status ), 'debug' );
	}

	public function delete_order_index( $post_id ) {
		if ( get_post_type( $post_id ) !== 'shop_order' ) {
			return;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Data modification doesn't need caching
		$wpdb->delete( $this->order_index_table, array( 'order_id' => $post_id ), array( '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Data modification doesn't need caching
		$wpdb->delete( $this->trigram_table, array( 'order_id' => $post_id ), array( '%d' ) );

		miniload_log( sprintf( 'Order #%d removed from search index', $post_id ), 'debug' );
	}

	/**
	 * Rebuild entire order index
	 *
	 * @param int $offset Starting offset
	 * @param int $batch_size Batch size
	 * @return array Results
	 */
	public function rebuild_index( $offset = 0, $batch_size = 100 ) {
		// Debug logging
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'MiniLoad rebuild_index: Starting with offset=' . $offset . ', batch_size=' . $batch_size );
		}

		// Get orders - exclude auto-draft orders
		$args = array(
			'type'     => 'shop_order',
			'limit'    => $batch_size,
			'offset'   => $offset,
			'orderby'  => 'id',
			'order'    => 'ASC',
			'return'   => 'ids',
			'status'   => array( 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed' ),
		);

		$order_ids = wc_get_orders( $args );

		// Check if wc_get_orders returned a valid result
		if ( ! is_array( $order_ids ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'MiniLoad rebuild_index: wc_get_orders returned non-array: ' . var_export( $order_ids, true ) );
			}
			// Convert to empty array to continue gracefully
			$order_ids = array();
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'MiniLoad rebuild_index: Found ' . count( $order_ids ) . ' orders to process' );
		}

		if ( empty( $order_ids ) ) {
			return array(
				'completed' => true,
				'processed' => 0,
			);
		}

		// ULTRA-OPTIMIZED: Bulk process orders using direct SQL to avoid WooCommerce overhead
		global $wpdb;
		$processed_count = 0;

		if ( ! empty( $order_ids ) ) {
			$order_ids_str = implode( ',', array_map( 'intval', $order_ids ) );

			// Get all order data in one query
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$orders_data = $wpdb->get_results(
				"SELECT p.ID as order_id,
				        p.ID as order_number,
				        p.post_status as order_status,
				        p.post_date as order_date,
				        MAX(CASE WHEN pm.meta_key = '_billing_email' THEN pm.meta_value END) as customer_email,
				        MAX(CASE WHEN pm.meta_key = '_billing_first_name' THEN pm.meta_value END) as billing_first_name,
				        MAX(CASE WHEN pm.meta_key = '_billing_last_name' THEN pm.meta_value END) as billing_last_name,
				        MAX(CASE WHEN pm.meta_key = '_billing_phone' THEN pm.meta_value END) as billing_phone,
				        MAX(CASE WHEN pm.meta_key = '_billing_company' THEN pm.meta_value END) as billing_company,
				        MAX(CASE WHEN pm.meta_key = '_billing_address_1' THEN pm.meta_value END) as billing_address_1,
				        MAX(CASE WHEN pm.meta_key = '_billing_address_2' THEN pm.meta_value END) as billing_address_2,
				        MAX(CASE WHEN pm.meta_key = '_shipping_address_1' THEN pm.meta_value END) as shipping_address_1,
				        MAX(CASE WHEN pm.meta_key = '_shipping_address_2' THEN pm.meta_value END) as shipping_address_2,
				        MAX(CASE WHEN pm.meta_key = '_customer_user' THEN pm.meta_value END) as customer_id,
				        MAX(CASE WHEN pm.meta_key = '_order_total' THEN pm.meta_value END) as order_total
				FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
				WHERE p.ID IN ($order_ids_str)
				  AND p.post_type = 'shop_order'
				GROUP BY p.ID
				ORDER BY p.ID ASC",
				ARRAY_A
			);

			// Get order items (products/SKUs) in bulk
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$order_items = $wpdb->get_results(
				"SELECT oi.order_id,
				        GROUP_CONCAT(DISTINCT oim_sku.meta_value SEPARATOR ' ') as sku_list,
				        GROUP_CONCAT(DISTINCT oi.order_item_name SEPARATOR ' ') as product_names
				FROM {$wpdb->prefix}woocommerce_order_items oi
				LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim_sku
				  ON oi.order_item_id = oim_sku.order_item_id AND oim_sku.meta_key = '_sku'
				WHERE oi.order_id IN ($order_ids_str)
				  AND oi.order_item_type = 'line_item'
				GROUP BY oi.order_id",
				OBJECT_K
			);

			// Build bulk INSERT values
			$values = array();
			$placeholders = array();

			foreach ( $orders_data as $order ) {
				$order_id = (int) $order['order_id'];
				$order_number = $order['order_number'];
				$customer_email = $order['customer_email'] ?: '';
				$customer_name = trim( ( $order['billing_first_name'] ?: '' ) . ' ' . ( $order['billing_last_name'] ?: '' ) );
				$order_date = $order['order_date'];
				$order_status = str_replace( 'wc-', '', $order['order_status'] );
				$billing_phone = $order['billing_phone'] ?: '';
				$billing_company = $order['billing_company'] ?: '';
				$billing_address = trim( ( $order['billing_address_1'] ?: '' ) . ' ' . ( $order['billing_address_2'] ?: '' ) );
				$shipping_address = trim( ( $order['shipping_address_1'] ?: '' ) . ' ' . ( $order['shipping_address_2'] ?: '' ) );
				$order_total = (float) ( $order['order_total'] ?: 0 );

				// Get SKUs and product names
				$sku_list = isset( $order_items[ $order_id ] ) ? $order_items[ $order_id ]->sku_list : '';
				$product_names = isset( $order_items[ $order_id ] ) ? $order_items[ $order_id ]->product_names : '';

				// Build searchable text
				$searchable_parts = array_filter( array(
					$order_number,
					$customer_email,
					$customer_name,
					$billing_phone,
					$billing_company,
					$billing_address,
					$shipping_address,
					$sku_list,
					$product_names,
				) );
				$searchable_text = implode( ' ', $searchable_parts );

				// Prepare values for bulk insert - match actual table structure
				$values[] = $order_id;
				$values[] = $order_number;
				$values[] = $customer_email;
				$values[] = $customer_name;
				$values[] = $billing_phone;
				$values[] = $billing_company;
				$values[] = $billing_address;
				$values[] = $shipping_address;
				$values[] = $order_status;
				$values[] = $order_total;
				$values[] = $order_date;
				$values[] = $sku_list;
				$values[] = $product_names;
				$values[] = $searchable_text;

				$placeholders[] = '(%d, %s, %s, %s, %s, %s, %s, %s, %s, %f, %s, %s, %s, %s)';
				$processed_count++;
			}

			// Bulk INSERT or REPLACE - much faster than individual queries
			if ( ! empty( $values ) ) {
				$sql = "REPLACE INTO {$wpdb->prefix}miniload_order_index
				        (order_id, order_number, customer_email, customer_name, billing_phone, billing_company,
				         billing_address, shipping_address, order_status, order_total, order_date,
				         sku_list, product_names, searchable_text)
				        VALUES ";
				$sql .= implode( ', ', $placeholders );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
				$wpdb->query( $wpdb->prepare( $sql, $values ) );
			}
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'MiniLoad rebuild_index: Successfully processed ' . $processed_count . ' orders' );
		}

		// Get total count - handle HPOS compatibility
		global $wpdb;
		if ( class_exists( 'Automattic\WooCommerce\Utilities\OrderUtil' ) &&
		     \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
			// HPOS is enabled - count from custom table
			$table_name = $wpdb->prefix . 'wc_orders';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$total_orders = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name} WHERE type = 'shop_order'" );
		} else {
			// Legacy mode - use direct database query to avoid memory issues
			// Count all WooCommerce orders (statuses starting with 'wc-') but exclude drafts
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$total_orders = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts}
				WHERE post_type = %s
				AND post_status LIKE 'wc-%%'",
				'shop_order'
			) );
		}

		$processed_so_far = $offset + count( $order_ids );
		$is_completed = $processed_so_far >= $total_orders || count( $order_ids ) < $batch_size;

		$result = array(
			'completed'   => $is_completed,
			'processed'   => $processed_count,  // Orders processed in this batch
			'total_processed' => $processed_so_far,  // Total orders processed so far
			'total'       => $total_orders,
			'next_offset' => $offset + $batch_size,
			'progress'    => $total_orders > 0 ? min( 100, round( ( $processed_so_far / $total_orders ) * 100 ) ) : 100,
		);

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'MiniLoad rebuild_index: Returning result - completed: ' . ( $is_completed ? 'yes' : 'no' ) . ', progress: ' . $result['progress'] . '%' );
		}

		return $result;
	}

	/**
	 * AJAX: Rebuild order index
	 */
	public function ajax_rebuild_index() {
		// Debug: Log that the handler was called
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'MiniLoad: ajax_rebuild_index called' );
		}

		// Security check
		if ( ! check_ajax_referer( 'miniload-ajax', 'nonce', false ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'MiniLoad: Invalid nonce in ajax_rebuild_index' );
			}
			wp_send_json_error( array( 'message' => 'Invalid nonce' ) );
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied' ) );
			return;
		}

		try {
			$offset = isset( $_POST['offset'] ) ? intval( $_POST['offset'] ) : 0;
			$batch_size = get_option( 'miniload_order_index_batch_size', 1000 ); // Increased from 50 to 1000 for much faster indexing with optimized SQL

			// Debug logging
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'MiniLoad: Rebuild starting - offset: ' . $offset . ', batch_size: ' . $batch_size );
			}

			$miniload_result = $this->rebuild_index( $offset, $batch_size );

			// Check if result is valid
			if ( ! is_array( $miniload_result ) ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( 'MiniLoad: rebuild_index returned non-array: ' . var_export( $miniload_result, true ) );
				}
				wp_send_json_error( array( 'message' => 'Invalid response from rebuild_index' ) );
				return;
			}

			if ( $miniload_result['completed'] ) {
				wp_send_json_success( array(
					'message'   => __( 'Order search index rebuilt successfully', 'miniload' ),
					'completed' => true,
					'total'     => $miniload_result['total'],
					'processed' => $miniload_result['processed']
				) );
			} else {
				wp_send_json_success( $miniload_result );
			}
		} catch ( \Exception $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'MiniLoad: Exception in ajax_rebuild_index: ' . $e->getMessage() );
				error_log( 'MiniLoad: Stack trace: ' . $e->getTraceAsString() );
			}
			wp_send_json_error( array(
				'message' => 'Error rebuilding index: ' . $e->getMessage()
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

		// Escape table names
		$escaped_order_table = esc_sql( $this->order_index_table );
		$escaped_trigram_table = esc_sql( $this->trigram_table );

		// Indexed orders
		// Direct database query with caching
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Required for performance optimization
		$cache_key = 'miniload_' . md5( "SELECT COUNT(*) FROM {$escaped_order_table}" );
		$cached = wp_cache_get( $cache_key );
		if ( false === $cached ) {
			$cached = $wpdb->get_var( "SELECT COUNT(*) FROM {$escaped_order_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			wp_cache_set( $cache_key, $cached, '', 3600 );
		}
		$stats['indexed_orders'] = $cached;

		// Total trigrams
		// Direct database query with caching
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Required for performance optimization
		$cache_key = 'miniload_' . md5( "SELECT COUNT(*) FROM {$escaped_trigram_table}" );
		$cached = wp_cache_get( $cache_key );
		if ( false === $cached ) {
			$cached = $wpdb->get_var( "SELECT COUNT(*) FROM {$escaped_trigram_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			wp_cache_set( $cache_key, $cached, '', 3600 );
		}
		$stats['total_trigrams'] = $cached;

		// Unique trigrams
		// Direct database query with caching
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Required for performance optimization
		$cache_key = 'miniload_' . md5( "SELECT COUNT(DISTINCT trigram) FROM {$escaped_trigram_table}" );
		$cached = wp_cache_get( $cache_key );
		if ( false === $cached ) {
			$cached = $wpdb->get_var( "SELECT COUNT(DISTINCT trigram) FROM {$escaped_trigram_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			wp_cache_set( $cache_key, $cached, '', 3600 );
		}
		$stats['unique_trigrams'] = $cached;

		// Average trigrams per order
		$stats['avg_trigrams'] = $stats['indexed_orders'] > 0
			? round( $stats['total_trigrams'] / $stats['indexed_orders'] )
			: 0;

		return $stats;
	}
}