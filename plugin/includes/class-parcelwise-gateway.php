<?php
/**
 * The seam between the chat loop and whichever model backend answers it.
 *
 * @package Parcelwise
 */

defined( 'ABSPATH' ) || exit;

/**
 * A model backend. Each one keeps conversation history in its own format, because that is what it
 * has to send back unchanged (Claude's thinking blocks, for one), so the chat loop only appends what
 * a gateway hands it and never looks inside.
 */
interface Parcelwise_Gateway {

	/**
	 * Stored with each conversation, so a history is only ever replayed through the backend that wrote it.
	 *
	 * @return string
	 */
	public function id();

	/**
	 * History entry for something the customer typed.
	 *
	 * @param string $text Customer message.
	 * @return mixed
	 */
	public function user_message( $text );

	/**
	 * History entry carrying tool results back to the model.
	 *
	 * @param array[] $results Each with id, name and output.
	 * @return mixed
	 */
	public function tool_results_message( array $results );

	/**
	 * @param array  $messages History so far, ending with the newest user or tool-results entry.
	 * @param string $system   System prompt.
	 * @param array  $tool     The lookup tool: name, description and a JSON schema.
	 * @return Parcelwise_Model_Turn|WP_Error
	 */
	public function send( array $messages, $system, array $tool );
}

/**
 * One model response, reduced to what the chat loop acts on.
 */
final class Parcelwise_Model_Turn {

	const REPLY      = 'reply';
	const TOOL_CALLS = 'tool_calls';
	/** A refusal, a cut-off answer or anything else the loop shouldn't save. */
	const STOPPED = 'stopped';

	/** @var string One of the constants above. */
	public $kind;

	/** @var mixed The model's message in the gateway's own history format. */
	public $message;

	/** @var string */
	public $text;

	/** @var array[] Each with id, name and args. */
	public $tool_calls;

	public function __construct( $kind, $message = null, $text = '', array $tool_calls = array() ) {
		$this->kind       = $kind;
		$this->message    = $message;
		$this->text       = $text;
		$this->tool_calls = $tool_calls;
	}
}

/**
 * Picks the backend for a chat.
 */
final class Parcelwise_Gateways {

	/**
	 * The store's own Anthropic key wins when one is saved, since entering it is an explicit choice.
	 * Otherwise the site's AI connection (WordPress 7.0+, Settings > Connectors) is used, if it can
	 * call tools.
	 *
	 * @return Parcelwise_Gateway|null Null when neither is available.
	 */
	public static function resolve( array $settings ) {
		if ( '' !== $settings['api_key'] ) {
			return new Parcelwise_Anthropic_Gateway( $settings['api_key'], $settings['model'] );
		}
		if ( Parcelwise_Connector_Gateway::is_available( Parcelwise_Chat::lookup_tool() ) ) {
			return new Parcelwise_Connector_Gateway( $settings['model'] );
		}
		return null;
	}

	/**
	 * @return string 'anthropic', 'connector' or 'none'.
	 */
	public static function current( array $settings ) {
		$gateway = self::resolve( $settings );
		return $gateway ? $gateway->id() : 'none';
	}
}
