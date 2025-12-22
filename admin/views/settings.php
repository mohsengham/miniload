<?php
/**
 * MiniLoad Settings View
 *
 * @package MiniLoad\Admin
 * @since 1.0.0
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

// Security check
if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'miniload' ) );
}

// Get current settings
$miniload_settings = get_option( 'miniload_settings', array() );
?>

<div class="wrap miniload-settings">
	<h1><?php echo esc_html__( 'MiniLoad Settings', 'miniload' ); ?></h1>

	<form method="post" action="options.php">
		<?php settings_fields( 'miniload_settings_group' ); ?>

		<table class="form-table">
			<tr>
				<th scope="row">
					<label for="miniload_cache_ttl">
						<?php echo esc_html__( 'Cache TTL', 'miniload' ); ?>
					</label>
				</th>
				<td>
					<input type="number" id="miniload_cache_ttl" name="miniload_settings[cache_ttl]"
					       value="<?php echo esc_attr( $miniload_settings['cache_ttl'] ?? 300 ); ?>" min="60" max="3600" />
					<p class="description">
						<?php echo esc_html__( 'Cache time-to-live in seconds (60-3600)', 'miniload' ); ?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="miniload_debug_mode">
						<?php echo esc_html__( 'Debug Mode', 'miniload' ); ?>
					</label>
				</th>
				<td>
					<label>
						<input type="checkbox" id="miniload_debug_mode" name="miniload_settings[debug_mode]"
						       value="1" <?php checked( ! empty( $miniload_settings['debug_mode'] ) ); ?> />
						<?php echo esc_html__( 'Enable debug logging', 'miniload' ); ?>
					</label>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="miniload_order_search_limit">
						<?php echo esc_html__( 'Order Search Max Results', 'miniload' ); ?>
					</label>
				</th>
				<td>
					<input type="number" id="miniload_order_search_limit" name="miniload_settings[order_search_limit]"
					       value="<?php echo esc_attr( $miniload_settings['order_search_limit'] ?? 5000 ); ?>" min="100" max="999999" />
					<p class="description">
						<?php echo esc_html__( 'Maximum number of orders to return in search results (100-999999)', 'miniload' ); ?><br>
						<?php echo esc_html__( 'Set to 999999 for unlimited results. Default: 5000', 'miniload' ); ?><br>
						<strong><?php echo esc_html__( 'Note:', 'miniload' ); ?></strong> <?php echo esc_html__( 'Higher values may impact performance on the orders page.', 'miniload' ); ?>
					</p>
				</td>
			</tr>
		</table>

		<?php submit_button(); ?>
	</form>
</div>