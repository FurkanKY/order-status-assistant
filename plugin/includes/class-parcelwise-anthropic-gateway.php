<?php
/**
 * Claude through the store's own Anthropic API key.
 *
 * @package Parcelwise
 */

defined( 'ABSPATH' ) || exit;

/**
 * Calls the Messages API through the WordPress HTTP API instead of the PHP SDK: plugins that bundle
 * their own HTTP client libraries clash with each other on shared hosting, and this keeps the plugin
 * dependency-free on PHP 7.4. This path also gets what the generic connector can't express: strict
 * tool schemas, the server-side refusal fallback, prompt caching and low effort.
 */
final class Parcelwise_Anthropic_Gateway implements Parcelwise_Gateway {

	const API_URL     = 'https://api.anthropic.com/v1/messages'; // phpcs:ignore PluginCheck.CodeAnalysis.AIProvider.DirectIntegration -- optional path for stores that save their own key; the site's AI Client is used otherwise.
	const API_VERSION = '2026-10-21';

	/** Models that accept the server-side refusal fallback. */
	const MODELS_WITH_FALLBACK = array( 'claude-opus-5-5', 'claude-sonnet-5-5' );

	/** @var string */
	private $api_key;

	/** @var string */
	private $model;

	public function __construct( $api_key, $model ) {
		$this->api_key = $api_key;
		$this->model   = $model;
	}

	public function id() {
		return 'anthropic';
	}

	public function user_message( $text ) {
		return array(
			'role'    => 'user',
			'content' => $text,
		);
	}

	public function tool_results_message( array $results ) {
		$content = array();
		foreach ( $results as $result ) {
			$content[] = array(
				'type'        => 'tool_result',
				'tool_use_id' => $result['id'],
				'content'     => wp_json_encode( $result['output'] ),
			);
		}
		return array(
			'role'    => 'user',
			'content' => $content,
		);
	}

	public function send( array $messages, $system, array $tool ) {
		$request = array(
			'model'         => $this->model,
			'max_tokens'    => 16000,
			'system'        => $system,
			'tools'         => array(
				array(
					'name'         => $tool['name'],
					'description'  => $tool['description'],
					'strict'       => true,
					'input_schema' => $tool['schema'],
				),
			),
			// Replies are short and the customer is waiting, so keep thinking brief.
			'output_config' => array( 'effort' => 'low' ),
			'cache_control' => array( 'type' => 'ephemeral' ),
			'messages'      => $messages,
		);
		$headers = array(
			'content-type'      => 'application/json',
			'x-api-key'         => $this->api_key,
			'anthropic-version' => self::API_VERSION,
		);
		if ( in_array( $this->model, self::MODELS_WITH_FALLBACK, true ) ) {
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
			Parcelwise_Settings::record_error( $code, is_wp_error( $http ) ? $http->get_error_message() : ( $body->error->type ?? 'unexpected response' ) );
			return new WP_Error( 'parcelwise_assistant_error', __( 'The assistant could not answer right now. Please try again in a moment.', 'parcelwise' ), array( 'status' => 502 ) );
		}

		$calls = array();
		$texts = array();
		foreach ( $body->content as $block ) {
			$type = $block->type ?? '';
			if ( 'tool_use' === $type ) {
				$calls[] = array(
					'id'   => $block->id,
					'name' => $block->name,
					// Re-decoded as an array, whatever JSON escaping the model used for the input.
					'args' => (array) json_decode( wp_json_encode( $block->input ), true ),
				);
			} elseif ( 'text' === $type ) {
				$texts[] = $block->text;
			}
		}

		// Sent back verbatim on the next call, thinking blocks included.
		$message = array(
			'role'    => 'assistant',
			'content' => $body->content,
		);
		$stop    = $body->stop_reason ?? '';

		if ( 'tool_use' === $stop && $calls ) {
			return new Parcelwise_Model_Turn( Parcelwise_Model_Turn::TOOL_CALLS, $message, '', $calls );
		}
		if ( 'end_turn' === $stop ) {
			return new Parcelwise_Model_Turn( Parcelwise_Model_Turn::REPLY, $message, trim( implode( "\n\n", $texts ) ) );
		}
		return new Parcelwise_Model_Turn( Parcelwise_Model_Turn::STOPPED );
	}
}
