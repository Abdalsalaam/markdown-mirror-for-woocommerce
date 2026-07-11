<?php
/**
 * Conflicts: detects other active .md-serving plugins and says so, once.
 *
 * @package AgentMint\ProductMarkdownMirror
 */

namespace AgentMint\ProductMarkdownMirror;

defined( 'ABSPATH' ) || exit;

/**
 * One-server-per-suffix guard.
 *
 * When another active plugin also serves .md suffixes, routing depends on
 * rule order and both plugins fight over the same URLs. The honest behavior
 * is one dismissible, functional notice naming the other plugin, shown only
 * on the plugins screen and our settings screen. No nags anywhere else.
 */
class Conflicts {

	/**
	 * User meta key recording dismissal.
	 *
	 * @var string
	 */
	const DISMISS_META = 'product_markdown_mirror_conflict_dismissed';

	/**
	 * Hook registration.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'admin_notices', array( $this, 'maybe_notice' ) );
		add_action( 'admin_init', array( $this, 'maybe_dismiss' ) );
	}

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
		$known = apply_filters( 'product_markdown_mirror_conflicting_plugins', array( 'markdown-mirror' ) );

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

	/**
	 * Show the dismissible conflict notice on relevant screens only.
	 *
	 * @return void
	 */
	public function maybe_notice() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, array( 'plugins', 'woocommerce_page_' . Settings::PAGE_SLUG ), true ) ) {
			return;
		}

		if ( get_user_meta( get_current_user_id(), self::DISMISS_META, true ) ) {
			return;
		}

		$detected = $this->detect();
		if ( empty( $detected ) ) {
			return;
		}

		$dismiss_url = wp_nonce_url(
			add_query_arg( 'product_markdown_mirror_dismiss_conflict', '1' ),
			'product_markdown_mirror_dismiss_conflict'
		);

		echo '<div class="notice notice-warning"><p>';
		printf(
			/* translators: %s: comma-separated plugin slugs. */
			esc_html__( 'Product Markdown Mirror: another active plugin also serves .md URLs (%s). Only one plugin should own that suffix; please keep one and deactivate the other.', 'product-markdown-mirror' ),
			esc_html( implode( ', ', $detected ) )
		);
		echo ' <a href="' . esc_url( $dismiss_url ) . '">' . esc_html__( 'Dismiss', 'product-markdown-mirror' ) . '</a>';
		echo '</p></div>';
	}

	/**
	 * Persist the per-user dismissal.
	 *
	 * @return void
	 */
	public function maybe_dismiss() {
		if ( ! isset( $_GET['product_markdown_mirror_dismiss_conflict'] ) ) {
			return;
		}

		check_admin_referer( 'product_markdown_mirror_dismiss_conflict' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		update_user_meta( get_current_user_id(), self::DISMISS_META, '1' );
	}
}
