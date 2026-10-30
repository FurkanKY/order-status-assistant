<?php
/**
 * Public REST endpoints: direct order lookup and the chat assistant.
 *
 * @package Parcelwise
 */

defined( 'ABSPATH' ) || exit;

/**
 * POST /wp-json/parcelwise/v1/lookup
 * POST /wp-json/parcelwise/v1/chat
 */
class Parcelwise_REST_Controller {

	const ROUTE_NAMESPACE = 'parcelwise/v1';

	/** @var Parcelwise_Order_Lookup */
	private $lookup;

	/** @var Parcelwise_Chat */
	private $chat;

	/** @var Parcelwise_Throttle */
	private $failed_lookups;

	/** @var Parcelwise_Throttle */
	private $chat_messages;

	public function __construct( Parcelwise_Order_Lookup $lookup, Parcelwise_Chat $chat, Parcelwise_Throttle $failed_lookups, Parcelwise_Throttle $chat_messages ) {
		$this->lookup         = $lookup;
		$this->chat           = $chat;
		$this->failed_lookups = $failed_lookups;
		$this->chat_messages  = $chat_messages;
	}

	public function register_routes() {
		// Both routes are public on purpose: shoppers aren't logged in. Ownership is checked in the lookup itself.
		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/lookup',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'lookup' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'order_number' => array(
						'type'      => 'string',
						'required'  => true,
						'maxLength' => 64,
					),
					'email'        => array(
						'type'      => 'string',
						'default'   => '',
						'maxLength' => 254,
					),
					'postcode'     => array(
						'type'      => 'string',
						'default'   => '',
						'maxLength' => 20,
					),
				),
			)
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/chat',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'chat' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'message'         => array(
						'type'      => 'string',
						'required'  => true,
						'maxLength' => Parcelwise_Chat::MAX_MESSAGE_LENGTH,
					),
					'conversation_id' => array(
						'type'      => 'string',
						'default'   => '',
						'maxLength' => 36,
					),
				),
			)
		);
	}

	public function lookup( WP_REST_Request $request ) {
		$email    = $request['email'];
		$postcode = $request['postcode'];

		if ( '' === trim( $email ) && '' === trim( $postcode ) ) {
			return new WP_Error(
				'parcelwise_missing_verification',
				__( 'Please provide the email address or postcode used for the order.', 'parcelwise' ),
				array( 'status' => 400 )
			);
		}

		if ( ! $this->failed_lookups->allowed() ) {
			return new WP_Error(
				'parcelwise_too_many_attempts',
				__( 'Too many attempts. Please try again in a few minutes.', 'parcelwise' ),
				array( 'status' => 429 )
			);
		}

		$order = $this->lookup->find( $request['order_number'], $email, $postcode );

		if ( ! $order ) {
			$this->failed_lookups->hit();
			// Same answer whether or not the order exists, so the endpoint can't be used to probe order numbers.
			return new WP_Error(
				'parcelwise_not_found',
				__( 'We could not find an order matching these details.', 'parcelwise' ),
				array( 'status' => 404 )
			);
		}

		return rest_ensure_response( $this->lookup->describe( $order ) );
	}

	public function chat( WP_REST_Request $request ) {
		if ( ! $this->chat_messages->allowed() ) {
			return new WP_Error(
				'parcelwise_too_many_messages',
				__( 'You have sent a lot of messages. Please try again later.', 'parcelwise' ),
				array( 'status' => 429 )
			);
		}
		$this->chat_messages->hit();

		$result = $this->chat->reply( $request['conversation_id'], $request['message'] );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}
}
