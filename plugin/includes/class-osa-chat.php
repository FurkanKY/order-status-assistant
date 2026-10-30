<?php
/**
 * Chat assistant backed by Claude, with order lookup as its only tool.
 *
 * @package OrderStatusAssistant
 */

defined( 'ABSPATH' ) || exit;

/**
 * Runs one customer message through Claude, calling lookup_order whenever the model asks for it.
 *
 * Talks to the Messages API through the WordPress HTTP API instead of the PHP SDK: plugins that
 * bundle their own HTTP client libraries clash with each other on shared hosting, and this keeps
 * the plugin dependency-free on PHP 7.4.
 *
 * Conversations live server-side and are only ever appended to, so the browser can't rewrite
 * earlier turns, and the thinking blocks Claude returns stay valid when they are sent back.
 */
class OSA_Chat {

	const API_URL            = 'https://api.anthropic.com/v1/messages';
	const API_VERSION        = '2026-10-21';
	const MAX_MESSAGE_LENGTH = 1000;
	const MAX_CUSTOMER_TURNS = 12;
	const MAX_TOOL_ROUNDS    = 4;
	const CONVERSATION_TTL   = HOUR_IN_SECONDS;

	/** Models that accept the server-side refusal fallback. */
	const MODELS_WITH_FALLBACK = array( 'claude-opus-5-5', 'claude-sonnet-5-5' );

	/** @var OSA_Order_Lookup */
	private $lookup;

	/** @var OSA_Throttle */
	private $failed_lookups;

	public function __construct( OSA_Order_Lookup $lookup, OSA_Throttle $failed_lookups ) {
		$this->lookup         = $lookup;
		$this->failed_lookups = $failed_lookups;
	}

	/**
	 * @param string $conversation_id Empty for a new conversation.
	 * @param string $message         What the customer typed.
	 * @return array|WP_Error The conversation_id and the assistant's reply.
	 */
	public function reply( $conversation_id, $message ) {
		$settings = OSA_Settings::get();
		$message  = trim( (string) $message );

		if ( '' === $settings['api_key'] ) {
			return new WP_Error( 'osa_chat_unavailable', __( 'The assistant is not available right now.', 'order-status-assistant' ), array( 'status' => 503 ) );
		}
		if ( '' === $message ) {
			return new WP_Error( 'osa_empty_message', __( 'Please type a message.', 'order-status-assistant' ), array( 'status' => 400 ) );
		}

		$conversation = $this->load( $conversation_id );
		if ( self::customer_turns( $conversation['messages'] ) >= self::MAX_CUSTOMER_TURNS ) {
			return new WP_Error( 'osa_conversation_too_long', __( 'This conversation is getting long. Please refresh the page to start a new one.', 'order-status-assistant' ), array( 'status' => 429 ) );
		}

		// Work on a copy: a turn that doesn't finish cleanly is dropped, never half-saved.
		$messages   = $conversation['messages'];
		$messages[] = array(
			'role'    => 'user',
			'content' => $message,
		);

		for ( $round = 0; $round <= self::MAX_TOOL_ROUNDS; $round++ ) {
			$response = $this->call_claude( $settings, $messages );
			if ( is_wp_error( $response ) ) {
				return $response;
			}

			// A refusal, a cut-off reply or anything unexpected: answer safely and keep the turn out of the history.
			if ( ! in_array( $response->stop_reason ?? '', array( 'end_turn', 'tool_use' ), true ) ) {
				break;
			}

			$messages[] = array(
				'role'    => 'assistant',
				'content' => $response->content,
			);

			if ( 'end_turn' === $response->stop_reason ) {
				$reply = self::text_of( $response->content );
				if ( '' === $reply ) {
					break;
				}
				$this->save( $conversation['id'], $messages );
				return array(
					'conversation_id' => $conversation['id'],
					'reply'           => $reply,
				);
			}

			$messages[] = array(
				'role'    => 'user',
				'content' => $this->run_tools( $response->content ),
			);
		}

		return array(
			'conversation_id' => $conversation['id'],
			'reply'           => self::handoff_reply( $settings ),
		);
	}

	/**
	 * @return object|WP_Error The decoded Messages API response.
	 */
	private function call_claude( array $settings, array $messages ) {
		$request = array(
			'model'         => $settings['model'],
			'max_tokens'    => 16000,
			'system'        => self::system_prompt( $settings ),
			'tools'         => array( self::lookup_tool() ),
			// Replies are short and the customer is waiting, so keep thinking brief.
			'output_config' => array( 'effort' => 'low' ),
			'cache_control' => array( 'type' => 'ephemeral' ),
			'messages'      => $messages,
		);
		$headers = array(
			'content-type'      => 'application/json',
			'x-api-key'         => $settings['api_key'],
			'anthropic-version' => self::API_VERSION,
		);
		if ( in_array( $settings['model'], self::MODELS_WITH_FALLBACK, true ) ) {
			// If a safety classifier declines, the API retries on Anthropic's recommended fallback model in the same call.
			$request['fallbacks']      = 'default';
			$headers['anthropic-beta'] = 'server-side-fallback-2026-07-01';
		}

		$http = wp_remote_post(
			self::API_URL,
			array(
				'timeout' => 60,
				'headers' => $headers,
				'body'    => wp_json_encode( $request ),
			)
		);
		$code = is_wp_error( $http ) ? 0 : (int) wp_remote_retrieve_response_code( $http );
		$body = is_wp_error( $http ) ? null : json_decode( wp_remote_retrieve_body( $http ) );

		if ( 200 !== $code || ! isset( $body->content ) || ! is_array( $body->content ) ) {
			// Shown on the settings page, so a store owner sees a wrong key or an empty balance right away.
			OSA_Settings::record_error( $code, is_wp_error( $http ) ? $http->get_error_message() : ( $body->error->type ?? 'unexpected response' ) );
			return new WP_Error( 'osa_assistant_error', __( 'The assistant could not answer right now. Please try again in a moment.', 'order-status-assistant' ), array( 'status' => 502 ) );
		}

		return $body;
	}

	/**
	 * Runs every tool call in one assistant turn and returns all results for a single user message.
	 */
	private function run_tools( array $content ) {
		$results = array();
		foreach ( $content as $block ) {
			if ( 'tool_use' !== ( $block->type ?? '' ) ) {
				continue;
			}
			$output    = 'lookup_order' === $block->name ? $this->lookup_order( $block->input ) : array( 'error' => 'unknown_tool' );
			$results[] = array(
				'type'        => 'tool_result',
				'tool_use_id' => $block->id,
				'content'     => wp_json_encode( $output ),
			);
		}
		return $results;
	}

	/**
	 * The model only ever sees an order after the same ownership check as the public lookup,
	 * so no wording in a customer's message can get it to reveal someone else's order.
	 */
	private function lookup_order( $input ) {
		if ( ! $this->failed_lookups->allowed() ) {
			return array(
				'found' => false,
				'error' => 'too_many_attempts',
			);
		}

		$order = $this->lookup->find( $input->order_number ?? '', $input->email ?? '', $input->postcode ?? '' );
		if ( ! $order ) {
			$this->failed_lookups->hit();
			return array( 'found' => false );
		}

		return array(
			'found' => true,
			'order' => $this->lookup->describe( $order ),
		);
	}

	private static function lookup_tool() {
		return array(
			'name'         => 'lookup_order',
			'description'  => 'Looks up one order and returns its status, items, refunds and tracking numbers. Needs the order number plus the email address or the postcode the customer used for the order. Returns found=false when those details do not belong to the same order.',
			'strict'       => true,
			'input_schema' => array(
				'type'                 => 'object',
				'properties'           => array(
					'order_number' => array(
						'type'        => 'string',
						'description' => 'The order number exactly as the customer gave it.',
					),
					'email'        => array(
						'type'        => 'string',
						'description' => 'The email address the customer gave, or an empty string.',
					),
					'postcode'     => array(
						'type'        => 'string',
						'description' => 'The postcode the customer gave, or an empty string.',
					),
				),
				'required'             => array( 'order_number', 'email', 'postcode' ),
				'additionalProperties' => false,
			),
		);
	}

	private static function system_prompt( array $settings ) {
		$contact = '' !== $settings['support_contact'] ? $settings['support_contact'] : 'the store';

		return sprintf(
			"You are the order status assistant for the online store \"%s\". You help customers find out where their order is: its status, items, refunds and tracking numbers. You don't handle anything else.\n\n" .
			"To look up an order you need its order number plus the email address or the postcode used for the order. Ask for whatever is missing, then call lookup_order. Never guess order details.\n\n" .
			"Only state what lookup_order returned. Don't promise refunds, discounts, delivery dates or exceptions. For those, and for anything other than order status, point the customer to %s.\n\n" .
			"If lookup_order finds nothing, say you couldn't find an order matching those details and ask the customer to check them. Don't suggest whether such an order exists.\n\n" .
			"Reply in the customer's language, in a few short sentences of plain text.",
			get_bloginfo( 'name' ),
			$contact
		);
	}

	private static function handoff_reply( array $settings ) {
		if ( '' !== $settings['support_contact'] ) {
			/* translators: %s: how to reach the store, for example an email address. */
			return sprintf( __( 'Sorry, I can\'t help with that here. Please contact %s.', 'order-status-assistant' ), $settings['support_contact'] );
		}
		return __( 'Sorry, I can\'t help with that here. Please contact the store.', 'order-status-assistant' );
	}

	private static function text_of( array $content ) {
		$parts = array();
		foreach ( $content as $block ) {
			if ( 'text' === ( $block->type ?? '' ) ) {
				$parts[] = $block->text;
			}
		}
		return trim( implode( "\n\n", $parts ) );
	}

	private static function customer_turns( array $messages ) {
		$turns = 0;
		foreach ( $messages as $message ) {
			if ( 'user' === $message['role'] && is_string( $message['content'] ) ) {
				++$turns;
			}
		}
		return $turns;
	}

	private function load( $conversation_id ) {
		if ( preg_match( '/^[0-9a-f-]{36}$/', (string) $conversation_id ) ) {
			$messages = get_transient( 'osa_conv_' . $conversation_id );
			if ( is_array( $messages ) ) {
				return array(
					'id'       => $conversation_id,
					'messages' => $messages,
				);
			}
		}
		return array(
			'id'       => wp_generate_uuid4(),
			'messages' => array(),
		);
	}

	private function save( $conversation_id, array $messages ) {
		set_transient( 'osa_conv_' . $conversation_id, $messages, self::CONVERSATION_TTL );
	}
}
