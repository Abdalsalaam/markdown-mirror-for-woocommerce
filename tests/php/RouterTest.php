<?php
/**
 * Router and response tests (T-06): rewrite rules, query var, serving, honest 404s, headers.
 *
 * @package AgentMint\MarkdownMirrorWC\Tests
 */

use AgentMint\MarkdownMirrorWC\Router;
use AgentMint\MarkdownMirrorWC\Settings;

/**
 * Tests for Router::handle_request() and rewrite registration.
 */
class RouterTest extends WP_UnitTestCase {

	/**
	 * Router under test.
	 *
	 * @var Router
	 */
	private $router;

	/**
	 * Set pretty permalinks, register router rules, and rebuild rewrites.
	 */
	public function set_up() {
		parent::set_up();

		global $wp_rewrite;
		$wp_rewrite->set_permalink_structure( '/%postname%/' );

		delete_option( Settings::OPTION_NAME );

		$this->router = new Router();
		$this->router->register_hooks();

		// Call directly instead of refiring the global init action, which
		// would make WooCommerce re-register gateways/blocks and emit
		// doing_it_wrong notices in the test context.
		$this->router->add_rules();
		$wp_rewrite->flush_rules( false );
	}

	/**
	 * Create a published simple product.
	 *
	 * @param array $overrides Setter overrides.
	 * @return WC_Product_Simple
	 */
	private function make_product( array $overrides = array() ) {
		$product = new WC_Product_Simple();
		$product->set_name( 'Router Widget' );
		$product->set_regular_price( '10.00' );
		$product->set_status( 'publish' );

		foreach ( $overrides as $setter => $value ) {
			$product->{$setter}( $value );
		}

		$product->save();

		// Reload: the in-memory object does not carry DB-generated state
		// (post_name/slug), and an empty slug would make tests vacuous.
		$product = wc_get_product( $product->get_id() );
		$this->assertNotSame( '', (string) $product->get_slug(), 'Product factory produced an empty slug.' );

		return $product;
	}

	/**
	 * The .md rewrite rule for products is registered.
	 */
	public function test_rewrite_rule_registered() {
		$rules = get_option( 'rewrite_rules' );

		$found = false;
		foreach ( (array) $rules as $regex => $target ) {
			if ( false !== strpos( $target, Router::QUERY_VAR ) ) {
				$found = true;
			}
		}

		$this->assertTrue( $found, 'No rewrite rule targets the mirror query var.' );
	}

	/**
	 * A published product serves a 200 markdown response with all contract headers.
	 */
	public function test_serves_markdown_for_published_product() {
		$product  = $this->make_product();
		$response = $this->router->handle_request( $product->get_slug() );

		$this->assertSame( 200, $response->get_status() );

		$headers = $response->get_headers();
		$this->assertStringContainsString( 'text/markdown', $headers['Content-Type'] );
		$this->assertStringContainsString( 'charset=UTF-8', $headers['Content-Type'] );
		$this->assertStringContainsString( '<' . $product->get_permalink() . '>; rel="canonical"', $headers['Link'] );
		$this->assertStringContainsString( 'noindex', $headers['X-Robots-Tag'] );
		$this->assertStringContainsString( 'max-age=', $headers['Cache-Control'] );
		$this->assertSame( 'nosniff', $headers['X-Content-Type-Options'] );
		$this->assertArrayHasKey( 'Last-Modified', $headers );

		$this->assertStringStartsWith( '# Router Widget', $response->get_body() );
	}

	/**
	 * Unknown slugs produce an honest 404.
	 */
	public function test_404_for_unknown_slug() {
		$response = $this->router->handle_request( 'no-such-product' );

		$this->assertSame( 404, $response->get_status() );
	}

	/**
	 * Unpublished products are not publicly reachable, so the mirror 404s.
	 *
	 * Built as publish-then-unpublish: drafts never get a slug in WordPress,
	 * so the realistic case is a formerly published product whose old .md URL
	 * must stop resolving.
	 */
	public function test_404_for_draft_product() {
		$product = $this->make_product();
		$slug    = $product->get_slug();

		wp_update_post(
			array(
				'ID'          => $product->get_id(),
				'post_status' => 'draft',
			)
		);
		clean_post_cache( $product->get_id() );

		$response = $this->router->handle_request( $slug );

		$this->assertSame( 404, $response->get_status() );
	}

	/**
	 * Password-protected products are not mirrored.
	 */
	public function test_404_for_password_protected_product() {
		$product = $this->make_product();
		wp_update_post(
			array(
				'ID'            => $product->get_id(),
				'post_password' => 'secret',
			)
		);

		$response = $this->router->handle_request( $product->get_slug() );

		$this->assertSame( 404, $response->get_status() );
	}

	/**
	 * The master toggle turns the whole surface off.
	 */
	public function test_404_when_disabled_in_settings() {
		update_option( Settings::OPTION_NAME, array( 'enabled' => 'no' ) );

		$product  = $this->make_product();
		$response = $this->router->handle_request( $product->get_slug() );

		$this->assertSame( 404, $response->get_status() );
	}

	/**
	 * Products excluded via the documented filter 404 as well.
	 */
	public function test_404_when_excluded_by_filter() {
		$product = $this->make_product();

		add_filter(
			'mdmirwc_is_mirrored',
			static function ( $mirrored, $checked ) use ( $product ) {
				if ( $checked->get_id() === $product->get_id() ) {
					return false;
				}
				return $mirrored;
			},
			10,
			2
		);

		$response = $this->router->handle_request( $product->get_slug() );

		$this->assertSame( 404, $response->get_status() );
	}

	/**
	 * Regex metacharacters in a merchant-set product base cannot break the rule.
	 */
	public function test_regex_metachars_in_product_base_are_quoted() {
		global $wp_rewrite;

		update_option( 'woocommerce_permalinks', array( 'product_base' => '/shop(x)/product' ) );

		$router = new Router();
		$router->add_rules();

		$found = false;
		foreach ( (array) $wp_rewrite->extra_rules_top as $regex => $target ) {
			if ( false !== strpos( $regex, 'shop\(x\)' ) && false !== strpos( $target, Router::QUERY_VAR ) ) {
				$found = true;
			}
		}

		$this->assertTrue( $found, 'Product base was not preg-quoted in the rewrite rule.' );

		delete_option( 'woocommerce_permalinks' );
	}

	/**
	 * The deferred activation flush flag is consumed exactly once.
	 */
	public function test_activation_flush_flag_consumed() {
		update_option( 'mdmirwc_flush_needed', 'yes', false );

		$this->router->maybe_flush_rules();

		$this->assertFalse( get_option( 'mdmirwc_flush_needed' ) );
	}

	/**
	 * A .md request URL resolves to our query var through the rewrite system.
	 */
	public function test_pretty_url_maps_to_query_var() {
		$product = $this->make_product();

		$this->go_to( home_url( '/product/' . $product->get_slug() . '.md' ) );

		$this->assertSame( $product->get_slug(), get_query_var( Router::QUERY_VAR ) );
	}
}
