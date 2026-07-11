<?php
/**
 * Plugin Name: Markdown Mirror for WooCommerce
 * Plugin URI: https://agentmint.net/blueprints/product-markdown-mirror/
 * Description: Serves read-only Markdown mirrors of WooCommerce product pages at {product-url}.md with rel="alternate" discovery. No tracking, no store writes.
 * Version: 1.0.0
 * Author: AgentMint
 * Author URI: https://agentmint.net
 * Text Domain: markdown-mirror-for-woocommerce
 * Domain Path: /languages
 * Requires Plugins: woocommerce
 * Requires PHP: 7.4
 * Requires at least: 6.5
 * Tested up to: 7.0
 * WC requires at least: 9.2
 * WC tested up to: 10.9
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package AgentMint\MarkdownMirrorWC
 */

defined( 'ABSPATH' ) || exit;

define( 'MDMIRWC_VERSION', '1.0.0' );
define( 'MDMIRWC_FILE', __FILE__ );
define( 'MDMIRWC_ABSPATH', trailingslashit( __DIR__ ) );

require_once MDMIRWC_ABSPATH . 'includes/class-main.php';

/**
 * Return the shared plugin instance.
 *
 * @return \AgentMint\MarkdownMirrorWC\Main
 */
function mdmirwc() {
	return \AgentMint\MarkdownMirrorWC\Main::instance();
}

mdmirwc();

/**
 * On activation, queue a rewrite-rules flush for the next request.
 *
 * The mirror rewrite rules are registered on init, which has not run yet
 * during activation, so the flush is deferred via a flag option instead of
 * being executed here (it would write rules that do not include ours).
 *
 * @return void
 */
function mdmirwc_activate() {
	update_option( 'mdmirwc_flush_needed', 'yes', false );
}
register_activation_hook( __FILE__, 'mdmirwc_activate' );

/**
 * On deactivation, flush rewrite rules so the mirror routes are removed.
 *
 * @return void
 */
function mdmirwc_deactivate() {
	delete_option( 'mdmirwc_flush_needed' );
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'mdmirwc_deactivate' );
