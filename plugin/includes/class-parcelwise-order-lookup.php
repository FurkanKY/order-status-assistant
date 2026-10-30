<?php
/**
 * Order lookup and ownership check.
 *
 * @package Parcelwise
 */

defined( 'ABSPATH' ) || exit;

/**
 * Finds an order from what a customer actually knows: the order number they
 * were shown, plus their email address or postcode.
 *
 * Everything goes through the WooCommerce order API, so lookups behave the same
 * on HPOS and on classic post storage.
 */
class Parcelwise_Order_Lookup {

	/** Most recent orders scanned per email when the number can't be resolved directly. */
	const MAX_ORDERS_PER_EMAIL = 50;

	/** Digit groups tried as order IDs, e.g. "TR-1234-26" gives 1234 and 26. */
	const MAX_ID_GUESSES = 3;

	/**
	 * @param string $order_number Order number as the customer typed it.
	 * @param string $email        Billing or account email.
	 * @param string $postcode     Billing or shipping postcode.
	 * @return WC_Order|null Null unless both the number and the owner match.
	 */
	public function find( $order_number, $email = '', $postcode = '' ) {
		$typed    = ltrim( trim( (string) $order_number ), '#' );
		$number   = self::normalize_number( $typed );
		$email    = self::normalize_email( $email );
		$postcode = self::normalize_postcode( $postcode );

		if ( '' === $number || ( '' === $email && '' === $postcode ) ) {
			return null;
		}

		foreach ( $this->candidates( $typed, $number, $email ) as $order ) {
			if ( $this->number_matches( $order, $number ) && $this->owner_matches( $order, $email, $postcode ) ) {
				return $order;
			}
		}

		return null;
	}

	/**
	 * What the customer is allowed to see about their order.
	 *
	 * @param WC_Order $order Verified order.
	 * @return array
	 */
	public function describe( WC_Order $order ) {
		$created = $order->get_date_created();
		$items   = array();
		foreach ( $order->get_items() as $item ) {
			$items[] = array(
				'name'     => $item->get_name(),
				'quantity' => $item->get_quantity(),
			);
		}

		return array(
			'order_number'   => (string) $order->get_order_number(),
			'status'         => $order->get_status(),
			'status_label'   => wc_get_order_status_name( $order->get_status() ),
			'date_created'   => $created ? $created->date( 'c' ) : null,
			'total'          => (float) $order->get_total(),
			'refunded_total' => (float) $order->get_total_refunded(),
			'currency'       => $order->get_currency(),
			'items'          => $items,
			'tracking'       => Parcelwise_Tracking::from_order( $order ),
		);
	}

	/**
	 * Orders that might be the one the customer means: cheap ID guesses first,
	 * then the customer's own recent orders.
	 *
	 * @return WC_Order[]
	 */
	private function candidates( $typed, $number, $email ) {
		// Custom order number plugins map their displayed number back to an ID on this filter.
		$guesses = array( apply_filters( 'woocommerce_shortcode_order_tracking_order_id', $typed ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own filter, applied on purpose.

		if ( preg_match_all( '/\d+/', $number, $digits ) ) {
			$guesses = array_merge( $guesses, array_slice( $digits[0], 0, self::MAX_ID_GUESSES ) );
		}

		$orders = array();
		foreach ( array_unique( array_map( 'absint', $guesses ) ) as $id ) {
			$order = $id ? wc_get_order( $id ) : false;
			// wc_get_order() also returns refunds, which are never what the customer is asking about.
			if ( $order instanceof WC_Order ) {
				$orders[ $order->get_id() ] = $order;
			}
		}

		// Sequential numbers like "SIP-000123" have no link to the ID, so look through the customer's orders.
		if ( '' !== $email ) {
			foreach ( $this->orders_for_email( $email ) as $order ) {
				$orders[ $order->get_id() ] = $order;
			}
		}

		return array_values( $orders );
	}

	/**
	 * Recent orders placed with this billing email or by the account that owns it.
	 *
	 * The database does the email comparison here: case-insensitive on
	 * MySQL/MariaDB, case-sensitive on SQLite.
	 *
	 * @return WC_Order[]
	 */
	private function orders_for_email( $email ) {
		$query = array(
			'type'    => 'shop_order',
			'limit'   => self::MAX_ORDERS_PER_EMAIL,
			'orderby' => 'date',
			'order'   => 'DESC',
		);

		$orders = wc_get_orders( $query + array( 'billing_email' => $email ) );

		$user = get_user_by( 'email', $email );
		if ( $user ) {
			$orders = array_merge( $orders, wc_get_orders( $query + array( 'customer_id' => $user->ID ) ) );
		}

		return $orders;
	}

	private function number_matches( WC_Order $order, $number ) {
		return self::normalize_number( $order->get_order_number() ) === $number
			|| (string) $order->get_id() === $number;
	}

	/**
	 * Accepts the billing email, the account email or either postcode, because
	 * customers rarely remember which one they used at checkout.
	 */
	private function owner_matches( WC_Order $order, $email, $postcode ) {
		if ( '' !== $email ) {
			$emails = array( $order->get_billing_email() );
			$user   = $order->get_customer_id() ? get_userdata( $order->get_customer_id() ) : false;
			if ( $user ) {
				$emails[] = $user->user_email;
			}
			if ( in_array( $email, array_map( array( __CLASS__, 'normalize_email' ), $emails ), true ) ) {
				return true;
			}
		}

		if ( '' !== $postcode ) {
			$postcodes = array( $order->get_billing_postcode(), $order->get_shipping_postcode() );
			if ( in_array( $postcode, array_map( array( __CLASS__, 'normalize_postcode' ), $postcodes ), true ) ) {
				return true;
			}
		}

		return false;
	}

	public static function normalize_number( $value ) {
		return strtoupper( preg_replace( '/[\s#]+/', '', (string) $value ) );
	}

	public static function normalize_email( $value ) {
		return strtolower( trim( (string) $value ) );
	}

	public static function normalize_postcode( $value ) {
		return strtoupper( preg_replace( '/[\s\-]+/', '', (string) $value ) );
	}
}
