<?php
/**
 * Search Settings Tab Content
 *
 * @package MiniLoad
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<div class="miniload-search-settings">
	<!-- Search Configuration -->
	<div class="miniload-section">
		<h3 class="miniload-section-title">
			<span class="dashicons dashicons-search"></span>
			<?php esc_html_e( 'Search Configuration', 'miniload' ); ?>
		</h3>

		<table class="form-table">
			<tr>
				<th scope="row"><?php esc_html_e( 'Enable AJAX Search', 'miniload' ); ?></th>
				<td>
					<label class="miniload-toggle">
						<input type="checkbox" name="miniload_ajax_search_enabled" value="1"
							<?php checked( '1', get_option( 'miniload_ajax_search_enabled', '1' ) ); ?> />
						<span class="miniload-toggle-slider"></span>
					</label>
					<p class="description"><?php esc_html_e( 'Enable real-time AJAX search with instant results', 'miniload' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Minimum Characters', 'miniload' ); ?></th>
				<td>
					<input type="number" name="miniload_search_min_chars"
						value="<?php echo esc_attr( get_option( 'miniload_search_min_chars', 3 ) ); ?>"
						min="1" max="10" />
					<p class="description"><?php esc_html_e( 'Minimum characters required to trigger search', 'miniload' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Search Delay (ms)', 'miniload' ); ?></th>
				<td>
					<input type="number" name="miniload_search_delay"
						value="<?php echo esc_attr( get_option( 'miniload_search_delay', 300 ) ); ?>"
						min="100" max="2000" step="100" />
					<p class="description"><?php esc_html_e( 'Delay before search triggers after typing stops', 'miniload' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Results Per Page', 'miniload' ); ?></th>
				<td>
					<input type="number" name="miniload_search_results_count"
						value="<?php echo esc_attr( get_option( 'miniload_search_results_count', 8 ) ); ?>"
						min="4" max="20" />
					<p class="description"><?php esc_html_e( 'Number of products to show in search results', 'miniload' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Search In Product Content', 'miniload' ); ?></th>
				<td>
					<label class="miniload-toggle">
						<input type="checkbox" name="miniload_search_in_content" value="1"
							<?php checked( '1', get_option( 'miniload_search_in_content', '1' ) ); ?> />
						<span class="miniload-toggle-slider"></span>
					</label>
					<p class="description"><?php esc_html_e( 'Include full product descriptions in search. Uncheck for better performance.', 'miniload' ); ?></p>
				</td>
			</tr>
		</table>
	</div>

	<!-- Search Fields -->
	<div class="miniload-section">
		<h3 class="miniload-section-title">
			<span class="dashicons dashicons-editor-ul"></span>
			<?php esc_html_e( 'Search Fields', 'miniload' ); ?>
		</h3>

		<table class="form-table">
			<tr>
				<th scope="row"><?php esc_html_e( 'Search In', 'miniload' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="miniload_search_in_title" value="1"
							<?php checked( '1', get_option( 'miniload_search_in_title', '1' ) ); ?> />
						<?php esc_html_e( 'Product Title', 'miniload' ); ?>
					</label><br>
					<label>
						<input type="checkbox" name="miniload_search_in_sku" value="1"
							<?php checked( '1', get_option( 'miniload_search_in_sku', '1' ) ); ?> />
						<?php esc_html_e( 'SKU', 'miniload' ); ?>
					</label><br>
					<label>
						<input type="checkbox" name="miniload_search_in_short_desc" value="1"
							<?php checked( '1', get_option( 'miniload_search_in_short_desc', '1' ) ); ?> />
						<?php esc_html_e( 'Short Description', 'miniload' ); ?>
					</label><br>
					<label>
						<input type="checkbox" name="miniload_search_in_categories" value="1"
							<?php checked( '1', get_option( 'miniload_search_in_categories', '1' ) ); ?> />
						<?php esc_html_e( 'Categories', 'miniload' ); ?>
					</label><br>
					<label>
						<input type="checkbox" name="miniload_search_in_tags" value="1"
							<?php checked( '1', get_option( 'miniload_search_in_tags', '1' ) ); ?> />
						<?php esc_html_e( 'Tags', 'miniload' ); ?>
					</label>
				</td>
			</tr>
		</table>
	</div>

	<!-- Search Box Appearance -->
	<div class="miniload-section">
		<h3 class="miniload-section-title">
			<span class="dashicons dashicons-admin-appearance"></span>
			<?php esc_html_e( 'Search Box Appearance', 'miniload' ); ?>
		</h3>

		<table class="form-table">
			<tr>
				<th scope="row"><?php esc_html_e( 'Show Categories Filter', 'miniload' ); ?></th>
				<td>
					<label class="miniload-toggle">
						<input type="checkbox" name="miniload_show_categories" value="1"
							<?php checked( '1', get_option( 'miniload_show_categories', '0' ) ); ?> />
						<span class="miniload-toggle-slider"></span>
					</label>
					<p class="description"><?php esc_html_e( 'Display category filter dropdown in search box', 'miniload' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Show Categories in Results', 'miniload' ); ?></th>
				<td>
					<label class="miniload-toggle">
						<input type="checkbox" name="miniload_show_categories_results" value="1"
							<?php checked( '1', get_option( 'miniload_show_categories_results', '1' ) ); ?> />
						<span class="miniload-toggle-slider"></span>
					</label>
					<p class="description"><?php esc_html_e( 'Display matching categories in search results dropdown', 'miniload' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Search Icon', 'miniload' ); ?></th>
				<td>
					<select name="miniload_search_icon_position">
						<option value="show" <?php selected( get_option( 'miniload_search_icon_position', 'show' ), 'show' ); ?>>
							<?php esc_html_e( 'Show', 'miniload' ); ?>
						</option>
						<option value="hide" <?php selected( get_option( 'miniload_search_icon_position', 'show' ), 'hide' ); ?>>
							<?php esc_html_e( 'Hide', 'miniload' ); ?>
						</option>
					</select>
					<p class="description">
						<?php
						if ( is_rtl() ) {
							esc_html_e( 'When shown, the icon appears on the left side (automatic for RTL layout)', 'miniload' );
						} else {
							esc_html_e( 'When shown, the icon appears on the right side (automatic for LTR layout)', 'miniload' );
						}
						?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Placeholder Text', 'miniload' ); ?></th>
				<td>
					<input type="text" name="miniload_search_placeholder" class="regular-text"
						value="<?php echo esc_attr( get_option( 'miniload_search_placeholder', __( 'Search products...', 'miniload' ) ) ); ?>" />
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Show Product Price', 'miniload' ); ?></th>
				<td>
					<label class="miniload-toggle">
						<input type="checkbox" name="miniload_show_price" value="1"
							<?php checked( '1', get_option( 'miniload_show_price', '1' ) ); ?> />
						<span class="miniload-toggle-slider"></span>
					</label>
					<p class="description"><?php esc_html_e( 'Display product prices in search results', 'miniload' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Show Product Image', 'miniload' ); ?></th>
				<td>
					<label class="miniload-toggle">
						<input type="checkbox" name="miniload_show_image" value="1"
							<?php checked( '1', get_option( 'miniload_show_image', '1' ) ); ?> />
						<span class="miniload-toggle-slider"></span>
					</label>
					<p class="description"><?php esc_html_e( 'Display product thumbnails in search results', 'miniload' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Font Style', 'miniload' ); ?></th>
				<td>
					<select name="miniload_font_style">
						<option value="inherit" <?php selected( get_option( 'miniload_font_style', 'inherit' ), 'inherit' ); ?>>
							<?php esc_html_e( 'Inherit from Theme', 'miniload' ); ?>
						</option>
						<option value="system" <?php selected( get_option( 'miniload_font_style', 'inherit' ), 'system' ); ?>>
							<?php esc_html_e( 'System Font Stack', 'miniload' ); ?>
						</option>
					</select>
					<p class="description"><?php esc_html_e( 'Choose whether to use your theme\'s font or a system font stack for the search box', 'miniload' ); ?></p>
				</td>
			</tr>
		</table>
	</div>

	<!-- Include comprehensive shortcode documentation -->
	<?php
	$miniload_settings_ajax_search_file = MINILOAD_PLUGIN_DIR . 'admin/settings-ajax-search.php';
	if ( file_exists( $miniload_settings_ajax_search_file ) ) {
		include $miniload_settings_ajax_search_file;
	} else {
		// Fallback to inline shortcode documentation if file not found
	?>
	<!-- Shortcode Usage -->
	<div class="miniload-section">
		<h3 class="miniload-section-title">
			<span class="dashicons dashicons-shortcode"></span>
			<?php esc_html_e( 'Shortcode Usage', 'miniload' ); ?>
		</h3>

		<!-- Important Notice Box -->
		<div style="background: #fff3cd; border: 1px solid #ffc107; border-radius: 4px; padding: 15px; margin-bottom: 20px;">
			<h4 style="margin-top: 0; color: #856404;">⚠️ Important for Regular Pages</h4>
			<p style="color: #856404; margin: 5px 0;">
				Always add <code style="background: white; padding: 2px 5px;">mobile_fullscreen="false"</code> when using the search in pages/posts to prevent it from hiding on mobile devices.
			</p>
		</div>

		<!-- Quick Copy Section -->
		<div style="background: #e8f5e9; border-left: 4px solid #4caf50; padding: 15px; margin-bottom: 20px;">
			<h4 style="margin-top: 0; color: #2e7d32;">🎯 Quick Copy Shortcodes for Common Uses</h4>

			<div style="margin: 10px 0;">
				<strong>For Regular Pages/Posts (Always Visible):</strong><br>
				<code style="background: white; padding: 5px; display: inline-block; margin: 5px 0;">[miniload_search mobile_fullscreen="false"]</code>
			</div>

			<div style="margin: 10px 0;">
				<strong>For WoodMart Header (Icon Only):</strong><br>
				<code style="background: white; padding: 5px; display: inline-block; margin: 5px 0;">[miniload_search icon_only="true" icon_color="#333" icon_bg_color="transparent"]</code>
			</div>

			<div style="margin: 10px 0;">
				<strong>For Sidebar (Full Width):</strong><br>
				<code style="background: white; padding: 5px; display: inline-block; margin: 5px 0;">[miniload_search mobile_fullscreen="false" width="100%"]</code>
			</div>

			<div style="margin: 10px 0;">
				<strong>For Shop Page (With Categories):</strong><br>
				<code style="background: white; padding: 5px; display: inline-block; margin: 5px 0;">[miniload_search mobile_fullscreen="false" categories="true" style="modern"]</code>
			</div>
		</div>

		<div class="miniload-shortcode-examples">
			<h4><?php esc_html_e( 'Basic Usage (Always Visible)', 'miniload' ); ?></h4>
			<code>[miniload_search mobile_fullscreen="false"]</code>

			<h4><?php esc_html_e( 'Icon Only for Headers', 'miniload' ); ?></h4>
			<code>[miniload_search icon_only="true" icon_color="#333" icon_bg_color="transparent"]</code>

			<h4><?php esc_html_e( 'With Categories', 'miniload' ); ?></h4>
			<code>[miniload_search categories="true" mobile_fullscreen="false"]</code>

			<h4><?php esc_html_e( 'Custom Placeholder', 'miniload' ); ?></h4>
			<code>[miniload_search placeholder="Find products..." mobile_fullscreen="false"]</code>
		</div>
	</div>
	<?php } ?>
</div>

<style>
.miniload-shortcode-examples {
	background: #f0f0f1;
	padding: 20px;
	border-radius: 4px;
}

.miniload-shortcode-examples h4 {
	margin: 15px 0 5px 0;
	color: #555;
}

.miniload-shortcode-examples h4:first-child {
	margin-top: 0;
}

.miniload-shortcode-examples code {
	display: block;
	background: #fff;
	padding: 10px;
	border: 1px solid #ddd;
	font-size: 13px;
}
</style>