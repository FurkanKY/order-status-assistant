<?php
/**
 * Front-end chat box.
 *
 * @package Parcelwise
 */

defined( 'ABSPATH' ) || exit;

/**
 * [parcelwise] shortcode. The page only learns the chat endpoint, never the API key.
 */
class Parcelwise_Widget {

	public static function register() {
		add_shortcode( 'parcelwise', array( __CLASS__, 'render' ) );
	}

	public static function render() {
		wp_enqueue_style( 'parcelwise-widget', plugins_url( 'assets/widget.css', PARCELWISE_PLUGIN_FILE ), array(), PARCELWISE_VERSION );
		wp_enqueue_script( 'parcelwise-widget', plugins_url( 'assets/widget.js', PARCELWISE_PLUGIN_FILE ), array(), PARCELWISE_VERSION, true );
		wp_localize_script(
			'parcelwise-widget',
			'parcelwiseWidget',
			array(
				'endpoint' => esc_url_raw( rest_url( Parcelwise_REST_Controller::ROUTE_NAMESPACE . '/chat' ) ),
				'strings'  => array(
					'greeting'    => __( 'Hi! Send me your order number and the email address you used, and I\'ll check on your order.', 'parcelwise' ),
					'placeholder' => __( 'Type your message', 'parcelwise' ),
					'send'        => __( 'Send', 'parcelwise' ),
					'error'       => __( 'Something went wrong. Please try again.', 'parcelwise' ),
					/* translators: %s: carrier name, such as Aras Kargo. */
					'track'       => __( 'Track with %s', 'parcelwise' ),
					/* translators: %s: carrier name, such as PTT Kargo. */
					'openCarrier' => __( 'Open %s', 'parcelwise' ),
				),
			)
		);

		return '<div class="parcelwise-chat" data-parcelwise-chat></div>';
	}
}
