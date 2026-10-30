<?php
/**
 * Whatever AI provider the site has connected under Settings > Connectors (WordPress 7.0+).
 *
 * @package Parcelwise
 */

defined( 'ABSPATH' ) || exit;

use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\UserMessage;
use WordPress\AiClient\Tools\DTO\FunctionDeclaration;
use WordPress\AiClient\Tools\DTO\FunctionResponse;

/**
 * Lets a store use the AI connection it already has instead of a Parcelwise-specific API key. History is
 * kept as the AI Client's own message arrays, which round-trip through Message::fromArray().
 */
final class Parcelwise_Connector_Gateway implements Parcelwise_Gateway {

	/** @var string */
	private $model;

	/**
	 * @param string $model Preferred model; a site connected to another provider uses its own.
	 */
	public function __construct( $model ) {
		$this->model = $model;
	}

	/**
	 * True when WordPress has an AI connection that can generate text and call tools. Makes no model call.
	 */
	public static function is_available( array $tool ) {
		if ( ! function_exists( 'wp_ai_client_prompt' ) || ! function_exists( 'wp_supports_ai' ) || ! wp_supports_ai() ) {
			return false;
		}
		try {
			return (bool) wp_ai_client_prompt( 'Where is my order?' )
				->using_function_declarations( self::declaration( $tool ) )
				->is_supported_for_text_generation();
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	public function id() {
		return 'connector';
	}

	public function user_message( $text ) {
		return ( new UserMessage( array( new MessagePart( $text ) ) ) )->toArray();
	}

	public function tool_results_message( array $results ) {
		$parts = array();
		foreach ( $results as $result ) {
			$parts[] = new MessagePart( new FunctionResponse( $result['id'], $result['name'], $result['output'] ) );
		}
		return ( new UserMessage( $parts ) )->toArray();
	}

	public function send( array $messages, $system, array $tool ) {
		try {
			$history = array_map( array( Message::class, 'fromArray' ), $messages );
			$current = array_pop( $history );

			$builder = wp_ai_client_prompt()
				->using_system_instruction( $system )
				->using_function_declarations( self::declaration( $tool ) )
				->using_model_preference( $this->model );
			if ( $history ) {
				$builder->with_history( ...$history );
			}
			$result = $builder->with_message_parts( ...$current->getParts() )->generate_text_result();
		} catch ( \Throwable $e ) {
			$result = new WP_Error( 'parcelwise_connector_exception', $e->getMessage() );
		}

		if ( is_wp_error( $result ) ) {
			Parcelwise_Settings::record_error( 0, $result->get_error_message() );
			return new WP_Error( 'parcelwise_assistant_error', __( 'The assistant could not answer right now. Please try again in a moment.', 'parcelwise' ), array( 'status' => 502 ) );
		}

		$candidate = $result->getCandidates()[0];
		$message   = $candidate->getMessage();
		$calls     = array();
		$texts     = array();
		foreach ( $message->getParts() as $part ) {
			$type = (string) $part->getType();
			if ( 'function_call' === $type ) {
				$call    = $part->getFunctionCall();
				$calls[] = array(
					'id'   => $call->getId(),
					'name' => $call->getName(),
					'args' => (array) $call->getArgs(),
				);
			} elseif ( 'text' === $type && 'thought' !== (string) $part->getChannel() ) {
				$texts[] = $part->getText();
			}
		}

		if ( $calls ) {
			return new Parcelwise_Model_Turn( Parcelwise_Model_Turn::TOOL_CALLS, $message->toArray(), '', $calls );
		}
		if ( 'stop' === (string) $candidate->getFinishReason() ) {
			return new Parcelwise_Model_Turn( Parcelwise_Model_Turn::REPLY, $message->toArray(), trim( implode( "\n\n", $texts ) ) );
		}
		return new Parcelwise_Model_Turn( Parcelwise_Model_Turn::STOPPED );
	}

	private static function declaration( array $tool ) {
		return new FunctionDeclaration( $tool['name'], $tool['description'], $tool['schema'] );
	}
}
