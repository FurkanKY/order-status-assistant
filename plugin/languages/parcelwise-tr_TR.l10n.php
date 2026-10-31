<?php
/**
 * Turkish translation of the customer-facing tracking page, in WordPress 6.5's PHP translation format.
 *
 * @package Parcelwise
 */

defined( 'ABSPATH' ) || exit;

return array(
	'domain'       => 'parcelwise',
	'plural-forms' => 'nplurals=2; plural=(n > 1);',
	'language'     => 'tr_TR',
	'messages'     => array(
		// Tracking form.
		'Order number'                                   => 'Sipariş numarası',
		'Email or postcode used for the order'           => 'Siparişte kullandığınız e-posta veya posta kodu',
		'Find my order'                                  => 'Siparişimi bul',
		'Order tracking needs JavaScript to be enabled.' => 'Sipariş takibi için tarayıcınızda JavaScript açık olmalı.',
		'Order %s'                                       => 'Sipariş %s',
		'Items'                                          => 'Ürünler',
		'Refunded: %1$s %2$s'                            => 'İade edilen: %1$s %2$s',
		'Track with %s'                                  => '%s ile takip et',
		'Open %s and enter the tracking number'          => '%s sitesini açıp takip numarasını girin',
		'Tracking number: %s'                            => 'Takip numarası: %s',
		'Checking…'                                      => 'Kontrol ediliyor…',
		'Something went wrong. Please try again.'        => 'Bir sorun oluştu. Lütfen tekrar deneyin.',

		// Stages.
		'Order received'                                 => 'Sipariş alındı',
		'We\'ve got your order and will start preparing it once the payment is confirmed.' => 'Siparişiniz bize ulaştı, ödeme onaylanınca hazırlamaya başlayacağız.',
		'Preparing'                                      => 'Hazırlanıyor',
		'We\'re packing your order. The tracking number will appear here once it ships.' => 'Siparişiniz paketleniyor. Kargoya verilince takip numarası burada görünecek.',
		'Shipped'                                        => 'Kargoya verildi',
		'Your order has been handed to the carrier. You can follow its latest status on the carrier\'s site.' => 'Siparişiniz kargo firmasına teslim edildi. Güncel durumunu kargo firmasının sitesinden takip edebilirsiniz.',
		'Order cancelled'                                => 'Sipariş iptal edildi',
		'This order was cancelled. If you think this is a mistake, please contact us.' => 'Bu sipariş iptal edildi. Bir yanlışlık olduğunu düşünüyorsanız bize yazın.',
		'Refunded'                                       => 'İade edildi',
		'This order has been refunded. How long the money takes to reach your account depends on your bank.' => 'Bu siparişin ücreti iade edildi. Tutarın hesabınıza geçme süresi bankanıza göre değişir.',
		'Payment failed'                                 => 'Ödeme alınamadı',
		'The payment for this order didn\'t go through. You can place the order again or contact us.' => 'Bu siparişin ödemesi tamamlanamadı. Siparişinizi yeniden verebilir ya da bize yazabilirsiniz.',

		// Lookup answers.
		'Please provide the email address or postcode used for the order.' => 'Lütfen siparişte kullandığınız e-posta adresini veya posta kodunu yazın.',
		'Too many attempts. Please try again in a few minutes.' => 'Çok fazla deneme yapıldı. Lütfen birkaç dakika sonra tekrar deneyin.',
		'We could not find an order matching these details.' => 'Bu bilgilerle eşleşen bir sipariş bulamadık.',
	),
);
