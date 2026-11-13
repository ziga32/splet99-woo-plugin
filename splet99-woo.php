<?php
/**
 * Plugin Name:          Splet99 Woo
 * Plugin URI:           https://github.com/ziga32/splet99-woo-plugin
 * Description:          Orodja Splet99 za spletne trgovine WooCommerce, ki jih vzdržujemo.
 * Version:              0.3.0
 * Requires at least:    6.0
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * WC requires at least: 8.0
 * Author:               Splet99 d.o.o.
 * Author URI:           https://splet99.net
 * Text Domain:          splet99-woo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SPLET99_WOO_VERSION', '0.3.0' );
define( 'SPLET99_WOO_FILE', __FILE__ );
define( 'SPLET99_WOO_DIR', plugin_dir_path( __FILE__ ) );
define( 'SPLET99_WOO_URL', plugin_dir_url( __FILE__ ) );

require_once SPLET99_WOO_DIR . 'includes/class-plugin.php';

\Splet99\Woo\Plugin::init();

add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );
