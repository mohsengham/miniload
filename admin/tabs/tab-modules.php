<?php
/**
 * Modules Tab Content
 *
 * @package MiniLoad
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<div class="miniload-modules">
	<!-- Core Modules -->
	<div class="miniload-section">
		<h3 class="miniload-section-title">
			<span class="dashicons dashicons-admin-plugins"></span>
			<?php esc_html_e( 'Core Modules', 'miniload' ); ?>
		</h3>

		<div class="miniload-modules-grid">
			<!-- AJAX Search Module -->
			<div class="miniload-module-card">
				<div class="miniload-module-header">
					<span class="dashicons dashicons-search"></span>
					<h4><?php esc_html_e( 'AJAX Search', 'miniload' ); ?></h4>
					<label class="miniload-toggle">
						<input type="checkbox" name="miniload_ajax_search_module" value="1"
							<?php checked( '1', get_option( 'miniload_ajax_search_enabled', '1' ) ); ?> />
						<span class="miniload-toggle-slider"></span>
					</label>
				</div>
				<p><?php esc_html_e( 'Real-time product search with instant results. Uses FULLTEXT indexing for lightning-fast performance.', 'miniload' ); ?></p>
				<div class="miniload-module-meta">
					<span class="dashicons dashicons-performance"></span> 5-10x faster
					<span class="dashicons dashicons-database"></span> Indexed
				</div>
			</div>

			<!-- Admin Quick Search Module -->
			<div class="miniload-module-card">
				<div class="miniload-module-header">
					<span class="dashicons dashicons-dashboard"></span>
					<h4><?php esc_html_e( 'Admin Quick Search', 'miniload' ); ?></h4>
					<label class="miniload-toggle">
						<input type="checkbox" name="miniload_admin_search_enabled" value="1"
							<?php checked( '1', get_option( 'miniload_admin_search_enabled', '1' ) ); ?> />
						<span class="miniload-toggle-slider"></span>
					</label>
				</div>
				<p><?php esc_html_e( 'Quick admin search modal (Alt+K) for products, posts, orders, and customers.', 'miniload' ); ?></p>
				<div class="miniload-module-meta">
					<span class="dashicons dashicons-admin-network"></span> Alt+K shortcut
					<span class="dashicons dashicons-category"></span> Tabbed interface
				</div>
			</div>

			<!-- Media Search Optimizer -->
			<div class="miniload-module-card">
				<div class="miniload-module-header">
					<span class="dashicons dashicons-images-alt2"></span>
					<h4><?php esc_html_e( 'Media Search', 'miniload' ); ?></h4>
					<label class="miniload-toggle">
						<input type="checkbox" name="miniload_media_search_enabled" value="1"
							<?php checked( '1', get_option( 'miniload_media_search_enabled', '0' ) ); ?> />
						<span class="miniload-toggle-slider"></span>
					</label>
				</div>
				<p><?php esc_html_e( 'Accelerates WordPress media library search with indexed alt text, captions, and EXIF data.', 'miniload' ); ?></p>
				<div class="miniload-module-meta">
					<span class="dashicons dashicons-media-document"></span> Alt text
					<span class="dashicons dashicons-camera-alt"></span> EXIF data
				</div>
			</div>

			<!-- Editor Link Optimizer -->
			<div class="miniload-module-card">
				<div class="miniload-module-header">
					<span class="dashicons dashicons-admin-links"></span>
					<h4><?php esc_html_e( 'Editor Link Builder', 'miniload' ); ?></h4>
					<label class="miniload-toggle">
						<input type="checkbox" name="miniload_editor_link_enabled" value="1"
							<?php checked( '1', get_option( 'miniload_editor_link_enabled', '0' ) ); ?> />
						<span class="miniload-toggle-slider"></span>
					</label>
				</div>
				<p><?php esc_html_e( 'Enhances WordPress editor link search with product results using FULLTEXT index.', 'miniload' ); ?></p>
				<div class="miniload-module-meta">
					<span class="dashicons dashicons-edit"></span> Editor integration
					<span class="dashicons dashicons-products"></span> Product links
				</div>
			</div>
		</div>
	</div>

	<!-- Performance Modules -->
	<div class="miniload-section">
		<h3 class="miniload-section-title">
			<span class="dashicons dashicons-performance"></span>
			<?php esc_html_e( 'Performance Modules', 'miniload' ); ?>
		</h3>

		<div class="miniload-modules-grid">
			<!-- Query Optimizer -->
			<div class="miniload-module-card">
				<div class="miniload-module-header">
					<span class="dashicons dashicons-database"></span>
					<h4><?php esc_html_e( 'Query Optimizer', 'miniload' ); ?></h4>
					<label class="miniload-toggle">
						<input type="checkbox" name="miniload_query_optimizer_enabled" value="1"
							<?php checked( '1', get_option( 'miniload_query_optimizer_enabled', '1' ) ); ?> />
						<span class="miniload-toggle-slider"></span>
					</label>
				</div>
				<p><?php esc_html_e( 'Optimizes WooCommerce database queries for faster page loads.', 'miniload' ); ?></p>
				<div class="miniload-module-meta">
					<span class="dashicons dashicons-chart-line"></span> 30-50% faster
					<span class="dashicons dashicons-filter"></span> Smart filtering
				</div>
			</div>

			<!-- Cache Manager -->
			<div class="miniload-module-card">
				<div class="miniload-module-header">
					<span class="dashicons dashicons-backup"></span>
					<h4><?php esc_html_e( 'Cache Manager', 'miniload' ); ?></h4>
					<label class="miniload-toggle">
						<input type="checkbox" name="miniload_cache_enabled" value="1"
							<?php checked( '1', get_option( 'miniload_cache_enabled', '1' ) ); ?> />
						<span class="miniload-toggle-slider"></span>
					</label>
				</div>
				<p><?php esc_html_e( 'Intelligent caching system for search results and product data.', 'miniload' ); ?></p>
				<div class="miniload-module-meta">
					<span class="dashicons dashicons-clock"></span> Auto-expire
					<span class="dashicons dashicons-update"></span> Smart invalidation
				</div>
			</div>

		</div>
	</div>

	<!-- Single Product Optimization Modules -->
	<div class="miniload-section">
		<h3 class="miniload-section-title">
			<span class="dashicons dashicons-products"></span>
			<?php esc_html_e( 'Single Product Optimization', 'miniload' ); ?>
		</h3>

		<div class="miniload-modules-grid">
			<!-- Related Products Cache -->
			<div class="miniload-module-card">
				<div class="miniload-module-header">
					<span class="dashicons dashicons-networking"></span>
					<h4><?php esc_html_e( 'Related Products Cache', 'miniload' ); ?></h4>
					<label class="miniload-toggle">
						<input type="checkbox" name="miniload_modules[related_products_cache]" value="1"
							<?php checked( ! isset( $miniload_settings['modules']['related_products_cache'] ) || $miniload_settings['modules']['related_products_cache'] !== false ); ?>>
						<span class="miniload-toggle-slider"></span>
					</label>
				</div>
				<p><?php esc_html_e( 'Caches related products, upsells, and cross-sells to speed up single product pages.', 'miniload' ); ?></p>
				<div class="miniload-module-meta">
					<span class="dashicons dashicons-clock"></span> 24hr cache
					<span class="dashicons dashicons-performance"></span> Fast lookups
				</div>
			</div>

			<!-- Review Stats Cache -->
			<div class="miniload-module-card">
				<div class="miniload-module-header">
					<span class="dashicons dashicons-star-filled"></span>
					<h4><?php esc_html_e( 'Review Stats Cache', 'miniload' ); ?></h4>
					<label class="miniload-toggle">
						<input type="checkbox" name="miniload_modules[review_stats_cache]" value="1"
							<?php checked( ! isset( $miniload_settings['modules']['review_stats_cache'] ) || $miniload_settings['modules']['review_stats_cache'] !== false ); ?>>
						<span class="miniload-toggle-slider"></span>
					</label>
				</div>
				<p><?php esc_html_e( 'Pre-calculates and caches product ratings and review counts for instant display.', 'miniload' ); ?></p>
				<div class="miniload-module-meta">
					<span class="dashicons dashicons-database"></span> Indexed table
					<span class="dashicons dashicons-update"></span> Auto-updates
				</div>
			</div>

		</div>
	</div>

</div>

<style>
.miniload-modules-grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
	gap: 20px;
	margin-top: 20px;
}

.miniload-module-card {
	background: #fff;
	border: 1px solid #e0e0e0;
	border-radius: 8px;
	padding: 20px;
	transition: all 0.3s ease;
}

.miniload-module-card:hover {
	box-shadow: 0 4px 12px rgba(0,0,0,0.08);
	transform: translateY(-2px);
}

.miniload-module-header {
	display: flex;
	align-items: center;
	margin-bottom: 15px;
	gap: 10px;
}

.miniload-module-header .dashicons {
	font-size: 24px;
	color: #1a1a1a;
}

.miniload-module-header h4 {
	flex: 1;
	margin: 0;
	font-size: 16px;
	font-weight: 600;
}

.miniload-module-card p {
	color: #666;
	margin: 0 0 15px 0;
	line-height: 1.5;
}

.miniload-module-meta {
	display: flex;
	gap: 15px;
	flex-wrap: wrap;
	font-size: 12px;
	color: #999;
	padding-top: 15px;
	border-top: 1px solid #f0f0f0;
}

.miniload-module-meta .dashicons {
	font-size: 14px;
	width: 14px;
	height: 14px;
	margin-right: 3px;
}
</style>