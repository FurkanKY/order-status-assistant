<?php
/**
 * Per-visitor attempt counter.
 *
 * @package OrderStatusAssistant
 */

defined( 'ABSPATH' ) || exit;

/**
 * Counts attempts per IP address inside a time window.
 */
class OSA_Throttle {

	/** @var string */
	private $prefix;

	/** @var int */
	private $max;

	/** @var int */
	private $window;

	public function __construct( $prefix, $max, $window ) {
		$this->prefix = $prefix;
		$this->max    = (int) $max;
		$this->window = (int) $window;
	}

	/**
	 * Failed order lookups, shared by the lookup endpoint and the chat tool so neither can be used to guess orders.
	 */
	public static function failed_lookups() {
		return new self( 'osa_failures_', apply_filters( 'osa_max_failed_lookups', 10 ), 10 * MINUTE_IN_SECONDS );
	}

	/**
	 * Chat messages. Each one costs the store an API call.
	 */
	public static function chat_messages() {
		return new self( 'osa_chat_', apply_filters( 'osa_max_chat_messages', 30 ), HOUR_IN_SECONDS );
	}

	public function allowed() {
		return (int) get_transient( $this->key() ) < $this->max;
	}

	public function hit() {
		set_transient( $this->key(), (int) get_transient( $this->key() ) + 1, $this->window );
	}

	private function key() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return $this->prefix . md5( $ip );
	}
}
