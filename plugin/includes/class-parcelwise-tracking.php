<?php
/**
 * Tracking number extraction.
 *
 * @package Parcelwise
 */

defined( 'ABSPATH' ) || exit;

/**
 * Collects tracking numbers from wherever stores keep them: a shipment tracking plugin,
 * one of the Turkish cargo tracking plugins, or a note typed by hand.
 */
class Parcelwise_Tracking {

	/**
	 * A tracking keyword, an optional "no/number/kodu", then a token containing at
	 * least one digit, so "Kargo şirketi: Yurtiçi" is not read as a tracking number.
	 */
	const NOTE_PATTERN = '/(?:takip|tracking|kargo|shipment|g[öo]nderi)\w*(?:\s+(?:numaras[ıi]|number|kodu|code|no|id)\.?)?\s*[:#]?\s*((?=[A-Z0-9-]*\d)[A-Z0-9][A-Z0-9-]{5,39})/iu';

	/**
	 * @param WC_Order $order Order to inspect.
	 * @return array[] Entries with number, provider, url, url_type and source.
	 */
	public static function from_order( WC_Order $order ) {
		$found = array_merge(
			self::from_shipment_tracking( $order ),
			self::from_turkish_plugins( $order ),
			self::from_notes( $order )
		);

		$entries = array();
		foreach ( self::unique( $found ) as $entry ) {
			$entries[] = self::with_link( $entry );
		}
		return $entries;
	}

	/**
	 * WooCommerce Shipment Tracking and Advanced Shipment Tracking share one meta key.
	 */
	private static function from_shipment_tracking( WC_Order $order ) {
		$found = array();
		$items = $order->get_meta( '_wc_shipment_tracking_items' );
		foreach ( is_array( $items ) ? $items : array() as $item ) {
			if ( ! empty( $item['tracking_number'] ) ) {
				$found[] = self::entry(
					$item['tracking_number'],
					! empty( $item['custom_tracking_provider'] ) ? $item['custom_tracking_provider'] : ( $item['tracking_provider'] ?? '' ),
					$item['custom_tracking_link'] ?? '',
					'shipment_tracking'
				);
			}
		}
		return $found;
	}

	/**
	 * Kargo Takip Türkiye, Hezarfen and Intense Kargo Takip, the plugins Turkish stores use to
	 * record which carrier took the parcel.
	 */
	private static function from_turkish_plugins( WC_Order $order ) {
		$found = array();

		if ( self::looks_like_number( $order->get_meta( 'tracking_code' ) ) ) {
			$found[] = self::entry( $order->get_meta( 'tracking_code' ), $order->get_meta( 'tracking_company' ), '', 'kargo_takip_turkiye' );
		}

		// One row per shipment: id||order_id||courier_id||courier_title||tracking_num||tracking_url||sms_sent.
		foreach ( $order->get_meta( '_hezarfen_mst_shipment_data', false ) as $meta ) {
			$row = explode( '||', (string) $meta->value );
			if ( count( $row ) >= 6 && self::looks_like_number( $row[4] ) ) {
				$found[] = self::entry( $row[4], '' !== $row[2] ? $row[2] : $row[3], $row[5], 'hezarfen' );
			}
		}

		if ( self::looks_like_number( $order->get_meta( 'shipping_number' ) ) ) {
			$found[] = self::entry( $order->get_meta( 'shipping_number' ), $order->get_meta( 'shipping_company' ), $order->get_meta( 'in_kargotakip_tracking_url' ), 'intense_kargo_takip' );
		}

		return $found;
	}

	/**
	 * Many stores simply paste the tracking number into an order note, and often name the
	 * carrier in another note ("Kargo şirketi: Yurtiçi").
	 */
	private static function from_notes( WC_Order $order ) {
		$numbers  = array();
		$carriers = array();
		foreach ( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) as $note ) {
			$text     = wp_strip_all_tags( $note->content );
			$named    = Parcelwise_Carriers::mentioned_in( $text );
			$carriers = array_merge( $carriers, $named );
			if ( preg_match_all( self::NOTE_PATTERN, $text, $matches ) ) {
				foreach ( $matches[1] as $number ) {
					$numbers[] = array( $number, 1 === count( $named ) ? $named[0] : '' );
				}
			}
		}

		// A carrier named elsewhere in the notes only counts when it is the only one named.
		$carriers = array_values( array_unique( $carriers ) );
		$fallback = 1 === count( $carriers ) ? $carriers[0] : '';

		$found = array();
		foreach ( $numbers as list( $number, $carrier ) ) {
			$found[] = self::entry( $number, '' !== $carrier ? $carrier : $fallback, '', 'order_note' );
		}
		return $found;
	}

	/**
	 * Known carriers get a freshly built link even when a saved one exists: saved links are where
	 * dead tracking URLs live. Unknown carriers keep whatever link the store saved.
	 */
	private static function with_link( array $entry ) {
		$key = Parcelwise_Carriers::identify( $entry['provider'] );
		if ( null !== $key ) {
			$link              = Parcelwise_Carriers::link( $key, $entry['number'] );
			$entry['provider'] = $link['name'];
			$entry['url']      = $link['url'];
			$entry['url_type'] = $link['url_type'];
		} else {
			$entry['url']      = esc_url_raw( $entry['url'], array( 'https', 'http' ) );
			$entry['url_type'] = '' !== $entry['url'] ? 'tracking' : '';
		}
		return $entry;
	}

	private static function entry( $number, $provider, $url, $source ) {
		return array(
			'number'   => trim( (string) $number ),
			'provider' => trim( (string) $provider ),
			'url'      => trim( (string) $url ),
			'source'   => $source,
		);
	}

	private static function looks_like_number( $value ) {
		return is_string( $value ) && preg_match( '/^[A-Z0-9-]{6,40}$/i', trim( $value ) ) && preg_match( '/\d/', $value );
	}

	/**
	 * One entry per number. The first source wins, but a carrier found by a later source fills
	 * in one the first didn't know.
	 */
	private static function unique( array $found ) {
		$seen = array();
		foreach ( $found as $entry ) {
			$key = strtoupper( $entry['number'] );
			if ( ! isset( $seen[ $key ] ) ) {
				$seen[ $key ] = $entry;
			} elseif ( '' === $seen[ $key ]['provider'] && '' !== $entry['provider'] ) {
				$seen[ $key ]['provider'] = $entry['provider'];
			}
		}
		return array_values( $seen );
	}
}
