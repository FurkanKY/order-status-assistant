<?php
/**
 * Plugin Name:       Parcelwise
 * Description:       Verified order status chat for WooCommerce. Customers ask where their order is and get its status and tracking number, and never see anyone else's order.
 * Version:           0.3.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Text Domain:       parcelwise
 * License:           GPL-2.0-or-later
 *
 * @package Parcelwise
 */

defined( 'ABSPATH' ) || exit;

define( 'PARCELWISE_PLUGIN_FILE', __FILE__ );
define( 'PARCELWISE_VERSION', '0.3.0' );

require_once __DIR__ . '/includes/class-parcelwise-throttle.php';
require_once __DIR__ . '/includes/class-parcelwise-carriers.php';
require_once __DIR__ . '/includes/class-parcelwise-tracking.php';
require_once __DIR__ . '/includes/class-parcelwise-order-lookup.php';
require_once __DIR__ . '/includes/class-parcelwise-gateway.php';
require_once __DIR__ . '/includes/class-parcelwise-anthropic-gateway.php';
require_once __DIR__ . '/includes/class-parcelwise-connector-gateway.php';
require_once __DIR__ . '/includes/class-parcelwise-chat.php';
require_once __DIR__ . '/includes/class-parcelwise-settings.php';
require_once __DIR__ . '/includes/class-parcelwise-widget.php';
require_once __DIR__ . '/includes/class-parcelwise-rest-controller.php';

// Declare HPOS support so WooCommerce doesn't flag the plugin as incompatible.
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', PARCELWISE_PLUGIN_FILE, true );
		}
	}
);

Parcelwise_Settings::register();
Parcelwise_Widget::register();

add_action(
	'rest_api_init',
	function () {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$lookup         = new Parcelwise_Order_Lookup();
		$failed_lookups = Parcelwise_Throttle::failed_lookups();
		$controller     = new Parcelwise_REST_Controller( $lookup, new Parcelwise_Chat( $lookup, $failed_lookups ), $failed_lookups, Parcelwise_Throttle::chat_messages() );
		$controller->register_routes();
	}
);
