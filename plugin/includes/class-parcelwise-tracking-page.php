<?php
/**
 * Order tracking form for guests.
 *
 * @package Parcelwise
 */

defined( 'ABSPATH' ) || exit;

/**
 * [parcelwise_tracking] shortcode: order number plus email or postcode, then the order's stage,
 * items and a button to the carrier's tracking page. Needs no AI connection, so it works on any
 * store from the moment the plugin is activated.
 */
class Parcelwise_Tracking_Page {

	public static function register() {
		add_shortcode( 'parcelwise_tracking', array( __CLASS__, 'render' ) );
	}

	/**
	 * What the customer reads, in their own terms rather than WooCommerce's. Keyed by stage, and by
	 * status where one stage covers several: "closed" alone would leave a customer asking support
	 * whether their order was cancelled, refunded or never paid.
	 */
	public static function stage_texts() {
		return array(
			'waiting'   => array(
				'title' => __( 'Order received', 'parcelwise' ),
				'text'  => __( 'We\'ve got your order and will start preparing it once the payment is confirmed.', 'parcelwise' ),
			),
			'preparing' => array(
				'title' => __( 'Preparing', 'parcelwise' ),
				'text'  => __( 'We\'re packing your order. The tracking number will appear here once it ships.', 'parcelwise' ),
			),
			// Not "delivered": WooCommerce keeps an order "completed" whether or not the parcel has arrived.
			'shipped'   => array(
				'title' => __( 'Shipped', 'parcelwise' ),
				'text'  => __( 'Your order has been handed to the carrier. You can follow its latest status on the carrier\'s site.', 'parcelwise' ),
			),
			'cancelled' => array(
				'title' => __( 'Order cancelled', 'parcelwise' ),
				'text'  => __( 'This order was cancelled. If you think this is a mistake, please contact us.', 'parcelwise' ),
			),
			'refunded'  => array(
				'title' => __( 'Refunded', 'parcelwise' ),
				'text'  => __( 'This order has been refunded. How long the money takes to reach your account depends on your bank.', 'parcelwise' ),
			),
			'failed'    => array(
				'title' => __( 'Payment failed', 'parcelwise' ),
				'text'  => __( 'The payment for this order didn\'t go through. You can place the order again or contact us.', 'parcelwise' ),
			),
		);
	}

	public static function render() {
		wp_enqueue_style( 'parcelwise-tracking', plugins_url( 'assets/tracking.css', PARCELWISE_PLUGIN_FILE ), array(), PARCELWISE_VERSION );
		wp_enqueue_script( 'parcelwise-tracking', plugins_url( 'assets/tracking.js', PARCELWISE_PLUGIN_FILE ), array(), PARCELWISE_VERSION, true );
		wp_localize_script(
			'parcelwise-tracking',
			'parcelwiseTracking',
			array(
				'endpoint' => esc_url_raw( rest_url( Parcelwise_REST_Controller::ROUTE_NAMESPACE . '/lookup' ) ),
				'stages'   => self::stage_texts(),
				'steps'    => array( 'waiting', 'preparing', 'shipped' ),
				'strings'  => array(
					/* translators: %s: order number. */
					'order'       => __( 'Order %s', 'parcelwise' ),
					'items'       => __( 'Items', 'parcelwise' ),
					/* translators: 1: refunded amount, 2: currency code. */
					'refunded'    => __( 'Refunded: %1$s %2$s', 'parcelwise' ),
					/* translators: %s: carrier name, such as Aras Kargo. */
					'track'       => __( 'Track with %s', 'parcelwise' ),
					/* translators: %s: carrier name, such as PTT Kargo. */
					'openCarrier' => __( 'Open %s and enter the tracking number', 'parcelwise' ),
					/* translators: %s: tracking number. */
					'trackingNo'  => __( 'Tracking number: %s', 'parcelwise' ),
					'busy'        => __( 'Checking…', 'parcelwise' ),
					'error'       => __( 'Something went wrong. Please try again.', 'parcelwise' ),
				),
			)
		);

		ob_start();
		?>
		<div class="parcelwise-tracking" data-parcelwise-tracking>
			<form class="parcelwise-tracking__form">
				<p>
					<label for="parcelwise-tracking-number"><?php esc_html_e( 'Order number', 'parcelwise' ); ?></label>
					<input id="parcelwise-tracking-number" name="order_number" type="text" required maxlength="64" autocomplete="off">
				</p>
				<p>
					<label for="parcelwise-tracking-owner"><?php esc_html_e( 'Email or postcode used for the order', 'parcelwise' ); ?></label>
					<input id="parcelwise-tracking-owner" name="owner" type="text" required maxlength="254" autocomplete="email">
				</p>
				<button type="submit"><?php esc_html_e( 'Find my order', 'parcelwise' ); ?></button>
			</form>
			<div class="parcelwise-tracking__result" aria-live="polite"></div>
			<noscript><?php esc_html_e( 'Order tracking needs JavaScript to be enabled.', 'parcelwise' ); ?></noscript>
		</div>
		<?php
		return ob_get_clean();
	}
}
