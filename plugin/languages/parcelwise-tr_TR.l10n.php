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
		'You\'ll find it in your order confirmation email.' => 'Sipariş onay e-postanızda yazar.',
		'Email or postcode used for the order'           => 'Siparişte kullandığınız e-posta veya posta kodu',
		'Find my order'                                  => 'Siparişimi bul',
		'Order tracking needs JavaScript to be enabled.' => 'Sipariş takibi için tarayıcınızda JavaScript açık olmalı.',
		'Order #%s'                                      => 'Sipariş #%s',
		'Items'                                          => 'Ürünler',
		'Refunded so far: %s'                            => 'İade edilen tutar: %s',
		'Track with %s'                                  => '%s ile takip et',
		'Go to %s'                                       => '%s sitesine git',
		'Copy the tracking number and enter it on the carrier\'s site.' => 'Takip numarasını kopyalayıp kargo firmasının sitesine girin.',
		'The tracking number hasn\'t been added yet.'    => 'Takip numarası henüz eklenmedi.',
		'Tracking number: %s'                            => 'Takip numarası: %s',
		'Copy'                                           => 'Kopyala',
		'Copied'                                         => 'Kopyalandı',
		'Questions about your order?'                    => 'Siparişinizle ilgili bir sorunuz mu var?',
		'Check the order number in your order confirmation email, and use the email address or postcode you ordered with.' => 'Sipariş numarasını onay e-postanızdan kontrol edin ve siparişte kullandığınız e-posta adresini veya posta kodunu yazın.',
		'Checking…'                                      => 'Kontrol ediliyor…',
		'Something went wrong. Please try again.'        => 'Bir sorun oluştu. Lütfen tekrar deneyin.',

		// Chat.
		'Hi! Send me your order number and the email address you used, and I\'ll check on your order.' => 'Merhaba! Sipariş numaranızı ve siparişte kullandığınız e-posta adresini yazın, siparişinize hemen bakayım.',
		'Type your message'                              => 'Mesajınızı yazın',
		'Send'                                           => 'Gönder',
		'Typing…'                                        => 'Yazıyor…',
		'The assistant is not available right now.'      => 'Asistan şu anda kullanılamıyor.',
		'Please type a message.'                         => 'Lütfen bir mesaj yazın.',
		'This conversation is getting long. Please refresh the page to start a new one.' => 'Bu konuşma çok uzadı. Yeni bir konuşma başlatmak için sayfayı yenileyin.',
		'You have sent a lot of messages. Please try again later.' => 'Çok fazla mesaj gönderdiniz. Lütfen biraz sonra tekrar deneyin.',
		'The assistant could not answer right now. Please try again in a moment.' => 'Asistan şu anda cevap veremedi. Lütfen birazdan tekrar deneyin.',
		'Sorry, I can\'t help with that here. Please contact %s.' => 'Üzgünüm, bu konuda burada yardımcı olamıyorum. Lütfen %s üzerinden bize ulaşın.',
		'Sorry, I can\'t help with that here. Please contact the store.' => 'Üzgünüm, bu konuda burada yardımcı olamıyorum. Lütfen mağazayla iletişime geçin.',

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
