<?php
/**
 * Uninstall FreemKit
 *
 * @package    FreemKit
 */

// If uninstall is not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Delete options and data for a single site.
 *
 * @param int $site_id Site ID. 0 for current site.
 */
function freemkit_delete_site_options( int $site_id = 0 ): void {
	global $wpdb;

	$option_names = array(
		'freemkit_settings',
		'freemkit_network_settings',
		'freemkit_db_version',
	);

	if ( $site_id > 0 ) {
		switch_to_blog( $site_id );
	}

	foreach ( $option_names as $option_name ) {
		delete_option( $option_name );
	}

	if ( defined( 'FREEMKIT_DELETE_DATA' ) && FREEMKIT_DELETE_DATA ) {

		// Delete the subscribers table.
		$table_name = $wpdb->prefix . 'freemkit_subscribers';
		$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	if ( $site_id > 0 ) {
		restore_current_blog();
	}
}

// Handle multisite uninstall.
if ( is_multisite() ) {
	// Delete options for each site.
	$sites = get_sites(
		array(
			'fields' => 'ids',
		)
	);

	foreach ( (array) $sites as $site_id ) {
		freemkit_delete_site_options( $site_id );
	}

	// Delete network options.
	$option_names = array(
		'freemkit_settings',
		'freemkit_network_settings',
	);

	foreach ( $option_names as $option_name ) {
		delete_site_option( $option_name );
	}
} else {
	// Delete options for single site.
	freemkit_delete_site_options();
}
