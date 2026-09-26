<?php
/**
 * Uninstall: remove everything the plugin stored.
 *
 * Runs only when the plugin is DELETED from the Plugins screen, never on
 * deactivation. Without it the provider API key stayed in wp_options in
 * plain text after the plugin was gone, where nothing would ever use or
 * rotate it again. The knowledge base and bot settings go too, as WordPress
 * expects of a deleted plugin; deactivate instead to keep them.
 *
 * @package Xenios_KB_Bot
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Delete the plugin's options and transients on the current site.
 */
function xenios_kb_bot_uninstall_site() {
	global $wpdb;

	$options = array(
		'xenios_kb_bot_llm_key',
		'xenios_kb_bot_llm_endpoint',
		'xenios_kb_bot_llm_model',
		'xenios_kb_bot_kb',
		'xenios_kb_bot_bot_name',
		'xenios_kb_bot_welcome',
		'xenios_kb_bot_accent',
	);
	foreach ( $options as $option ) {
		delete_option( $option );
	}

	// Session, rate-limit and backoff transients are keyed by a salted hash,
	// so they cannot be listed by name; match the shared prefix instead.
	// With a persistent object cache they never reach this table and simply
	// expire (all within two hours).
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off cleanup, no API for prefix deletes.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_xenios_kb_bot_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_xenios_kb_bot_' ) . '%'
		)
	);
}

if ( is_multisite() ) {
	// A network-wide delete runs this file once; each site has its own
	// options table.
	$xenios_kb_bot_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $xenios_kb_bot_site_ids as $xenios_kb_bot_site_id ) {
		switch_to_blog( $xenios_kb_bot_site_id );
		xenios_kb_bot_uninstall_site();
		restore_current_blog();
	}
} else {
	xenios_kb_bot_uninstall_site();
}
