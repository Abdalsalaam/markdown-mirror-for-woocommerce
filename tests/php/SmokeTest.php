<?php
/**
 * Environment smoke tests: the harness boots WordPress, WooCommerce, and the plugin.
 *
 * @package AgentMint\MarkdownMirrorWC\Tests
 */

use AgentMint\MarkdownMirrorWC\Main;

/**
 * Smoke tests for the test harness itself.
 */
class SmokeTest extends WP_UnitTestCase {

	/**
	 * WordPress test suite is up.
	 */
	public function test_wordpress_is_loaded() {
		$this->assertTrue( function_exists( 'do_action' ) );
	}

	/**
	 * WooCommerce is active inside the test environment.
	 */
	public function test_woocommerce_is_loaded() {
		$this->assertTrue( class_exists( 'WooCommerce' ) );
		$this->assertInstanceOf( 'WooCommerce', WC() );
	}

	/**
	 * The plugin booted: singleton exists, components loaded, constants defined.
	 */
	public function test_plugin_is_loaded() {
		$this->assertTrue( defined( 'MDMIRWC_VERSION' ) );
		$this->assertInstanceOf( Main::class, mdmirwc() );
		$this->assertSame( mdmirwc(), Main::instance() );
		$this->assertTrue( Main::instance()->is_loaded() );
	}

	/**
	 * A WooCommerce product can be created and read back (CRUD path works).
	 */
	public function test_can_create_product() {
		$product = new WC_Product_Simple();
		$product->set_name( 'Harness Widget' );
		$product->set_regular_price( '19.99' );
		$product->set_status( 'publish' );
		$product_id = $product->save();

		$this->assertGreaterThan( 0, $product_id );

		$loaded = wc_get_product( $product_id );
		$this->assertSame( 'Harness Widget', $loaded->get_name() );
		$this->assertSame( '19.99', $loaded->get_regular_price() );
	}
}
