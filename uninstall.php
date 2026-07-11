<?php
/**
 * Uninstall handler: removes every option, transient, and user-meta row the
 * plugin can create. Multisite-aware. Documented in the readme FAQ.
 *
 * @package AgentMint\ProductMarkdownMirror
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Remove all plugin data for the current site.
 *
 * @return void
 */
function product_markdown_mirror_uninstall_site() {
	global $wpdb;

	delete_option( 'product_markdown_mirror_settings' );
	delete_option( 'product_markdown_mirror_flush_needed' );
	delete_option( 'product_markdown_mirror_cache_gen' );

	// Cached mirrors (all generations) and their timeout rows.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup of plugin-prefixed transients; no API exists for prefix deletes.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_product_markdown_mirror_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_product_markdown_mirror_' ) . '%'
		)
	);

	// Per-user conflict-notice dismissals written by pre-release builds
	// (the released plugin shows no notices; the key stays swept here).
	delete_metadata( 'user', 0, 'product_markdown_mirror_conflict_dismissed', '', true );

	// Term cache versions.
	delete_metadata( 'term', 0, 'product_markdown_mirror_ver', '', true );

	// On persistent object caches, transients can live outside the options
	// table entirely; the SQL sweep above cannot reach them. Uninstall is a
	// one-time event, so a full cache flush is the correct, complete cleanup.
	wp_cache_flush();
}

if ( is_multisite() ) {
	$product_markdown_mirror_site_ids = get_sites( array( 'fields' => 'ids' ) );

	foreach ( $product_markdown_mirror_site_ids as $product_markdown_mirror_site_id ) {
		switch_to_blog( $product_markdown_mirror_site_id );
		product_markdown_mirror_uninstall_site();
		restore_current_blog();
	}
} else {
	product_markdown_mirror_uninstall_site();
}
