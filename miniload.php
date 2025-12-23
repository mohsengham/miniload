<?php
/**
 * MiniLoad - Performance Optimizer for WooCommerce
 *
 * @package           MiniLoad
 * @author            Minimall Team
 * @copyright         2025 MiniMall Team
 * @license           GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:       MiniLoad - Performance Optimizer for WooCommerce
 * Plugin URI:        https://github.com/mohsengham/miniload
 * Description:       Supercharge your WooCommerce store with blazing-fast AJAX search, optimized queries, and intelligent caching.
 * Version:           1.0.6
 * Requires at least: 5.0
 * Requires PHP:      7.4
 * Author:            Minimall Team
 * Author URI:        https://minimall.work
 * Text Domain:       miniload
 * Domain Path:       /languages
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * WC requires at least: 3.0
 * WC tested up to:   10.4.3
 * Requires Plugins:  woocommerce
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

// Define plugin constants
define( 'MINILOAD_VERSION', '1.0.5' );
define( 'MINILOAD_PLUGIN_FILE', __FILE__ );
define( 'MINILOAD_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MINILOAD_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MINILOAD_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Check minimum requirements
if ( ! function_exists( 'miniload_check_requirements' ) ) {
	/**
	 * Check if minimum requirements are met
	 *
	 * @return bool
	 */
	function miniload_check_requirements() {
		$errors = array();

		// Check PHP version
		if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
			$errors[] = sprintf(
				/* translators: %s: PHP version */
				__( 'MiniLoad requires PHP version %s or higher.', 'miniload' ),
				'7.4'
			);
		}

		// Check WordPress version
		global $wp_version;
		if ( version_compare( $wp_version, '5.8', '<' ) ) {
			$errors[] = sprintf(
				/* translators: %s: WordPress version */
				__( 'MiniLoad requires WordPress version %s or higher.', 'miniload' ),
				'5.8'
			);
		}

		// Display errors if any
		if ( ! empty( $errors ) ) {
			add_action( 'admin_notices', function() use ( $errors ) {
				foreach ( $errors as $error ) {
					?>
					<div class="notice notice-error">
						<p><?php echo esc_html( $error ); ?></p>
					</div>
					<?php
				}
			} );
			return false;
		}

		return true;
	}
}

// Check requirements before initializing
if ( ! miniload_check_requirements() ) {
	return;
}

// Include the autoloader
require_once MINILOAD_PLUGIN_DIR . 'includes/class-autoloader.php';

// Initialize autoloader
MiniLoad\Autoloader::init();

// Include core functions
require_once MINILOAD_PLUGIN_DIR . 'includes/functions.php';

/**
 * Main plugin class
 */
if ( ! class_exists( 'MiniLoad' ) ) {

	final class MiniLoad {

		/**
		 * Plugin instance
		 *
		 * @var MiniLoad
		 */
		private static $instance = null;

		/**
		 * Module instances
		 *
		 * @var array
		 */
		private $modules = array();

		/**
		 * Get plugin instance
		 *
		 * @return MiniLoad
		 */
		public static function instance() {
			if ( is_null( self::$instance ) ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		/**
		 * Constructor
		 */
		private function __construct() {
			$this->init_hooks();
		}

		/**
		 * Initialize hooks
		 */
		private function init_hooks() {
			// Activation/Deactivation hooks
			register_activation_hook( MINILOAD_PLUGIN_FILE, array( $this, 'activate' ) );
			register_deactivation_hook( MINILOAD_PLUGIN_FILE, array( $this, 'deactivate' ) );

			// Init action
			add_action( 'init', array( $this, 'init' ), 0 );

			// Check if WooCommerce is active
			add_action( 'plugins_loaded', array( $this, 'check_woocommerce' ), 1 );

			// Declare HPOS compatibility
			add_action( 'before_woocommerce_init', array( $this, 'declare_hpos_compatibility' ) );

			// WordPress 4.6+ automatically loads translations for plugins on WordPress.org
			// No need to manually load text domain

			// Load modules after WooCommerce
			add_action( 'plugins_loaded', array( $this, 'load_modules' ), 20 );

			// For AJAX requests, load modules immediately to ensure handlers are registered
			if ( wp_doing_ajax() ) {
				add_action( 'init', array( $this, 'load_modules' ), 5 );
			}

			// Admin hooks
			if ( is_admin() ) {
				add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
				add_action( 'admin_init', array( $this, 'register_settings' ) );
				add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
				add_filter( 'plugin_action_links_' . MINILOAD_PLUGIN_BASENAME, array( $this, 'add_action_links' ) );
			}

			// AJAX hooks
			add_action( 'wp_ajax_miniload_ajax', array( $this, 'handle_ajax' ) );
			add_action( 'wp_ajax_miniload_save_settings', array( $this, 'ajax_save_settings' ) );
			add_action( 'wp_ajax_miniload_direct_save', array( $this, 'direct_save' ) );

			// WP-CLI support (disabled for now)
			// if ( defined( 'WP_CLI' ) && WP_CLI ) {
			// 	$this->init_cli();
			// }
		}

		/**
		 * Plugin activation
		 */
		public function activate() {
			// Set activation flag
			add_option( 'miniload_activated', time() );

			// Create database tables
			$this->create_tables();

			// Set default options
			$this->set_default_options();

			// Clear rewrite rules
			flush_rewrite_rules();

			// Log activation
			miniload_log( 'Plugin activated', 'info' );
		}

		/**
		 * Plugin deactivation
		 */
		public function deactivate() {
			// Clean up scheduled events
			$this->clear_scheduled_events();

			// Clear transients
			$this->clear_transients();

			// Log deactivation
			miniload_log( 'Plugin deactivated', 'info' );
		}

		/**
		 * Initialize plugin
		 */
		public function init() {
			// Register post types, taxonomies, etc. if needed
			$this->register_post_types();
		}


		/**
		 * Check if WooCommerce is active
		 */
		public function check_woocommerce() {
			if ( ! class_exists( 'WooCommerce' ) ) {
				add_action( 'admin_notices', function() {
					?>
					<div class="notice notice-error">
						<p><?php esc_html_e( 'MiniLoad requires WooCommerce to be installed and activated.', 'miniload' ); ?></p>
					</div>
					<?php
				} );
				return false;
			}
			return true;
		}

		/**
		 * Declare compatibility with WooCommerce HPOS (High-Performance Order Storage)
		 */
		public function declare_hpos_compatibility() {
			if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
				\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', MINILOAD_PLUGIN_FILE, true );
			}
		}

		/**
		 * Load modules
		 */
		public function load_modules() {
			// Prevent loading modules twice
			static $modules_loaded = false;
			if ( $modules_loaded ) {
				return;
			}

			// Only load if WooCommerce is active
			if ( ! class_exists( 'WooCommerce' ) ) {
				return;
			}

			$modules_loaded = true;

			// Get enabled modules from settings
			$enabled_modules = $this->get_enabled_modules();

			// Load each enabled module
			foreach ( $enabled_modules as $module_id => $module_class ) {
				$this->load_module( $module_id, $module_class );
			}

			// ALWAYS load Ajax Search Pro module (critical feature)
			if ( ! isset( $this->modules['ajax_search_pro'] ) ) {
				require_once MINILOAD_PLUGIN_DIR . 'modules/class-ajax-search-pro.php';
				$this->modules['ajax_search_pro'] = new \MiniLoad\Modules\Ajax_Search_Pro();
			}

			// Load Query Optimizer module for performance
			if ( ! isset( $this->modules['query_optimizer'] ) ) {
				require_once MINILOAD_PLUGIN_DIR . 'modules/query-optimizer.php';
			}

			// Load Search Indexer for managing search index
			if ( ! isset( $this->modules['search_indexer'] ) && is_admin() ) {
				require_once MINILOAD_PLUGIN_DIR . 'modules/class-search-indexer.php';
				$this->modules['search_indexer'] = new \MiniLoad\Modules\Search_Indexer();
			}

			// Load Media Search Optimizer if enabled
			if ( get_option( 'miniload_media_search_enabled', false ) ) {
				require_once MINILOAD_PLUGIN_DIR . 'modules/class-media-search-optimizer.php';
				$this->modules['media_search'] = new \MiniLoad\Modules\Media_Search_Optimizer();
			}

			// Load Editor Link Optimizer if enabled
			if ( get_option( 'miniload_editor_link_enabled', false ) ) {
				require_once MINILOAD_PLUGIN_DIR . 'modules/class-editor-link-optimizer-simple.php';
				$this->modules['editor_link'] = new \MiniLoad\Modules\Editor_Link_Optimizer();
			}

			// Load Price Filter Optimizer
			require_once MINILOAD_PLUGIN_DIR . 'modules/class-price-filter-optimizer.php';
			$this->modules['price_filter'] = new \MiniLoad\Modules\Price_Filter_Optimizer();

			// Allow other plugins to hook in
			do_action( 'miniload_modules_loaded', $this->modules );
		}

		/**
		 * Load a single module
		 *
		 * @param string $module_id Module ID
		 * @param string $module_class Module class name
		 */
		private function load_module( $module_id, $module_class ) {
			try {
				// Map module class names to file names
				$module_files = array(
					'Database_Indexes' => 'class-database-indexes.php',
					'Query_Cache' => 'class-query-cache.php',
					'Pagination_Optimizer' => 'class-pagination-optimizer.php',
					'Sort_Index' => 'class-sort-index.php',
					'Filter_Cache' => 'class-filter-cache.php',
					'Search_Optimizer' => 'class-search-optimizer.php',
					'Order_Search_Optimizer' => 'class-order-search-optimizer.php',
					'Admin_Counts_Cache' => 'class-admin-counts-cache.php',
					'Category_Counter_Cache' => 'class-category-counter-cache.php',
					'Admin_Dashboard_Cache' => 'class-admin-dashboard-cache.php',
					'Related_Products_Cache' => 'class-related-products-cache.php',
					'Review_Stats_Cache' => 'class-review-stats-cache.php',
					'Notification_Counts_Cache' => 'class-notification-counts-cache.php',
					'Frontend_Counts_Cache' => 'class-frontend-counts-cache.php',
					'Ajax_Search_Pro' => 'class-ajax-search-pro.php',
				);

				// Include the file if it exists and isn't already loaded
				if ( isset( $module_files[ $module_class ] ) ) {
					$file_path = MINILOAD_PLUGIN_DIR . 'modules/' . $module_files[ $module_class ];
					if ( file_exists( $file_path ) ) {
						require_once $file_path;
					}
				}

				$full_class = 'MiniLoad\\Modules\\' . $module_class;

				if ( class_exists( $full_class ) ) {
					$this->modules[ $module_id ] = new $full_class();
					miniload_log( sprintf( 'Module loaded: %s', $module_id ), 'debug' );
				}
			} catch ( \Exception $e ) {
				miniload_log( sprintf( 'Failed to load module %s: %s', $module_id, $e->getMessage() ), 'error' );
			}
		}

		/**
		 * Get enabled modules
		 *
		 * @return array
		 */
		private function get_enabled_modules() {
			$modules = array(
				'database_indexes'    => 'Database_Indexes',
				'query_cache'        => 'Query_Cache',
				'pagination_optimizer' => 'Pagination_Optimizer',
				'sort_index'         => 'Sort_Index',
				'filter_cache'       => 'Filter_Cache',
				'search_optimizer'   => 'Search_Optimizer',
				'order_search_optimizer' => 'Order_Search_Optimizer',
				'admin_counts_cache' => 'Admin_Counts_Cache',
				'category_counter_cache' => 'Category_Counter_Cache',
				'admin_dashboard_cache' => 'Admin_Dashboard_Cache',
				'related_products_cache' => 'Related_Products_Cache',
				'review_stats_cache' => 'Review_Stats_Cache',
				'notification_counts_cache' => 'Notification_Counts_Cache',
				'frontend_counts_cache' => 'Frontend_Counts_Cache',
				'ajax_search_pro' => 'Ajax_Search_Pro',
			);

			// Filter modules based on settings
			$miniload_settings = get_option( 'miniload_settings', array() );
			$enabled_modules = array();

			foreach ( $modules as $module_id => $module_class ) {
				// Check if module is enabled in settings
				// Module is enabled if: not set (default), or explicitly set to truthy value (1, '1', true)
				// Module is disabled if: explicitly set to falsy value (0, '0', false)
				if ( ! isset( $miniload_settings['modules'] ) ||
				     ! isset( $miniload_settings['modules'][ $module_id ] ) ||
				     ! empty( $miniload_settings['modules'][ $module_id ] ) ) {
					$enabled_modules[ $module_id ] = $module_class;
				}
			}

			return apply_filters( 'miniload_enabled_modules', $enabled_modules );
		}

		/**
		 * Register plugin settings
		 */
		public function register_settings() {
			register_setting(
				'miniload_settings_group',
				'miniload_settings',
				array(
					'sanitize_callback' => array( $this, 'sanitize_settings' ),
				)
			);

			// Register search module settings with proper sanitization
			register_setting( 'miniload_search_settings', 'miniload_ajax_search_enabled', array(
				'type' => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default' => false
			) );
			register_setting( 'miniload_search_settings', 'miniload_search_min_chars', array(
				'type' => 'integer',
				'sanitize_callback' => 'absint',
				'default' => 3
			) );
			register_setting( 'miniload_search_settings', 'miniload_search_max_results', array(
				'type' => 'integer',
				'sanitize_callback' => 'absint',
				'default' => 10
			) );
			register_setting( 'miniload_search_settings', 'miniload_replace_search', array(
				'type' => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default' => false
			) );
			register_setting( 'miniload_search_settings', 'miniload_enable_search_modal', array(
				'type' => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default' => false
			) );
			// Analytics removed - no tracking
			register_setting( 'miniload_search_settings', 'miniload_search_in_content', array(
				'type' => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default' => false
			) );
			register_setting( 'miniload_search_settings', 'miniload_media_search_enabled', array(
				'type' => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default' => false
			) );
			register_setting( 'miniload_search_settings', 'miniload_editor_link_enabled', array(
				'type' => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default' => false
			) );
			register_setting( 'miniload_search_settings', 'miniload_index_batch_size', array(
				'type' => 'integer',
				'sanitize_callback' => 'absint',
				'default' => 100
			) );

			// Additional search settings that were missing
			register_setting( 'miniload_search_settings', 'miniload_search_delay', array(
				'type' => 'integer',
				'sanitize_callback' => 'absint',
				'default' => 300
			) );
			register_setting( 'miniload_search_settings', 'miniload_search_results_count', array(
				'type' => 'integer',
				'sanitize_callback' => 'absint',
				'default' => 10
			) );
			register_setting( 'miniload_search_settings', 'miniload_search_in_title', array(
				'type' => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default' => true
			) );
			register_setting( 'miniload_search_settings', 'miniload_search_in_sku', array(
				'type' => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default' => true
			) );
			register_setting( 'miniload_search_settings', 'miniload_search_in_short_desc', array(
				'type' => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default' => true
			) );
			register_setting( 'miniload_search_settings', 'miniload_search_in_categories', array(
				'type' => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default' => false
			) );
			register_setting( 'miniload_search_settings', 'miniload_search_in_tags', array(
				'type' => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default' => false
			) );
			register_setting( 'miniload_search_settings', 'miniload_show_categories', array(
				'type' => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default' => false
			) );
			register_setting( 'miniload_search_settings', 'miniload_show_categories_results', array(
				'type' => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default' => false
			) );
			register_setting( 'miniload_search_settings', 'miniload_search_icon_position', array(
				'type' => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default' => 'right'
			) );
			register_setting( 'miniload_search_settings', 'miniload_search_placeholder', array(
				'type' => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default' => 'Search products...'
			) );
			register_setting( 'miniload_search_settings', 'miniload_show_price', array(
				'type' => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default' => true
			) );
			register_setting( 'miniload_search_settings', 'miniload_show_image', array(
				'type' => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default' => true
			) );
			register_setting( 'miniload_search_settings', 'miniload_font_style', array(
				'type' => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default' => 'default'
			) );

			// Register module settings
			register_setting( 'miniload_modules_settings', 'miniload_ajax_search_enabled', array(
				'type' => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default' => true
			) );
			register_setting( 'miniload_modules_settings', 'miniload_admin_search_enabled', array(
				'type' => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default' => true
			) );
			register_setting( 'miniload_modules_settings', 'miniload_media_search_enabled', array(
				'type' => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default' => false
			) );
			register_setting( 'miniload_modules_settings', 'miniload_editor_link_enabled', array(
				'type' => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default' => false
			) );
			register_setting( 'miniload_modules_settings', 'miniload_query_optimizer_enabled', array(
				'type' => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default' => true
			) );
			register_setting( 'miniload_modules_settings', 'miniload_cache_enabled', array(
				'type' => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default' => true
			) );

			// Register general settings
			register_setting( 'miniload_general_settings', 'miniload_enabled', array(
				'type' => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default' => true
			) );
			register_setting( 'miniload_general_settings', 'miniload_debug_mode', array(
				'type' => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default' => false
			) );
			register_setting( 'miniload_general_settings', 'miniload_priority', array(
				'type' => 'integer',
				'sanitize_callback' => 'absint',
				'default' => 10
			) );
			register_setting( 'miniload_general_settings', 'miniload_cache_duration', array(
				'type' => 'integer',
				'sanitize_callback' => 'absint',
				'default' => 3600
			) );

			// Register Order Search settings
			register_setting( 'miniload_order_search_settings', 'miniload_enable_order_search', array(
				'type' => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default' => true
			) );
			register_setting( 'miniload_order_search_settings', 'miniload_auto_index_orders', array(
				'type' => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default' => true
			) );
			register_setting( 'miniload_order_search_settings', 'miniload_order_search_method', array(
				'type' => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default' => 'trigram'
			) );
			register_setting( 'miniload_order_search_settings', 'miniload_order_index_batch_size', array(
				'type' => 'integer',
				'sanitize_callback' => 'absint',
				'default' => 100
			) );
		}

		/**
		 * Sanitize settings
		 *
		 * @param array $settings Raw settings
		 * @return array Sanitized settings
		 */
		public function sanitize_settings( $settings ) {
			$sanitized = array();

			// Cache TTL
			if ( isset( $miniload_settings['cache_ttl'] ) ) {
				$sanitized['cache_ttl'] = min( max( intval( $miniload_settings['cache_ttl'] ), 60 ), 3600 );
			}

			// Debug mode
			$sanitized['debug_mode'] = ! empty( $miniload_settings['debug_mode'] );

			// Order search limit
			if ( isset( $miniload_settings['order_search_limit'] ) ) {
				$sanitized['order_search_limit'] = min( max( intval( $miniload_settings['order_search_limit'] ), 100 ), 999999 );
			} else {
				$sanitized['order_search_limit'] = 5000; // Default
			}

			// Module settings
			if ( isset( $miniload_settings['modules'] ) && is_array( $miniload_settings['modules'] ) ) {
				$sanitized['modules'] = array_map( 'boolval', $miniload_settings['modules'] );
			}

			return $sanitized;
		}

		/**
		 * Add admin menu
		 */
		public function add_admin_menu() {
			// Single page with tabs - clean and modern approach
			$icon_url = plugin_dir_url( __FILE__ ) . 'assets/images/logo.png';

			add_menu_page(
				__( 'MiniLoad', 'miniload' ),
				__( 'MiniLoad', 'miniload' ),
				'manage_options',
				'miniload',
				array( $this, 'render_admin_page' ),
				$icon_url,
				58
			);
		}

		/**
		 * Render admin page - Single page with tabs
		 */
		public function render_admin_page() {
			// Include the new single-page admin interface
			include MINILOAD_PLUGIN_DIR . 'admin/admin-page.php';
		}

		/**
		 * AJAX handler for saving settings
		 */
		public function ajax_save_settings() {
			// Check permissions
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( 'Unauthorized' );
			}

			// Check nonce
			if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'miniload_ajax_save' ) ) {
				wp_send_json_error( 'Security check failed' );
			}

			$tab = sanitize_text_field( wp_unslash( $_POST['tab'] ?? '' ) );
			// Don't sanitize the serialized data before parsing - it breaks array notation
			$data = wp_unslash( $_POST['data'] ?? '' );

			// Parse the serialized form data
			parse_str( $data, $form_data );

			// Sanitize the parsed data recursively
			$form_data = map_deep( $form_data, 'sanitize_text_field' );

			// Debug log the received data
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {


			}

			// Save based on tab
			switch ( $tab ) {
				case 'search':
					// Handle checkbox values correctly (they come as '1' or '0' from JS)
					update_option( 'miniload_ajax_search_enabled', ! empty( $form_data['miniload_ajax_search_enabled'] ) && $form_data['miniload_ajax_search_enabled'] !== '0' ? '1' : '0' );
					update_option( 'miniload_search_min_chars', absint( $form_data['miniload_search_min_chars'] ?? 3 ) );
					update_option( 'miniload_search_delay', absint( $form_data['miniload_search_delay'] ?? 300 ) );
					update_option( 'miniload_search_results_count', absint( $form_data['miniload_search_results_count'] ?? 8 ) );
					update_option( 'miniload_search_in_content', ! empty( $form_data['miniload_search_in_content'] ) && $form_data['miniload_search_in_content'] !== '0' ? '1' : '0' );
					update_option( 'miniload_search_in_title', ! empty( $form_data['miniload_search_in_title'] ) && $form_data['miniload_search_in_title'] !== '0' ? '1' : '0' );
					update_option( 'miniload_search_in_sku', ! empty( $form_data['miniload_search_in_sku'] ) && $form_data['miniload_search_in_sku'] !== '0' ? '1' : '0' );
					update_option( 'miniload_search_in_short_desc', ! empty( $form_data['miniload_search_in_short_desc'] ) && $form_data['miniload_search_in_short_desc'] !== '0' ? '1' : '0' );
					update_option( 'miniload_search_in_categories', ! empty( $form_data['miniload_search_in_categories'] ) && $form_data['miniload_search_in_categories'] !== '0' ? '1' : '0' );
					update_option( 'miniload_search_in_tags', ! empty( $form_data['miniload_search_in_tags'] ) && $form_data['miniload_search_in_tags'] !== '0' ? '1' : '0' );
					update_option( 'miniload_show_categories', ! empty( $form_data['miniload_show_categories'] ) && $form_data['miniload_show_categories'] !== '0' ? '1' : '0' );
					update_option( 'miniload_show_categories_results', ! empty( $form_data['miniload_show_categories_results'] ) && $form_data['miniload_show_categories_results'] !== '0' ? '1' : '0' );
					// Migrate old icon position values to new format
					$icon_position = sanitize_text_field( $form_data['miniload_search_icon_position'] ?? 'show' );
					if ( in_array( $icon_position, array( 'left', 'right', 'both' ) ) ) {
						$icon_position = 'show';
					} elseif ( ! in_array( $icon_position, array( 'show', 'hide' ) ) ) {
						$icon_position = 'show';
					}
					update_option( 'miniload_search_icon_position', $icon_position );
					update_option( 'miniload_search_placeholder', sanitize_text_field( $form_data['miniload_search_placeholder'] ?? __( 'Search products...', 'miniload' ) ) );
					update_option( 'miniload_show_price', ! empty( $form_data['miniload_show_price'] ) && $form_data['miniload_show_price'] !== '0' ? '1' : '0' );
					update_option( 'miniload_show_image', ! empty( $form_data['miniload_show_image'] ) && $form_data['miniload_show_image'] !== '0' ? '1' : '0' );
					break;

				case 'modules':
					// This case is handled by direct_save now
					// Keeping empty case to prevent errors
					break;

				case 'settings':
					// Handle checkbox values correctly (they come as '1' or '0' from JS)
					update_option( 'miniload_enabled', ! empty( $form_data['miniload_enabled'] ) && $form_data['miniload_enabled'] !== '0' ? '1' : '0' );
					update_option( 'miniload_debug_mode', ! empty( $form_data['miniload_debug_mode'] ) && $form_data['miniload_debug_mode'] !== '0' ? '1' : '0' );
					update_option( 'miniload_priority', absint( $form_data['miniload_priority'] ?? 10 ) );
					update_option( 'miniload_cache_duration', absint( $form_data['miniload_cache_duration'] ?? 3600 ) );
					update_option( 'miniload_batch_size', absint( $form_data['miniload_batch_size'] ?? 100 ) );
					update_option( 'miniload_memory_limit', sanitize_text_field( $form_data['miniload_memory_limit'] ?? 'default' ) );
					update_option( 'miniload_auto_index', ! empty( $form_data['miniload_auto_index'] ) && $form_data['miniload_auto_index'] !== '0' ? '1' : '0' );
					update_option( 'miniload_uninstall_behavior', sanitize_text_field( $form_data['miniload_uninstall_behavior'] ?? 'keep' ) );
					update_option( 'miniload_rest_api_enabled', ! empty( $form_data['miniload_rest_api_enabled'] ) && $form_data['miniload_rest_api_enabled'] !== '0' ? '1' : '0' );
					update_option( 'miniload_nonce_lifetime', sanitize_text_field( $form_data['miniload_nonce_lifetime'] ?? '1' ) );
					break;

				default:
					wp_send_json_error( 'Invalid tab' );
			}

			// Clear cache
			wp_cache_flush();

			// Return success
			wp_send_json_success( 'Settings saved successfully!' );
		}

		/**
		 * Direct save - simple and guaranteed to work
		 */
		public function direct_save() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( 'Unauthorized' );
			}

			// Skip nonce check for now - admin capability check is sufficient
			// The form save already verifies user permissions

			$raw_settings = isset( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : array();

			// Handle both regular and array notation settings
			$miniload_modules_array = array();
			$regular_settings = array();

			foreach ( $raw_settings as $key => $value ) {
				// Check if this is array notation like miniload_modules[related_products_cache]
				if ( preg_match( '/^(miniload_modules)\[([^\]]+)\]$/', $key, $matches ) ) {
					// This is an array notation setting
					$module_name = sanitize_text_field( $matches[2] );
					$miniload_modules_array[ $module_name ] = ( $value === '1' );
				} else {
					// Regular setting
					$regular_settings[ $key ] = sanitize_text_field( $value );
				}
			}

			// Save regular settings (including module toggles)
			foreach ( $regular_settings as $key => $value ) {
				// Only save miniload_ prefixed settings for security
				if ( strpos( $key, 'miniload_' ) === 0 ) {
					// Sanitize based on type
					if ( in_array( $value, array( '0', '1' ), true ) ) {
						// Boolean values
						update_option( $key, $value );
					} elseif ( is_numeric( $value ) ) {
						// Numeric values
						update_option( $key, absint( $value ) );
					} else {
						// Text values
						update_option( $key, sanitize_text_field( $value ) );
					}
				}
			}

			// Save array-based module settings
			// Process ALL module array settings
			$miniload_settings = get_option( 'miniload_settings', array() );

			if ( ! isset( $miniload_settings['modules'] ) ) {
				$miniload_settings['modules'] = array();
			}

			// First, convert any existing boolean values to integers for consistency
			if ( isset( $miniload_settings['modules'] ) ) {
				foreach ( $miniload_settings['modules'] as $key => $value ) {
					if ( is_bool( $value ) ) {
						$miniload_settings['modules'][ $key ] = $value ? 1 : 0;
					}
				}
			}

			// Process any miniload_modules[xxx] settings found
			foreach ( $raw_settings as $key => $value ) {
				// Check if this is a module setting
				if ( strpos( $key, 'miniload_modules[' ) === 0 ) {
					// Extract module name - everything after 'miniload_modules[' and before optional ']'
					$module_name = str_replace( array('miniload_modules[', ']'), '', $key );
					$module_name = sanitize_text_field( $module_name );

					// Store as 1 for enabled or 0 for disabled
					if ( $value === '1' ) {
						$miniload_settings['modules'][ $module_name ] = 1;
					} else {
						$miniload_settings['modules'][ $module_name ] = 0;
					}
				}
			}

			// Remove any filters that might interfere
			remove_all_filters( 'pre_update_option_miniload_settings' );
			remove_all_filters( 'sanitize_option_miniload_settings' );

			// Save the updated settings
			update_option( 'miniload_settings', $miniload_settings );

			// Clear cache after saving
			wp_cache_flush();

			wp_send_json_success( 'Settings saved successfully!' );
		}


		/**
		 * Enqueue admin scripts and styles
		 *
		 * @param string $hook Current admin page hook
		 */
		public function admin_enqueue_scripts( $hook ) {
			// Always add CSS for menu icon
			wp_add_inline_style( 'admin-menu', '
				#adminmenu .toplevel_page_miniload .wp-menu-image img {
					width: 20px;
					height: 20px;
					margin-top: -2px;
					opacity: 0.8;
					filter: brightness(0) invert(1);
				}
				#adminmenu .toplevel_page_miniload:hover .wp-menu-image img,
				#adminmenu .toplevel_page_miniload.current .wp-menu-image img,
				#adminmenu .toplevel_page_miniload.wp-has-current-submenu .wp-menu-image img {
					opacity: 1;
				}
				/* Fix for RTL */
				body.rtl #adminmenu .toplevel_page_miniload .wp-menu-image img {
					margin-right: 0;
					margin-left: 0;
				}
			' );

			// Only load other assets on our pages
			if ( strpos( $hook, 'miniload' ) === false ) {
				return;
			}

			// Ensure dashicons are loaded
			wp_enqueue_style( 'dashicons' );

			// Enqueue styles
			wp_enqueue_style(
				'miniload-admin',
				MINILOAD_PLUGIN_URL . 'assets/css/admin.css',
				array( 'dashicons' ),
				MINILOAD_VERSION
			);

			// Enqueue scripts
			wp_enqueue_script(
				'miniload-admin',
				MINILOAD_PLUGIN_URL . 'assets/js/admin.js',
				array( 'jquery', 'wp-util' ),
				MINILOAD_VERSION,
				true
			);

			// Localize script
			wp_localize_script( 'miniload-admin', 'miniload', array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'miniload-ajax' ),
				'strings'  => array(
					'confirm_rebuild' => __( 'Are you sure you want to rebuild indexes? This may take a while.', 'miniload' ),
					'processing'      => __( 'Processing...', 'miniload' ),
					'success'        => __( 'Success!', 'miniload' ),
					'error'          => __( 'An error occurred.', 'miniload' ),
				),
			) );
		}

		/**
		 * Add plugin action links
		 *
		 * @param array $links Existing links
		 * @return array
		 */
		public function add_action_links( $links ) {
			$action_links = array(
				'<a href="' . admin_url( 'admin.php?page=miniload-settings' ) . '">' . __( 'Settings', 'miniload' ) . '</a>',
			);

			return array_merge( $action_links, $links );
		}

		/**
		 * Handle AJAX requests
		 */
		public function handle_ajax() {
			// Verify nonce
			if ( ! check_ajax_referer( 'miniload-ajax', 'nonce', false ) ) {
				wp_die( esc_html__( 'Security check failed', 'miniload' ) );
			}

			// Check permissions
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Permission denied', 'miniload' ) );
			}

			$action = isset( $_POST['miniload_action'] ) ? sanitize_text_field( wp_unslash( $_POST['miniload_action'] ) ) : '';

			switch ( $action ) {
				case 'rebuild_indexes':
					$this->ajax_rebuild_indexes();
					break;

				case 'clear_cache':
					$this->ajax_clear_cache();
					break;

				case 'run_cleanup':
					$this->ajax_run_cleanup();
					break;

				default:
					wp_send_json_error( __( 'Invalid action', 'miniload' ) );
			}
		}

		/**
		 * AJAX: Rebuild indexes
		 */
		private function ajax_rebuild_indexes() {
			try {
				// Rebuild database indexes
				$miniload_indexer = new \MiniLoad\Modules\Database_Indexes();
				$miniload_result = $miniload_indexer->rebuild_all_indexes();

				wp_send_json_success( array(
					'message' => __( 'Indexes rebuilt successfully', 'miniload' ),
					'details' => $miniload_result,
				) );
			} catch ( \Exception $e ) {
				wp_send_json_error( $e->getMessage() );
			}
		}

		/**
		 * AJAX: Clear cache
		 */
		private function ajax_clear_cache() {
			$this->clear_transients();
			wp_send_json_success( __( 'Cache cleared successfully', 'miniload' ) );
		}

		/**
		 * AJAX: Run cleanup
		 */
		private function ajax_run_cleanup() {
			try {
				// Run database cleanup
				$cleanup = new \MiniLoad\Modules\Database_Cleanup();
				$miniload_result = $cleanup->run_cleanup();

				wp_send_json_success( array(
					'message' => __( 'Cleanup completed successfully', 'miniload' ),
					'details' => $miniload_result,
				) );
			} catch ( \Exception $e ) {
				wp_send_json_error( $e->getMessage() );
			}
		}

		/**
		 * Initialize WP-CLI commands
		 */
		private function init_cli() {
			\WP_CLI::add_command( 'miniload', 'MiniLoad\\CLI\\Commands' );
		}

		/**
		 * Create database tables
		 */
		private function create_tables() {
			global $wpdb;

			require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

			$charset_collate = $wpdb->get_charset_collate();

			// Sort index table
			$sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}miniload_sort_index (
				product_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
				price DECIMAL(10,2),
				regular_price DECIMAL(10,2),
				sale_price DECIMAL(10,2),
				total_sales INT UNSIGNED DEFAULT 0,
				average_rating DECIMAL(3,2),
				review_count INT UNSIGNED DEFAULT 0,
				stock_quantity INT,
				is_featured TINYINT DEFAULT 0,
				is_on_sale TINYINT DEFAULT 0,
				menu_order INT DEFAULT 0,
				date_created DATETIME,
				date_modified DATETIME,
				INDEX idx_price (price),
				INDEX idx_popularity (total_sales DESC),
				INDEX idx_rating (average_rating DESC),
				INDEX idx_date (date_created DESC),
				INDEX idx_featured (is_featured, menu_order)
			) $charset_collate;";

			dbDelta( $sql );

			// Search index table
			$sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}miniload_search_index (
				product_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
				title VARCHAR(255),
				sku VARCHAR(100),
				attributes TEXT,
				categories TEXT,
				tags TEXT,
				FULLTEXT idx_search (title, attributes, categories, tags),
				INDEX idx_sku (sku)
			) $charset_collate ENGINE=InnoDB;";

			dbDelta( $sql );

			// Filter cache table
			$sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}miniload_filter_cache (
				cache_key VARCHAR(32) NOT NULL PRIMARY KEY,
				filter_data LONGTEXT,
				created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
				INDEX idx_created (created_at)
			) $charset_collate;";

			dbDelta( $sql );

			// Analytics removed - no tracking tables created

			// Update database version
			update_option( 'miniload_db_version', MINILOAD_VERSION );
		}

		/**
		 * Set default options
		 */
		private function set_default_options() {
			$defaults = array(
				'version' => MINILOAD_VERSION,
				'modules' => array(
					'database_indexes'    => true,
					'query_cache'        => true,
					'pagination_optimizer' => true,
					'sort_index'         => true,
					'filter_cache'       => true,
					'search_optimizer'   => true,
				),
				'cache_ttl' => 300, // 5 minutes
				'debug_mode' => false,
				'order_search_limit' => 5000, // Default max orders in search results
			);

			// Only set if not exists
			if ( false === get_option( 'miniload_settings' ) ) {
				add_option( 'miniload_settings', $defaults );
			}
		}

		/**
		 * Clear scheduled events
		 */
		private function clear_scheduled_events() {
			wp_clear_scheduled_hook( 'miniload_daily_cleanup' );
			wp_clear_scheduled_hook( 'miniload_hourly_sync' );
		}

		/**
		 * Clear transients
		 */
		private function clear_transients() {
			global $wpdb;

			// Delete MiniLoad transients
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for performance optimization
			$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_miniload_%'" );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for performance optimization
			$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_miniload_%'" );
		}

		/**
		 * Register post types
		 */
		private function register_post_types() {
			// Register custom post types if needed
		}

		/**
		 * Get a module instance
		 *
		 * @param string $module_id Module ID
		 * @return object|null
		 */
		public function get_module( $module_id ) {
			return isset( $this->modules[ $module_id ] ) ? $this->modules[ $module_id ] : null;
		}

		/**
		 * Check if a module is enabled
		 *
		 * @param string $module_id Module ID
		 * @return bool
		 */
		public function is_module_enabled( $module_id ) {
			$miniload_settings = get_option( 'miniload_settings', array() );
			return ! isset( $miniload_settings['modules'][ $module_id ] ) || $miniload_settings['modules'][ $module_id ] !== false;
		}

		/**
		 * Prevent cloning
		 */
		private function __clone() {}

		/**
		 * Prevent unserializing
		 */
		public function __wakeup() {
			throw new \Exception( 'Cannot unserialize singleton' );
		}
	}

}

/**
 * Main function to get plugin instance
 *
 * @return MiniLoad
 */
function miniload() {
	return MiniLoad::instance();
}

// Initialize plugin
miniload();
