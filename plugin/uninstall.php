<?php
/**
 * Removes the plugin's settings and stored conversations when it is deleted.
 *
 * @package OrderStatusAssistant
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'osa_settings' );
delete_option( 'osa_last_error' );

// Conversations and rate-limit counters are transients that expire on their own; remove them now anyway.
global $wpdb;
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off cleanup of this plugin's own transients.
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_osa_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_osa_' ) . '%'
	)
);
