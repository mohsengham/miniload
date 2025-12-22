<?php
/**
 * Settings Tab Content
 *
 * @package MiniLoad
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<div class="miniload-settings">
	<!-- General Settings -->
	<div class="miniload-section">
		<h3 class="miniload-section-title">
			<span class="dashicons dashicons-admin-settings"></span>
			<?php esc_html_e( 'General Settings', 'miniload' ); ?>
		</h3>

		<table class="form-table">
			<tr>
				<th scope="row"><?php esc_html_e( 'Enable MiniLoad', 'miniload' ); ?></th>
				<td>
					<label class="miniload-toggle">
						<input type="checkbox" name="miniload_enabled" value="1"
							<?php checked( '1', get_option( 'miniload_enabled', '1' ) ); ?> />
						<span class="miniload-toggle-slider"></span>
					</label>
					<p class="description"><?php esc_html_e( 'Master switch to enable/disable all MiniLoad features', 'miniload' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Debug Mode', 'miniload' ); ?></th>
				<td>
					<label class="miniload-toggle">
						<input type="checkbox" name="miniload_debug_mode" value="1"
							<?php checked( '1', get_option( 'miniload_debug_mode', '0' ) ); ?> />
						<span class="miniload-toggle-slider"></span>
					</label>
					<p class="description"><?php esc_html_e( 'Enable debug logging for troubleshooting', 'miniload' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Load Priority', 'miniload' ); ?></th>
				<td>
					<input type="number" name="miniload_priority" value="<?php echo esc_attr( get_option( 'miniload_priority', 10 ) ); ?>"
						min="1" max="100" />
					<p class="description"><?php esc_html_e( 'Plugin load priority (lower numbers load first)', 'miniload' ); ?></p>
				</td>
			</tr>
		</table>
	</div>

	<!-- Performance Settings -->
	<div class="miniload-section">
		<h3 class="miniload-section-title">
			<span class="dashicons dashicons-performance"></span>
			<?php esc_html_e( 'Performance Settings', 'miniload' ); ?>
		</h3>

		<table class="form-table">
			<tr>
				<th scope="row"><?php esc_html_e( 'Cache Duration', 'miniload' ); ?></th>
				<td>
					<input type="number" name="miniload_cache_duration" value="<?php echo esc_attr( get_option( 'miniload_cache_duration', 3600 ) ); ?>"
						min="60" max="86400" step="60" />
					<p class="description"><?php esc_html_e( 'Cache duration in seconds (default: 3600 = 1 hour)', 'miniload' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Batch Processing Size', 'miniload' ); ?></th>
				<td>
					<input type="number" name="miniload_batch_size" value="<?php echo esc_attr( get_option( 'miniload_batch_size', 100 ) ); ?>"
						min="10" max="500" step="10" />
					<p class="description"><?php esc_html_e( 'Number of items to process per batch during indexing', 'miniload' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Memory Limit', 'miniload' ); ?></th>
				<td>
					<select name="miniload_memory_limit">
						<option value="default" <?php selected( get_option( 'miniload_memory_limit', 'default' ), 'default' ); ?>>
							<?php esc_html_e( 'Default', 'miniload' ); ?>
						</option>
						<option value="256M" <?php selected( get_option( 'miniload_memory_limit', 'default' ), '256M' ); ?>>
							256M
						</option>
						<option value="512M" <?php selected( get_option( 'miniload_memory_limit', 'default' ), '512M' ); ?>>
							512M
						</option>
						<option value="1024M" <?php selected( get_option( 'miniload_memory_limit', 'default' ), '1024M' ); ?>>
							1024M
						</option>
					</select>
					<p class="description"><?php esc_html_e( 'Memory limit for indexing operations', 'miniload' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Auto-index New Products', 'miniload' ); ?></th>
				<td>
					<label class="miniload-toggle">
						<input type="checkbox" name="miniload_auto_index" value="1"
							<?php checked( '1', get_option( 'miniload_auto_index', '1' ) ); ?> />
						<span class="miniload-toggle-slider"></span>
					</label>
					<p class="description"><?php esc_html_e( 'Automatically index new products when they are created', 'miniload' ); ?></p>
				</td>
			</tr>
		</table>
	</div>

	<!-- Advanced Settings -->
	<div class="miniload-section">
		<h3 class="miniload-section-title">
			<span class="dashicons dashicons-admin-tools"></span>
			<?php esc_html_e( 'Advanced Settings', 'miniload' ); ?>
		</h3>

		<table class="form-table">
			<tr>
				<th scope="row"><?php esc_html_e( 'Database Prefix', 'miniload' ); ?></th>
				<td>
					<input type="text" name="miniload_db_prefix" class="regular-text"
						value="<?php echo esc_attr( get_option( 'miniload_db_prefix', 'miniload_' ) ); ?>" readonly />
					<p class="description"><?php esc_html_e( 'Database table prefix (read-only)', 'miniload' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Uninstall Behavior', 'miniload' ); ?></th>
				<td>
					<select name="miniload_uninstall_behavior">
						<option value="keep" <?php selected( get_option( 'miniload_uninstall_behavior', 'keep' ), 'keep' ); ?>>
							<?php esc_html_e( 'Keep data and settings', 'miniload' ); ?>
						</option>
						<option value="remove" <?php selected( get_option( 'miniload_uninstall_behavior', 'keep' ), 'remove' ); ?>>
							<?php esc_html_e( 'Remove all data and settings', 'miniload' ); ?>
						</option>
					</select>
					<p class="description"><?php esc_html_e( 'What to do with data when plugin is uninstalled', 'miniload' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'REST API', 'miniload' ); ?></th>
				<td>
					<label class="miniload-toggle">
						<input type="checkbox" name="miniload_rest_api_enabled" value="1"
							<?php checked( '1', get_option( 'miniload_rest_api_enabled', '1' ) ); ?> />
						<span class="miniload-toggle-slider"></span>
					</label>
					<p class="description"><?php esc_html_e( 'Enable REST API endpoints for search', 'miniload' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'AJAX Nonce Lifetime', 'miniload' ); ?></th>
				<td>
					<select name="miniload_nonce_lifetime">
						<option value="1" <?php selected( get_option( 'miniload_nonce_lifetime', '1' ), '1' ); ?>>
							<?php esc_html_e( '1 day', 'miniload' ); ?>
						</option>
						<option value="2" <?php selected( get_option( 'miniload_nonce_lifetime', '1' ), '2' ); ?>>
							<?php esc_html_e( '2 days', 'miniload' ); ?>
						</option>
						<option value="7" <?php selected( get_option( 'miniload_nonce_lifetime', '1' ), '7' ); ?>>
							<?php esc_html_e( '1 week', 'miniload' ); ?>
						</option>
					</select>
					<p class="description"><?php esc_html_e( 'How long AJAX nonces remain valid', 'miniload' ); ?></p>
				</td>
			</tr>
		</table>
	</div>

	<!-- License & Support -->
	<div class="miniload-section">
		<h3 class="miniload-section-title">
			<span class="dashicons dashicons-admin-network"></span>
			<?php esc_html_e( 'License & Support', 'miniload' ); ?>
		</h3>

		<div class="miniload-support-info">
			<div class="miniload-info-card">
				<h4><?php esc_html_e( 'License', 'miniload' ); ?></h4>
				<p><?php esc_html_e( 'MiniLoad is released under the GPLv2 or later license.', 'miniload' ); ?></p>
				<p><?php esc_html_e( 'This is free software; you can redistribute it and/or modify it under the terms of the GNU General Public License.', 'miniload' ); ?></p>
			</div>

			<div class="miniload-info-card">
				<h4><?php esc_html_e( 'Support', 'miniload' ); ?></h4>
				<p><?php esc_html_e( 'Need help? Visit our support channels:', 'miniload' ); ?></p>
				<ul>
					<li><a href="https://github.com/mohsengham/miniload" target="_blank"><?php esc_html_e( 'GitHub Repository', 'miniload' ); ?></a></li>
					<li><a href="https://wordpress.org/support/plugin/miniload" target="_blank"><?php esc_html_e( 'WordPress.org Forums', 'miniload' ); ?></a></li>
					<li><a href="https://github.com/mohsengham/miniload/wiki" target="_blank"><?php esc_html_e( 'Documentation', 'miniload' ); ?></a></li>
				</ul>
			</div>

			<div class="miniload-info-card">
				<h4><?php esc_html_e( 'Credits', 'miniload' ); ?></h4>
				<p><?php esc_html_e( 'Developed by MiniMall Team', 'miniload' ); ?></p>
				<p><?php esc_html_e( 'Special thanks to all contributors and testers.', 'miniload' ); ?></p>
				<p><a href="https://github.com/mohsengham/miniload/graphs/contributors" target="_blank"><?php esc_html_e( 'View Contributors', 'miniload' ); ?></a></p>
			</div>
		</div>
	</div>

	<!-- Reset Settings -->
	<div class="miniload-section">
		<h3 class="miniload-section-title">
			<span class="dashicons dashicons-warning"></span>
			<?php esc_html_e( 'Danger Zone', 'miniload' ); ?>
		</h3>

		<div class="miniload-danger-zone">
			<p><?php esc_html_e( 'These actions are irreversible. Please proceed with caution.', 'miniload' ); ?></p>

			<button type="button" class="button button-link-delete" onclick="if(confirm('<?php esc_attr_e( 'Are you sure? This will reset all settings to defaults.', 'miniload' ); ?>')) { document.getElementById('reset-settings-form').submit(); }">
				<?php esc_html_e( 'Reset All Settings', 'miniload' ); ?>
			</button>

			<form id="reset-settings-form" method="post" action="" style="display:none;">
				<?php wp_nonce_field( 'miniload_reset_settings', 'miniload_reset_nonce' ); ?>
				<input type="hidden" name="miniload_reset_settings" value="1" />
			</form>
		</div>
	</div>
</div>

<style>
.miniload-support-info {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
	gap: 20px;
	margin-top: 20px;
}

.miniload-info-card {
	background: #fff;
	border: 1px solid #e0e0e0;
	border-radius: 8px;
	padding: 20px;
}

.miniload-info-card h4 {
	margin: 0 0 15px 0;
	color: #333;
	font-size: 16px;
}

.miniload-info-card p {
	color: #666;
	line-height: 1.6;
}

.miniload-info-card ul {
	list-style: none;
	padding: 0;
	margin: 10px 0 0 0;
}

.miniload-info-card ul li {
	padding: 5px 0;
}

.miniload-info-card a {
	color: #667eea;
	text-decoration: none;
}

.miniload-info-card a:hover {
	text-decoration: underline;
}

.miniload-danger-zone {
	background: #fff5f5;
	border: 1px solid #ffdddd;
	border-radius: 8px;
	padding: 20px;
}

.miniload-danger-zone p {
	color: #d63638;
	margin: 0 0 20px 0;
}

.button-link-delete {
	color: #d63638 !important;
}

.button-link-delete:hover {
	color: #a02222 !important;
}
</style>