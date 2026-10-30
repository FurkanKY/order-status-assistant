<?php
/**
 * Plugin Name: Parcelwise - Dev Fixtures
 * Description: Test-only helpers for the local Playground store. Never install on a real store.
 *
 * @package Parcelwise
 */

defined( 'ABSPATH' ) || exit;

// Mimics a custom order number plugin that does NOT hook the tracking filter, the harder case for lookups.
add_filter(
	'woocommerce_order_number',
	function ( $number, $order ) {
		$custom = $order->get_meta( '_osa_dev_order_number' );
		return $custom ? $custom : $number;
	},
	10,
	2
);

// High by default so expected failures in the test run don't trip the limiters; their own tests lower them.
add_filter(
	'parcelwise_max_failed_lookups',
	function () {
		return (int) get_option( 'osa_dev_max_failures', 1000 );
	}
);
add_filter(
	'parcelwise_max_chat_messages',
	function () {
		return (int) get_option( 'osa_dev_max_chat', 1000 );
	}
);

// Answers Claude API calls with a scripted fake, so chat tests run offline, for free and the same way every time.
// The eval switches this off with the osa_dev_real_claude option and real calls go through.
add_filter(
	'pre_http_request',
	function ( $preempt, $args, $url ) {
		if ( 0 !== strpos( $url, 'https://api.anthropic.com/' ) ) {
			return $preempt;
		}
		if ( get_option( 'osa_dev_real_claude' ) ) {
			$GLOBALS['osa_dev_call_started'] = microtime( true );
			return $preempt;
		}

		// The site connector's Anthropic provider lists models before it sends anything.
		if ( 0 === strpos( $url, 'https://api.anthropic.com/v1/models' ) ) {
			return osa_dev_fake_models( $args['headers'] );
		}

		$request  = json_decode( $args['body'], true );
		$response = osa_dev_fake_claude( $request );
		osa_dev_log_call( $args['headers'], $request, $response, 0 );

		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( $response ),
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	},
	10,
	3
);

// Records real Claude calls for the eval: request, response and how long the call took.
add_action(
	'http_api_debug',
	function ( $response, $context, $transport, $args, $url ) {
		if ( 0 !== strpos( $url, 'https://api.anthropic.com/v1/messages' ) || ! get_option( 'osa_dev_real_claude' ) ) {
			return;
		}
		$headers              = $args['headers'];
		$headers['x-api-key'] = 'redacted';
		osa_dev_log_call(
			$headers,
			json_decode( $args['body'], true ),
			is_wp_error( $response ) ? array( 'error' => $response->get_error_message() ) : json_decode( wp_remote_retrieve_body( $response ), true ),
			round( microtime( true ) - ( $GLOBALS['osa_dev_call_started'] ?? microtime( true ) ), 3 )
		);
	},
	10,
	5
);

/**
 * Appends one API call to a log the test runner reads back. Each eval case sends its own
 * X-OSA-Eval-Case header, so cases running side by side keep separate logs.
 */
function osa_dev_log_call( $headers, $request, $response, $seconds ) {
	$case  = isset( $_SERVER['HTTP_X_OSA_EVAL_CASE'] ) ? wp_unslash( $_SERVER['HTTP_X_OSA_EVAL_CASE'] ) : '';
	$key   = osa_dev_log_key( $case );
	$log   = get_option( $key, array() );
	$log[] = array(
		'headers'  => $headers,
		'body'     => $request,
		'response' => $response,
		'seconds'  => $seconds,
	);
	update_option( $key, $log, false );
}

function osa_dev_log_key( $case ) {
	return '' === (string) $case ? 'osa_dev_claude_log' : 'osa_dev_claude_log_' . md5( (string) $case );
}

add_action(
	'rest_api_init',
	function () {
		$route = function ( $path, $callback ) {
			register_rest_route(
				'osa-dev/v1',
				$path,
				array(
					'methods'             => 'POST',
					'permission_callback' => '__return_true',
					'callback'            => $callback,
				)
			);
		};

		$route(
			'/storage',
			function ( WP_REST_Request $request ) {
				update_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'no' );
				update_option( 'woocommerce_custom_orders_table_enabled', 'legacy' === $request['mode'] ? 'no' : 'yes' );
				return array( 'requested' => $request['mode'] );
			}
		);

		$route( '/seed', 'osa_dev_seed' );

		$route(
			'/limit',
			function ( WP_REST_Request $request ) {
				update_option( 'osa_dev_max_failures', (int) $request['max'] );
				delete_transient( 'parcelwise_failures_' . md5( osa_dev_ip() ) );
				return array( 'max' => (int) $request['max'] );
			}
		);

		// Resets the assistant settings, limiters and API call log before each chat test.
		$route(
			'/config',
			function ( WP_REST_Request $request ) {
				update_option(
					'parcelwise_settings',
					array(
						'api_key'         => (string) $request['api_key'],
						'model'           => $request['model'] ? (string) $request['model'] : 'claude-opus-5-5',
						'support_contact' => 'destek@example.com',
					)
				);
				// The key WordPress's Settings > Connectors screen saves for the Anthropic provider.
				if ( $request['connector_key'] ) {
					update_option( 'connectors_ai_anthropic_api_key', (string) $request['connector_key'] );
				} else {
					delete_option( 'connectors_ai_anthropic_api_key' );
				}
				update_option( 'osa_dev_real_claude', ! empty( $request['real_claude'] ) );
				update_option( 'osa_dev_max_chat', $request['max_chat'] ? (int) $request['max_chat'] : 1000 );
				update_option( 'osa_dev_max_failures', 1000 );
				delete_transient( 'parcelwise_chat_' . md5( osa_dev_ip() ) );
				delete_transient( 'parcelwise_failures_' . md5( osa_dev_ip() ) );
				delete_option( 'osa_dev_claude_log' );
				delete_option( 'parcelwise_last_error' );
				return array( 'ok' => true );
			}
		);

		$route(
			'/last-error',
			function () {
				return get_option( 'parcelwise_last_error', null );
			}
		);

		$route(
			'/claude-log',
			function ( WP_REST_Request $request ) {
				return get_option( osa_dev_log_key( $request['case'] ), array() );
			}
		);

		// Can PHP inside Playground reach the real API? No key is sent; a 401 means the network works.
		$route(
			'/net-check',
			function () {
				remove_all_filters( 'pre_http_request' );
				$response = wp_remote_get( 'https://api.anthropic.com/v1/models', array( 'timeout' => 20 ) );
				return is_wp_error( $response )
					? array( 'error' => $response->get_error_message() )
					: array( 'status' => wp_remote_retrieve_response_code( $response ) );
			}
		);
	}
);

// Logs a browser on this machine into the local Playground admin (/?osa_dev_login), so admin
// pages such as Plugin Check can be opened without typing a password.
add_action(
	'init',
	function () {
		if ( ! isset( $_GET['osa_dev_login'] ) || ! in_array( osa_dev_ip(), array( '127.0.0.1', '::1' ), true ) ) {
			return;
		}
		wp_set_current_user( 1 );
		wp_set_auth_cookie( 1 );
		wp_safe_redirect( admin_url( 'tools.php?page=plugin-check' ) );
		exit;
	}
);

function osa_dev_ip() {
	return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
}

/**
 * Message content as plain text, whether it was sent as a string (this plugin) or as text blocks
 * (the site connector's provider). Tool results are left out.
 */
function osa_dev_text_of( $content ) {
	if ( is_string( $content ) ) {
		return $content;
	}
	$text = '';
	foreach ( (array) $content as $block ) {
		if ( 'text' === ( $block['type'] ?? '' ) ) {
			$text .= ' ' . $block['text'];
		}
	}
	return $text;
}

/**
 * GET /v1/models, which the connector's Anthropic provider calls to see what it can use. Like the real
 * API, it refuses a request without a key.
 */
function osa_dev_fake_models( array $headers ) {
	$headers = array_change_key_case( $headers );
	$ok      = ! empty( $headers['x-api-key'] );
	$body    = $ok
		? array(
			'data'     => array(
				array(
					'type'         => 'model',
					'id'           => 'claude-opus-5-5',
					'display_name' => 'Claude Opus 5.5',
					'created_at'   => '2026-06-01T00:00:00Z',
				),
				array(
					'type'         => 'model',
					'id'           => 'claude-haiku-5-5',
					'display_name' => 'Claude Haiku 5.5',
					'created_at'   => '2026-06-01T00:00:00Z',
				),
			),
			'has_more' => false,
			'first_id' => 'claude-opus-5-5',
			'last_id'  => 'claude-haiku-5-5',
		)
		: array(
			'type'  => 'error',
			'error' => array(
				'type'    => 'authentication_error',
				'message' => 'x-api-key header is required',
			),
		);

	return array(
		'headers'  => array( 'content-type' => 'application/json' ),
		'body'     => wp_json_encode( $body ),
		'response' => array(
			'code'    => $ok ? 200 : 401,
			'message' => $ok ? 'OK' : 'Unauthorized',
		),
		'cookies'  => array(),
		'filename' => null,
	);
}

/**
 * Plays Claude's part: asks for missing details, calls lookup_order once it has an order number
 * and an email, then turns the tool result into a sentence. Every reply carries a thinking block,
 * like the real model, so tests can check those are sent back untouched.
 */
function osa_dev_fake_claude( array $request ) {
	$messages = $request['messages'];
	$last     = end( $messages );
	$respond  = function ( array $content, $stop_reason ) {
		return array(
			'id'           => 'msg_dev_' . wp_generate_password( 12, false ),
			'type'         => 'message',
			'role'         => 'assistant',
			'model'        => 'claude-opus-5-5',
			'content'      => $content,
			'stop_reason'  => $stop_reason,
			'stop_details' => null,
			'usage'        => array(
				'input_tokens'  => 0,
				'output_tokens' => 0,
			),
		);
	};
	$text     = function ( $value ) {
		return array(
			'type' => 'text',
			'text' => $value,
		);
	};
	$thinking = array(
		'type'      => 'thinking',
		'thinking'  => '',
		'signature' => 'dev-signature',
	);

	$tool_result = null;
	foreach ( is_array( $last['content'] ) ? $last['content'] : array() as $block ) {
		if ( 'tool_result' === ( $block['type'] ?? '' ) ) {
			$tool_result = $block;
		}
	}

	if ( $tool_result ) {
		$result = json_decode( is_string( $tool_result['content'] ) ? $tool_result['content'] : osa_dev_text_of( $tool_result['content'] ), true );
		if ( empty( $result['found'] ) ) {
			return $respond( array( $thinking, $text( 'Bu bilgilerle eşleşen bir sipariş bulamadım, bilgileri kontrol eder misiniz?' ) ), 'end_turn' );
		}
		$order    = $result['order'];
		$tracking = $order['tracking'] ? ' Kargo takip numarası: ' . $order['tracking'][0]['number'] . '.' : '';
		return $respond( array( $thinking, $text( sprintf( '%s numaralı siparişinizin durumu: %s.%s', $order['order_number'], $order['status_label'], $tracking ) ) ), 'end_turn' );
	}

	if ( false !== strpos( osa_dev_text_of( $last['content'] ), 'REFUSE_TEST' ) ) {
		$refusal                 = $respond( array(), 'refusal' );
		$refusal['stop_details'] = array(
			'type'     => 'refusal',
			'category' => null,
		);
		return $refusal;
	}

	$said = '';
	foreach ( $messages as $message ) {
		if ( 'user' === $message['role'] ) {
			$said .= ' ' . osa_dev_text_of( $message['content'] );
		}
	}
	// Order numbers written as "sipariş: 11", "#11" or "11 numaralı / 11 nolu".
	preg_match_all( '/(?:sipari[şs]:\s*#?|#)([A-Z0-9-]+)|\b([A-Z]{2,4}-[\w-]+|\d{1,6})\s+(?:numaral[ıi]|nolu)/iu', $said, $found );
	$numbers = array_values( array_filter( array_merge( $found[1], $found[2] ) ) );
	if ( ! $numbers || ! preg_match_all( '/[\w.+-]+@[\w-]+(?:\.[\w-]+)+/u', $said, $emails ) ) {
		return $respond( array( $thinking, $text( 'Sipariş numaranızı ve siparişte kullandığınız e-posta adresini yazar mısınız?' ) ), 'end_turn' );
	}
	// The most recently mentioned number, like a model following the conversation.
	usort(
		$numbers,
		function ( $a, $b ) use ( $said ) {
			return strrpos( $said, $a ) <=> strrpos( $said, $b );
		}
	);

	return $respond(
		array(
			$thinking,
			array(
				'type'  => 'tool_use',
				'id'    => 'toolu_dev_' . wp_generate_password( 12, false ),
				'name'  => 'lookup_order',
				'input' => array(
					'order_number' => end( $numbers ),
					'email'        => end( $emails[0] ),
					'postcode'     => '',
				),
			),
		),
		'tool_use'
	);
}

/**
 * Creates one order per scenario and returns what the test runner needs to look them up.
 */
function osa_dev_seed() {
	// New WooCommerce stores start in "coming soon" mode, which hides pages from visitors.
	update_option( 'woocommerce_coming_soon', 'no' );

	$product = new WC_Product_Simple();
	$product->set_name( 'Deneme Ürünü' );
	$product->set_regular_price( '250' );
	$product->save();

	$make = function ( array $address, $status = 'processing', $customer_id = 0 ) use ( $product ) {
		$order = wc_create_order( array( 'customer_id' => $customer_id ) );
		$order->add_product( $product, 1 );
		$order->set_address( $address + array( 'country' => 'TR' ), 'billing' );
		$order->set_address( $address + array( 'country' => 'TR' ), 'shipping' );
		$order->calculate_totals();
		$order->set_status( $status );
		$order->save();
		return $order;
	};

	$plain = $make(
		array(
			'first_name' => 'Ayşe',
			'last_name'  => 'Yılmaz',
			'email'      => 'Ayse.Yilmaz@Example.com',
			'postcode'   => '34710',
		)
	);

	$user_id = wp_insert_user(
		array(
			'user_login' => 'mehmet',
			'user_email' => 'mehmet@example.com',
			'user_pass'  => wp_generate_password(),
		)
	);
	$account = $make(
		array(
			'email'    => 'mehmet.kaya@is.example.com',
			'postcode' => '34000',
		),
		'processing',
		$user_id
	);

	$custom_with_id = $make(
		array(
			'email'    => 'can@example.com',
			'postcode' => '06100',
		)
	);
	$custom_with_id->update_meta_data( '_osa_dev_order_number', 'TR-' . $custom_with_id->get_id() . '-26' );
	$custom_with_id->save();

	$sequential = $make(
		array(
			'email'    => 'zeynep.demir@example.com',
			'postcode' => '35210',
		)
	);
	$sequential->update_meta_data( '_osa_dev_order_number', 'SIP-000123' );
	$sequential->save();

	$refunded = $make(
		array(
			'email'    => 'iade@example.com',
			'postcode' => '16000',
		),
		'completed'
	);
	wc_create_refund(
		array(
			'order_id' => $refunded->get_id(),
			'amount'   => $refunded->get_total(),
			'reason'   => 'Test iadesi',
		)
	);

	$partial        = $make(
		array(
			'email'    => 'kismi@example.com',
			'postcode' => '07100',
		),
		'completed'
	);
	$partial_refund = wc_create_refund(
		array(
			'order_id' => $partial->get_id(),
			'amount'   => 50,
			'reason'   => 'Kısmi iade',
		)
	);

	$note_tracking = $make(
		array(
			'email'    => 'kargo@example.com',
			'postcode' => '34000',
		),
		'completed'
	);
	$note_tracking->add_order_note( 'Kargo takip no: 7312345678901' );
	$note_tracking->add_order_note( 'Kargo şirketi: Yurtiçi' );

	$meta_tracking = $make(
		array(
			'email'    => 'takip@example.com',
			'postcode' => '34000',
		),
		'completed'
	);
	$meta_tracking->update_meta_data(
		'_wc_shipment_tracking_items',
		array(
			array(
				'tracking_provider'        => 'ups',
				'custom_tracking_provider' => '',
				'custom_tracking_link'     => '',
				'tracking_number'          => '1Z999AA10123456784',
				'date_shipped'             => time(),
			),
		)
	);
	$meta_tracking->save();

	// Ayşe's second order, so a customer can ask about two of their own orders at once.
	$plain_second = $make(
		array(
			'first_name' => 'Ayşe',
			'last_name'  => 'Yılmaz',
			'email'      => 'Ayse.Yilmaz@Example.com',
			'postcode'   => '34710',
		),
		'completed'
	);
	$plain_second->add_order_note( 'Takip numarası: AR123456789TR' );
	// "arasında" contains "aras" but names no carrier.
	$plain_second->add_order_note( 'Paket diğer siparişler arasında hazırlanıyor.' );

	// Turkish cargo plugins, each storing the carrier its own way.
	$kargo_takip = $make(
		array(
			'email'    => 'aras@example.com',
			'postcode' => '06000',
		),
		'completed'
	);
	$kargo_takip->update_meta_data( 'tracking_company', 'aras' );
	$kargo_takip->update_meta_data( 'tracking_code', '4400112233' );
	$kargo_takip->save();

	$hezarfen = $make(
		array(
			'email'    => 'hezarfen@example.com',
			'postcode' => '06000',
		),
		'completed'
	);
	// Hezarfen still saves Aras-era style links that no longer work; Parcelwise rebuilds them.
	$hezarfen->add_meta_data( '_hezarfen_mst_shipment_data', '1||' . $hezarfen->get_id() . '||yurtici||Yurtiçi Kargo||5550001112223||http://example.com/dead-link||0', false );
	$hezarfen->save();

	$ptt_note = $make(
		array(
			'email'    => 'ptt@example.com',
			'postcode' => '06000',
		),
		'completed'
	);
	$ptt_note->add_order_note( 'PTT Kargo takip no: RR123456789TR' );
	

	$surat_note = $make(
		array(
			'email'    => 'surat@example.com',
			'postcode' => '06000',
		),
		'completed'
	);
	$surat_note->add_order_note( 'Sürat ile gönderildi, takip no: 9001234567' );

	$chat_page_id = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'Sipariş Asistanı',
			'post_content' => '[parcelwise]',
		)
	);

	return array(
		'storage'      => \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? 'hpos' : 'legacy',
		'refund_id'    => is_wp_error( $partial_refund ) ? 0 : $partial_refund->get_id(),
		'chat_page_id' => $chat_page_id,
		'orders'       => array_map(
			'osa_dev_fixture',
			array(
				'plain'        => $plain,
				'account'      => $account,
				'customWithId' => $custom_with_id,
				'sequential'   => $sequential,
				'refunded'     => $refunded,
				'partial'      => $partial,
				'noteTracking' => $note_tracking,
				'metaTracking' => $meta_tracking,
				'plain2'       => $plain_second,
				'kargoTakip'   => $kargo_takip,
				'hezarfen'     => $hezarfen,
				'pttNote'      => $ptt_note,
				'suratNote'	   => $surat_note,
			)
		),
	);
}

function osa_dev_fixture( WC_Order $order ) {
	// Reload so refunds and status changes made after creation are visible.
	$order = wc_get_order( $order->get_id() );
	return array(
		'id'       => $order->get_id(),
		'number'   => (string) $order->get_order_number(),
		'status'   => $order->get_status(),
		'total'    => (float) $order->get_total(),
		'email'    => $order->get_billing_email(),
		'tracking' => array_column( Parcelwise_Tracking::from_order( $order ), 'number' ),
	);
}
