<?php
/**
 * Dashboard Tab Content
 *
 * @package MiniLoad
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Get statistics
global $wpdb;

// Product stats
// Direct database query with caching
		$miniload_cache_key1 = 'miniload_' . md5(  "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish'"  );
		$miniload_cached1 = wp_cache_get( $miniload_cache_key1 );
		if ( false === $miniload_cached1 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for performance optimization
			$miniload_cached1 = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish'" );
			wp_cache_set( $miniload_cache_key1, $miniload_cached1, '', 3600 );
		}
		$miniload_total_products = $miniload_cached1;
// Direct database query with caching
		$miniload_cache_key2 = 'miniload_' . md5(  "SELECT COUNT(*) FROM {$wpdb->prefix}miniload_product_search"  );
		$miniload_cached2 = wp_cache_get( $miniload_cache_key2 );
		if ( false === $miniload_cached2 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for performance optimization
			$miniload_cached2 = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}miniload_product_search" );
			wp_cache_set( $miniload_cache_key2, $miniload_cached2, '', 3600 );
		}
		$miniload_indexed_products = $miniload_cached2;
$miniload_search_coverage = $miniload_total_products > 0 ? round( ( $miniload_indexed_products / $miniload_total_products ) * 100, 1 ) : 0;

// Analytics removed - no tracking
$miniload_total_searches = 0;
$miniload_searches_today = 0;

// Media stats
$miniload_media_indexed = 0;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for performance optimization
if ( $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}miniload_media_search'" ) ) {
	// Direct database query with caching
		$miniload_cache_key5 = 'miniload_' . md5(  "SELECT COUNT(*) FROM {$wpdb->prefix}miniload_media_search"  );
		$miniload_cached5 = wp_cache_get( $miniload_cache_key5 );
		if ( false === $miniload_cached5 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for performance optimization
			$miniload_cached5 = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}miniload_media_search" );
			wp_cache_set( $miniload_cache_key5, $miniload_cached5, '', 3600 );
		}
		$miniload_media_indexed = $miniload_cached5;
}

// Performance metrics
// Direct database query with caching
		$miniload_cache_key6 = 'miniload_' . md5(  "SELECT AVG(CHAR_LENGTH(search_text)) FROM {$wpdb->prefix}miniload_product_search"  );
		$miniload_cached6 = wp_cache_get( $miniload_cache_key6 );
		if ( false === $miniload_cached6 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for performance optimization
			$miniload_cached6 = $wpdb->get_var( "SELECT AVG(CHAR_LENGTH(search_text)) FROM {$wpdb->prefix}miniload_product_search" );
			wp_cache_set( $miniload_cache_key6, $miniload_cached6, '', 3600 );
		}
		$miniload_avg_index_size = $miniload_cached6;
$miniload_cache_size = size_format( strlen( serialize( wp_cache_get( 'miniload_cache_info', 'miniload' ) ) ), 2 );
?>

<div class="miniload-dashboard">
	<!-- Welcome Section -->
	<div class="miniload-welcome-banner">
		<h2><?php esc_html_e( 'Welcome to MiniLoad', 'miniload' ); ?></h2>
		<p><?php esc_html_e( 'Your WooCommerce store is now supercharged with advanced search and performance optimizations.', 'miniload' ); ?></p>
	</div>

	<!-- Stats Grid -->
	<div class="miniload-stats-grid">
		<div class="miniload-stat-card">
			<div class="miniload-stat-icon dashicons dashicons-search"></div>
			<div class="miniload-stat-value"><?php echo esc_html( number_format( $miniload_indexed_products ) ); ?></div>
			<div class="miniload-stat-label"><?php esc_html_e( 'Products Indexed', 'miniload' ); ?></div>
			<div class="miniload-stat-meta"><?php echo esc_html( $miniload_search_coverage ); ?>% coverage</div>
		</div>


		<div class="miniload-stat-card">
			<div class="miniload-stat-icon dashicons dashicons-images-alt2"></div>
			<div class="miniload-stat-value"><?php echo esc_html( number_format( $miniload_media_indexed ) ); ?></div>
			<div class="miniload-stat-label"><?php esc_html_e( 'Media Indexed', 'miniload' ); ?></div>
			<div class="miniload-stat-meta"><?php echo get_option( 'miniload_media_search_enabled' ) ? esc_html__( 'Active', 'miniload' ) : esc_html__( 'Inactive', 'miniload' ); ?></div>
		</div>


		<?php
		// Review Stats Cache stats
		$miniload_review_stats_table = $wpdb->prefix . 'miniload_review_stats';
		$miniload_products_with_reviews = 0;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Table existence check
		$table_exists_query = $wpdb->prepare( "SHOW TABLES LIKE %s", $miniload_review_stats_table );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared above
		if ( $wpdb->get_var( $table_exists_query ) ) {
			// Direct database query with caching
			// Use sprintf with escaped table name since table names can't be parameterized in prepare()
			$miniload_escaped_table = esc_sql( $miniload_review_stats_table );
			$miniload_count_query = sprintf(
				"SELECT COUNT(*) FROM `%s` WHERE review_count > 0",
				$miniload_escaped_table
			);
			$miniload_cache_key8 = 'miniload_' . md5( $miniload_count_query );
			$miniload_cached8 = wp_cache_get( $miniload_cache_key8 );
			if ( false === $miniload_cached8 ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Stats query with proper escaping
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query uses sprintf with escaped table name
				$miniload_cached8 = $wpdb->get_var( $miniload_count_query );
			wp_cache_set( $miniload_cache_key8, $miniload_cached8, '', 3600 );
		}
		$miniload_products_with_reviews = $miniload_cached8;
		}
		?>
		<div class="miniload-stat-card">
			<div class="miniload-stat-icon dashicons dashicons-star-filled"></div>
			<div class="miniload-stat-value"><?php echo esc_html( number_format( $miniload_products_with_reviews ) ); ?></div>
			<div class="miniload-stat-label"><?php esc_html_e( 'Products with Reviews', 'miniload' ); ?></div>
			<div class="miniload-stat-meta"><?php esc_html_e( 'Pre-calculated stats', 'miniload' ); ?></div>
		</div>
	</div>

	<!-- Quick Actions -->
	<div class="miniload-section">
		<h3 class="miniload-section-title">
			<span class="dashicons dashicons-admin-tools"></span>
			<?php esc_html_e( 'Quick Actions', 'miniload' ); ?>
		</h3>

		<div class="miniload-quick-actions">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=miniload&tab=tools&action=rebuild-search-index' ) ); ?>" class="button button-primary">
				<span class="dashicons dashicons-update"></span>
				<?php esc_html_e( 'Rebuild Search Index', 'miniload' ); ?>
			</a>

			<a href="<?php echo esc_url( admin_url( 'admin.php?page=miniload&tab=tools&action=clear-cache' ) ); ?>" class="button">
				<span class="dashicons dashicons-trash"></span>
				<?php esc_html_e( 'Clear All Caches', 'miniload' ); ?>
			</a>

			<a href="<?php echo esc_url( admin_url( 'admin.php?page=miniload&tab=search' ) ); ?>" class="button">
				<span class="dashicons dashicons-admin-settings"></span>
				<?php esc_html_e( 'Configure Search', 'miniload' ); ?>
			</a>
		</div>
	</div>

	<!-- System Status -->
	<div class="miniload-section">
		<h3 class="miniload-section-title">
			<span class="dashicons dashicons-info"></span>
			<?php esc_html_e( 'System Status', 'miniload' ); ?>
		</h3>

		<div class="miniload-system-status">
			<?php
			$miniload_status_checks = array(
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for performance optimization
				'search_table' => $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}miniload_product_search'" ) ? 'good' : 'error',
				'fulltext_support' => version_compare( $wpdb->db_version(), '5.6', '>=' ) ? 'good' : 'warning',
				'woocommerce' => class_exists( 'WooCommerce' ) ? 'good' : 'error',
				'php_version' => version_compare( PHP_VERSION, '7.2', '>=' ) ? 'good' : 'warning',
			);
			?>

			<table class="widefat">
				<tr>
					<td><?php esc_html_e( 'Search Index Table', 'miniload' ); ?></td>
					<td>
						<?php if ( $miniload_status_checks['search_table'] === 'good' ) : ?>
							<span style="color: green;">✓ <?php esc_html_e( 'Active', 'miniload' ); ?></span>
						<?php else : ?>
							<span style="color: red;">✗ <?php esc_html_e( 'Missing', 'miniload' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'MySQL FULLTEXT Support', 'miniload' ); ?></td>
					<td>
						<?php if ( $miniload_status_checks['fulltext_support'] === 'good' ) : ?>
							<span style="color: green;">✓ <?php esc_html_e( 'Supported', 'miniload' ); ?></span>
						<?php else : ?>
							<span style="color: orange;">⚠ <?php esc_html_e( 'Requires MySQL 5.6+', 'miniload' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'WooCommerce', 'miniload' ); ?></td>
					<td>
						<?php if ( $miniload_status_checks['woocommerce'] === 'good' ) : ?>
							<span style="color: green;">✓ <?php esc_html_e( 'Active', 'miniload' ); ?></span>
						<?php else : ?>
							<span style="color: red;">✗ <?php esc_html_e( 'Not Active', 'miniload' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'PHP Version', 'miniload' ); ?></td>
					<td>
						<?php echo esc_html( PHP_VERSION ); ?>
						<?php if ( $miniload_status_checks['php_version'] === 'good' ) : ?>
							<span style="color: green;">✓</span>
						<?php else : ?>
							<span style="color: orange;">⚠ <?php esc_html_e( 'Upgrade recommended', 'miniload' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
			</table>
		</div>
	</div>
</div>

<style>
.miniload-welcome-banner {
	background: #1a1a1a;
	color: white;
	padding: 30px;
	border-radius: 8px;
	margin-bottom: 30px;
}

.miniload-welcome-banner h2 {
	color: white;
	margin: 0 0 10px 0;
	font-size: 28px;
}

.miniload-welcome-banner p {
	font-size: 16px;
	margin: 0;
	opacity: 0.95;
}

.miniload-stat-meta {
	font-size: 12px;
	color: #999;
	margin-top: 5px;
}

.miniload-quick-actions {
	display: flex;
	gap: 15px;
	flex-wrap: wrap;
}

.miniload-quick-actions .button {
	display: inline-flex;
	align-items: center;
	gap: 5px;
}

.miniload-system-status table {
	border: 1px solid #e0e0e0;
}

.miniload-system-status td {
	padding: 12px;
}
</style>

<script>
jQuery(document).ready(function($) {
	// Test search speed
	$('#miniload-test-search').on('click', function() {
		var $btn = $(this);
		$btn.prop('disabled', true).text('Testing...');

		var startTime = performance.now();

		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: {
				action: 'miniload_ajax_search',
				term: 'test',
				nonce: '<?php echo esc_attr( wp_create_nonce( 'miniload_search_nonce' ) ); ?>'
			},
			success: function(response) {
				var endTime = performance.now();
				var duration = Math.round(endTime - startTime);
				alert('Search completed in ' + duration + 'ms');
			},
			complete: function() {
				$btn.prop('disabled', false).html('<span class="dashicons dashicons-search"></span> Test Search Speed');
			}
		});
	});
});
</script>