<?php
/**
 * MiniLoad Dashboard View
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

// Get current status
global $wpdb;

// Get table statistics directly
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
		$miniload_cache_key3 = 'miniload_' . md5(  "SELECT COUNT(*) FROM {$wpdb->prefix}miniload_filter_cache"  );
		$miniload_cached3 = wp_cache_get( $miniload_cache_key3 );
		if ( false === $miniload_cached3 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for performance optimization
			$miniload_cached3 = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}miniload_filter_cache" );
			wp_cache_set( $miniload_cache_key3, $miniload_cached3, '', 3600 );
		}
		$miniload_filter_cache_count = $miniload_cached3;
// Direct database query with caching
		$miniload_cache_key4 = 'miniload_' . md5(  "SELECT COUNT(*) FROM {$wpdb->prefix}miniload_search_log"  );
		$miniload_cached4 = wp_cache_get( $miniload_cache_key4 );
		if ( false === $miniload_cached4 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for performance optimization
			$miniload_cached4 = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}miniload_search_log" );
			wp_cache_set( $miniload_cache_key4, $miniload_cached4, '', 3600 );
		}
		$miniload_search_log_count = $miniload_cached4;

// Get total products
// Direct database query with caching
		$miniload_cache_key5 = 'miniload_' . md5(  "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish'"  );
		$miniload_cached5 = wp_cache_get( $miniload_cache_key5 );
		if ( false === $miniload_cached5 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for performance optimization
			$miniload_cached5 = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish'" );
			wp_cache_set( $miniload_cache_key5, $miniload_cached5, '', 3600 );
		}
		$miniload_total_products = $miniload_cached5;

// Get MySQL version
// Direct database query with caching
		$miniload_cache_key6 = 'miniload_' . md5(  "SELECT VERSION()"  );
		$miniload_cached6 = wp_cache_get( $miniload_cache_key6 );
		if ( false === $miniload_cached6 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for performance optimization
			$miniload_cached6 = $wpdb->get_var( "SELECT VERSION()" );
			wp_cache_set( $miniload_cache_key6, $miniload_cached6, '', 3600 );
		}
		$miniload_mysql_version = $miniload_cached6;
$miniload_wc_version = defined( 'WC_VERSION' ) ? WC_VERSION : 'Not detected';

// Calculate coverage percentages
$miniload_sort_coverage = $miniload_total_products > 0 ? round( ( $miniload_sort_index_count / $miniload_total_products ) * 100, 1 ) : 0;
$miniload_search_coverage = $miniload_total_products > 0 ? round( ( $miniload_search_index_count / $miniload_total_products ) * 100, 1 ) : 0;

// Calculate overall optimization score based on index coverage
$miniload_optimization_score = round( ( $miniload_sort_coverage + $miniload_search_coverage ) / 2 );
?>

<div class="wrap miniload-dashboard">
	<h1 class="wp-heading-inline">
		<?php echo esc_html__( 'MiniLoad Dashboard', 'miniload' ); ?>
	</h1>

	<div class="miniload-header">
		<p class="description">
			<?php echo esc_html__( 'MySQL Turbo Mode for WooCommerce - Pure MySQL Performance Optimization', 'miniload' ); ?>
		</p>
	</div>

	<!-- Status Cards -->
	<div class="miniload-status-cards">
		<div class="status-card">
			<h3><?php echo esc_html__( 'Optimization Score', 'miniload' ); ?></h3>
			<div class="status-value">
				<span class="score <?php echo $miniload_optimization_score >= 80 ? 'good' : ( $miniload_optimization_score >= 50 ? 'warning' : 'bad' ); ?>">
					<?php echo esc_html( $miniload_optimization_score ); ?>%
				</span>
			</div>
			<p class="status-info">
				<?php
				printf(
					/* translators: %1$d: number of indexed products, %2$d: total number of products */
					esc_html__( '%1$d of %2$d products indexed', 'miniload' ),
					esc_html( max( $miniload_sort_index_count, $miniload_search_index_count ) ),
					esc_html( $miniload_total_products )
				);
				?>
			</p>
		</div>

		<div class="status-card">
			<h3><?php echo esc_html__( 'MySQL Version', 'miniload' ); ?></h3>
			<div class="status-value">
				<?php echo esc_html( $miniload_mysql_version ); ?>
			</div>
			<p class="status-info">
				<?php
				if ( version_compare( $miniload_mysql_version, '5.7', '>=' ) ) {
					echo '<span class="dashicons dashicons-yes-alt good"></span> ' . esc_html__( 'Optimal version', 'miniload' );
				} else {
					echo '<span class="dashicons dashicons-warning warning"></span> ' . esc_html__( 'Consider upgrading', 'miniload' );
				}
				?>
			</p>
		</div>

		<div class="status-card">
			<h3><?php echo esc_html__( 'WooCommerce', 'miniload' ); ?></h3>
			<div class="status-value">
				<?php echo esc_html( $miniload_wc_version ); ?>
			</div>
			<p class="status-info">
				<?php
				if ( class_exists( 'Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' ) &&
				     wc_get_container()->get( Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled() ) {
					echo '<span class="dashicons dashicons-yes-alt good"></span> ' . esc_html__( 'HPOS enabled', 'miniload' );
				} else {
					echo '<span class="dashicons dashicons-info"></span> ' . esc_html__( 'Legacy storage', 'miniload' );
				}
				?>
			</p>
		</div>

		<div class="status-card">
			<h3><?php echo esc_html__( 'Cache Status', 'miniload' ); ?></h3>
			<div class="status-value">
				<span class="good"><?php echo esc_html__( 'Active', 'miniload' ); ?></span>
			</div>
			<p class="status-info">
				<button type="button" class="button button-small" id="miniload-clear-cache">
					<?php echo esc_html__( 'Clear Cache', 'miniload' ); ?>
				</button>
			</p>
		</div>
	</div>

	<!-- Index Status -->
	<?php if ( $sort_index_count == 0 || $search_index_count == 0 ) : ?>
		<div class="notice notice-warning">
			<p>
				<strong><?php echo esc_html__( 'Indexes Not Built!', 'miniload' ); ?></strong>
				<?php echo esc_html__( 'Build indexes to enable MiniLoad optimizations.', 'miniload' ); ?>
				<button type="button" class="button button-primary" id="miniload-create-indexes" style="margin-left: 10px;">
					<?php echo esc_html__( 'Build Indexes Now', 'miniload' ); ?>
				</button>
			</p>
		</div>
	<?php endif; ?>

	<!-- Quick Actions -->
	<div class="miniload-quick-actions">
		<h2><?php echo esc_html__( 'Quick Actions', 'miniload' ); ?></h2>

		<div class="action-buttons">
			<button type="button" class="button button-primary" id="miniload-rebuild-indexes">
				<span class="dashicons dashicons-database"></span>
				<?php echo esc_html__( 'Rebuild All Indexes', 'miniload' ); ?>
			</button>

			<button type="button" class="button" id="miniload-analyze-tables">
				<span class="dashicons dashicons-chart-bar"></span>
				<?php echo esc_html__( 'Analyze Tables', 'miniload' ); ?>
			</button>

			<button type="button" class="button" id="miniload-optimize-tables">
				<span class="dashicons dashicons-admin-tools"></span>
				<?php echo esc_html__( 'Optimize Tables', 'miniload' ); ?>
			</button>

			<button type="button" class="button" id="miniload-run-cleanup">
				<span class="dashicons dashicons-trash"></span>
				<?php echo esc_html__( 'Run Cleanup', 'miniload' ); ?>
			</button>
		</div>
	</div>

	<!-- Index Details -->
	<div class="miniload-index-details">
		<h2><?php echo esc_html__( 'MiniLoad Indexes', 'miniload' ); ?></h2>

		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><?php echo esc_html__( 'Index Type', 'miniload' ); ?></th>
					<th><?php echo esc_html__( 'Products Indexed', 'miniload' ); ?></th>
					<th><?php echo esc_html__( 'Coverage', 'miniload' ); ?></th>
					<th><?php echo esc_html__( 'Purpose', 'miniload' ); ?></th>
					<th><?php echo esc_html__( 'Status', 'miniload' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td><strong><?php echo esc_html__( 'Sort Index', 'miniload' ); ?></strong></td>
					<td><?php echo number_format( $sort_index_count ); ?></td>
					<td><?php echo esc_html( $sort_coverage ); ?>%</td>
					<td><?php echo esc_html__( 'Eliminates postmeta JOINs for sorting', 'miniload' ); ?></td>
					<td>
						<?php if ( $sort_index_count > 0 ) : ?>
							<span class="dashicons dashicons-yes-alt good"></span>
							<?php echo esc_html__( 'Active', 'miniload' ); ?>
						<?php else : ?>
							<span class="dashicons dashicons-warning warning"></span>
							<?php echo esc_html__( 'Not Built', 'miniload' ); ?>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<td><strong><?php echo esc_html__( 'Search Index', 'miniload' ); ?></strong></td>
					<td><?php echo number_format( $search_index_count ); ?></td>
					<td><?php echo esc_html( $search_coverage ); ?>%</td>
					<td><?php echo esc_html__( 'FULLTEXT search with SKU instant lookup', 'miniload' ); ?></td>
					<td>
						<?php if ( $search_index_count > 0 ) : ?>
							<span class="dashicons dashicons-yes-alt good"></span>
							<?php echo esc_html__( 'Active', 'miniload' ); ?>
						<?php else : ?>
							<span class="dashicons dashicons-warning warning"></span>
							<?php echo esc_html__( 'Not Built', 'miniload' ); ?>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<td><strong><?php echo esc_html__( 'Filter Cache', 'miniload' ); ?></strong></td>
					<td><?php echo number_format( $filter_cache_count ); ?></td>
					<td>-</td>
					<td><?php echo esc_html__( 'Denormalized filter values', 'miniload' ); ?></td>
					<td>
						<span class="dashicons dashicons-yes-alt good"></span>
						<?php echo esc_html__( 'Active', 'miniload' ); ?>
					</td>
				</tr>
				<tr>
					<td><strong><?php echo esc_html__( 'Query Cache', 'miniload' ); ?></strong></td>
					<td colspan="2"><?php echo esc_html__( 'Intelligent caching', 'miniload' ); ?></td>
					<td><?php echo esc_html__( 'Caches expensive queries', 'miniload' ); ?></td>
					<td>
						<span class="dashicons dashicons-yes-alt good"></span>
						<?php echo esc_html__( 'Active', 'miniload' ); ?>
					</td>
				</tr>
				<tr>
					<td><strong><?php echo esc_html__( 'Pagination Optimizer', 'miniload' ); ?></strong></td>
					<td colspan="2"><?php echo esc_html__( 'No SQL_CALC_FOUND_ROWS', 'miniload' ); ?></td>
					<td><?php echo esc_html__( 'Removes full table scans', 'miniload' ); ?></td>
					<td>
						<span class="dashicons dashicons-yes-alt good"></span>
						<?php echo esc_html__( 'Active', 'miniload' ); ?>
					</td>
				</tr>
			</tbody>
		</table>
	</div>

	<!-- Performance Tips -->
	<div class="miniload-tips">
		<h2><?php echo esc_html__( 'Performance Tips', 'miniload' ); ?></h2>
		<ul>
			<li><?php echo esc_html__( '✓ Keep all indexes active for optimal performance', 'miniload' ); ?></li>
			<li><?php echo esc_html__( '✓ Run cleanup monthly to maintain database health', 'miniload' ); ?></li>
			<li><?php echo esc_html__( '✓ Enable HPOS in WooCommerce for better scalability', 'miniload' ); ?></li>
			<li><?php echo esc_html__( '✓ Consider upgrading to MySQL 5.7+ for JSON support', 'miniload' ); ?></li>
		</ul>
	</div>
</div>

<style>
.miniload-dashboard {
	max-width: 1200px;
}

.miniload-status-cards {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
	gap: 20px;
	margin: 20px 0;
}

.status-card {
	background: #fff;
	border: 1px solid #ccd0d4;
	border-radius: 4px;
	padding: 20px;
	text-align: center;
}

.status-card h3 {
	margin-top: 0;
	color: #23282d;
	font-size: 14px;
	font-weight: 600;
}

.status-value {
	font-size: 32px;
	font-weight: bold;
	margin: 10px 0;
}

.status-value .good { color: #46b450; }
.status-value .warning { color: #ffb900; }
.status-value .bad { color: #dc3232; }

.dashicons.good { color: #46b450; }
.dashicons.warning { color: #ffb900; }

.miniload-quick-actions {
	background: #fff;
	border: 1px solid #ccd0d4;
	padding: 20px;
	margin: 20px 0;
}

.action-buttons {
	display: flex;
	gap: 10px;
	flex-wrap: wrap;
}

.action-buttons .button .dashicons {
	margin-right: 5px;
	vertical-align: text-bottom;
}

.index-group {
	margin: 20px 0;
}

.index-group h3 {
	background: #f1f1f1;
	padding: 10px;
	margin: 0 0 10px 0;
}

.miniload-tips {
	background: #fff;
	border: 1px solid #ccd0d4;
	padding: 20px;
	margin: 20px 0;
}

.miniload-tips ul {
	margin: 10px 0 0 20px;
}

.miniload-tips li {
	margin: 5px 0;
}
</style>