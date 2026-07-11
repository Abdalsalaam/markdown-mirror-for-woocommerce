<?php
/**
 * Cache layer tests (T-08).
 *
 * @package AgentMint\ProductMarkdownMirror\Tests
 */

use AgentMint\ProductMarkdownMirror\Cache;
use AgentMint\ProductMarkdownMirror\Renderer;
use AgentMint\ProductMarkdownMirror\Router;
use AgentMint\ProductMarkdownMirror\Settings;

/**
 * Renderer double that counts render calls.
 */
class PMM_Counting_Renderer extends Renderer {

	/**
	 * Render invocation count.
	 *
	 * @var int
	 */
	public static $calls = 0;

	/**
	 * Count and delegate.
	 *
	 * @param WC_Product $product Product.
	 * @param array      $args    Args.
	 * @return string
	 */
	public function render( WC_Product $product, array $args = array() ) {
		self::$calls++;
		return parent::render( $product, $args );
	}
}

/**
 * Tests for Cache and its invalidation hooks.
 */
class CacheTest extends WP_UnitTestCase {

	/**
	 * Reset counters and options.
	 */
	public function set_up() {
		parent::set_up();
		PMM_Counting_Renderer::$calls = 0;
		delete_option( Settings::OPTION_NAME );

		$cache = new Cache();
		$cache->register_hooks();
	}

	/**
	 * Create a published product, reloaded.
	 *
	 * @return WC_Product
	 */
	private function make_product() {
		$product = new WC_Product_Simple();
		$product->set_name( 'Cache Widget' );
		$product->set_regular_price( '7.00' );
		$product->set_status( 'publish' );
		$product->save();

		return wc_get_product( $product->get_id() );
	}

	/**
	 * Basic set/get round trip.
	 */
	public function test_set_get_roundtrip() {
		$product = $this->make_product();
		$cache   = new Cache();

		$this->assertFalse( $cache->get( $product ) );

		$cache->set( $product, 'CACHED BODY' );

		$this->assertSame( 'CACHED BODY', $cache->get( $product ) );
	}

	/**
	 * The router renders once and serves the second request from cache.
	 */
	public function test_router_serves_from_cache() {
		$product = $this->make_product();
		$router  = new Router( new PMM_Counting_Renderer() );

		$first  = $router->handle_request( $product->get_slug() );
		$second = $router->handle_request( $product->get_slug() );

		$this->assertSame( 200, $first->get_status() );
		$this->assertSame( 200, $second->get_status() );
		$this->assertSame( $first->get_body(), $second->get_body() );
		$this->assertSame( 1, PMM_Counting_Renderer::$calls );
	}

	/**
	 * Saving the product invalidates its cached mirror.
	 */
	public function test_product_save_invalidates() {
		$product = $this->make_product();
		$cache   = new Cache();

		$cache->set( $product, 'STALE' );

		$product->set_name( 'Cache Widget Renamed' );
		$product->save();

		$this->assertFalse( $cache->get( $product ) );
	}

	/**
	 * A variation save invalidates the parent's cached mirror.
	 */
	public function test_variation_save_invalidates_parent() {
		$attribute = new WC_Product_Attribute();
		$attribute->set_name( 'Size' );
		$attribute->set_options( array( 'S' ) );
		$attribute->set_variation( true );

		$parent = new WC_Product_Variable();
		$parent->set_name( 'Cache Variable' );
		$parent->set_status( 'publish' );
		$parent->set_attributes( array( $attribute ) );
		$parent->save();

		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $parent->get_id() );
		$variation->set_attributes( array( 'size' => 'S' ) );
		$variation->set_regular_price( '9.00' );
		$variation->save();

		$parent = wc_get_product( $parent->get_id() );
		$cache  = new Cache();
		$cache->set( $parent, 'STALE PARENT' );

		$variation->set_stock_status( 'outofstock' );
		$variation->save();

		$this->assertFalse( $cache->get( $parent ) );
	}

	/**
	 * Changing plugin settings invalidates every cached mirror.
	 */
	public function test_settings_change_invalidates_all() {
		$product_a = $this->make_product();
		$product_b = $this->make_product();
		$cache     = new Cache();

		$cache->set( $product_a, 'A' );
		$cache->set( $product_b, 'B' );

		update_option( Settings::OPTION_NAME, array( 'include_description' => 'no' ) );

		$this->assertFalse( $cache->get( $product_a ) );
		$this->assertFalse( $cache->get( $product_b ) );
	}
}
