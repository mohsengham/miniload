<?php
/**
 * AJAX Search Settings Page
 *
 * @package MiniLoad
 * @subpackage Admin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Get current settings
$miniload_search_enabled = get_option( 'miniload_ajax_search_enabled', true );
$miniload_min_chars = get_option( 'miniload_search_min_chars', 2 );
$miniload_max_results = get_option( 'miniload_search_max_results', 10 );
$miniload_replace_search = get_option( 'miniload_replace_search', false );
$miniload_enable_modal = get_option( 'miniload_enable_search_modal', false );
// Analytics removed - no tracking
?>

<div class="miniload-settings-section">
	<h2><?php esc_html_e( 'AJAX Search Settings', 'miniload' ); ?></h2>

	<div class="miniload-settings-info">
		<p><?php esc_html_e( 'Configure the AJAX Search module - Better than FiboSearch!', 'miniload' ); ?></p>
	</div>

	<table class="form-table">
		<tr>
			<th scope="row"><?php esc_html_e( 'Enable AJAX Search', 'miniload' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="miniload_ajax_search_enabled" value="1" <?php checked( $miniload_search_enabled, true ); ?>>
					<?php esc_html_e( 'Enable AJAX search functionality', 'miniload' ); ?>
				</label>
			</td>
		</tr>

		<tr>
			<th scope="row"><?php esc_html_e( 'Minimum Characters', 'miniload' ); ?></th>
			<td>
				<input type="number" name="miniload_search_min_chars" value="<?php echo esc_attr( $miniload_min_chars ); ?>" min="1" max="10">
				<p class="description"><?php esc_html_e( 'Minimum characters before search starts', 'miniload' ); ?></p>
			</td>
		</tr>

		<tr>
			<th scope="row"><?php esc_html_e( 'Maximum Results', 'miniload' ); ?></th>
			<td>
				<input type="number" name="miniload_search_max_results" value="<?php echo esc_attr( $miniload_max_results ); ?>" min="5" max="50">
				<p class="description"><?php esc_html_e( 'Maximum number of results to display', 'miniload' ); ?></p>
			</td>
		</tr>

		<tr>
			<th scope="row"><?php esc_html_e( 'Replace Default Search', 'miniload' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="miniload_replace_search" value="1" <?php checked( $miniload_replace_search, true ); ?>>
					<?php esc_html_e( 'Replace WordPress default search forms', 'miniload' ); ?>
				</label>
			</td>
		</tr>

		<tr>
			<th scope="row"><?php esc_html_e( 'Enable Search Modal', 'miniload' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="miniload_enable_search_modal" value="1" <?php checked( $miniload_enable_modal, true ); ?>>
					<?php esc_html_e( 'Enable modal search (Ctrl+/)', 'miniload' ); ?>
				</label>
			</td>
		</tr>

		<tr>
			<th scope="row"><?php esc_html_e( 'Search Content', 'miniload' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="miniload_search_in_content" id="miniload_search_in_content" value="1" <?php checked( get_option( 'miniload_search_in_content', true ), true ); ?>>
					<?php esc_html_e( 'Include full product descriptions in search', 'miniload' ); ?>
				</label>
				<p class="description">
					<?php esc_html_e( 'Unchecking this will only search in product titles, SKUs, and short descriptions for better performance.', 'miniload' ); ?>
					<br><strong><?php esc_html_e( 'Note: After changing this setting, you need to rebuild the search index.', 'miniload' ); ?></strong>
				</p>
				<p>
					<button type="button" class="button" id="miniload-rebuild-search-index">
						<span class="dashicons dashicons-update" style="vertical-align: text-top;"></span>
						<?php esc_html_e( 'Rebuild Search Index', 'miniload' ); ?>
					</button>
					<span id="rebuild-search-status" style="margin-left: 10px;"></span>
				</p>
			</td>
		</tr>
	</table>

	<hr>

	<h3><?php esc_html_e( 'Advanced Search Features', 'miniload' ); ?></h3>

	<table class="form-table">
		<tr>
			<th scope="row"><?php esc_html_e( 'Media Library Search', 'miniload' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="miniload_media_search_enabled" id="miniload_media_search_enabled" value="1" <?php checked( get_option( 'miniload_media_search_enabled', false ), true ); ?>>
					<?php esc_html_e( 'Enable accelerated media library search', 'miniload' ); ?>
				</label>
				<p class="description">
					<?php esc_html_e( 'Indexes media titles, alt text, captions, and metadata for faster searching.', 'miniload' ); ?>
					<br><?php esc_html_e( 'Note: Initial indexing may take a few minutes for large media libraries.', 'miniload' ); ?>
				</p>
				<p>
					<button type="button" class="button" id="miniload-rebuild-media-index">
						<span class="dashicons dashicons-update" style="vertical-align: text-top;"></span>
						<?php esc_html_e( 'Rebuild Media Index', 'miniload' ); ?>
					</button>
					<span id="rebuild-media-status" style="margin-left: 10px;"></span>
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Editor Link Builder', 'miniload' ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="miniload_editor_link_enabled" id="miniload_editor_link_enabled" value="1" <?php checked( get_option( 'miniload_editor_link_enabled', false ), true ); ?>>
					<?php esc_html_e( 'Use FULLTEXT product search in editor link dialog', 'miniload' ); ?>
				</label>
				<p class="description">
					<?php esc_html_e( 'Intercepts WordPress editor link search to use our optimized FULLTEXT product index.', 'miniload' ); ?>
					<br><?php esc_html_e( 'Makes product linking 5-10x faster when creating content.', 'miniload' ); ?>
					<br><strong><?php esc_html_e( 'Requires: Product search index to be built (from main search settings).', 'miniload' ); ?></strong>
				</p>
			</td>
		</tr>
	</table>

	<hr>

	<h3><?php esc_html_e( 'Shortcode Examples', 'miniload' ); ?></h3>

	<div class="miniload-shortcode-examples">
		<style>
			.miniload-shortcode-examples {
				background: #f9f9f9;
				padding: 20px;
				border-radius: 5px;
				margin: 20px 0;
			}
			.shortcode-example {
				margin-bottom: 20px;
				background: white;
				padding: 15px;
				border-left: 4px solid #1e88e5;
			}
			.shortcode-example h4 {
				margin-top: 0;
				color: #333;
			}
			.shortcode-example code {
				display: block;
				padding: 10px;
				background: #f4f4f4;
				border: 1px solid #ddd;
				border-radius: 3px;
				font-family: monospace;
				font-size: 13px;
			}
			.shortcode-params {
				margin-top: 10px;
				font-size: 13px;
				color: #666;
			}
			.shortcode-params strong {
				color: #333;
			}
		</style>

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

			<div style="margin: 10px 0;">
				<strong>For Mobile-First Design:</strong><br>
				<code style="background: white; padding: 5px; display: inline-block; margin: 5px 0;">[miniload_search style="mobile-first" mobile_fullscreen="true"]</code>
			</div>
		</div>

		<div class="shortcode-example">
			<h4>1. Simple Search Box (Always Visible)</h4>
			<code>[miniload_search mobile_fullscreen="false"]</code>
			<div class="shortcode-params">
				Clean search box that stays visible on all screen sizes
			</div>
		</div>

		<div class="shortcode-example">
			<h4>2. Search Box with Categories</h4>
			<code>[miniload_search categories="true" category_dropdown="true"]</code>
			<div class="shortcode-params">
				Includes category filter dropdown
			</div>
		</div>

		<div class="shortcode-example">
			<h4>3. Custom Width Search Box</h4>
			<code>[miniload_search width="100%" max_width="500px"]</code>
			<div class="shortcode-params">
				<strong>width:</strong> Set specific width (px, %, em)<br>
				<strong>max_width:</strong> Maximum width limit
			</div>
		</div>

		<div class="shortcode-example">
			<h4>4. Search Icon Position</h4>
			<code>[miniload_search submit_position="left"]</code>
			<div class="shortcode-params">
				<strong>Options:</strong> left, right (default), both, none
			</div>
		</div>

		<div class="shortcode-example">
			<h4>5. Icon Only (Click to Open)</h4>
			<code>[miniload_search icon_only="true" icon_size="large" icon_color="#fff" icon_bg_color="#1e88e5"]</code>
			<div class="shortcode-params">
				Shows only search icon that opens search box on click
			</div>
		</div>

		<div class="shortcode-example">
			<h4>6. Floating Search Button</h4>
			<code>[miniload_search floating="true" floating_position="bottom-right"]</code>
			<div class="shortcode-params">
				<strong>Positions:</strong> bottom-right, bottom-left, top-right, top-left
			</div>
		</div>

		<div class="shortcode-example">
			<h4>7. Grid Layout Results</h4>
			<code>[miniload_search layout="grid" show_image="true" show_price="true"]</code>
			<div class="shortcode-params">
				<strong>Layouts:</strong> list (default), grid, compact
			</div>
		</div>

		<div class="shortcode-example">
			<h4>8. Minimal Search (No Button)</h4>
			<code>[miniload_search style="minimal" show_submit="false" clear_button="false"]</code>
			<div class="shortcode-params">
				Clean minimal search, press Enter to search
			</div>
		</div>

		<div class="shortcode-example">
			<h4>9. Custom Placeholder & Settings</h4>
			<code>[miniload_search placeholder="Find your book..." min_chars="1" max_results="20"]</code>
			<div class="shortcode-params">
				Customize search behavior and appearance
			</div>
		</div>

		<div class="shortcode-example">
			<h4>10. Mobile Optimized</h4>
			<code>[miniload_search style="mobile-first" mobile_fullscreen="true"]</code>
			<div class="shortcode-params">
				Optimized for mobile devices with fullscreen mode
			</div>
		</div>
	</div>

	<hr>

	<h3><?php esc_html_e( 'Complete Parameters Reference', 'miniload' ); ?></h3>

	<!-- Categories of parameters -->
	<div style="background: #f0f8ff; border: 1px solid #b0d4ff; border-radius: 4px; padding: 15px; margin-bottom: 20px;">
		<strong>📋 Parameter Categories:</strong>
		<span style="margin: 0 10px;">🎨 Display</span>
		<span style="margin: 0 10px;">⚙️ Behavior</span>
		<span style="margin: 0 10px;">📐 Layout</span>
		<span style="margin: 0 10px;">📱 Mobile</span>
		<span style="margin: 0 10px;">🎯 Advanced</span>
	</div>

	<table class="widefat striped">
		<thead>
			<tr>
				<th width="20%"><?php esc_html_e( 'Parameter', 'miniload' ); ?></th>
				<th width="15%"><?php esc_html_e( 'Default', 'miniload' ); ?></th>
				<th><?php esc_html_e( 'Description', 'miniload' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<tr style="background: #fff9e6;">
				<td><code><strong>mobile_fullscreen</strong></code> 📱</td>
				<td><strong style="color: #ff6b00;">true</strong></td>
				<td><strong>⚠️ IMPORTANT: Set to "false" for pages/posts to keep search visible on mobile. When "true", search hides on mobile unless triggered by icon.</strong></td>
			</tr>
			<tr>
				<td><code>placeholder</code></td>
				<td>Search products...</td>
				<td>Search input placeholder text</td>
			</tr>
			<tr>
				<td><code>width</code></td>
				<td>auto</td>
				<td>Search box width (px, %, em)</td>
			</tr>
			<tr>
				<td><code>max_width</code></td>
				<td>700px</td>
				<td>Maximum width of search box</td>
			</tr>
			<tr>
				<td><code>categories</code></td>
				<td>false</td>
				<td>Show category dropdown</td>
			</tr>
			<tr>
				<td><code>show_submit</code></td>
				<td>true</td>
				<td>Show search button</td>
			</tr>
			<tr>
				<td><code>submit_position</code></td>
				<td>right</td>
				<td>Button position: left, right, both, none</td>
			</tr>
			<tr>
				<td><code>clear_button</code></td>
				<td>true</td>
				<td>Show clear button</td>
			</tr>
			<tr>
				<td><code>min_chars</code></td>
				<td>2</td>
				<td>Minimum characters to trigger search</td>
			</tr>
			<tr>
				<td><code>max_results</code></td>
				<td>10</td>
				<td>Maximum results to display</td>
			</tr>
			<tr>
				<td><code>layout</code></td>
				<td>list</td>
				<td>Results layout: list, grid, compact</td>
			</tr>
			<tr>
				<td><code>style</code></td>
				<td>default</td>
				<td>Search style: default, minimal, modern, mobile-first</td>
			</tr>
			<tr>
				<td><code>show_image</code></td>
				<td>true</td>
				<td>Show product images in results</td>
			</tr>
			<tr>
				<td><code>show_price</code></td>
				<td>true</td>
				<td>Show product prices in results</td>
			</tr>
			<tr>
				<td><code>show_sku</code></td>
				<td>false</td>
				<td>Show product SKU in results</td>
			</tr>
			<tr style="background: #f0f8ff;">
				<td><code>icon_only</code> 📱</td>
				<td>false</td>
				<td><strong>Perfect for headers!</strong> Shows only search icon that opens search box on click</td>
			</tr>
			<tr style="background: #f0f8ff;">
				<td><code>icon_size</code></td>
				<td>medium</td>
				<td>Icon size when using icon_only: small (36px), medium (44px), large (56px)</td>
			</tr>
			<tr style="background: #f0f8ff;">
				<td><code>icon_color</code></td>
				<td>#333</td>
				<td>Icon color (e.g., "#fff" for white, "#333" for dark)</td>
			</tr>
			<tr style="background: #f0f8ff;">
				<td><code>icon_bg_color</code></td>
				<td>#1e88e5</td>
				<td>Icon background color (use "transparent" for no background)</td>
			</tr>
			<tr style="background: #f0f8ff;">
				<td><code>icon_position</code></td>
				<td>right</td>
				<td>Icon alignment: left, center, right</td>
			</tr>
			<tr>
				<td><code>floating</code></td>
				<td>false</td>
				<td>Enable floating search button</td>
			</tr>
			<tr>
				<td><code>floating_position</code></td>
				<td>bottom-right</td>
				<td>Floating button position: bottom-right, bottom-left, top-right, top-left</td>
			</tr>
		</tbody>
	</table>

	<hr>

	<!-- Pro Tips Section -->
	<div style="background: #e3f2fd; border-left: 4px solid #2196f3; padding: 15px; margin-top: 20px;">
		<h4 style="margin-top: 0; color: #1565c0;">💡 Pro Tips</h4>
		<ul style="margin: 10px 0; padding-left: 20px;">
			<li><strong>WoodMart Theme:</strong> Use <code>icon_only="true"</code> with <code>icon_bg_color="transparent"</code> for best header integration</li>
			<li><strong>Mobile Issues:</strong> Always add <code>mobile_fullscreen="false"</code> when placing search in pages or posts</li>
			<li><strong>Performance:</strong> Set <code>min_chars="3"</code> to reduce server load on large catalogs</li>
			<li><strong>Clean Look:</strong> Use <code>style="minimal" show_submit="false"</code> for a minimalist design</li>
			<li><strong>Full Width:</strong> Add <code>width="100%"</code> when placing in sidebars or narrow columns</li>
		</ul>
	</div>

	<!-- Analytics removed - no tracking -->

	<script type="text/javascript">
	jQuery(document).ready(function($) {
		$('#miniload-rebuild-search-index').on('click', function() {
			var $button = $(this);
			var $status = $('#rebuild-search-status');

			if (!confirm('<?php echo esc_js( __( 'This will rebuild the entire search index. Continue?', 'miniload' ) ); ?>')) {
				return;
			}

			$button.prop('disabled', true);
			$status.html('<span class="spinner is-active" style="float: none;"></span> <?php echo esc_js( __( 'Rebuilding index...', 'miniload' ) ); ?>');

			$.ajax({
				url: ajaxurl,
				type: 'POST',
				data: {
					action: 'miniload_rebuild_search_index',
					nonce: '<?php echo esc_attr( wp_create_nonce( 'miniload_rebuild_search' ) ); ?>'
				},
				success: function(response) {
					if (response.success) {
						$status.html('<span style="color: green;">✓ ' + response.data.message + '</span>');
					} else {
						$status.html('<span style="color: red;">✗ ' + (response.data || '<?php echo esc_js( __( 'Error rebuilding index', 'miniload' ) ); ?>') + '</span>');
					}
				},
				error: function() {
					$status.html('<span style="color: red;">✗ <?php echo esc_js( __( 'Error rebuilding index', 'miniload' ) ); ?></span>');
				},
				complete: function() {
					$button.prop('disabled', false);
				}
			});
		});

		// Media index rebuild
		$('#miniload-rebuild-media-index').on('click', function() {
			var $button = $(this);
			var $status = $('#rebuild-media-status');

			if (!confirm('<?php echo esc_js( __( 'This will rebuild the media search index. Continue?', 'miniload' ) ); ?>')) {
				return;
			}

			$button.prop('disabled', true);
			$status.html('<span class="spinner is-active" style="float: none;"></span> <?php echo esc_js( __( 'Rebuilding media index...', 'miniload' ) ); ?>');

			$.ajax({
				url: ajaxurl,
				type: 'POST',
				data: {
					action: 'miniload_rebuild_media_index',
					nonce: '<?php echo esc_attr( wp_create_nonce( 'miniload_rebuild_media' ) ); ?>'
				},
				success: function(response) {
					if (response.success) {
						$status.html('<span style="color: green;">✓ ' + response.data.message + '</span>');
					} else {
						$status.html('<span style="color: red;">✗ ' + (response.data || '<?php echo esc_js( __( 'Error rebuilding index', 'miniload' ) ); ?>') + '</span>');
					}
				},
				error: function() {
					$status.html('<span style="color: red;">✗ <?php echo esc_js( __( 'Error rebuilding index', 'miniload' ) ); ?></span>');
				},
				complete: function() {
					$button.prop('disabled', false);
				}
			});
		});
	});
	</script>

</div>