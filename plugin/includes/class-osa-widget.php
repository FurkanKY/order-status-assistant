<?php
/**
 * Front-end chat box.
 *
 * @package OrderStatusAssistant
 */

defined( 'ABSPATH' ) || exit;

/**
 * [order_status_assistant] shortcode. The page only learns the chat endpoint, never the API key.
 */
class OSA_Widget {

	public static function register() {
		add_shortcode( 'order_status_assistant', array( __CLASS__, 'render' ) );
	}

	public static function render() {
		wp_enqueue_style( 'osa-widget', plugins_url( 'assets/widget.css', OSA_PLUGIN_FILE ), array(), OSA_VERSION );
		wp_enqueue_script( 'osa-widget', plugins_url( 'assets/widget.js', OSA_PLUGIN_FILE ), array(), OSA_VERSION, true );
		wp_localize_script(
			'osa-widget',
			'osaWidget',
			array(
				'endpoint' => esc_url_raw( rest_url( OSA_REST_Controller::ROUTE_NAMESPACE . '/chat' ) ),
				'strings'  => array(
					'greeting'    => __( 'Hi! Send me your order number and the email address you used, and I\'ll check on your order.', 'order-status-assistant' ),
					'placeholder' => __( 'Type your message', 'order-status-assistant' ),
					'send'        => __( 'Send', 'order-status-assistant' ),
					'error'       => __( 'Something went wrong. Please try again.', 'order-status-assistant' ),
				),
			)
		);

		return '<div class="osa-chat" data-osa-chat></div>';
	}
}
