<?php
/**
 * WP-CLI Commands for MiniLoad
 *
 * @package MiniLoad
 * @since 1.0.7
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Manage MiniLoad plugin operations.
 */
class MiniLoad_CLI {

	/**
	 * Show all indexes status.
	 *
	 * ## EXAMPLES
	 *
	 *     wp miniload status
	 *
	 * @when after_wp_load
	 */
	public function status( $args, $assoc_args ) {
		$this->order_status( array(), array() );
		WP_CLI::line( '' );
		$this->product_status( array(), array() );
	}

	/**
	 * Rebuild all indexes.
	 *
	 * ## OPTIONS
	 *
	 * [--type=<type>]
	 * : Index type to rebuild (orders|products|all)
	 * ---
	 * default: all
	 * ---
	 *
	 * [--batch-size=<size>]
	 * : Number of items to process per batch.
	 * ---
	 * default: 50
	 * ---
	 *
	 * [--clear]
	 * : Clear existing index before rebuilding.
	 *
	 * [--progress]
	 * : Show progress bar.
	 *
	 * ## EXAMPLES
	 *
	 *     # Rebuild all indexes
	 *     wp miniload rebuild --type=all --progress
	 *
	 *     # Rebuild only products
	 *     wp miniload rebuild --type=products --clear
	 *
	 * @when after_wp_load
	 */
	public function rebuild( $args, $assoc_args ) {
		$type = isset( $assoc_args['type'] ) ? $assoc_args['type'] : 'all';

		switch ( $type ) {
			case 'orders':
				$this->rebuild_orders( $args, $assoc_args );
				break;
			case 'products':
				$this->rebuild_products( $args, $assoc_args );
				break;
			case 'all':
				WP_CLI::line( '=== Rebuilding Order Index ===' );
				$this->rebuild_orders( $args, $assoc_args );
				WP_CLI::line( '' );
				WP_CLI::line( '=== Rebuilding Product Index ===' );
				$this->rebuild_products( $args, $assoc_args );
				break;
			default:
				WP_CLI::error( 'Invalid type. Use: orders, products, or all' );
		}
	}

	/**
	 * Rebuild the order search index.
	 *
	 * ## OPTIONS
	 *
	 * [--batch-size=<size>]
	 * : Number of orders to process per batch.
	 * ---
	 * default: 50
	 * ---
	 *
	 * [--clear]
	 * : Clear existing index before rebuilding.
	 *
	 * [--progress]
	 * : Show progress bar.
	 *
	 * [--turbo]
	 * : Use direct database operations for maximum speed (bypasses WooCommerce).
	 *
	 * ## EXAMPLES
	 *
	 *     # Rebuild order index
	 *     wp miniload rebuild-orders
	 *
	 *     # Turbo mode for maximum speed
	 *     wp miniload rebuild-orders --turbo --batch-size=1000
	 *
	 *     # Clear and rebuild with custom batch size
	 *     wp miniload rebuild-orders --clear --batch-size=100 --progress
	 *
	 * @when after_wp_load
	 */
	public function rebuild_orders( $args, $assoc_args ) {
		$batch_size = isset( $assoc_args['batch-size'] ) ? intval( $assoc_args['batch-size'] ) : 500; // Increased default
		$clear = isset( $assoc_args['clear'] );
		$show_progress = isset( $assoc_args['progress'] );
		$turbo = isset( $assoc_args['turbo'] );

		// Optimize WordPress for bulk operations
		@ini_set( 'memory_limit', '512M' );
		@set_time_limit( 0 );
		wp_suspend_cache_invalidation( true );
		wp_defer_term_counting( true );
		wp_defer_comment_counting( true );
		if ( ! defined( 'WP_IMPORTING' ) ) {
			define( 'WP_IMPORTING', true );
		}

		// MiniLoad plugin should already be loaded
		if ( ! class_exists( 'MiniLoad' ) ) {
			WP_CLI::error( 'MiniLoad class not found. Make sure the plugin is active.' );
			return;
		}

		$miniload = \MiniLoad::instance();

		// Ensure modules are loaded
		$miniload->load_modules();

		// Get Order Search Optimizer module
		$optimizer = $miniload->get_module( 'order_search_optimizer' );

		if ( ! $optimizer ) {
			WP_CLI::error( 'Order Search Optimizer module not available. Make sure it is enabled in MiniLoad settings.' );
			return;
		}

		// Clear index if requested
		if ( $clear ) {
			global $wpdb;
			WP_CLI::line( 'Clearing existing index...' );
			$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}miniload_order_index" );
			$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}miniload_order_trigrams" );
			WP_CLI::success( 'Index cleared.' );
		}

		// TURBO MODE - Direct SQL for maximum speed
		if ( $turbo ) {
			global $wpdb;
			WP_CLI::line( '🚀 TURBO MODE ACTIVATED - Using direct SQL for maximum speed!' );

			$total_orders = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'shop_order' AND post_status LIKE 'wc-%'" );
			WP_CLI::line( sprintf( 'Found %d orders to index.', $total_orders ) );

			// Insert all orders in one query (super fast!)
			WP_CLI::line( 'Building index in single operation...' );

			$result = $wpdb->query( "
				INSERT IGNORE INTO {$wpdb->prefix}miniload_order_index
				(order_id, order_number, customer_email, order_status, order_date, order_total, billing_phone, customer_name)
				SELECT
					p.ID as order_id,
					COALESCE(om_order_key.meta_value, p.ID) as order_number,
					COALESCE(om_email.meta_value, '') as customer_email,
					p.post_status as order_status,
					p.post_date as order_date,
					CAST(COALESCE(om_total.meta_value, 0) AS DECIMAL(10,2)) as order_total,
					COALESCE(om_phone.meta_value, '') as billing_phone,
					CONCAT_WS(' ', om_first.meta_value, om_last.meta_value) as customer_name
				FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} om_order_key ON p.ID = om_order_key.post_id AND om_order_key.meta_key = '_order_key'
				LEFT JOIN {$wpdb->postmeta} om_email ON p.ID = om_email.post_id AND om_email.meta_key = '_billing_email'
				LEFT JOIN {$wpdb->postmeta} om_total ON p.ID = om_total.post_id AND om_total.meta_key = '_order_total'
				LEFT JOIN {$wpdb->postmeta} om_phone ON p.ID = om_phone.post_id AND om_phone.meta_key = '_billing_phone'
				LEFT JOIN {$wpdb->postmeta} om_first ON p.ID = om_first.post_id AND om_first.meta_key = '_billing_first_name'
				LEFT JOIN {$wpdb->postmeta} om_last ON p.ID = om_last.post_id AND om_last.meta_key = '_billing_last_name'
				WHERE p.post_type = 'shop_order'
				AND p.post_status LIKE 'wc-%'
			" );

			if ( $result !== false ) {
				$indexed = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}miniload_order_index" );
				WP_CLI::success( sprintf( '⚡ TURBO INDEX COMPLETE! Indexed %d orders in seconds!', $indexed ) );
			} else {
				WP_CLI::error( 'Failed to build index: ' . $wpdb->last_error );
			}

			// Re-enable caches
			wp_suspend_cache_invalidation( false );
			wp_defer_term_counting( false );
			wp_defer_comment_counting( false );
			return;
		}

		// Get total count
		global $wpdb;
		$total_orders = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts}
			WHERE post_type = %s
			AND post_status LIKE 'wc-%%'",
			'shop_order'
		) );

		WP_CLI::line( sprintf( 'Found %d orders to index.', $total_orders ) );
		WP_CLI::line( sprintf( 'Processing in batches of %d...', $batch_size ) );

		$offset = 0;
		$total_processed = 0;
		$progress = null;

		if ( $show_progress ) {
			$progress = \WP_CLI\Utils\make_progress_bar( 'Indexing orders', $total_orders );
		}

		// Main rebuild loop
		while ( true ) {
			$result = $optimizer->rebuild_index( $offset, $batch_size );

			if ( ! $result || ! is_array( $result ) ) {
				WP_CLI::error( 'Failed to process batch at offset ' . $offset );
				break;
			}

			$total_processed += $result['processed'];

			if ( $show_progress ) {
				for ( $i = 0; $i < $result['processed']; $i++ ) {
					$progress->tick();
				}
			} else {
				WP_CLI::line( sprintf(
					'Processed batch: %d orders (Total: %d/%d - %.1f%%)',
					$result['processed'],
					$total_processed,
					$total_orders,
					$result['progress']
				) );
			}

			if ( $result['completed'] ) {
				break;
			}

			$offset = $result['next_offset'];

			// Prevent infinite loop
			if ( $offset > $total_orders + $batch_size ) {
				WP_CLI::warning( 'Offset exceeded total orders, stopping.' );
				break;
			}
		}

		if ( $show_progress ) {
			$progress->finish();
		}

		// Final stats
		$indexed = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}miniload_order_index" );
		WP_CLI::success( sprintf( 'Index rebuild complete! Indexed %d orders.', $indexed ) );
	}

	/**
	 * Rebuild the product search index.
	 *
	 * ## OPTIONS
	 *
	 * [--batch-size=<size>]
	 * : Number of products to process per batch.
	 * ---
	 * default: 100
	 * ---
	 *
	 * [--clear]
	 * : Clear existing index before rebuilding.
	 *
	 * [--progress]
	 * : Show progress bar.
	 *
	 * ## EXAMPLES
	 *
	 *     # Rebuild product index
	 *     wp miniload rebuild-products
	 *
	 *     # Clear and rebuild with progress
	 *     wp miniload rebuild-products --clear --progress
	 *
	 * @when after_wp_load
	 */
	public function rebuild_products( $args, $assoc_args ) {
		$batch_size = isset( $assoc_args['batch-size'] ) ? intval( $assoc_args['batch-size'] ) : 50; // Reduced for memory
		$clear = isset( $assoc_args['clear'] );
		$show_progress = isset( $assoc_args['progress'] );

		// Optimize for speed and memory
		@ini_set( 'memory_limit', '1024M' );
		@set_time_limit( 0 );
		wp_suspend_cache_invalidation( true );
		wp_defer_term_counting( true );
		wp_defer_comment_counting( true );

		// Disable autoload for options
		add_filter( 'pre_option_rewrite_rules', '__return_false' );
		add_filter( 'pre_transient_timeout_doing_cron', '__return_zero' );

		// MiniLoad plugin should already be loaded
		if ( ! class_exists( 'MiniLoad' ) ) {
			WP_CLI::error( 'MiniLoad class not found. Make sure the plugin is active.' );
			return;
		}

		$miniload = \MiniLoad::instance();

		// Ensure modules are loaded
		$miniload->load_modules();

		// Get Search Optimizer module
		$optimizer = $miniload->get_module( 'search_optimizer' );

		if ( ! $optimizer ) {
			WP_CLI::error( 'Search Optimizer module not available. Make sure it is enabled in MiniLoad settings.' );
			return;
		}

		// Clear index if requested
		if ( $clear ) {
			global $wpdb;
			WP_CLI::line( 'Clearing existing product index...' );
			$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}miniload_search_index" );
			WP_CLI::success( 'Product index cleared.' );
		}

		// Get total count
		global $wpdb;
		$total_products = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts}
			WHERE post_type = %s AND post_status = %s",
			'product', 'publish'
		) );

		WP_CLI::line( sprintf( 'Found %d products to index.', $total_products ) );
		WP_CLI::line( sprintf( 'Processing in batches of %d...', $batch_size ) );

		$offset = 0;
		$total_processed = 0;
		$progress = null;

		if ( $show_progress ) {
			$progress = \WP_CLI\Utils\make_progress_bar( 'Indexing products', $total_products );
		}

		// Main rebuild loop with memory management
		while ( true ) {
			// Monitor memory usage
			$memory_usage = memory_get_usage( true );
			$memory_peak = memory_get_peak_usage( true );

			// Log memory status periodically
			if ( $offset % ( $batch_size * 5 ) === 0 && ! $show_progress ) {
				WP_CLI::line( sprintf(
					'Memory usage: %s (Peak: %s)',
					size_format( $memory_usage ),
					size_format( $memory_peak )
				) );
			}

			$result = $optimizer->rebuild_index( $offset, $batch_size );

			if ( ! $result || ! is_array( $result ) ) {
				WP_CLI::error( 'Failed to process batch at offset ' . $offset );
				break;
			}

			$total_processed += $result['processed'];

			if ( $show_progress ) {
				for ( $i = 0; $i < $result['processed']; $i++ ) {
					$progress->tick();
				}
			} else {
				WP_CLI::line( sprintf(
					'Processed batch: %d products (Total: %d/%d - %.1f%%) | Mem: %s',
					$result['processed'],
					$total_processed,
					$total_products,
					( $total_processed / $total_products ) * 100,
					size_format( memory_get_usage( true ) )
				) );
			}

			if ( $result['completed'] ) {
				break;
			}

			$offset += $batch_size;

			// Clear caches after each batch to free memory
			wp_cache_flush_group( 'posts' );
			wp_cache_flush_group( 'post_meta' );
			wp_cache_flush_group( 'products' );
			wp_cache_flush_group( 'terms' );

			// Prevent infinite loop
			if ( $offset > $total_products + $batch_size ) {
				WP_CLI::warning( 'Offset exceeded total products, stopping.' );
				break;
			}
		}

		if ( $show_progress ) {
			$progress->finish();
		}

		// Re-enable caches
		wp_suspend_cache_invalidation( false );
		wp_defer_term_counting( false );
		wp_defer_comment_counting( false );

		// Final stats
		$indexed = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}miniload_search_index" );
		$memory_peak = memory_get_peak_usage( true );
		WP_CLI::success( sprintf(
			'Product index rebuild complete! Indexed %d products. Peak memory: %s',
			$indexed,
			size_format( $memory_peak )
		) );
	}

	/**
	 * Show order index status.
	 *
	 * ## EXAMPLES
	 *
	 *     wp miniload order-status
	 *
	 * @when after_wp_load
	 */
	public function order_status( $args, $assoc_args ) {
		global $wpdb;

		// Get counts
		$indexed = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}miniload_order_index" );
		$total = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts}
			WHERE post_type = %s
			AND post_status LIKE 'wc-%%'",
			'shop_order'
		) );

		$percentage = $total > 0 ? round( ( $indexed / $total ) * 100, 2 ) : 0;

		// Get table sizes
		$index_size = $wpdb->get_var( $wpdb->prepare(
			"SELECT ROUND(((data_length + index_length) / 1024 / 1024), 2) AS size_mb
			FROM information_schema.TABLES
			WHERE table_schema = %s
			AND table_name = %s",
			DB_NAME,
			$wpdb->prefix . 'miniload_order_index'
		) );

		$trigram_size = $wpdb->get_var( $wpdb->prepare(
			"SELECT ROUND(((data_length + index_length) / 1024 / 1024), 2) AS size_mb
			FROM information_schema.TABLES
			WHERE table_schema = %s
			AND table_name = %s",
			DB_NAME,
			$wpdb->prefix . 'miniload_order_trigrams'
		) );

		// Display status
		WP_CLI::line( '=== MiniLoad Order Index Status ===' );
		WP_CLI::line( '' );
		WP_CLI::line( sprintf( 'Indexed Orders: %s', number_format( $indexed ) ) );
		WP_CLI::line( sprintf( 'Total Orders:   %s', number_format( $total ) ) );
		WP_CLI::line( sprintf( 'Coverage:       %.2f%%', $percentage ) );
		WP_CLI::line( '' );
		WP_CLI::line( '=== Table Sizes ===' );
		WP_CLI::line( sprintf( 'Order Index:    %.2f MB', $index_size ?: 0 ) );
		WP_CLI::line( sprintf( 'Trigrams:       %.2f MB', $trigram_size ?: 0 ) );
		WP_CLI::line( '' );

		if ( $indexed > 0 ) {
			// Show recent indexed orders
			$recent = $wpdb->get_results(
				"SELECT order_id, order_status, order_date
				FROM {$wpdb->prefix}miniload_order_index
				ORDER BY order_id DESC
				LIMIT 5"
			);

			WP_CLI::line( '=== Recently Indexed Orders ===' );
			foreach ( $recent as $order ) {
				WP_CLI::line( sprintf(
					'#%d - %s - %s',
					$order->order_id,
					str_replace( 'wc-', '', $order->order_status ),
					$order->order_date
				) );
			}
		}

		// Check if rebuild is needed
		if ( $percentage < 100 ) {
			WP_CLI::line( '' );
			WP_CLI::warning( sprintf(
				'Index is incomplete (%.2f%%). Run "wp miniload rebuild-index" to complete.',
				$percentage
			) );
		} else {
			WP_CLI::success( 'Order index is complete!' );
		}
	}

	/**
	 * Show product index status.
	 *
	 * ## EXAMPLES
	 *
	 *     wp miniload product-status
	 *
	 * @when after_wp_load
	 */
	public function product_status( $args, $assoc_args ) {
		global $wpdb;

		// Get counts
		$indexed = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}miniload_search_index" );
		$total = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts}
			WHERE post_type = %s AND post_status = %s",
			'product', 'publish'
		) );

		$percentage = $total > 0 ? round( ( $indexed / $total ) * 100, 2 ) : 0;

		// Get table size
		$table_size = $wpdb->get_var( $wpdb->prepare(
			"SELECT ROUND(((data_length + index_length) / 1024 / 1024), 2) AS size_mb
			FROM information_schema.TABLES
			WHERE table_schema = %s
			AND table_name = %s",
			DB_NAME,
			$wpdb->prefix . 'miniload_search_index'
		) );

		// Display status
		WP_CLI::line( '=== MiniLoad Product Index Status ===' );
		WP_CLI::line( '' );
		WP_CLI::line( sprintf( 'Indexed Products: %s', number_format( $indexed ) ) );
		WP_CLI::line( sprintf( 'Total Products:   %s', number_format( $total ) ) );
		WP_CLI::line( sprintf( 'Coverage:         %.2f%%', $percentage ) );
		WP_CLI::line( sprintf( 'Table Size:       %.2f MB', $table_size ?: 0 ) );
		WP_CLI::line( '' );

		if ( $indexed > 0 ) {
			// Show recently indexed products
			$recent = $wpdb->get_results(
				"SELECT product_id, sku, title
				FROM {$wpdb->prefix}miniload_search_index
				ORDER BY product_id DESC
				LIMIT 5"
			);

			if ( ! empty( $recent ) ) {
				WP_CLI::line( '=== Recently Indexed Products ===' );
				foreach ( $recent as $product ) {
					WP_CLI::line( sprintf(
						'#%d - %s - %s',
						$product->product_id,
						$product->sku ?: 'No SKU',
						substr( $product->title, 0, 50 )
					) );
				}
			}
		}

		// Check if rebuild is needed
		if ( $percentage < 100 ) {
			WP_CLI::line( '' );
			WP_CLI::warning( sprintf(
				'Product index is incomplete (%.2f%%). Run "wp miniload rebuild-products" to complete.',
				$percentage
			) );
		} else {
			WP_CLI::success( 'Product index is complete!' );
		}
	}

	/**
	 * Clear indexes.
	 *
	 * ## OPTIONS
	 *
	 * [--type=<type>]
	 * : Index type to clear (orders|products|all)
	 * ---
	 * default: all
	 * ---
	 *
	 * [--yes]
	 * : Skip confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp miniload clear --type=all --yes
	 *     wp miniload clear --type=orders --yes
	 *
	 * @when after_wp_load
	 */
	public function clear( $args, $assoc_args ) {
		$type = isset( $assoc_args['type'] ) ? $assoc_args['type'] : 'all';

		WP_CLI::confirm( sprintf( 'Are you sure you want to clear the %s index?', $type ), $assoc_args );

		global $wpdb;

		switch ( $type ) {
			case 'orders':
				$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}miniload_order_index" );
				$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}miniload_order_trigrams" );
				WP_CLI::success( 'Order index cleared.' );
				break;

			case 'products':
				$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}miniload_search_index" );
				WP_CLI::success( 'Product index cleared.' );
				break;

			case 'all':
				$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}miniload_order_index" );
				$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}miniload_order_trigrams" );
				$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}miniload_search_index" );
				WP_CLI::success( 'All indexes cleared.' );
				break;

			default:
				WP_CLI::error( 'Invalid type. Use: orders, products, or all' );
		}
	}

	/**
	 * Search for orders using the index.
	 *
	 * ## OPTIONS
	 *
	 * <search>
	 * : Search term (order number, email, phone, etc.)
	 *
	 * [--limit=<limit>]
	 * : Maximum number of results.
	 * ---
	 * default: 10
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp miniload search "john@example.com"
	 *     wp miniload search "12345" --limit=5
	 *
	 * @when after_wp_load
	 */
	public function search( $args, $assoc_args ) {
		$search_term = $args[0];
		$limit = isset( $assoc_args['limit'] ) ? intval( $assoc_args['limit'] ) : 10;

		// MiniLoad plugin should already be loaded
		if ( ! class_exists( 'MiniLoad' ) ) {
			WP_CLI::error( 'MiniLoad class not found. Make sure the plugin is active.' );
			return;
		}

		$miniload = \MiniLoad::instance();

		// Ensure modules are loaded
		$miniload->load_modules();

		// Get Order Search Optimizer module
		$optimizer = $miniload->get_module( 'order_search_optimizer' );

		global $wpdb;

		// Search in index
		$results = $wpdb->get_results( $wpdb->prepare(
			"SELECT order_id, order_number, customer_email, order_status, order_total, order_date
			FROM {$wpdb->prefix}miniload_order_index
			WHERE order_number LIKE %s
			   OR customer_email LIKE %s
			   OR billing_phone LIKE %s
			   OR searchable_text LIKE %s
			ORDER BY order_date DESC
			LIMIT %d",
			'%' . $wpdb->esc_like( $search_term ) . '%',
			'%' . $wpdb->esc_like( $search_term ) . '%',
			'%' . $wpdb->esc_like( $search_term ) . '%',
			'%' . $wpdb->esc_like( $search_term ) . '%',
			$limit
		) );

		if ( empty( $results ) ) {
			WP_CLI::line( 'No orders found matching: ' . $search_term );
			return;
		}

		WP_CLI::line( sprintf( 'Found %d orders matching: %s', count( $results ), $search_term ) );
		WP_CLI::line( '' );

		// Display results in a table
		$data = array();
		foreach ( $results as $order ) {
			$data[] = array(
				'ID' => $order->order_id,
				'Order #' => $order->order_number ?: '#' . $order->order_id,
				'Email' => $order->customer_email,
				'Status' => str_replace( 'wc-', '', $order->order_status ),
				'Total' => wc_price( $order->order_total ),
				'Date' => $order->order_date,
			);
		}

		WP_CLI\Utils\format_items( 'table', $data, array( 'ID', 'Order #', 'Email', 'Status', 'Total', 'Date' ) );
	}

	/**
	 * Show cache statistics.
	 *
	 * ## EXAMPLES
	 *
	 *     wp miniload cache-stats
	 *
	 * @when after_wp_load
	 */
	public function cache_stats( $args, $assoc_args ) {
		global $wpdb;

		// Get cache stats
		$transient_count = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->options}
			WHERE option_name LIKE '_transient_miniload_%'"
		);

		$cache_size = $wpdb->get_var(
			"SELECT SUM(LENGTH(option_value)) / 1024 / 1024
			FROM {$wpdb->options}
			WHERE option_name LIKE '_transient_miniload_%'"
		);

		WP_CLI::line( '=== MiniLoad Cache Statistics ===' );
		WP_CLI::line( '' );
		WP_CLI::line( sprintf( 'Cached Items: %d', $transient_count ) );
		WP_CLI::line( sprintf( 'Cache Size:   %.2f MB', $cache_size ?: 0 ) );
		WP_CLI::line( '' );

		// Show cache breakdown by type
		$breakdown = $wpdb->get_results(
			"SELECT
				SUBSTRING_INDEX(SUBSTRING(option_name, 20), '_', 1) as cache_type,
				COUNT(*) as count,
				SUM(LENGTH(option_value)) / 1024 as size_kb
			FROM {$wpdb->options}
			WHERE option_name LIKE '_transient_miniload_%'
			GROUP BY cache_type
			ORDER BY count DESC"
		);

		if ( ! empty( $breakdown ) ) {
			WP_CLI::line( '=== Cache Breakdown ===' );
			$data = array();
			foreach ( $breakdown as $item ) {
				$data[] = array(
					'Type' => $item->cache_type,
					'Count' => $item->count,
					'Size' => sprintf( '%.2f KB', $item->size_kb ),
				);
			}
			WP_CLI\Utils\format_items( 'table', $data, array( 'Type', 'Count', 'Size' ) );
		}
	}

	/**
	 * Clear all MiniLoad caches.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Skip confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp miniload clear-cache --yes
	 *
	 * @when after_wp_load
	 */
	public function clear_cache( $args, $assoc_args ) {
		WP_CLI::confirm( 'Are you sure you want to clear all MiniLoad caches?', $assoc_args );

		global $wpdb;

		// Clear transients
		$deleted = $wpdb->query(
			"DELETE FROM {$wpdb->options}
			WHERE option_name LIKE '_transient_miniload_%'
			   OR option_name LIKE '_transient_timeout_miniload_%'"
		);

		// Clear object cache
		wp_cache_flush();

		WP_CLI::success( sprintf( 'Cleared %d cache entries.', $deleted / 2 ) ); // Divide by 2 because of timeout entries
	}
}

// Register the command
WP_CLI::add_command( 'miniload', 'MiniLoad_CLI' );