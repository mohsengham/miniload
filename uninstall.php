<?php
/**
 * MiniLoad Uninstall
 *
 * @package MiniLoad
 * @since   1.0.0
 */

// Exit if accessed directly
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Check if we should remove data
$miniload_uninstall_behavior = get_option( 'miniload_uninstall_behavior', 'keep' );

if ( $miniload_uninstall_behavior === 'remove' ) {
	global $wpdb;

	// Drop custom tables
	$miniload_tables = array(
		$wpdb->prefix . 'miniload_search_index',
		// Analytics removed - no tracking tables
		$wpdb->prefix . 'miniload_media_search'
	);

	foreach ( $miniload_tables as $miniload_table ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Required for performance optimization
		$wpdb->query( "DROP TABLE IF EXISTS " . esc_sql( $miniload_table ) );
	}

	// Remove all plugin options
	$miniload_options = array(
		'miniload_version',
		'miniload_enabled',
		'miniload_debug_mode',
		'miniload_priority',
		'miniload_settings',
		'miniload_ajax_search_enabled',
		'miniload_search_min_chars',
		'miniload_search_delay',
		'miniload_search_results_count',
		'miniload_search_in_content',
		'miniload_search_in_title',
		'miniload_search_in_sku',
		'miniload_search_in_short_desc',
		'miniload_search_in_categories',
		'miniload_search_in_tags',
		'miniload_show_categories',
		'miniload_search_icon_position',
		'miniload_search_placeholder',
		'miniload_show_price',
		'miniload_show_image',
		'miniload_admin_search_enabled',
		'miniload_media_search_enabled',
		'miniload_editor_link_enabled',
		'miniload_query_optimizer_enabled',
		'miniload_cache_enabled',
		// Analytics removed - no tracking
		'miniload_performance_monitor_enabled',
		'miniload_cache_duration',
		'miniload_batch_size',
		'miniload_memory_limit',
		'miniload_auto_index',
		'miniload_uninstall_behavior',
		'miniload_rest_api_enabled',
		'miniload_nonce_lifetime',
		'miniload_db_prefix',
		'miniload_replace_search',
		'miniload_enable_search_modal',
		'miniload_track_searches',
		'miniload_search_max_results'
	);

	foreach ( $miniload_options as $miniload_option ) {
		delete_option( $miniload_option );
	}

	// Remove transients
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for performance optimization
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_miniload_%'" );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for performance optimization
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_miniload_%'" );

	// Clear any cached data
	wp_cache_flush();
}