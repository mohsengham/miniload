<?php
/**
 * Tools Tab Content
 *
 * @package MiniLoad
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Handle tool actions
if ( isset( $_GET['action'] ) && isset( $_GET['_wpnonce'] ) ) {
	if ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'miniload_tool_action' ) ) {
		$action = sanitize_text_field( wp_unslash( $_GET['action'] ) );
		$miniload_message = '';

		switch ( $action ) {
			case 'rebuild-search-index':
				// Rebuild search index
				$miniload_indexer = new \MiniLoad\Modules\Search_Indexer();
				$miniload_result = $miniload_indexer->rebuild_index();
				$miniload_message = sprintf(
					/* translators: %d: number of products indexed */
					__( 'Search index rebuilt successfully. %d products indexed.', 'miniload' ),
					$result
				);
				break;

			case 'rebuild-media-index':
				// Rebuild media index
				if ( class_exists( '\MiniLoad\Modules\Media_Search_Optimizer' ) ) {
					$miniload_media_optimizer = new \MiniLoad\Modules\Media_Search_Optimizer();
					$miniload_result = $miniload_media_optimizer->rebuild_index();
					$miniload_message = sprintf(
						/* translators: %d: number of media items indexed */
						__( 'Media index rebuilt successfully. %d items indexed.', 'miniload' ),
						$result
					);
				}
				break;

			case 'clear-cache':
				// Clear all caches
				wp_cache_flush();
				delete_transient( 'miniload_search_cache' );
				$miniload_message = __( 'All caches cleared successfully.', 'miniload' );
				break;

			case 'optimize-tables':
				// Optimize database tables
				global $wpdb;
				$miniload_tables = array(
					$wpdb->prefix . 'miniload_product_search',
					$wpdb->prefix . 'miniload_search_analytics',
					$wpdb->prefix . 'miniload_media_search'
				);

				foreach ( $tables as $miniload_table ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for performance optimization
					if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $miniload_table ) ) ) {
						$wpdb->query( "OPTIMIZE TABLE " . esc_sql( $miniload_table ) );
					}
				}
				$miniload_message = __( 'Database tables optimized successfully.', 'miniload' );
				break;

			case 'export-settings':
				// Export settings
				global $wpdb;
				$miniload_settings = array();
				// Direct database query with caching
		$miniload_cache_key = 'miniload_' . md5(  "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'miniload_%'"  );
		$miniload_cached = wp_cache_get( $miniload_cache_key );
		if ( false === $miniload_cached ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for performance optimization
			$miniload_cached = $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'miniload_%'" );
			wp_cache_set( $miniload_cache_key, $miniload_cached, '', 3600 );
		}
				foreach ( $miniload_cached as $miniload_option ) {
					$miniload_settings[$miniload_option->option_name] = maybe_unserialize( $miniload_option->option_value );
				}

				header( 'Content-Type: application/json' );
				header( 'Content-Disposition: attachment; filename="miniload-settings-' . gmdate('Y-m-d') . '.json"' );
				echo json_encode( $miniload_settings, JSON_PRETTY_PRINT );
				exit;
				break;
		}

		if ( $message ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
		}
	}
}
?>

<div class="miniload-tools">
	<!-- Index Management -->
	<div class="miniload-section">
		<h3 class="miniload-section-title">
			<span class="dashicons dashicons-database"></span>
			<?php esc_html_e( 'Index Management', 'miniload' ); ?>
		</h3>

		<div class="miniload-tools-grid">
			<div class="miniload-tool-card">
				<h4><?php esc_html_e( 'Product Search Index', 'miniload' ); ?></h4>
				<p><?php esc_html_e( 'Rebuild the FULLTEXT search index for all products.', 'miniload' ); ?></p>
				<?php
				global $wpdb;
				// Direct database query with caching
		$miniload_cache_key1 = 'miniload_' . md5(  "SELECT COUNT(*) FROM {$wpdb->prefix}miniload_product_search"  );
		$miniload_cached1 = wp_cache_get( $miniload_cache_key1 );
		if ( false === $miniload_cached1 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for performance optimization
			$miniload_cached1 = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}miniload_product_search" );
			wp_cache_set( $miniload_cache_key1, $miniload_cached1, '', 3600 );
		}
		$miniload_indexed = $miniload_cached1;
				// Direct database query with caching
		$miniload_cache_key2 = 'miniload_' . md5(  "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish'"  );
		$miniload_cached2 = wp_cache_get( $miniload_cache_key2 );
		if ( false === $miniload_cached2 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for performance optimization
			$miniload_cached2 = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish'" );
			wp_cache_set( $miniload_cache_key2, $miniload_cached2, '', 3600 );
		}
		$miniload_total = $miniload_cached2;
				?>
				<div class="miniload-tool-stats">
					<span><?php echo esc_html( sprintf(
						/* translators: %1$d: number of indexed products, %2$d: total number of products */
						__( '%1$d of %2$d products indexed', 'miniload' ),
						$miniload_indexed,
						$miniload_total
					) ); ?></span>
				</div>
				<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=miniload&tab=tools&action=rebuild-search-index' ), 'miniload_tool_action' ) ); ?>"
				   class="button button-primary">
					<span class="dashicons dashicons-update"></span>
					<?php esc_html_e( 'Rebuild Index', 'miniload' ); ?>
				</a>
			</div>

			<div class="miniload-tool-card">
				<h4><?php esc_html_e( 'Media Search Index', 'miniload' ); ?></h4>
				<p><?php esc_html_e( 'Rebuild the search index for media library items.', 'miniload' ); ?></p>
				<?php
				global $wpdb;
				$miniload_media_indexed = 0;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for performance optimization
				if ( $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}miniload_media_search'" ) ) {
					// Direct database query with caching
		$miniload_cache_key3 = 'miniload_' . md5(  "SELECT COUNT(*) FROM {$wpdb->prefix}miniload_media_search"  );
		$miniload_cached3 = wp_cache_get( $miniload_cache_key3 );
		if ( false === $miniload_cached3 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for performance optimization
			$miniload_cached3 = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}miniload_media_search" );
			wp_cache_set( $miniload_cache_key3, $miniload_cached3, '', 3600 );
		}
		$miniload_media_indexed = $miniload_cached3;
				}
				// Direct database query with caching
		$miniload_cache_key4 = 'miniload_' . md5(  "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment'"  );
		$miniload_cached4 = wp_cache_get( $miniload_cache_key4 );
		if ( false === $miniload_cached4 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for performance optimization
			$miniload_cached4 = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment'" );
			wp_cache_set( $miniload_cache_key4, $miniload_cached4, '', 3600 );
		}
		$miniload_media_total = $miniload_cached4;
				?>
				<div class="miniload-tool-stats">
					<span><?php echo esc_html( sprintf(
						/* translators: %1$d: number of indexed media items, %2$d: total number of media items */
						__( '%1$d of %2$d media items indexed', 'miniload' ),
						$miniload_media_indexed,
						$miniload_media_total
					) ); ?></span>
				</div>
				<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=miniload&tab=tools&action=rebuild-media-index' ), 'miniload_tool_action' ) ); ?>"
				   class="button">
					<span class="dashicons dashicons-update"></span>
					<?php esc_html_e( 'Rebuild Index', 'miniload' ); ?>
				</a>
			</div>
		</div>
	</div>

	<!-- Cache Management -->
	<div class="miniload-section">
		<h3 class="miniload-section-title">
			<span class="dashicons dashicons-backup"></span>
			<?php esc_html_e( 'Cache Management', 'miniload' ); ?>
		</h3>

		<div class="miniload-tools-grid">
			<div class="miniload-tool-card">
				<h4><?php esc_html_e( 'Clear All Caches', 'miniload' ); ?></h4>
				<p><?php esc_html_e( 'Clear all MiniLoad caches including search results and transients.', 'miniload' ); ?></p>
				<div class="miniload-tool-stats">
					<?php
					global $wpdb;
					$miniload_cache_size = 0;
					// Direct database query with caching
		$miniload_cache_key5 = 'miniload_' . md5(  "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_miniload_%'"  );
		$miniload_cached5 = wp_cache_get( $miniload_cache_key5 );
		if ( false === $miniload_cached5 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for performance optimization
			$miniload_cached5 = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_miniload_%'" );
			wp_cache_set( $miniload_cache_key5, $miniload_cached5, '', 3600 );
		}
		$miniload_cache_size = $miniload_cached5;
					?>
					<span><?php echo esc_html( sprintf(
						/* translators: %d: number of cached items */
						__( '%d cached items', 'miniload' ),
						$miniload_cache_size
					) ); ?></span>
				</div>
				<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=miniload&tab=tools&action=clear-cache' ), 'miniload_tool_action' ) ); ?>"
				   class="button button-secondary">
					<span class="dashicons dashicons-trash"></span>
					<?php esc_html_e( 'Clear Cache', 'miniload' ); ?>
				</a>
			</div>

			<div class="miniload-tool-card">
				<h4><?php esc_html_e( 'Optimize Database', 'miniload' ); ?></h4>
				<p><?php esc_html_e( 'Optimize MiniLoad database tables for better performance.', 'miniload' ); ?></p>
				<div class="miniload-tool-stats">
					<span><?php esc_html_e( 'Defragment and optimize tables', 'miniload' ); ?></span>
				</div>
				<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=miniload&tab=tools&action=optimize-tables' ), 'miniload_tool_action' ) ); ?>"
				   class="button">
					<span class="dashicons dashicons-admin-tools"></span>
					<?php esc_html_e( 'Optimize', 'miniload' ); ?>
				</a>
			</div>
		</div>
	</div>

	<!-- Import/Export -->
	<div class="miniload-section">
		<h3 class="miniload-section-title">
			<span class="dashicons dashicons-migrate"></span>
			<?php esc_html_e( 'Import/Export', 'miniload' ); ?>
		</h3>

		<div class="miniload-tools-grid">
			<div class="miniload-tool-card">
				<h4><?php esc_html_e( 'Export Settings', 'miniload' ); ?></h4>
				<p><?php esc_html_e( 'Download all MiniLoad settings as a JSON file.', 'miniload' ); ?></p>
				<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=miniload&tab=tools&action=export-settings' ), 'miniload_tool_action' ) ); ?>"
				   class="button">
					<span class="dashicons dashicons-download"></span>
					<?php esc_html_e( 'Export Settings', 'miniload' ); ?>
				</a>
			</div>

			<div class="miniload-tool-card">
				<h4><?php esc_html_e( 'Import Settings', 'miniload' ); ?></h4>
				<p><?php esc_html_e( 'Import MiniLoad settings from a JSON file.', 'miniload' ); ?></p>
				<form method="post" enctype="multipart/form-data" action="">
					<?php wp_nonce_field( 'miniload_import_settings', 'miniload_import_nonce' ); ?>
					<input type="file" name="miniload_import_file" accept=".json" />
					<button type="submit" name="miniload_import" class="button">
						<span class="dashicons dashicons-upload"></span>
						<?php esc_html_e( 'Import Settings', 'miniload' ); ?>
					</button>
				</form>
			</div>
		</div>
	</div>

	<!-- Diagnostics -->
	<div class="miniload-section">
		<h3 class="miniload-section-title">
			<span class="dashicons dashicons-info"></span>
			<?php esc_html_e( 'Diagnostics', 'miniload' ); ?>
		</h3>

		<div class="miniload-diagnostics">
			<table class="widefat striped">
				<tbody>
					<tr>
						<td><?php esc_html_e( 'Plugin Version', 'miniload' ); ?></td>
						<td><?php echo esc_html( MINILOAD_VERSION ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'WordPress Version', 'miniload' ); ?></td>
						<td><?php echo esc_html( get_bloginfo( 'version' ) ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'WooCommerce Version', 'miniload' ); ?></td>
						<td><?php echo defined( 'WC_VERSION' ) ? esc_html( WC_VERSION ) : esc_html__( 'Not Active', 'miniload' ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'PHP Version', 'miniload' ); ?></td>
						<td><?php echo esc_html( PHP_VERSION ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'MySQL Version', 'miniload' ); ?></td>
						<td><?php global $wpdb; echo esc_html( $wpdb->db_version() ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'FULLTEXT Support', 'miniload' ); ?></td>
						<td>
							<?php if ( version_compare( $wpdb->db_version(), '5.6', '>=' ) ) : ?>
								<span style="color: green;">✓ <?php esc_html_e( 'Supported', 'miniload' ); ?></span>
							<?php else : ?>
								<span style="color: red;">✗ <?php esc_html_e( 'Not Supported', 'miniload' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Memory Limit', 'miniload' ); ?></td>
						<td><?php echo esc_html( WP_MEMORY_LIMIT ); ?></td>
					</tr>
				</tbody>
			</table>
		</div>
	</div>
</div>

<style>
.miniload-tools-grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
	gap: 20px;
	margin-top: 20px;
}

.miniload-tool-card {
	background: #fff;
	border: 1px solid #e0e0e0;
	border-radius: 8px;
	padding: 25px;
}

.miniload-tool-card h4 {
	margin: 0 0 10px 0;
	font-size: 16px;
	font-weight: 600;
	color: #333;
}

.miniload-tool-card p {
	color: #666;
	margin: 0 0 15px 0;
}

.miniload-tool-stats {
	background: #f8f9fa;
	padding: 10px 15px;
	border-radius: 4px;
	margin-bottom: 15px;
	font-size: 13px;
	color: #555;
}

.miniload-tool-card .button {
	display: inline-flex;
	align-items: center;
	gap: 5px;
}

.miniload-diagnostics {
	background: #fff;
	padding: 20px;
	border-radius: 8px;
}

.miniload-diagnostics table {
	border: none;
}

.miniload-diagnostics td:first-child {
	font-weight: 600;
	width: 200px;
}
</style>