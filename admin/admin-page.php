<?php
/**
 * MiniLoad Admin Page - Modern Single Page Interface
 *
 * @package MiniLoad
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Get current tab
$miniload_current_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'dashboard';

// Define tabs with proper dashicons
$tabs = array(
	'dashboard' => array(
		'title' => __( 'Dashboard', 'miniload' ),
		'icon' => 'dashicons-dashboard'
	),
	'search' => array(
		'title' => __( 'Search Settings', 'miniload' ),
		'icon' => 'dashicons-search'
	),
	'modules' => array(
		'title' => __( 'Modules', 'miniload' ),
		'icon' => 'dashicons-admin-plugins'
	),
	'tools' => array(
		'title' => __( 'Tools', 'miniload' ),
		'icon' => 'dashicons-admin-tools'
	),
	'settings' => array(
		'title' => __( 'Settings', 'miniload' ),
		'icon' => 'dashicons-admin-settings'
	)
);
?>

<div class="wrap">
	<?php
	// Debug: Check if form is submitted
	if ( isset( $_POST['miniload_save_settings'] ) ) {
		// Check nonce
		if ( ! isset( $_POST['miniload_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['miniload_nonce'] ) ), 'miniload_save_settings' ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Security check failed. Please try again.', 'miniload' ) . '</p></div>';
		} else {
		// Save settings based on current tab
		switch ( $miniload_current_tab ) {
			case 'search':
				// Save search settings
				update_option( 'miniload_ajax_search_enabled', isset( $_POST['miniload_ajax_search_enabled'] ) ? 1 : 0 );
				update_option( 'miniload_search_min_chars', absint( $_POST['miniload_search_min_chars'] ?? 3 ) );
				update_option( 'miniload_search_delay', absint( $_POST['miniload_search_delay'] ?? 300 ) );
				update_option( 'miniload_search_results_count', absint( $_POST['miniload_search_results_count'] ?? 8 ) );
				update_option( 'miniload_search_in_content', isset( $_POST['miniload_search_in_content'] ) ? 1 : 0 );
				update_option( 'miniload_search_in_title', isset( $_POST['miniload_search_in_title'] ) ? 1 : 0 );
				update_option( 'miniload_search_in_sku', isset( $_POST['miniload_search_in_sku'] ) ? 1 : 0 );
				update_option( 'miniload_search_in_short_desc', isset( $_POST['miniload_search_in_short_desc'] ) ? 1 : 0 );
				update_option( 'miniload_search_in_categories', isset( $_POST['miniload_search_in_categories'] ) ? 1 : 0 );
				update_option( 'miniload_search_in_tags', isset( $_POST['miniload_search_in_tags'] ) ? 1 : 0 );
				update_option( 'miniload_show_categories', isset( $_POST['miniload_show_categories'] ) ? 1 : 0 );
				update_option( 'miniload_show_categories_results', isset( $_POST['miniload_show_categories_results'] ) ? 1 : 0 );
				update_option( 'miniload_search_icon_position', sanitize_text_field( wp_unslash( $_POST['miniload_search_icon_position'] ?? 'left' ) ) );
				update_option( 'miniload_search_placeholder', sanitize_text_field( wp_unslash( $_POST['miniload_search_placeholder'] ?? __( 'Search products...', 'miniload' ) ) ) );
				update_option( 'miniload_show_price', isset( $_POST['miniload_show_price'] ) ? 1 : 0 );
				update_option( 'miniload_show_image', isset( $_POST['miniload_show_image'] ) ? 1 : 0 );
				update_option( 'miniload_font_style', sanitize_text_field( wp_unslash( $_POST['miniload_font_style'] ?? 'inherit' ) ) );
				break;

			case 'modules':
				// Save module settings
				update_option( 'miniload_ajax_search_enabled', isset( $_POST['miniload_ajax_search_module'] ) ? 1 : 0 );
				update_option( 'miniload_admin_search_enabled', isset( $_POST['miniload_admin_search_enabled'] ) ? 1 : 0 );
				update_option( 'miniload_media_search_enabled', isset( $_POST['miniload_media_search_enabled'] ) ? 1 : 0 );
				update_option( 'miniload_editor_link_enabled', isset( $_POST['miniload_editor_link_enabled'] ) ? 1 : 0 );
				update_option( 'miniload_query_optimizer_enabled', isset( $_POST['miniload_query_optimizer_enabled'] ) ? 1 : 0 );
				update_option( 'miniload_cache_enabled', isset( $_POST['miniload_cache_enabled'] ) ? 1 : 0 );
				// Asset optimizer and lazy load removed - focusing on core search/filter optimization
				// Analytics removed - no tracking
				update_option( 'miniload_performance_monitor_enabled', isset( $_POST['miniload_performance_monitor_enabled'] ) ? 1 : 0 );

				// Save module array settings (for related products and review stats)
				$miniload_current_settings = get_option( 'miniload_settings', array() );
				if ( ! isset( $miniload_current_settings['modules'] ) ) {
					$miniload_current_settings['modules'] = array();
				}

				// Process miniload_modules array from form
				if ( isset( $_POST['miniload_modules'] ) && is_array( $_POST['miniload_modules'] ) ) {
					$miniload_modules = array_map( 'sanitize_text_field', wp_unslash( $_POST['miniload_modules'] ) );
					// Module is checked - set to true (or don't set, as default is enabled)
					foreach ( $miniload_modules as $miniload_module_key => $miniload_value ) {
						unset( $miniload_current_settings['modules'][$miniload_module_key] ); // Remove false flag if exists
					}
				}

				// Check which modules should be disabled (not in POST but exist as possible modules)
				$miniload_all_possible_modules = array( 'related_products_cache', 'review_stats_cache' );
				foreach ( $miniload_all_possible_modules as $miniload_module_key ) {
					if ( ! isset( $_POST['miniload_modules'] ) || ! isset( $_POST['miniload_modules'][$miniload_module_key] ) ) {
						// Module is unchecked - set to false to disable
						$miniload_current_settings['modules'][$miniload_module_key] = false;
					}
				}

				update_option( 'miniload_settings', $miniload_current_settings );
				break;

			case 'settings':
				// Save general settings
				update_option( 'miniload_enabled', isset( $_POST['miniload_enabled'] ) ? 1 : 0 );
				update_option( 'miniload_debug_mode', isset( $_POST['miniload_debug_mode'] ) ? 1 : 0 );
				update_option( 'miniload_priority', absint( $_POST['miniload_priority'] ?? 10 ) );
				update_option( 'miniload_cache_duration', absint( $_POST['miniload_cache_duration'] ?? 3600 ) );
				update_option( 'miniload_batch_size', absint( $_POST['miniload_batch_size'] ?? 100 ) );
				update_option( 'miniload_memory_limit', sanitize_text_field( wp_unslash( $_POST['miniload_memory_limit'] ?? 'default' ) ) );
				update_option( 'miniload_auto_index', isset( $_POST['miniload_auto_index'] ) ? 1 : 0 );
				update_option( 'miniload_uninstall_behavior', sanitize_text_field( wp_unslash( $_POST['miniload_uninstall_behavior'] ?? 'keep' ) ) );
				update_option( 'miniload_rest_api_enabled', isset( $_POST['miniload_rest_api_enabled'] ) ? 1 : 0 );
				update_option( 'miniload_nonce_lifetime', sanitize_text_field( wp_unslash( $_POST['miniload_nonce_lifetime'] ?? '1' ) ) );
				break;
		}

		// Clear cache after saving settings
		wp_cache_flush();

		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved successfully!', 'miniload' ) . '</p></div>';
		}
	}
	?>
</div>

<div class="miniload-admin-wrap">
	<div class="miniload-admin-header">
		<div class="miniload-logo">
			<h1>
				<img src="<?php echo esc_url( plugin_dir_url( dirname( __FILE__ ) ) . 'assets/images/logo.png' ); ?>" alt="MiniLoad" style="height: 30px; vertical-align: middle; margin-right: 10px;">
				<?php echo esc_html( get_admin_page_title() ); ?>
			</h1>
			<span class="miniload-version">v<?php echo esc_html( MINILOAD_VERSION ); ?></span>
		</div>
		<div class="miniload-header-actions">
			<a href="https://github.com/mohsengham/miniload" target="_blank" class="button button-secondary">
				<span class="dashicons dashicons-external"></span> GitHub
			</a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=miniload&tab=tools&action=clear-cache' ) ); ?>" class="button button-secondary">
				<span class="dashicons dashicons-trash"></span> <?php esc_html_e( 'Clear Cache', 'miniload' ); ?>
			</a>
		</div>
	</div>

	<style>
		.miniload-nav-tabs .nav-tab .dashicons {
			font-size: 16px;
			width: 16px;
			height: 16px;
			margin-right: 5px;
			vertical-align: text-bottom;
			line-height: 1;
		}
		.miniload-nav-tabs .nav-tab-active .dashicons {
			color: #0073aa;
		}
		.miniload-header-actions .button .dashicons {
			font-size: 16px;
			width: 16px;
			height: 16px;
			line-height: 1;
			margin-right: 3px;
		}
		/* RTL adjustments */
		body.rtl .miniload-nav-tabs .nav-tab .dashicons {
			margin-right: 0;
			margin-left: 5px;
		}
		body.rtl .miniload-header-actions .button .dashicons {
			margin-right: 0;
			margin-left: 3px;
		}
	</style>

	<nav class="nav-tab-wrapper miniload-nav-tabs">
		<?php
		$miniload_dashicon_classes = array(
			'dashboard' => 'dashicons-chart-area',
			'search' => 'dashicons-search',
			'modules' => 'dashicons-admin-plugins',
			'tools' => 'dashicons-admin-tools',
			'settings' => 'dashicons-admin-generic'
		);
		foreach ( $tabs as $miniload_tab_key => $tab ) :
		?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=miniload&tab=' . $miniload_tab_key ) ); ?>"
			   class="nav-tab <?php echo $miniload_current_tab === $miniload_tab_key ? 'nav-tab-active' : ''; ?>">
				<span class="dashicons <?php echo esc_attr( $miniload_dashicon_classes[$miniload_tab_key] ); ?>"></span>
				<?php echo esc_html( $tab['title'] ); ?>
			</a>
		<?php endforeach; ?>
	</nav>

	<div class="miniload-admin-content">
		<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=miniload&tab=' . $miniload_current_tab ) ); ?>">
			<?php wp_nonce_field( 'miniload_save_settings', 'miniload_nonce' ); ?>
			<input type="hidden" name="miniload_current_tab" value="<?php echo esc_attr( $miniload_current_tab ); ?>" />

			<div class="miniload-tab-content">
				<?php
				// Include tab content
				$miniload_tab_file = MINILOAD_PLUGIN_DIR . 'admin/tabs/tab-' . $miniload_current_tab . '.php';
				if ( file_exists( $miniload_tab_file ) ) {
					include $miniload_tab_file;
				} else {
					echo '<p>' . esc_html__( 'Tab content not found.', 'miniload' ) . '</p>';
				}
				?>
			</div>

			<?php if ( in_array( $miniload_current_tab, array( 'search', 'modules', 'settings' ) ) ) : ?>
				<div class="miniload-submit-wrapper">
					<button type="button" id="miniload-save-button" class="button button-primary button-hero">
						<?php esc_html_e( 'Save Settings', 'miniload' ); ?>
					</button>
					<span id="miniload-save-status" style="margin-left: 20px; display: none;"></span>
				</div>
			<?php endif; ?>
		</form>

		<script>
		jQuery(document).ready(function($) {
			// FIXED Save button handler
			$('#miniload-save-button').on('click', function(e) {
				e.preventDefault();

				var $btn = $(this);
				var $status = $('#miniload-save-status');

				// Disable button and show loading
				$btn.prop('disabled', true).text('Saving...');
				$status.show().html('<span style="color: #666;">Saving settings...</span>');

				// Collect ALL form values properly
				var settings = {};

				// Get ALL checkboxes - save their actual state
				$('.miniload-tab-content input[type="checkbox"]').each(function() {
					var name = $(this).attr('name');
					if (name) {
						settings[name] = $(this).is(':checked') ? '1' : '0';
					}
				});

				// Get all other inputs
				$('.miniload-tab-content input[type="text"], .miniload-tab-content input[type="number"], .miniload-tab-content select').each(function() {
					var name = $(this).attr('name');
					if (name) {
						settings[name] = $(this).val();
					}
				});

				console.log('Settings to save:', settings);

				// Use the direct save that works
				$.ajax({
					url: ajaxurl,
					type: 'POST',
					data: {
						action: 'miniload_direct_save',
						settings: settings
					},
					success: function(response) {
						$status.html('<span style="color: green;">✓ Settings saved successfully!</span>');

						// Reload to show saved values
						setTimeout(function() {
							location.reload();
						}, 1000);
					},
					error: function() {
						$status.html('<span style="color: red;">✗ Failed to save settings</span>');
					},
					complete: function() {
						$btn.prop('disabled', false).text('Save Settings');
					}
				});
			});
		});
		</script>
	</div>
</div>

<style>
/* Ensure Dashicons Font is loaded */
@font-face {
	font-family: 'dashicons';
	src: url('<?php echo esc_url( includes_url( 'fonts/dashicons.eot' ) ); ?>');
	src: url('<?php echo esc_url( includes_url( 'fonts/dashicons.eot?#iefix' ) ); ?>') format('embedded-opentype'),
		url('<?php echo esc_url( includes_url( 'fonts/dashicons.woff2' ) ); ?>') format('woff2'),
		url('<?php echo esc_url( includes_url( 'fonts/dashicons.woff' ) ); ?>') format('woff'),
		url('<?php echo esc_url( includes_url( 'fonts/dashicons.ttf' ) ); ?>') format('truetype'),
		url('<?php echo esc_url( includes_url( 'fonts/dashicons.svg#dashicons' ) ); ?>') format('svg');
	font-weight: normal;
	font-style: normal;
}

/* Modern Admin Styles - Black/White/Gray Theme */
.miniload-admin-wrap {
	margin: 20px 20px 0 0;
	background: #f0f0f1;
}

.miniload-admin-header {
	background: #1a1a1a;
	padding: 30px;
	border-radius: 8px 8px 0 0;
	display: flex;
	justify-content: space-between;
	align-items: center;
	box-shadow: 0 2px 4px rgba(0,0,0,0.2);
}

.miniload-logo h1 {
	color: #fff;
	margin: 0;
	font-size: 32px;
	font-weight: 600;
	display: flex;
	align-items: center;
	gap: 15px;
}

.miniload-logo h1 .dashicons {
	font-size: 36px;
	width: 36px;
	height: 36px;
}

.miniload-version {
	color: #999;
	font-size: 14px;
	margin-left: 15px;
	background: rgba(255,255,255,0.1);
	padding: 4px 12px;
	border-radius: 20px;
}

.miniload-header-actions {
	display: flex;
	gap: 10px;
}

.miniload-header-actions .button {
	display: flex;
	align-items: center;
	gap: 5px;
	background: #333;
	border: 1px solid #555;
	color: #fff;
}

.miniload-header-actions .button:hover {
	background: #444;
	border-color: #666;
	color: #fff;
}

.miniload-nav-tabs {
	background: #fff;
	padding: 0;
	margin: 0;
	border-bottom: 2px solid #e0e0e0;
	display: flex;
}

.miniload-nav-tabs .nav-tab {
	background: transparent;
	border: none;
	border-bottom: 3px solid transparent;
	padding: 20px 30px;
	font-size: 14px;
	font-weight: 500;
	color: #666;
	display: flex;
	align-items: center;
	gap: 8px;
	margin: 0;
	transition: all 0.3s ease;
}

.miniload-nav-tabs .nav-tab:hover {
	background: #f8f9fa;
	color: #333;
}

.miniload-nav-tabs .nav-tab-active {
	color: #1a1a1a;
	border-bottom-color: #1a1a1a;
	background: #f8f9fa;
}

.miniload-icon {
	display: inline-block;
	margin-right: 8px;
	vertical-align: middle;
}

.miniload-icon .icon-fallback {
	font-size: 16px;
	line-height: 1;
}

.miniload-nav-tabs .nav-tab .dashicons,
.miniload-header-actions .dashicons,
.miniload-logo .dashicons {
	font-family: dashicons !important;
	font-size: 18px !important;
	width: 18px !important;
	height: 18px !important;
	line-height: 1 !important;
	vertical-align: middle !important;
	margin-right: 5px !important;
	display: inline-block !important;
}

.dashicons:before {
	font-family: dashicons !important;
	display: inline-block !important;
	line-height: 1 !important;
	font-weight: 400 !important;
	font-style: normal !important;
	speak: none !important;
	text-decoration: inherit !important;
	text-transform: none !important;
	text-rendering: auto !important;
	-webkit-font-smoothing: antialiased !important;
	-moz-osx-font-smoothing: grayscale !important;
}

/* Specific dashicon content */
.dashicons-dashboard:before { content: "\f226"; }
.dashicons-search:before { content: "\f179"; }
.dashicons-admin-plugins:before { content: "\f106"; }
.dashicons-admin-tools:before { content: "\f107"; }
.dashicons-admin-settings:before { content: "\f108"; }
.dashicons-performance:before { content: "\f311"; }
.dashicons-trash:before { content: "\f182"; }
.dashicons-saved:before { content: "\f147"; }
.dashicons-external:before { content: "\f504"; }

.miniload-admin-content {
	background: #fff;
	padding: 40px;
	min-height: 500px;
	border-radius: 0 0 8px 8px;
	box-shadow: 0 2px 4px rgba(0,0,0,0.05);
}

.miniload-tab-content {
	max-width: 1200px;
	margin: 0 auto;
}

.miniload-submit-wrapper {
	margin-top: 40px;
	padding-top: 30px;
	border-top: 1px solid #e0e0e0;
	text-align: center;
}

.miniload-submit-wrapper .button-hero,
.miniload-submit-wrapper input[type="submit"].button-hero {
	padding: 15px 40px !important;
	font-size: 16px !important;
	display: inline-block !important;
	background: #1a1a1a !important;
	border: none !important;
	color: #fff !important;
	box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3) !important;
	cursor: pointer !important;
	height: auto !important;
	line-height: normal !important;
}

.miniload-submit-wrapper .button-hero:hover,
.miniload-submit-wrapper input[type="submit"].button-hero:hover {
	background: #333 !important;
	color: #fff !important;
	box-shadow: 0 6px 20px rgba(0, 0, 0, 0.4) !important;
	transform: translateY(-1px);
}

/* Responsive */
@media (max-width: 768px) {
	.miniload-admin-header {
		flex-direction: column;
		text-align: center;
		gap: 20px;
	}

	.miniload-nav-tabs {
		flex-wrap: wrap;
	}

	.miniload-nav-tabs .nav-tab {
		padding: 15px 20px;
		font-size: 13px;
	}

	.miniload-admin-content {
		padding: 20px;
	}
}

/* Stats Cards */
.miniload-stats-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
	gap: 20px;
	margin-bottom: 40px;
}

.miniload-stat-card {
	background: #fff;
	border: 1px solid #e0e0e0;
	border-radius: 8px;
	padding: 25px;
	text-align: center;
	transition: all 0.3s ease;
}

.miniload-stat-card:hover {
	box-shadow: 0 4px 12px rgba(0,0,0,0.08);
	transform: translateY(-2px);
}

.miniload-stat-value {
	font-size: 36px;
	font-weight: 700;
	color: #1a1a1a;
	margin: 10px 0;
}

.miniload-stat-label {
	font-size: 14px;
	color: #666;
	text-transform: uppercase;
	letter-spacing: 1px;
}

.miniload-stat-icon {
	font-size: 48px;
	color: #e0e0e0;
	margin-bottom: 10px;
}

/* Settings Sections */
.miniload-section {
	background: #f8f9fa;
	border-radius: 8px;
	padding: 30px;
	margin-bottom: 30px;
}

.miniload-section-title {
	font-size: 20px;
	font-weight: 600;
	color: #333;
	margin: 0 0 20px 0;
	display: flex;
	align-items: center;
	gap: 10px;
}

.miniload-section-title .dashicons {
	color: #1a1a1a;
}

/* Toggle Switches */
.miniload-toggle {
	position: relative;
	display: inline-block;
	width: 60px;
	height: 30px;
}

.miniload-toggle input {
	opacity: 0;
	width: 0;
	height: 0;
}

.miniload-toggle-slider {
	position: absolute;
	cursor: pointer;
	top: 0;
	left: 0;
	right: 0;
	bottom: 0;
	background-color: #ccc;
	transition: .4s;
	border-radius: 30px;
}

.miniload-toggle-slider:before {
	position: absolute;
	content: "";
	height: 22px;
	width: 22px;
	left: 4px;
	bottom: 4px;
	background-color: white;
	transition: .4s;
	border-radius: 50%;
}

.miniload-toggle input:checked + .miniload-toggle-slider {
	background-color: #1a1a1a;
}

.miniload-toggle input:checked + .miniload-toggle-slider:before {
	transform: translateX(30px);
}
</style>

<script>
jQuery(document).ready(function($) {
	// Add smooth transitions
	$('.miniload-stat-card').each(function(index) {
		$(this).css('animation-delay', (index * 0.1) + 's');
	});

	// Tab switching with keyboard
	$('.nav-tab').on('keydown', function(e) {
		if (e.key === 'Enter' || e.key === ' ') {
			e.preventDefault();
			window.location.href = $(this).attr('href');
		}
	});
});
</script>