<?php
/**
 * Tracking number extraction.
 *
 * @package Parcelwise
 */

defined( 'ABSPATH' ) || exit;

/**
 * Collects tracking numbers from wherever stores keep them: a shipment
 * tracking plugin, or a note typed by hand.
 */
class Parcelwise_Tracking {

	/**
	 * A tracking keyword, an optional "no/number/kodu", then a token containing at
	 * least one digit, so "Kargo şirketi: Yurtiçi" is not read as a tracking number.
	 */
	const NOTE_PATTERN = '/(?:takip|tracking|kargo|shipment|g[öo]nderi)\w*(?:\s+(?:numaras[ıi]|number|kodu|code|no|id)\.?)?\s*[:#]?\s*((?=[A-Z0-9-]*\d)[A-Z0-9][A-Z0-9-]{5,39})/iu';

	/**
	 * @param WC_Order $order Order to inspect.
	 * @return array[] Entries with number, provider, url and source.
	 */
	public static function from_order( WC_Order $order ) {
		$found = array();

		// Used by both WooCommerce Shipment Tracking and Advanced Shipment Tracking.
		$items = $order->get_meta( '_wc_shipment_tracking_items' );
		if ( is_array( $items ) ) {
			foreach ( $items as $item ) {
				if ( empty( $item['tracking_number'] ) ) {
					continue;
				}
				$found[] = array(
					'number'   => (string) $item['tracking_number'],
					'provider' => (string) ( ! empty( $item['custom_tracking_provider'] ) ? $item['custom_tracking_provider'] : ( $item['tracking_provider'] ?? '' ) ),
					'url'      => (string) ( $item['custom_tracking_link'] ?? '' ),
					'source'   => 'shipment_tracking',
				);
			}
		}

		// Many stores simply paste the tracking number into an order note.
		foreach ( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) as $note ) {
			if ( preg_match_all( self::NOTE_PATTERN, wp_strip_all_tags( $note->content ), $matches ) ) {
				foreach ( $matches[1] as $number ) {
					$found[] = array(
						'number'   => $number,
						'provider' => '',
						'url'      => '',
						'source'   => 'order_note',
					);
				}
			}
		}

		return self::unique( $found );
	}

	private static function unique( array $found ) {
		$seen = array();
		foreach ( $found as $entry ) {
			$key = strtoupper( $entry['number'] );
			if ( ! isset( $seen[ $key ] ) ) {
				$seen[ $key ] = $entry;
			}
		}
		return array_values( $seen );
	}
}
