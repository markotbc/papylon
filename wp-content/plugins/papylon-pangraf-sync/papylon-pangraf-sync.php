<?php
/**
 * Plugin Name:       Papylon Pangraf Sync
 * Description:       Imports products, prices and stock from the Pangraf API into WooCommerce on a schedule.
 * Version:           1.0.0
 * Author:            Papylon
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Text Domain:       papylon-pangraf-sync
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/includes/class-settings.php';
require_once __DIR__ . '/includes/class-api.php';
require_once __DIR__ . '/includes/class-sync.php';
require_once __DIR__ . '/includes/class-admin.php';

/**
 * Boot once WooCommerce (and its bundled Action Scheduler) is loaded.
 */
function papylon_pangraf_sync_boot() {
	if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
		add_action(
			'admin_notices',
			function () {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'Papylon Pangraf Sync requires WooCommerce.', 'papylon-pangraf-sync' ) . '</p></div>';
			}
		);
		return;
	}

	Papylon_Pangraf_Sync::init();

	if ( is_admin() ) {
		Papylon_Pangraf_Admin::init();
	}
}
add_action( 'plugins_loaded', 'papylon_pangraf_sync_boot' );

register_activation_hook( __FILE__, array( 'Papylon_Pangraf_Sync', 'reschedule' ) );
register_deactivation_hook( __FILE__, array( 'Papylon_Pangraf_Sync', 'unschedule' ) );
