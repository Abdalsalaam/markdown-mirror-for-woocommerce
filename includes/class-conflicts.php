<?php
/**
 * Conflicts: detects other active .md-serving plugins.
 *
 * @package AgentMint\MarkdownMirrorWC
 */

namespace AgentMint\MarkdownMirrorWC;

defined( 'ABSPATH' ) || exit;

/**
 * One-server-per-suffix guard.
 *
 * When another active plugin also serves .md suffixes, routing depends on
 * rule order and both plugins fight over the same URLs. Detection results
 * surface only as the status row on the plugin's own settings screen; the
 * plugin adds nothing to admin_notices.
 */
class Conflicts {

	/**
	 * Detect active plugins known to serve .md suffixes.
	 *
	 * @return string[] Matching plugin directory slugs.
	 */
	public function detect() {
		/**
		 * Filter the list of plugin directory slugs known to serve .md URLs.
		 *
		 * @since 1.0.0
		 *
		 * @param string[] $slugs Plugin directory slugs.
		 */
		$known = apply_filters( 'mdmirwc_conflicting_plugins', array( 'markdown-mirror' ) );

		$active   = (array) get_option( 'active_plugins', array() );
		$detected = array();

		foreach ( $active as $plugin_file ) {
			$dir = dirname( (string) $plugin_file );

			if ( in_array( $dir, $known, true ) ) {
				$detected[] = $dir;
			}
		}

		return $detected;
	}
}
