<?php
/**
 * Term router tests (T-17): taxonomy rules, hierarchical paths, honest 404s.
 *
 * @package AgentMint\ProductMarkdownMirror\Tests
 */

use AgentMint\ProductMarkdownMirror\Settings;
use AgentMint\ProductMarkdownMirror\Term_Router;

/**
 * Tests for Term_Router::handle_term_request() and rule registration.
 */
class TermRouterTest extends WP_UnitTestCase {

	/**
	 * Router under test.
	 *
	 * @var Term_Router
	 */
	private $router;

	/**
	 * Pretty permalinks, enabled taxonomies, registered rules.
	 */
	public function set_up() {
		parent::set_up();

		global $wp_rewrite;
		$wp_rewrite->set_permalink_structure( '/%postname%/' );

		update_option(
			Settings::OPTION_NAME,
			array(
				'mirror_categories' => 'yes',
				'mirror_tags'       => 'yes',
			)
		);

		$this->router = new Term_Router();
		$this->router->register_hooks();
		$this->router->add_rules();
		$wp_rewrite->flush_rules( false );
	}

	/**
	 * Create a product category.
	 *
	 * @param string $name   Name.
	 * @param int    $parent_id Parent ID.
	 * @return WP_Term
	 */
	private function make_category( $name, $parent_id = 0 ) {
		$result = wp_insert_term( $name, 'product_cat', array( 'parent' => $parent_id ) );
		return get_term( $result['term_id'], 'product_cat' );
	}

	/**
	 * Create a visible product in categories.
	 *
	 * @param string $name    Name.
	 * @param array  $cat_ids Category IDs.
	 * @return WC_Product
	 */
	private function make_product( $name, array $cat_ids = array() ) {
		$product = new WC_Product_Simple();
		$product->set_name( $name );
		$product->set_regular_price( '11.00' );
		$product->set_status( 'publish' );
		$product->set_category_ids( $cat_ids );
		$product->save();

		return wc_get_product( $product->get_id() );
	}

	/**
	 * Rules exist only for enabled taxonomies.
	 */
	public function test_rules_registered_only_for_enabled_taxonomies() {
		global $wp_rewrite;

		$wp_rewrite->extra_rules_top = array();
		update_option( Settings::OPTION_NAME, array( 'mirror_categories' => 'yes' ) );

		$router = new Term_Router();
		$router->add_rules();

		$cat_base = preg_quote( get_taxonomy( 'product_cat' )->rewrite['slug'], '#' );
		$tag_base = preg_quote( get_taxonomy( 'product_tag' )->rewrite['slug'], '#' );

		$cat_rules = 0;
		$tag_rules = 0;
		foreach ( array_keys( (array) $wp_rewrite->extra_rules_top ) as $regex ) {
			if ( 0 === strpos( $regex, '^' . $cat_base . '/' ) ) {
				++$cat_rules;
			}
			if ( 0 === strpos( $regex, '^' . $tag_base . '/' ) ) {
				++$tag_rules;
			}
		}

		$this->assertGreaterThanOrEqual( 2, $cat_rules, 'Category .md rules missing.' );
		$this->assertSame( 0, $tag_rules, 'Tag rules must not register while the tag toggle is off.' );
	}

	/**
	 * A category mirror serves with the full header contract, minus Last-Modified.
	 */
	public function test_serves_category_mirror() {
		$term = $this->make_category( 'Servers' );
		$this->make_product( 'Rack Unit', array( $term->term_id ) );

		$response = $this->router->handle_term_request( 'product_cat', $term->slug, 1 );

		$this->assertSame( 200, $response->get_status() );

		$headers = $response->get_headers();
		$this->assertStringContainsString( 'text/markdown', $headers['Content-Type'] );
		$this->assertSame( 'nosniff', $headers['X-Content-Type-Options'] );
		$this->assertStringContainsString( 'noindex', $headers['X-Robots-Tag'] );
		$this->assertStringContainsString( 'max-age=', $headers['Cache-Control'] );
		$this->assertStringContainsString( '<' . get_term_link( $term ) . '>; rel="canonical"', $headers['Link'] );
		$this->assertArrayNotHasKey( 'Last-Modified', $headers, 'Terms have no honest modified date.' );

		$this->assertStringStartsWith( '# Servers', $response->get_body() );
	}

	/**
	 * Hierarchical paths must match the term's real path exactly.
	 */
	public function test_hierarchical_path_verification() {
		$parent = $this->make_category( 'Audio' );
		$child  = $this->make_category( 'Headphones', $parent->term_id );
		$other  = $this->make_category( 'Video' );

		$correct = $this->router->handle_term_request( 'product_cat', $parent->slug . '/' . $child->slug, 1 );
		$this->assertSame( 200, $correct->get_status() );

		$wrong_parent = $this->router->handle_term_request( 'product_cat', $other->slug . '/' . $child->slug, 1 );
		$this->assertSame( 404, $wrong_parent->get_status() );

		$missing_parent = $this->router->handle_term_request( 'product_cat', $child->slug, 1 );
		$this->assertSame( 404, $missing_parent->get_status() );
	}

	/**
	 * Honest 404s: unknown term, disabled taxonomy, out-of-range page, filter exclusion.
	 */
	public function test_404_matrix() {
		$term = $this->make_category( 'Real Cat' );

		$this->assertSame( 404, $this->router->handle_term_request( 'product_cat', 'no-such-term', 1 )->get_status() );

		$this->assertSame( 404, $this->router->handle_term_request( 'product_tag', 'anything', 1 )->get_status(), 'product_tag serving requires the tag toggle checked per request.' );

		$this->assertSame( 404, $this->router->handle_term_request( 'post_tag', 'anything', 1 )->get_status(), 'Non-product taxonomies are never served.' );

		$this->assertSame( 404, $this->router->handle_term_request( 'product_cat', $term->slug, 99 )->get_status() );

		add_filter( 'product_markdown_mirror_term_is_mirrored', '__return_false' );
		$this->assertSame( 404, $this->router->handle_term_request( 'product_cat', $term->slug, 1 )->get_status() );
		remove_filter( 'product_markdown_mirror_term_is_mirrored', '__return_false' );
	}

	/**
	 * Tag serving works when its toggle is on (set in set_up).
	 */
	public function test_serves_tag_mirror() {
		$result = wp_insert_term( 'fresh', 'product_tag' );
		$term   = get_term( $result['term_id'], 'product_tag' );

		$product = $this->make_product( 'Tagged Item' );
		wp_set_object_terms( $product->get_id(), array( $term->term_id ), 'product_tag' );

		$response = $this->router->handle_term_request( 'product_tag', $term->slug, 1 );

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringContainsString( 'Tagged Item', $response->get_body() );
	}

	/**
	 * Mirror pagination pages serve beyond page 1.
	 */
	public function test_serves_page_two() {
		add_filter(
			'product_markdown_mirror_term_page_size',
			static function () {
				return 2;
			}
		);

		$term = $this->make_category( 'Paged Router Cat' );
		$this->make_product( 'P One', array( $term->term_id ) );
		$this->make_product( 'P Two', array( $term->term_id ) );
		$this->make_product( 'P Three', array( $term->term_id ) );

		$response = $this->router->handle_term_request( 'product_cat', $term->slug, 2 );

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringContainsString( '(page 2 of 2)', $response->get_body() );
	}

	/**
	 * The router serves term pages from the cache when present.
	 */
	public function test_serves_from_cache() {
		$term = $this->make_category( 'Cached Router Cat' );
		$this->make_product( 'Cache Filler', array( $term->term_id ) );

		$cache = new AgentMint\ProductMarkdownMirror\Cache();
		$cache->set_term_mirror( $term, 1, '# CACHED SENTINEL' );

		$response = $this->router->handle_term_request( 'product_cat', $term->slug, 1 );

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringContainsString( 'CACHED SENTINEL', $response->get_body() );
	}

	/**
	 * Toggles default off (author decision D1): fresh settings serve nothing.
	 */
	public function test_defaults_off() {
		delete_option( Settings::OPTION_NAME );

		$term     = $this->make_category( 'Default Off Cat' );
		$response = $this->router->handle_term_request( 'product_cat', $term->slug, 1 );

		$this->assertSame( 404, $response->get_status() );
	}

	/**
	 * Pretty .md URLs map to the term query vars, including page variants.
	 */
	public function test_pretty_url_maps_to_query_vars() {
		$parent = $this->make_category( 'Mapping Audio' );
		$child  = $this->make_category( 'Mapping Headphones', $parent->term_id );

		$base = get_taxonomy( 'product_cat' )->rewrite['slug'];

		$this->go_to( home_url( '/' . $base . '/' . $parent->slug . '/' . $child->slug . '.md' ) );
		$this->assertSame( 'product_cat', get_query_var( Term_Router::QUERY_VAR_TAX ) );
		$this->assertSame( $parent->slug . '/' . $child->slug, get_query_var( Term_Router::QUERY_VAR_TERM ) );

		$this->go_to( home_url( '/' . $base . '/' . $parent->slug . '/page/3.md' ) );
		$this->assertSame( $parent->slug, get_query_var( Term_Router::QUERY_VAR_TERM ) );
		$this->assertSame( '3', (string) get_query_var( Term_Router::QUERY_VAR_PAGE ) );
	}
}
