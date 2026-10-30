<?php
/**
 * Turkish carriers and their tracking pages.
 *
 * @package Parcelwise
 */

defined( 'ABSPATH' ) || exit;

/**
 * Turns "Aras", "aras-kargo" or "Yurtiçi Kargo" plus a tracking number into a link that works today.
 *
 * Carriers change their tracking URLs (Aras did, and links saved by other plugins now return 404),
 * so links are built here when the order is shown rather than trusted from wherever they were saved.
 * Every template was checked against the carrier's site on 2026-10-30. A store can fix one without
 * waiting for an update through the parcelwise_carriers filter.
 */
class Parcelwise_Carriers {

	/**
	 * name:     shown to customers.
	 * url:      tracking page with a {number} placeholder, or '' when the carrier has no linkable one.
	 * site:     where to send customers when there is no tracking link.
	 * ids:      how other plugins and store owners write the carrier, compared without spaces,
	 *           punctuation or a trailing "kargo".
	 * mentions: words that name the carrier in a free-text order note.
	 */
	const CARRIERS = array(
		'yurtici'         => array(
			'name'     => 'Yurtiçi Kargo',
			'url'      => 'https://www.yurticikargo.com/tr/online-servisler/gonderi-sorgula?code={number}',
			'site'     => 'https://www.yurticikargo.com/',
			'ids'      => array( 'yurtici' ),
			'mentions' => array( 'yurtici' ),
		),
		'aras'            => array(
			'name'     => 'Aras Kargo',
			'url'      => 'https://ebranch.araskargo.com.tr/track/{number}',
			'site'     => 'https://www.araskargo.com.tr/',
			'ids'      => array( 'aras' ),
			'mentions' => array( 'aras' ),
		),
		// MNG Kargo became DHL eCommerce Türkiye in 2025; stores still write MNG.
		'dhlecommerce'    => array(
			'name'     => 'DHL eCommerce (MNG Kargo)',
			'url'      => 'https://www.dhlecommerce.com.tr/gonderitakip?gonderino={number}',
			'site'     => 'https://www.dhlecommerce.com.tr/',
			'ids'      => array( 'dhlecommerce', 'mng' ),
			'mentions' => array( 'mng', 'dhl ecommerce' ),
		),
		// PTT moved tracking into a form on its home page; there is no URL that takes the number.
		'ptt'             => array(
			'name'     => 'PTT Kargo',
			'url'      => '',
			'site'     => 'https://www.ptt.gov.tr/',
			'ids'      => array( 'ptt' ),
			'mentions' => array( 'ptt' ),
		),
		'hepsijet'        => array(
			'name'     => 'HepsiJET',
			'url'      => 'https://www.hepsijet.com/gonderi-takibi/{number}',
			'site'     => 'https://www.hepsijet.com/',
			'ids'      => array( 'hepsijet', 'hepsijetentegrasyon' ),
			'mentions' => array( 'hepsijet' ),
		),
		'trendyolexpress' => array(
			'name'     => 'Trendyol Express',
			'url'      => 'https://kargotakip.trendyol.com/?orderNumber={number}',
			'site'     => 'https://kargotakip.trendyol.com/',
			'ids'      => array( 'trendyolexpress', 'tex' ),
			'mentions' => array( 'trendyol express' ),
		),
		// Sendeo shut down at the start of 2025 and its shipments are tracked by Kolay Gelsin.
		'kolaygelsin'     => array(
			'name'     => 'Kolay Gelsin',
			'url'      => 'https://kolaygelsin.com/tracking?q={number}',
			'site'     => 'https://kolaygelsin.com/',
			'ids'      => array( 'kolaygelsin', 'sendeo' ),
			'mentions' => array( 'kolay gelsin', 'kolaygelsin', 'sendeo' ),
		),

		'suratkargo'	=> array(
			'name'		=> 'Sürat Kargo',
			'url'		=> 'https://www.suratkargo.com.tr/KargoTakip/?kargotakipno={number}',
			'site'		=> 'https://www.suratkargo.com.tr/',
			'ids'		=> array( 'surat' ),
			'mentions'  => array( 'surat'),
		),
	);

	public static function all() {
		return (array) apply_filters( 'parcelwise_carriers', self::CARRIERS );
	}

	/**
	 * @param string $provider A carrier id or name as another plugin or the store wrote it.
	 * @return string|null Carrier key, or null for a carrier this list doesn't know.
	 */
	public static function identify( $provider ) {
		$compact = str_replace( ' ', '', self::words( $provider ) );
		$compact = preg_replace( '/(.+)kargo$/', '$1', $compact );
		foreach ( self::all() as $key => $carrier ) {
			if ( in_array( $compact, $carrier['ids'], true ) ) {
				return $key;
			}
		}
		return null;
	}

	/**
	 * Carriers named anywhere in a piece of text, as whole words, so "arasında" is not Aras.
	 *
	 * @return string[] Carrier keys.
	 */
	public static function mentioned_in( $text ) {
		$haystack = ' ' . self::words( $text ) . ' ';
		$found    = array();
		foreach ( self::all() as $key => $carrier ) {
			foreach ( $carrier['mentions'] as $mention ) {
				if ( false !== strpos( $haystack, ' ' . $mention . ' ' ) ) {
					$found[] = $key;
					break;
				}
			}
		}
		return $found;
	}

	/**
	 * @return array name, url and url_type: "tracking" for the parcel's own page, "carrier_site"
	 *               when the customer has to enter the number on the carrier's site.
	 */
	public static function link( $key, $number ) {
		$carrier = self::all()[ $key ];
		if ( '' !== $carrier['url'] ) {
			return array(
				'name'     => $carrier['name'],
				'url'      => str_replace( '{number}', rawurlencode( $number ), $carrier['url'] ),
				'url_type' => 'tracking',
			);
		}
		return array(
			'name'     => $carrier['name'],
			'url'      => $carrier['site'],
			'url_type' => 'carrier_site',
		);
	}

	/**
	 * Lowercase ASCII words separated by single spaces: "Yurtiçi'ne" becomes "yurtici ne".
	 */
	private static function words( $text ) {
		$text = strtr( (string) $text, array( 'İ' => 'i', 'I' => 'i', 'ı' => 'i', 'Ç' => 'c', 'ç' => 'c', 'Ğ' => 'g', 'ğ' => 'g', 'Ö' => 'o', 'ö' => 'o', 'Ş' => 's', 'ş' => 's', 'Ü' => 'u', 'ü' => 'u' ) );
		return trim( preg_replace( '/[^a-z0-9]+/', ' ', strtolower( $text ) ) );
	}
}
