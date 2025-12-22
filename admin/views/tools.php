<?php
/**
 * MiniLoad Tools View
 *
 * @package MiniLoad\Admin
 * @since 1.0.0
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

// Security check
if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'miniload' ) );
}

// Get statistics
global $wpdb;
// Direct database query with caching
		$miniload_cache_key1 = 'miniload_' . md5(  "SELECT COUNT(*) FROM {$wpdb->prefix}miniload_sort_index"  );
		$miniload_cached1 = wp_cache_get( $miniload_cache_key1 );
		if ( false === $miniload_cached1 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for performance optimization
			$miniload_cached1 = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}miniload_sort_index" );
			wp_cache_set( $miniload_cache_key1, $miniload_cached1, '', 3600 );
		}
		$miniload_sort_index_count = $miniload_cached1;
// Direct database query with caching
		$miniload_cache_key2 = 'miniload_' . md5(  "SELECT COUNT(*) FROM {$wpdb->prefix}miniload_search_index"  );
		$miniload_cached2 = wp_cache_get( $miniload_cache_key2 );
		if ( false === $miniload_cached2 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for performance optimization
			$miniload_cached2 = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}miniload_search_index" );
			wp_cache_set( $miniload_cache_key2, $miniload_cached2, '', 3600 );
		}
		$miniload_search_index_count = $miniload_cached2;
// Direct database query with caching
		$miniload_cache_key3 = 'miniload_' . md5(  "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish'"  );
		$miniload_cached3 = wp_cache_get( $miniload_cache_key3 );
		if ( false === $miniload_cached3 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for performance optimization
			$miniload_cached3 = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish'" );
			wp_cache_set( $miniload_cache_key3, $miniload_cached3, '', 3600 );
		}
		$miniload_total_products = $miniload_cached3;
// Direct database query with caching
		$miniload_cache_key4 = 'miniload_' . md5(  "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_miniload_%'"  );
		$miniload_cached4 = wp_cache_get( $miniload_cache_key4 );
		if ( false === $miniload_cached4 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for performance optimization
			$miniload_cached4 = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_miniload_%'" );
			wp_cache_set( $miniload_cache_key4, $miniload_cached4, '', 3600 );
		}
		$miniload_cache_size = $miniload_cached4;
?>

<div class="wrap miniload-tools">
	<h1><?php echo esc_html__( 'MiniLoad Tools', 'miniload' ); ?></h1>

	<p class="description"><?php echo esc_html__( 'Maintenance and optimization tools for MiniLoad.', 'miniload' ); ?></p>

	<!-- Index Management -->
	<div class="tool-card" style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; margin-top: 20px;">
		<h2><?php echo esc_html__( 'Index Management', 'miniload' ); ?></h2>

		<table class="widefat" style="margin-top: 10px;">
			<thead>
				<tr>
					<th><?php echo esc_html__( 'Index', 'miniload' ); ?></th>
					<th><?php echo esc_html__( 'Indexed', 'miniload' ); ?></th>
					<th><?php echo esc_html__( 'Total', 'miniload' ); ?></th>
					<th><?php echo esc_html__( 'Coverage', 'miniload' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td><strong><?php echo esc_html__( 'Sort Index', 'miniload' ); ?></strong></td>
					<td><?php echo number_format( $miniload_sort_index_count ); ?></td>
					<td><?php echo number_format( $miniload_total_products ); ?></td>
					<td>
						<?php
						$miniload_sort_coverage = $miniload_total_products > 0 ? round( ( $miniload_sort_index_count / $miniload_total_products ) * 100, 1 ) : 0;
						echo esc_html( $miniload_sort_coverage . '%' );
						?>
					</td>
				</tr>
				<tr>
					<td><strong><?php echo esc_html__( 'Search Index', 'miniload' ); ?></strong></td>
					<td><?php echo number_format( $miniload_search_index_count ); ?></td>
					<td><?php echo number_format( $miniload_total_products ); ?></td>
					<td>
						<?php
						$miniload_search_coverage = $miniload_total_products > 0 ? round( ( $miniload_search_index_count / $miniload_total_products ) * 100, 1 ) : 0;
						echo esc_html( $miniload_search_coverage . '%' );
						?>
					</td>
				</tr>
			</tbody>
		</table>

		<div style="margin-top: 20px;">
			<?php if ( $miniload_sort_coverage < 100 || $miniload_search_coverage < 100 ) : ?>
				<button type="button" class="button button-primary" id="miniload-rebuild-indexes">
					<span class="dashicons dashicons-update" style="vertical-align: text-bottom;"></span>
					<?php echo esc_html__( 'Rebuild All Indexes', 'miniload' ); ?>
				</button>
				<span style="margin-left: 10px; color: #d63638;">
					<?php echo esc_html__( 'Warning: Indexes are incomplete. Rebuild to achieve full optimization.', 'miniload' ); ?>
				</span>
			<?php else : ?>
				<button type="button" class="button" id="miniload-rebuild-indexes">
					<span class="dashicons dashicons-update" style="vertical-align: text-bottom;"></span>
					<?php echo esc_html__( 'Rebuild All Indexes', 'miniload' ); ?>
				</button>
				<span style="margin-left: 10px; color: #00a32a;">
					✓ <?php echo esc_html__( 'All indexes are complete', 'miniload' ); ?>
				</span>
			<?php endif; ?>
		</div>
	</div>

	<!-- Cache Management -->
	<div class="tool-card" style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; margin-top: 20px;">
		<h2><?php echo esc_html__( 'Cache Management', 'miniload' ); ?></h2>

		<p><?php echo esc_html__( 'Current cache entries:', 'miniload' ); ?> <strong><?php echo number_format( $miniload_cache_size ); ?></strong></p>

		<div style="margin-top: 20px;">
			<button type="button" class="button" id="miniload-clear-cache">
				<span class="dashicons dashicons-trash" style="vertical-align: text-bottom;"></span>
				<?php echo esc_html__( 'Clear All Caches', 'miniload' ); ?>
			</button>
			<span style="margin-left: 10px; color: #666;">
				<?php echo esc_html__( 'Clears query cache, filter cache, and transients', 'miniload' ); ?>
			</span>
		</div>
	</div>

	<!-- Database Optimization -->
	<div class="tool-card" style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; margin-top: 20px;">
		<h2><?php echo esc_html__( 'Database Optimization', 'miniload' ); ?></h2>

		<p><?php echo esc_html__( 'Optimize database tables for better performance.', 'miniload' ); ?></p>

		<div style="margin-top: 20px;">
			<button type="button" class="button" id="miniload-optimize-tables">
				<span class="dashicons dashicons-admin-tools" style="vertical-align: text-bottom;"></span>
				<?php echo esc_html__( 'Optimize Tables', 'miniload' ); ?>
			</button>
			<button type="button" class="button" id="miniload-analyze-tables" style="margin-left: 10px;">
				<span class="dashicons dashicons-chart-bar" style="vertical-align: text-bottom;"></span>
				<?php echo esc_html__( 'Analyze Tables', 'miniload' ); ?>
			</button>
		</div>
	</div>

	<!-- Quick Commands -->
	<div class="tool-card" style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; margin-top: 20px;">
		<h2><?php echo esc_html__( 'Quick Commands', 'miniload' ); ?></h2>

		<p><?php echo esc_html__( 'Run these commands via SSH for manual operations:', 'miniload' ); ?></p>

		<div style="background: #f0f0f0; padding: 15px; border-radius: 3px; font-family: monospace; margin-top: 10px;">
			<strong><?php echo esc_html__( 'Index all products:', 'miniload' ); ?></strong><br>
			<code style="background: #fff; padding: 5px; display: inline-block; margin: 5px 0;">
				php /home/zabanpub/public_html/miniload-full-index.php
			</code><br><br>

			<strong><?php echo esc_html__( 'Quick index (first 1000 products):', 'miniload' ); ?></strong><br>
			<code style="background: #fff; padding: 5px; display: inline-block; margin: 5px 0;">
				php /home/zabanpub/public_html/miniload-index.php
			</code><br><br>

			<strong><?php echo esc_html__( 'Test MiniLoad status:', 'miniload' ); ?></strong><br>
			<code style="background: #fff; padding: 5px; display: inline-block; margin: 5px 0;">
				php /home/zabanpub/public_html/test-miniload.php
			</code>
		</div>
	</div>

	<!-- Performance Tips -->
	<div style="background: #f9f9f9; border: 1px solid #ccd0d4; padding: 20px; margin-top: 20px; border-left: 4px solid #0073aa;">
		<h3 style="margin-top: 0;"><?php echo esc_html__( 'Performance Tips', 'miniload' ); ?></h3>
		<ul style="margin-left: 20px;">
			<li><?php echo esc_html__( 'Run index rebuild after importing new products', 'miniload' ); ?></li>
			<li><?php echo esc_html__( 'Clear cache after major product updates', 'miniload' ); ?></li>
			<li><?php echo esc_html__( 'Monitor index coverage - aim for 100%', 'miniload' ); ?></li>
			<li><?php echo esc_html__( 'Use the full indexer for complete optimization', 'miniload' ); ?></li>
		</ul>
	</div>
</div>