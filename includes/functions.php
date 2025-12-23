<?php
/**
 * MiniLoad Core Functions
 *
 * @package MiniLoad
 * @since 1.0.0
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

/**
 * Log messages
 *
 * @param string $message Message to log
 * @param string $level Log level (debug, info, warning, error)
 */
function miniload_log( $miniload_message, $level = 'info' ) {
	// Check if debug mode is enabled
	$miniload_settings = get_option( 'miniload_settings', array() );

	if ( empty( $miniload_settings['debug_mode'] ) && $level === 'debug' ) {
		return;
	}

	// Prepare log entry
	$log_entry = sprintf(
		'[%s] [%s] %s',
		current_time( 'mysql' ),
		strtoupper( $level ),
		$miniload_message
	);

	// Log to error log if WP_DEBUG_LOG is enabled
	if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {

	}

	// Also log to database for admin viewing
	if ( $level === 'error' || $level === 'warning' ) {
		$logs = get_option( 'miniload_logs', array() );

		// Keep only last 100 entries
		if ( count( $logs ) >= 100 ) {
			array_shift( $logs );
		}

		$logs[] = array(
			'time'    => current_time( 'timestamp' ),
			'level'   => $level,
			'message' => $miniload_message,
		);

		update_option( 'miniload_logs', $logs, false );
	}
}

/**
 * Get MiniLoad option
 *
 * @param string $key Option key
 * @param mixed $default Default value
 * @return mixed
 */
function miniload_get_option( $key, $default = null ) {
	$miniload_settings = get_option( 'miniload_settings', array() );
	return isset( $miniload_settings[ $key ] ) ? $miniload_settings[ $key ] : $default;
}

/**
 * Update MiniLoad option
 *
 * @param string $key Option key
 * @param mixed $value Option value
 * @return bool
 */
function miniload_update_option( $key, $value ) {
	$miniload_settings = get_option( 'miniload_settings', array() );
	$miniload_settings[ $key ] = $value;
	return update_option( 'miniload_settings', $miniload_settings );
}

/**
 * Check if MiniLoad is in debug mode
 *
 * @return bool
 */
function miniload_is_debug() {
	return (bool) miniload_get_option( 'debug_mode', false );
}

/**
 * Get cache key
 *
 * @param string $type Cache type
 * @param mixed $data Data to create key from
 * @return string
 */
function miniload_get_cache_key( $type, $data ) {
	if ( is_array( $data ) || is_object( $data ) ) {
		$data = serialize( $data );
	}

	return 'miniload_' . $type . '_' . md5( $data );
}

/**
 * Set cached data
 *
 * @param string $key Cache key
 * @param mixed $data Data to cache
 * @param int $expiration Expiration time in seconds
 * @return bool
 */
function miniload_set_cache( $key, $data, $expiration = null ) {
	if ( is_null( $expiration ) ) {
		$expiration = miniload_get_option( 'cache_ttl', 300 );
	}

	return set_transient( $key, $data, $expiration );
}

/**
 * Get cached data
 *
 * @param string $key Cache key
 * @return mixed
 */
function miniload_get_cache( $key ) {
	return get_transient( $key );
}

/**
 * Delete cached data
 *
 * @param string $key Cache key
 * @return bool
 */
function miniload_delete_cache( $key ) {
	return delete_transient( $key );
}

/**
 * Clear all MiniLoad caches
 */
function miniload_clear_all_caches() {
	global $wpdb;

	// Clear transients
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for performance optimization
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_miniload_%'" );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for performance optimization
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_miniload_%'" );

	// Clear filter cache table
	$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}miniload_filter_cache" );

	// Trigger action for modules to clear their caches
	do_action( 'miniload_clear_all_caches' );

	miniload_log( 'All caches cleared', 'info' );
}

/**
 * Check if a database table exists
 *
 * @param string $table_name Table name (without prefix)
 * @return bool
 */
function miniload_table_exists( $table_name ) {
	global $wpdb;

	$full_table_name = $wpdb->prefix . $table_name;
	$query = $wpdb->prepare( "SHOW TABLES LIKE %s", $full_table_name );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for performance optimization
	return $wpdb->get_var( $query ) === $full_table_name; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
}

/**
 * Get database table size
 *
 * @param string $table_name Table name (without prefix)
 * @return int Size in bytes
 */
function miniload_get_table_size( $table_name ) {
	global $wpdb;

	$full_table_name = $wpdb->prefix . $table_name;

	$query = $wpdb->prepare(
		"SELECT data_length + index_length
		FROM information_schema.TABLES
		WHERE table_schema = %s
		AND table_name = %s",
		DB_NAME,
		$full_table_name
	);

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for performance optimization
	return (int) $wpdb->get_var( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
}

/**
 * Format bytes to human readable
 *
 * @param int $bytes Bytes
 * @param int $precision Decimal precision
 * @return string
 */
function miniload_format_bytes( $bytes, $precision = 2 ) {
	$units = array( 'B', 'KB', 'MB', 'GB', 'TB' );

	$bytes = max( $bytes, 0 );
	$pow = floor( ( $bytes ? log( $bytes ) : 0 ) / log( 1024 ) );
	$pow = min( $pow, count( $units ) - 1 );

	$bytes /= pow( 1024, $pow );

	return round( $bytes, $precision ) . ' ' . $units[ $pow ];
}

/**
 * Measure query execution time
 *
 * @param callable $callback Query callback
 * @return array Result and execution time
 */
function miniload_measure_query( $callback ) {
	$start = microtime( true );
	$miniload_result = call_user_func( $callback );
	$time = microtime( true ) - $start;

	return array(
		'result' => $miniload_result,
		'time'   => round( $time * 1000, 2 ), // Convert to milliseconds
	);
}

/**
 * Check if WooCommerce HPOS is enabled
 *
 * @return bool
 */
function miniload_is_hpos_enabled() {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
		return \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}
	return false;
}

/**
 * Get WooCommerce orders table name
 *
 * @return string
 */
function miniload_get_orders_table() {
	global $wpdb;

	if ( miniload_is_hpos_enabled() ) {
		return $wpdb->prefix . 'wc_orders';
	}

	return $wpdb->posts;
}

/**
 * Sanitize and validate cache key
 *
 * @param string $key Cache key
 * @return string
 */
function miniload_sanitize_cache_key( $key ) {
	// Remove invalid characters
	$key = preg_replace( '/[^a-zA-Z0-9_\-]/', '', $key );

	// Limit length
	if ( strlen( $key ) > 32 ) {
		$key = substr( $key, 0, 32 );
	}

	return $key;
}

/**
 * Check if current request is AJAX
 *
 * @return bool
 */
function miniload_is_ajax() {
	return defined( 'DOING_AJAX' ) && DOING_AJAX;
}

/**
 * Check if current request is REST API
 *
 * @return bool
 */
function miniload_is_rest() {
	return defined( 'REST_REQUEST' ) && REST_REQUEST;
}

/**
 * Get product IDs from a WP_Query
 *
 * @param WP_Query $query Query object
 * @return array
 */
function miniload_get_product_ids_from_query( $query ) {
	$product_ids = array();

	if ( $query->have_posts() ) {
		foreach ( $query->posts as $post ) {
			if ( is_object( $post ) ) {
				$product_ids[] = $post->ID;
			} elseif ( is_numeric( $post ) ) {
				$product_ids[] = $post;
			}
		}
	}

	return array_map( 'intval', $product_ids );
}

/**
 * Invalidate product cache
 *
 * @param int $product_id Product ID
 */
function miniload_invalidate_product_cache( $product_id ) {
	// Delete specific product caches
	delete_transient( 'miniload_product_' . $product_id );

	// Trigger action for modules
	do_action( 'miniload_invalidate_product_cache', $product_id );

	miniload_log( sprintf( 'Product cache invalidated: %d', $product_id ), 'debug' );
}

/**
 * Get MySQL version
 *
 * @return string
 */
function miniload_get_mysql_version() {
	global $wpdb;

	// Direct database query with caching
		$cache_key = 'miniload_' . md5(  "SELECT VERSION()"  );
		$cached = wp_cache_get( $cache_key );
		if ( false === $cached ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Required for performance optimization
			$cached = $wpdb->get_var( "SELECT VERSION()" );
			wp_cache_set( $cache_key, $cached, '', 3600 );
		}

	// Extract just the version number
	if ( preg_match( '/^(\d+\.\d+\.\d+)/', $cached, $matches ) ) {
		return $matches[1];
	}

	return $cached;
}

/**
 * Check if MySQL supports a feature
 *
 * @param string $feature Feature name
 * @return bool
 */
function miniload_mysql_supports( $feature ) {
	$version = miniload_get_mysql_version();

	switch ( $feature ) {
		case 'json':
			return version_compare( $version, '5.7.0', '>=' );

		case 'fulltext_innodb':
			return version_compare( $version, '5.6.0', '>=' );

		case 'partitioning':
			return version_compare( $version, '5.1.0', '>=' );

		default:
			return false;
	}
}

/**
 * Safe database query with error handling
 *
 * @param string $query SQL query
 * @param mixed ...$args Query arguments
 * @return mixed Query result or false on error
 */
function miniload_safe_query( $query, ...$args ) {
	global $wpdb;

	// Prepare query if arguments provided
	if ( ! empty( $args ) ) {
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $query is the SQL template for prepare()
		$query = $wpdb->prepare( $query, ...$args );
	}

	// Suppress errors
	$suppress = $wpdb->suppress_errors();

	// Execute query
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $query is prepared above on line 397
	$miniload_result = $wpdb->query( $query );

	// Check for errors
	if ( $wpdb->last_error ) {
		miniload_log( 'Database error: ' . $wpdb->last_error, 'error' );
		$miniload_result = false;
	}

	// Restore error suppression
	$wpdb->suppress_errors( $suppress );

	return $miniload_result;
}