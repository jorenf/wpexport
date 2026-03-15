<?php
/**
 * Plugin Name:       WC Order Export/Import
 * Plugin URI:        https://github.com/jorenf/wpexport
 * Description:       Export WooCommerce orders to CSV by date range and import orders from CSV.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            WP Developer
 * License:           GPL v2 or later
 * Text Domain:       wc-order-export-import
 * WC requires at least: 6.0
 * WC tested up to:   8.5
 */

defined( 'ABSPATH' ) || exit;

define( 'WCEI_VERSION',    '1.0.0' );
define( 'WCEI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WCEI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Bootstrap the plugin after all plugins are loaded so WooCommerce is available.
 */
add_action( 'plugins_loaded', 'wcei_init', 20 );

function wcei_init() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p>'
				. esc_html__( 'WC Order Export/Import requires WooCommerce to be installed and active.', 'wc-order-export-import' )
				. '</p></div>';
		} );
		return;
	}

	require_once WCEI_PLUGIN_DIR . 'includes/class-wcei-exporter.php';
	require_once WCEI_PLUGIN_DIR . 'includes/class-wcei-importer.php';
	require_once WCEI_PLUGIN_DIR . 'includes/class-wcei-admin.php';

	new WCEI_Admin();
}
