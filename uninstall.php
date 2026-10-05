<?php
/**
 * Uninstall routine for Meet With Me.
 *
 * Removes all plugin options, transients and custom tables for every site
 * in the network (multisite aware).
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Clean up a single site's plugin data.
 */
function mwm_uninstall_site(): void {
	global $wpdb;

	// Remove all plugin options.
	$options = [
		'mwm_general',
		'mwm_google',
		'mwm_meetings',
		'mwm_email',
		'mwm_style',
		'mwm_db_version',
	];

	foreach ( $options as $option ) {
		delete_option( $option );
	}

	// Remove plugin transients (Zoom tokens, freebusy cache, calendar list,
	// OAuth state, booking rate-limit counters, one-off errors).
	$wpdb->query(
		"DELETE FROM {$wpdb->options}
		 WHERE option_name LIKE '\_transient\_mwm\_%'
		    OR option_name LIKE '\_transient\_timeout\_mwm\_%'"
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL

	// Drop all plugin tables.
	$tables = [
		$wpdb->prefix . 'mwm_event_types',
		$wpdb->prefix . 'mwm_bookings',
		$wpdb->prefix . 'mwm_availability_rules',
		$wpdb->prefix . 'mwm_blocked_dates',
	];

	foreach ( $tables as $table ) {
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL
	}
}

if ( is_multisite() ) {
	$mwm_site_ids = get_sites(
		[
			'fields' => 'ids',
			'number' => 0, // No limit.
		]
	);

	foreach ( $mwm_site_ids as $mwm_site_id ) {
		switch_to_blog( (int) $mwm_site_id );
		mwm_uninstall_site();
		restore_current_blog();
	}
} else {
	mwm_uninstall_site();
}
