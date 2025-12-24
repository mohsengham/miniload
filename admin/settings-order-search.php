<?php
/**
 * Order Search Settings Page
 *
 * @package MiniLoad
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

// Get order search optimizer instance
$optimizer = null;
if ( class_exists( '\MiniLoad\Modules\Order_Search_Optimizer' ) ) {
	$optimizer = new \MiniLoad\Modules\Order_Search_Optimizer();
}

// Get stats
$stats = $optimizer ? $optimizer->get_stats() : array();

// Get total orders count (excluding auto-drafts)
// Try using direct database query for reliable count
global $wpdb;
if ( class_exists( 'Automattic\WooCommerce\Utilities\OrderUtil' ) &&
     Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
	// HPOS is enabled - count from custom table
	$table_name = $wpdb->prefix . 'wc_orders';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$total_orders = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name} WHERE type = 'shop_order'" );
} else {
	// Legacy mode - count all WooCommerce orders (statuses starting with 'wc-')
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	$total_orders = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s
		AND post_status LIKE 'wc-%%'",
		'shop_order'
	) );
}

$indexed_orders = isset( $stats['indexed_orders'] ) ? $stats['indexed_orders'] : 0;
$coverage = $total_orders > 0 ? round( ( $indexed_orders / $total_orders ) * 100, 1 ) : 0;

// Check if HPOS is enabled
$hpos_enabled = class_exists( 'Automattic\WooCommerce\Utilities\OrderUtil' ) &&
                Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
?>

<div class="miniload-order-search-settings">
	<!-- Order Search Status -->
	<div class="miniload-section">
		<h3 class="miniload-section-title">
			<span class="dashicons dashicons-chart-area"></span>
			<?php _e( 'Order Search Status', 'miniload' ); ?>
		</h3>

		<div class="miniload-stats-grid">
			<div class="miniload-stat-card">
				<div class="miniload-stat-value"><?php echo number_format( $indexed_orders ); ?></div>
				<div class="miniload-stat-label"><?php _e( 'Indexed Orders', 'miniload' ); ?></div>
			</div>
			<div class="miniload-stat-card">
				<div class="miniload-stat-value"><?php echo number_format( $total_orders ); ?></div>
				<div class="miniload-stat-label"><?php _e( 'Total Orders', 'miniload' ); ?></div>
			</div>
			<div class="miniload-stat-card">
				<div class="miniload-stat-value"><?php echo $coverage; ?>%</div>
				<div class="miniload-stat-label"><?php _e( 'Coverage', 'miniload' ); ?></div>
			</div>
			<?php if ( $hpos_enabled ) : ?>
			<div class="miniload-stat-card miniload-stat-card-success">
				<div class="miniload-stat-value"><span class="dashicons dashicons-yes-alt"></span></div>
				<div class="miniload-stat-label"><?php _e( 'HPOS Enabled', 'miniload' ); ?></div>
			</div>
			<?php endif; ?>
		</div>

		<?php if ( $coverage < 100 ) : ?>
		<div class="notice notice-warning inline">
			<p>
				<?php printf(
					__( '<strong>Index Incomplete:</strong> Only %d%% of orders are indexed. Click "Rebuild Order Index" below for optimal search performance.', 'miniload' ),
					$coverage
				); ?>
			</p>
		</div>
		<?php else : ?>
		<div class="notice notice-success inline">
			<p>
				<span class="dashicons dashicons-yes-alt"></span>
				<?php _e( '<strong>Index Complete:</strong> All orders are indexed and searchable!', 'miniload' ); ?>
			</p>
		</div>
		<?php endif; ?>
	</div>

	<!-- Index Management -->
	<div class="miniload-section">
		<h3 class="miniload-section-title">
			<span class="dashicons dashicons-admin-tools"></span>
			<?php _e( 'Index Management', 'miniload' ); ?>
		</h3>

		<!-- Search Settings -->
		<div class="miniload-settings-group">
			<label for="miniload_order_search_limit">
				<?php _e( 'Maximum Search Results', 'miniload' ); ?>
			</label>
			<input type="number"
				   id="miniload_order_search_limit"
				   name="miniload_order_search_limit"
				   value="<?php echo esc_attr( get_option( 'miniload_order_search_limit', 5000 ) ); ?>"
				   min="100"
				   max="999999"
				   step="100" />
			<p class="description">
				<?php _e( 'Maximum number of orders to return in search results. Higher values may slow down the search. Default: 5000', 'miniload' ); ?>
			</p>
			<button type="button" class="button button-secondary" id="save-search-limit">
				<?php _e( 'Save Limit', 'miniload' ); ?>
			</button>
		</div>

		<div id="order-index-progress" style="display:none; margin: 20px 0;">
			<p><strong><?php _e( 'Rebuilding Order Search Index...', 'miniload' ); ?></strong></p>
			<div class="miniload-progress-bar">
				<div id="order-index-progress-bar" class="miniload-progress-bar-fill">
					<span class="miniload-progress-text">0%</span>
				</div>
			</div>
			<p id="order-index-status" class="description"></p>
		</div>

		<p>
			<button type="button" id="rebuild-order-index" class="button button-primary">
				<span class="dashicons dashicons-update" style="vertical-align: text-bottom;"></span>
				<?php _e( 'Rebuild Order Index', 'miniload' ); ?>
			</button>
		</p>
		<p class="description">
			<?php _e( 'Rebuilds the search index for all orders. Run this if search results seem incomplete or after importing orders.', 'miniload' ); ?>
		</p>
	</div>

	<!-- Search Configuration -->
	<div class="miniload-section">
		<h3 class="miniload-section-title">
			<span class="dashicons dashicons-admin-settings"></span>
			<?php _e( 'Search Configuration', 'miniload' ); ?>
		</h3>

		<table class="form-table">
			<tr>
				<th scope="row"><?php _e( 'Enable Order Search', 'miniload' ); ?></th>
				<td>
					<label class="miniload-toggle">
						<input type="checkbox" name="miniload_enable_order_search" value="1"
							<?php checked( get_option( 'miniload_enable_order_search', '1' ), '1' ); ?>>
						<span class="miniload-toggle-slider"></span>
					</label>
					<p class="description">
						<?php _e( 'Enable ultra-fast order search optimization', 'miniload' ); ?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php _e( 'Auto-index New Orders', 'miniload' ); ?></th>
				<td>
					<label class="miniload-toggle">
						<input type="checkbox" name="miniload_auto_index_orders" value="1"
							<?php checked( get_option( 'miniload_auto_index_orders', '1' ), '1' ); ?>>
						<span class="miniload-toggle-slider"></span>
					</label>
					<p class="description">
						<?php _e( 'Automatically index new and updated orders for instant search', 'miniload' ); ?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php _e( 'Search Method', 'miniload' ); ?></th>
				<td>
					<select name="miniload_order_search_method" class="regular-text">
						<option value="trigram" <?php selected( get_option( 'miniload_order_search_method', 'trigram' ), 'trigram' ); ?>>
							<?php _e( 'Trigram (Fastest, supports partial matching)', 'miniload' ); ?>
						</option>
						<option value="fulltext" <?php selected( get_option( 'miniload_order_search_method', 'trigram' ), 'fulltext' ); ?>>
							<?php _e( 'Fulltext (Fast, exact words)', 'miniload' ); ?>
						</option>
						<option value="hybrid" <?php selected( get_option( 'miniload_order_search_method', 'trigram' ), 'hybrid' ); ?>>
							<?php _e( 'Hybrid (Best accuracy)', 'miniload' ); ?>
						</option>
					</select>
					<p class="description">
						<?php _e( 'Choose the search algorithm. Trigram is recommended for best performance.', 'miniload' ); ?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php _e( 'Index Batch Size', 'miniload' ); ?></th>
				<td>
					<input type="number" name="miniload_order_index_batch_size"
						value="<?php echo esc_attr( get_option( 'miniload_order_index_batch_size', '100' ) ); ?>"
						min="10" max="1000" step="10" class="small-text">
					<p class="description">
						<?php _e( 'Number of orders to process per batch when rebuilding index. Higher values are faster but use more memory.', 'miniload' ); ?>
					</p>
				</td>
			</tr>
		</table>
	</div>

	<!-- Features -->
	<div class="miniload-section">
		<h3 class="miniload-section-title">
			<span class="dashicons dashicons-star-filled"></span>
			<?php _e( 'Features', 'miniload' ); ?>
		</h3>

		<div class="miniload-features-grid">
			<div class="miniload-feature">
				<span class="dashicons dashicons-performance"></span>
				<h4><?php _e( 'Lightning Fast', 'miniload' ); ?></h4>
				<p><?php _e( 'Searches complete in milliseconds, even with millions of orders', 'miniload' ); ?></p>
			</div>
			<div class="miniload-feature">
				<span class="dashicons dashicons-search"></span>
				<h4><?php _e( 'Multi-field Search', 'miniload' ); ?></h4>
				<p><?php _e( 'Search by order #, email, phone, SKU, customer name, and more', 'miniload' ); ?></p>
			</div>
			<div class="miniload-feature">
				<span class="dashicons dashicons-format-aside"></span>
				<h4><?php _e( 'Partial Matching', 'miniload' ); ?></h4>
				<p><?php _e( 'Find orders with incomplete information using trigram matching', 'miniload' ); ?></p>
			</div>
			<div class="miniload-feature">
				<span class="dashicons dashicons-products"></span>
				<h4><?php _e( 'Product Search', 'miniload' ); ?></h4>
				<p><?php _e( 'Find orders containing specific products by SKU or name', 'miniload' ); ?></p>
			</div>
			<div class="miniload-feature">
				<span class="dashicons dashicons-update"></span>
				<h4><?php _e( 'Auto-indexing', 'miniload' ); ?></h4>
				<p><?php _e( 'New orders are indexed automatically in real-time', 'miniload' ); ?></p>
			</div>
			<div class="miniload-feature">
				<span class="dashicons dashicons-database"></span>
				<h4><?php _e( 'HPOS Compatible', 'miniload' ); ?></h4>
				<p><?php _e( 'Works seamlessly with High-Performance Order Storage', 'miniload' ); ?></p>
			</div>
		</div>
	</div>
</div>

<script>
jQuery(document).ready(function($) {
	// Save search limit
	$('#save-search-limit').on('click', function(e) {
		e.preventDefault();
		var $button = $(this);
		var limit = $('#miniload_order_search_limit').val();

		$button.prop('disabled', true).text('<?php _e( 'Saving...', 'miniload' ); ?>');

		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: {
				action: 'miniload_save_settings',
				nonce: '<?php echo wp_create_nonce( 'miniload-settings' ); ?>',
				settings: {
					'miniload_order_search_limit': limit
				}
			},
			success: function(response) {
				$button.text('<?php _e( 'Saved!', 'miniload' ); ?>').addClass('button-primary');

				// Show success notification
				var $notification = $('<div class="notice notice-success is-dismissible" style="margin-top: 10px;"><p><strong><?php _e( 'Success!', 'miniload' ); ?></strong> <?php _e( 'Order search limit updated to', 'miniload' ); ?> ' + limit + ' <?php _e( 'orders', 'miniload' ); ?>.</p></div>');
				$button.closest('.miniload-settings-group').after($notification);

				// Auto-dismiss after 5 seconds
				setTimeout(function() {
					$notification.fadeOut(300, function() { $(this).remove(); });
				}, 5000);

				setTimeout(function() {
					$button.prop('disabled', false).text('<?php _e( 'Save Limit', 'miniload' ); ?>').removeClass('button-primary');
				}, 2000);
			},
			error: function() {
				$button.prop('disabled', false).text('<?php _e( 'Error!', 'miniload' ); ?>');

				// Show error notification
				var $notification = $('<div class="notice notice-error is-dismissible" style="margin-top: 10px;"><p><strong><?php _e( 'Error!', 'miniload' ); ?></strong> <?php _e( 'Failed to update order search limit. Please try again.', 'miniload' ); ?></p></div>');
				$button.closest('.miniload-settings-group').after($notification);

				// Auto-dismiss after 5 seconds
				setTimeout(function() {
					$notification.fadeOut(300, function() { $(this).remove(); });
				}, 5000);

				setTimeout(function() {
					$button.text('<?php _e( 'Save Limit', 'miniload' ); ?>');
				}, 2000);
			}
		});
	});

	// Rebuild Order Index
	$('#rebuild-order-index').on('click', function() {
		var $button = $(this);
		var $progress = $('#order-index-progress');
		var $progressBar = $('#order-index-progress-bar');
		var $progressText = $progressBar.find('.miniload-progress-text');
		var $status = $('#order-index-status');

		$button.prop('disabled', true);
		$progress.slideDown();

		function processOrderBatch(offset) {
			offset = offset || 0;
			console.log('Processing batch with offset:', offset);

			$.ajax({
				url: ajaxurl,
				type: 'POST',
				data: {
					action: 'miniload_rebuild_order_index',
					nonce: '<?php echo wp_create_nonce( 'miniload-ajax' ); ?>',
					offset: offset
				},
				success: function(response) {
					console.log('Rebuild response:', response);
					if (response.success) {
						if (response.data.completed) {
							console.log('Rebuild completed');
							$progressBar.css('width', '100%');
							$progressText.text('100%');
							$status.html('<span style="color: #46b450;">✓ ' + response.data.message + '</span>');
							$button.prop('disabled', false);

							// Reload page after 2 seconds to show updated stats
							setTimeout(function() {
								location.reload();
							}, 2000);
						} else {
							var progress = response.data.progress || 0;
							var totalProcessed = response.data.total_processed || response.data.next_offset || offset;
							var total = response.data.total || 0;
							console.log('Continuing batch - Progress:', progress + '%, Next offset:', response.data.next_offset, 'Total processed:', totalProcessed);
							$progressBar.css('width', progress + '%');
							$progressText.text(progress + '%');
							$status.text('Processed ' + totalProcessed.toLocaleString() + ' of ' + total.toLocaleString() + ' orders...');

							// Continue with next batch
							if (response.data.next_offset !== undefined) {
								processOrderBatch(response.data.next_offset);
							} else {
								console.error('Missing next_offset in response');
								$status.html('<span style="color: #dc3232;">Error: Missing next_offset in response</span>');
								$button.prop('disabled', false);
							}
						}
					} else {
						$status.html('<span style="color: #dc3232;">Error: ' + (response.data.message || 'Unknown error') + '</span>');
						$button.prop('disabled', false);
					}
				},
				error: function(xhr, status, error) {
					console.error('AJAX Error:', status, error);
					console.error('Response:', xhr.responseText);
					var errorMsg = 'Failed to rebuild index';
					if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
						errorMsg = xhr.responseJSON.data.message;
					} else if (xhr.responseText) {
						errorMsg += ' (Check console for details)';
					}
					$status.html('<span style="color: #dc3232;">Error: ' + errorMsg + '</span>');
					$button.prop('disabled', false);
				}
			});
		}

		processOrderBatch(0);
	});
});
</script>

<style>
.miniload-stats-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
	gap: 20px;
	margin: 20px 0;
}

.miniload-stat-card {
	background: #f8f9fa;
	padding: 20px;
	border-radius: 8px;
	text-align: center;
	border: 1px solid #e1e4e8;
}

.miniload-stat-card-success {
	background: #d4edda;
	border-color: #c3e6cb;
}

.miniload-stat-value {
	font-size: 28px;
	font-weight: bold;
	color: #2c3e50;
	margin-bottom: 5px;
}

.miniload-stat-label {
	font-size: 12px;
	text-transform: uppercase;
	color: #6c757d;
}

.miniload-progress-bar {
	background: #f0f0f1;
	border-radius: 4px;
	overflow: hidden;
	height: 30px;
	position: relative;
}

.miniload-progress-bar-fill {
	background: linear-gradient(90deg, #3858e9, #6673fc);
	height: 100%;
	width: 0%;
	transition: width 0.3s ease;
	display: flex;
	align-items: center;
	justify-content: center;
}

.miniload-progress-text {
	color: white;
	font-weight: bold;
	font-size: 12px;
}

.miniload-features-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
	gap: 20px;
	margin-top: 20px;
}

.miniload-feature {
	padding: 15px;
	background: #f8f9fa;
	border-radius: 8px;
	border: 1px solid #e1e4e8;
}

.miniload-feature .dashicons {
	font-size: 24px;
	width: 24px;
	height: 24px;
	color: #3858e9;
	margin-bottom: 10px;
}

.miniload-feature h4 {
	margin: 10px 0 5px;
	font-size: 14px;
	font-weight: 600;
}

.miniload-feature p {
	margin: 0;
	font-size: 13px;
	color: #666;
}
</style>