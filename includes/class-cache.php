<?php
/**
 * Cache: per-product transient storage for rendered mirrors.
 *
 * @package AgentMint\ProductMarkdownMirror
 */

namespace AgentMint\ProductMarkdownMirror;

use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Transient-backed cache with generation-salt invalidation.
 *
 * Per-product entries are keyed by a global generation number; bumping the
 * generation invalidates everything at once without wildcard deletes (stale
 * entries simply expire). Freshness rule from the blueprint: a stale mirror
 * must never contradict the live page, so every product change invalidates.
 */
class Cache {

	/**
	 * Option storing the current cache generation.
	 *
	 * @var string
	 */
	const GENERATION_OPTION = 'product_markdown_mirror_cache_gen';

	/**
	 * Transient key prefix.
	 *
	 * @var string
	 */
	const KEY_PREFIX = 'product_markdown_mirror_';

	/**
	 * Hook registration for invalidation.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'woocommerce_new_product', array( $this, 'invalidate' ) );
		add_action( 'woocommerce_update_product', array( $this, 'invalidate' ) );
		add_action( 'woocommerce_product_set_stock', array( $this, 'invalidate_product_object' ) );
		add_action( 'woocommerce_variation_set_stock', array( $this, 'invalidate_variation_object' ) );
		add_action( 'woocommerce_new_product_variation', array( $this, 'invalidate_variation' ) );
		add_action( 'woocommerce_update_product_variation', array( $this, 'invalidate_variation' ) );
		// Both: update_option_* only fires for existing options; the first
		// settings save creates the option and fires add_option_* instead.
		add_action( 'update_option_' . Settings::OPTION_NAME, array( $this, 'invalidate_all' ) );
		add_action( 'add_option_' . Settings::OPTION_NAME, array( $this, 'invalidate_all' ) );
	}

	/**
	 * Fetch a cached mirror body.
	 *
	 * @param WC_Product $product Product.
	 * @return string|false
	 */
	public function get( WC_Product $product ) {
		$cached = get_transient( $this->key( $product->get_id() ) );

		return is_string( $cached ) ? $cached : false;
	}

	/**
	 * Store a rendered mirror body.
	 *
	 * @param WC_Product $product  Product.
	 * @param string     $markdown Rendered document.
	 * @return void
	 */
	public function set( WC_Product $product, $markdown ) {
		/**
		 * Filter the server-side cache TTL for rendered mirrors, in seconds.
		 *
		 * Invalidation hooks handle correctness; the TTL only bounds storage.
		 *
		 * @since 1.0.0
		 *
		 * @param int        $ttl     Seconds (default HOUR_IN_SECONDS).
		 * @param WC_Product $product Product being cached.
		 */
		$ttl = (int) apply_filters( 'product_markdown_mirror_cache_ttl', HOUR_IN_SECONDS, $product );

		set_transient( $this->key( $product->get_id() ), (string) $markdown, max( 60, $ttl ) );
	}

	/**
	 * Invalidate one product's cached mirror.
	 *
	 * @param int $product_id Product ID.
	 * @return void
	 */
	public function invalidate( $product_id ) {
		delete_transient( $this->key( (int) $product_id ) );
	}

	/**
	 * Invalidate from a product object (stock hooks pass objects).
	 *
	 * @param WC_Product $product Product.
	 * @return void
	 */
	public function invalidate_product_object( $product ) {
		if ( $product instanceof WC_Product ) {
			$this->invalidate( $product->get_id() );
		}
	}

	/**
	 * Invalidate the parent mirror from a variation object.
	 *
	 * @param WC_Product $variation Variation.
	 * @return void
	 */
	public function invalidate_variation_object( $variation ) {
		if ( $variation instanceof WC_Product && $variation->get_parent_id() ) {
			$this->invalidate( $variation->get_parent_id() );
		}
	}

	/**
	 * Invalidate the parent mirror from a variation ID.
	 *
	 * @param int $variation_id Variation ID.
	 * @return void
	 */
	public function invalidate_variation( $variation_id ) {
		$variation = wc_get_product( $variation_id );

		if ( $variation && $variation->get_parent_id() ) {
			$this->invalidate( $variation->get_parent_id() );
		}
	}

	/**
	 * Invalidate every cached mirror by bumping the generation.
	 *
	 * @return void
	 */
	public function invalidate_all() {
		$generation = (int) get_option( self::GENERATION_OPTION, 1 );

		update_option( self::GENERATION_OPTION, $generation + 1, false );
	}

	/**
	 * Transient key for a product under the current generation.
	 *
	 * @param int $product_id Product ID.
	 * @return string
	 */
	private function key( $product_id ) {
		$generation = (int) get_option( self::GENERATION_OPTION, 1 );

		return self::KEY_PREFIX . $generation . '_' . (int) $product_id;
	}
}
