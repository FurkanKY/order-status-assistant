<?php
/**
 * Chat assistant with order lookup as its only tool.
 *
 * @package Parcelwise
 */

defined( 'ABSPATH' ) || exit;

/**
 * Runs one customer message through the model, calling lookup_order whenever the model asks for it.
 * Which model answers is up to Parcelwise_Gateways; the rules here are the same for every backend.
 *
 * Conversations live server-side and are only ever appended to, so the browser can't rewrite
 * earlier turns, and whatever the model sent back (Claude's thinking blocks, for one) stays valid
 * when it is replayed.
 */
class Parcelwise_Chat {

	const MAX_MESSAGE_LENGTH = 1000;
	const MAX_CUSTOMER_TURNS = 12;
	const MAX_TOOL_ROUNDS    = 4;
	const CONVERSATION_TTL   = HOUR_IN_SECONDS;

	/** @var Parcelwise_Order_Lookup */
	private $lookup;

	/** @var Parcelwise_Throttle */
	private $failed_lookups;

	/** @var array[] Tracking links from orders verified during the current reply. */
	private $links = array();

	public function __construct( Parcelwise_Order_Lookup $lookup, Parcelwise_Throttle $failed_lookups ) {
		$this->lookup         = $lookup;
		$this->failed_lookups = $failed_lookups;
	}

	/**
	 * @param string $conversation_id Empty for a new conversation.
	 * @param string $message         What the customer typed.
	 * @return array|WP_Error The conversation_id and the assistant's reply.
	 */
	public function reply( $conversation_id, $message ) {
		$settings = Parcelwise_Settings::get();
		$message  = trim( (string) $message );
		$gateway  = Parcelwise_Gateways::resolve( $settings );

		if ( ! $gateway ) {
			return new WP_Error( 'parcelwise_chat_unavailable', __( 'The assistant is not available right now.', 'parcelwise' ), array( 'status' => 503 ) );
		}
		if ( '' === $message ) {
			return new WP_Error( 'parcelwise_empty_message', __( 'Please type a message.', 'parcelwise' ), array( 'status' => 400 ) );
		}

		$conversation = $this->load( $conversation_id, $gateway->id() );
		if ( $conversation['turns'] >= self::MAX_CUSTOMER_TURNS ) {
			return new WP_Error( 'parcelwise_conversation_too_long', __( 'This conversation is getting long. Please refresh the page to start a new one.', 'parcelwise' ), array( 'status' => 429 ) );
		}

		// Work on a copy: a turn that doesn't finish cleanly is dropped, never half-saved.
		$messages   = $conversation['messages'];
		$messages[] = $gateway->user_message( $message );
		$system     = self::system_prompt( $settings );

		$this->links = array();

		for ( $round = 0; $round <= self::MAX_TOOL_ROUNDS; $round++ ) {
			$turn = $gateway->send( $messages, $system, self::lookup_tool() );
			if ( is_wp_error( $turn ) ) {
				return $turn;
			}

			// A refusal, a cut-off reply or anything unexpected: answer safely and keep the turn out of the history.
			if ( Parcelwise_Model_Turn::STOPPED === $turn->kind || ( Parcelwise_Model_Turn::REPLY === $turn->kind && '' === $turn->text ) ) {
				break;
			}

			$messages[] = $turn->message;

			if ( Parcelwise_Model_Turn::REPLY === $turn->kind ) {
				$this->save( $conversation['id'], $gateway->id(), $conversation['turns'] + 1, $messages );
				// Links come from the verified order, never from the reply text, so the model can't invent one.
				return array(
					'conversation_id' => $conversation['id'],
					'reply'           => $turn->text,
					'links'           => array_values( $this->links ),
				);
			}

			$messages[] = $gateway->tool_results_message( $this->run_tools( $turn->tool_calls ) );
		}

		return array(
			'conversation_id' => $conversation['id'],
			'reply'           => self::handoff_reply( $settings ),
		);
	}

	/**
	 * The one tool the model gets, in a backend-neutral shape.
	 */
	public static function lookup_tool() {
		return array(
			'name'        => 'lookup_order',
			'description' => 'Looks up one order and returns its status, items, refunds and tracking numbers. Needs the order number plus the email address or the postcode the customer used for the order. Returns found=false when those details do not belong to the same order.',
			'schema'      => array(
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

	/**
	 * Runs every tool call in one model turn.
	 *
	 * @param array[] $calls Each with id, name and args.
	 * @return array[] Each with id, name and output.
	 */
	private function run_tools( array $calls ) {
		$results = array();
		foreach ( $calls as $call ) {
			$results[] = array(
				'id'     => $call['id'],
				'name'   => $call['name'],
				'output' => 'lookup_order' === $call['name'] ? $this->lookup_order( $call['args'] ) : array( 'error' => 'unknown_tool' ),
			);
		}
		return $results;
	}

	/**
	 * The model only ever sees an order after the same ownership check as the public lookup,
	 * so no wording in a customer's message can get it to reveal someone else's order.
	 */
	private function lookup_order( array $args ) {
		if ( ! $this->failed_lookups->allowed() ) {
			return array(
				'found' => false,
				'error' => 'too_many_attempts',
			);
		}

		$order = $this->lookup->find( (string) ( $args['order_number'] ?? '' ), (string) ( $args['email'] ?? '' ), (string) ( $args['postcode'] ?? '' ) );
		if ( ! $order ) {
			$this->failed_lookups->hit();
			return array( 'found' => false );
		}

		$described = $this->lookup->describe( $order );
		foreach ( $described['tracking'] as $tracking ) {
			if ( '' !== $tracking['url'] ) {
				$this->links[ $tracking['url'] ] = array(
					'label'    => '' !== $tracking['provider'] ? $tracking['provider'] : $tracking['number'],
					'number'   => $tracking['number'],
					'url'      => $tracking['url'],
					'url_type' => $tracking['url_type'],
				);
			}
		}

		return array(
			'found' => true,
			'order' => $described,
		);
	}

	private static function system_prompt( array $settings ) {
		$contact = '' !== $settings['support_contact'] ? $settings['support_contact'] : 'the store';

		return sprintf(
			"You are the order status assistant for the online store \"%s\". You help customers find out where their order is: its status, items, refunds and tracking numbers. You don't handle anything else.\n\n" .
			"To look up an order you need its order number plus the email address or the postcode used for the order. Ask for whatever is missing, then call lookup_order. Never guess order details.\n\n" .
			"Only state what lookup_order returned. Don't promise refunds, discounts, delivery dates or exceptions. For those, and for anything other than order status, point the customer to %s.\n\n" .
			"Tracking links are shown to the customer as buttons under your reply, so don't write out URLs; name the carrier and give the tracking number. When a tracking entry's url_type is carrier_site, tell the customer to enter the tracking number on that carrier's site.\n\n" .
			"If lookup_order finds nothing, say you couldn't find an order matching those details and ask the customer to check them. Don't suggest whether such an order exists.\n\n" .
			"Reply in the customer's language, in a few short sentences of plain text.",
			get_bloginfo( 'name' ),
			$contact
		);
	}

	private static function handoff_reply( array $settings ) {
		if ( '' !== $settings['support_contact'] ) {
			/* translators: %s: how to reach the store, for example an email address. */
			return sprintf( __( 'Sorry, I can\'t help with that here. Please contact %s.', 'parcelwise' ), $settings['support_contact'] );
		}
		return __( 'Sorry, I can\'t help with that here. Please contact the store.', 'parcelwise' );
	}

	/**
	 * A stored conversation is only picked up again by the backend that wrote it; otherwise a new one starts.
	 */
	private function load( $conversation_id, $gateway_id ) {
		if ( preg_match( '/^[0-9a-f-]{36}$/', (string) $conversation_id ) ) {
			$saved = get_transient( 'parcelwise_conv_' . $conversation_id );
			if ( is_array( $saved ) && ( $saved['gateway'] ?? '' ) === $gateway_id ) {
				return array(
					'id'       => $conversation_id,
					'turns'    => (int) $saved['turns'],
					'messages' => $saved['messages'],
				);
			}
		}
		return array(
			'id'       => wp_generate_uuid4(),
			'turns'    => 0,
			'messages' => array(),
		);
	}

	private function save( $conversation_id, $gateway_id, $turns, array $messages ) {
		set_transient(
			'parcelwise_conv_' . $conversation_id,
			array(
				'gateway'  => $gateway_id,
				'turns'    => $turns,
				'messages' => $messages,
			),
			self::CONVERSATION_TTL
		);
	}
}
