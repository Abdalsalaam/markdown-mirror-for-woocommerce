<?php
/**
 * Uninstall handler: removes every option, transient, and term-meta row the
 * plugin can create. Multisite-aware. Documented in the readme FAQ.
 *
 * @package AgentMint\MarkdownMirrorWC
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Remove all plugin data for the current site.
 *
 * @return void
 */
function mdmirwc_uninstall_site() {
	global $wpdb;

	delete_option( 'mdmirwc_settings' );
	delete_option( 'mdmirwc_flush_needed' );
	delete_option( 'mdmirwc_cache_gen' );

	// Cached mirrors (all generations) and their timeout rows.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup of plugin-prefixed transients; no API exists for prefix deletes.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_mdmirwc_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_mdmirwc_' ) . '%'
		)
	);

	// Term cache versions.
	delete_metadata( 'term', 0, 'mdmirwc_ver', '', true );

	// On persistent object caches, transients can live outside the options
	// table entirely; the SQL sweep above cannot reach them. Uninstall is a
	// one-time event, so a full cache flush is the correct, complete cleanup.
	wp_cache_flush();
}

if ( is_multisite() ) {
	$mdmirwc_site_ids = get_sites( array( 'fields' => 'ids' ) );

	foreach ( $mdmirwc_site_ids as $mdmirwc_site_id ) {
		switch_to_blog( $mdmirwc_site_id );
		mdmirwc_uninstall_site();
		restore_current_blog();
	}
} else {
	mdmirwc_uninstall_site();
}
