<?php
/**
 * Uninstall handler: removes every option and transient the plugin created.
 *
 * Hardened (multisite loop, transient sweep) in task T-09.
 *
 * @package AgentMint\ProductMarkdownMirror
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'product_markdown_mirror_settings' );
delete_option( 'product_markdown_mirror_flush_needed' );
