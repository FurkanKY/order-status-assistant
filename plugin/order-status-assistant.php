<?php
/**
 * Plugin Name:       Order Status Assistant for WooCommerce
 * Description:       Customers check their order status and tracking number by chatting with an AI assistant, and never see anyone else's order.
 * Version:           0.2.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Text Domain:       order-status-assistant
 * License:           GPL-2.0-or-later
 *
 * @package OrderStatusAssistant
 */

defined( 'ABSPATH' ) || exit;

define( 'OSA_PLUGIN_FILE', __FILE__ );
define( 'OSA_VERSION', '0.2.0' );

require_once __DIR__ . '/includes/class-osa-throttle.php';
require_once __DIR__ . '/includes/class-osa-tracking.php';
require_once __DIR__ . '/includes/class-osa-order-lookup.php';
require_once __DIR__ . '/includes/class-osa-chat.php';
require_once __DIR__ . '/includes/class-osa-settings.php';
require_once __DIR__ . '/includes/class-osa-widget.php';
require_once __DIR__ . '/includes/class-osa-rest-controller.php';

// Declare HPOS support so WooCommerce doesn't flag the plugin as incompatible.
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', OSA_PLUGIN_FILE, true );
		}
	}
);

OSA_Settings::register();
OSA_Widget::register();

add_action(
	'rest_api_init',
	function () {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$lookup         = new OSA_Order_Lookup();
		$failed_lookups = OSA_Throttle::failed_lookups();
		$controller     = new OSA_REST_Controller( $lookup, new OSA_Chat( $lookup, $failed_lookups ), $failed_lookups, OSA_Throttle::chat_messages() );
		$controller->register_routes();
	}
);
