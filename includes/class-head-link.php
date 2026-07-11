<?php
/**
 * Head link: advertises the mirror via rel="alternate" on product pages.
 *
 * @package AgentMint\ProductMarkdownMirror
 */

namespace AgentMint\ProductMarkdownMirror;

use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Prints one alternate-link tag on single product pages.
 *
 * This is the plugin's only front-end output: one static tag, no scripts,
 * no styles. The canonical stays the HTML page (per the blueprint), so the
 * mirror never competes with it.
 */
class Head_Link {

	/**
	 * Hook registration.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'wp_head', array( $this, 'maybe_render' ), 5 );
	}

	/**
	 * Render on single product pages when the surface is enabled.
	 *
	 * @return void
	 */
	public function maybe_render() {
		if ( ! Settings::mirrors_enabled() ) {
			return;
		}

		if ( function_exists( 'is_product' ) && is_product() ) {
			$product = wc_get_product( get_queried_object_id() );

			if ( $product ) {
				$this->render_for( $product );
			}

			return;
		}

		if ( is_tax( Term_Router::TAXONOMIES ) ) {
			$term = get_queried_object();

			if ( $term instanceof \WP_Term ) {
				$this->render_for_term( $term );
			}
		}
	}

	/**
	 * Print the alternate link tag for a term archive.
	 *
	 * @param \WP_Term $term Term.
	 * @return void
	 */
	public function render_for_term( \WP_Term $term ) {
		if ( ! Settings::term_mirrors_enabled( $term->taxonomy ) ) {
			return;
		}

		/** This filter is documented in includes/class-term-router.php */
		if ( ! apply_filters( 'product_markdown_mirror_term_is_mirrored', true, $term ) ) {
			return;
		}

		$url = Router::term_mirror_url( $term );

		if ( '' === $url ) {
			return;
		}

		printf(
			'<link rel="alternate" type="text/markdown" href="%s" />' . "\n",
			esc_url( $url )
		);
	}

	/**
	 * Print the alternate link tag for a product.
	 *
	 * @param WC_Product $product Product.
	 * @return void
	 */
	public function render_for( WC_Product $product ) {
		/**
		 * Filter whether a product gets a Markdown mirror.
		 *
		 * Documented in full in includes/class-router.php; applied here so the
		 * discovery link and the route always agree.
		 *
		 * @since 1.0.0
		 *
		 * @param bool       $mirrored Whether the product is mirrored.
		 * @param WC_Product $product  Product being rendered.
		 */
		if ( ! apply_filters( 'product_markdown_mirror_is_mirrored', true, $product ) ) {
			return;
		}

		printf(
			'<link rel="alternate" type="text/markdown" href="%s" />' . "\n",
			esc_url( Router::mirror_url( $product ) )
		);
	}
}
