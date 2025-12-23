<?php
/**
 * Order Search Tab Content
 *
 * @package MiniLoad
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

// Include the order search settings page
if ( file_exists( MINILOAD_PLUGIN_DIR . 'admin/settings-order-search.php' ) ) {
	include MINILOAD_PLUGIN_DIR . 'admin/settings-order-search.php';
} else {
	echo '<p>' . esc_html__( 'Order Search settings file not found.', 'miniload' ) . '</p>';
}
?>