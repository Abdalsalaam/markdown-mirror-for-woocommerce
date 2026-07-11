<?php
/**
 * Term renderer tests (T-16): taxonomy archive mirrors.
 *
 * @package AgentMint\ProductMarkdownMirror\Tests
 */

use AgentMint\ProductMarkdownMirror\Router;
use AgentMint\ProductMarkdownMirror\Term_Renderer;

/**
 * Tests for Term_Renderer::render() with categories, tags, and brands.
 */
class TermRendererTest extends WP_UnitTestCase {

	/**
	 * Create a product category term.
	 *
	 * @param string $name   Term name.
	 * @param int    $parent Parent term ID.
	 * @param string $desc   Description.
	 * @return WP_Term
	 */
	private function make_category( $name, $parent = 0, $desc = '' ) {
		$result = wp_insert_term(
			$name,
			'product_cat',
			array(
				'parent'      => $parent,
				'description' => $desc,
			)
		);

		return get_term( $result['term_id'], 'product_cat' );
	}

	/**
	 * Create a published, visible product inside terms.
	 *
	 * @param string $name      Product name.
	 * @param array  $cat_ids   Category term IDs.
	 * @param string $price     Regular price.
	 * @param string $visibility Catalog visibility.
	 * @return WC_Product
	 */
	private function make_product( $name, array $cat_ids = array(), $price = '10.00', $visibility = 'visible' ) {
		$product = new WC_Product_Simple();
		$product->set_name( $name );
		$product->set_regular_price( $price );
		$product->set_status( 'publish' );
		$product->set_catalog_visibility( $visibility );
		$product->set_category_ids( $cat_ids );
		$product->save();

		return wc_get_product( $product->get_id() );
	}

	/**
	 * Render helper.
	 *
	 * @param WP_Term $term Term.
	 * @param int     $page Page number.
	 * @return string|null
	 */
	private function render( $term, $page = 1 ) {
		$renderer = new Term_Renderer();
		return $renderer->render_term( get_term( $term->term_id, $term->taxonomy ), $page );
	}

	/**
	 * Full category document: header, description, product lines, canonical footer.
	 */
	public function test_category_document_structure() {
		$term      = $this->make_category( 'Grinders', 0, '<p>Burr <strong>grinders</strong> for filter coffee.</p>' );
		$product_a = $this->make_product( 'Alpha Grinder', array( $term->term_id ), '89.00' );
		$product_b = $this->make_product( 'Beta Grinder', array( $term->term_id ), '129.00' );

		$markdown = $this->render( $term );

		$this->assertStringStartsWith( '# Grinders', $markdown );
		$this->assertMatchesRegularExpression( '/^> Grinders\. Product category\. 2 products\.$/m', $markdown );
		$this->assertStringContainsString( '## Description', $markdown );
		$this->assertStringContainsString( 'Burr grinders for filter coffee.', $markdown );
		$this->assertStringNotContainsString( '<strong>', $markdown );
		$this->assertStringContainsString( '## Products (page 1 of 1)', $markdown );
		$this->assertStringContainsString( 'Alpha Grinder | 89.00 ' . get_woocommerce_currency() . ' | In stock | ' . Router::mirror_url( $product_a ), $markdown );
		$this->assertStringContainsString( 'Beta Grinder | 129.00 ' . get_woocommerce_currency() . ' | In stock | ' . Router::mirror_url( $product_b ), $markdown );
		$this->assertStringContainsString( 'Canonical: ' . get_term_link( $term ), $markdown );
		$this->assertStringNotContainsString( 'Next page:', $markdown );
		$this->assertStringNotContainsString( 'Last updated:', $markdown );
	}

	/**
	 * Subcategories list on the parent; parent line on the child.
	 */
	public function test_category_hierarchy_lines() {
		$parent = $this->make_category( 'Coffee Gear' );
		$child  = $this->make_category( 'Kettles', $parent->term_id );

		$parent_md = $this->render( $parent );
		$this->assertStringContainsString( '## Subcategories', $parent_md );
		$this->assertStringContainsString( 'Kettles (' . Router::term_mirror_url( $child ) . ')', $parent_md );

		$child_md = $this->render( $child );
		$this->assertStringContainsString( 'Parent category: Coffee Gear (' . Router::term_mirror_url( $parent ) . ')', $child_md );
		$this->assertStringNotContainsString( '## Subcategories', $child_md );
	}

	/**
	 * Archive visibility rules: hidden and search-only products are excluded.
	 */
	public function test_archive_visibility_respected() {
		$term = $this->make_category( 'Visibility Cat' );
		$this->make_product( 'Shown Product', array( $term->term_id ), '10.00', 'visible' );
		$this->make_product( 'Hidden Product', array( $term->term_id ), '10.00', 'hidden' );
		$this->make_product( 'Search Only Product', array( $term->term_id ), '10.00', 'search' );

		$markdown = $this->render( get_term( $term->term_id, 'product_cat' ) );

		$this->assertStringContainsString( 'Shown Product', $markdown );
		$this->assertStringNotContainsString( 'Hidden Product', $markdown );
		$this->assertStringNotContainsString( 'Search Only Product', $markdown );
		$this->assertMatchesRegularExpression( '/^> Visibility Cat\. Product category\. 1 product\.$/m', $markdown );
	}

	/**
	 * Pagination: page size via filter, page links, out-of-range null.
	 */
	public function test_pagination() {
		add_filter(
			'product_markdown_mirror_term_page_size',
			static function () {
				return 2;
			}
		);

		$term = $this->make_category( 'Paged Cat' );
		$this->make_product( 'Product A', array( $term->term_id ) );
		$this->make_product( 'Product B', array( $term->term_id ) );
		$this->make_product( 'Product C', array( $term->term_id ) );

		$term = get_term( $term->term_id, 'product_cat' );

		$page_one = $this->render( $term, 1 );
		$this->assertStringContainsString( '## Products (page 1 of 2)', $page_one );
		$this->assertStringContainsString( 'Next page: ' . Router::term_mirror_url( $term, 2 ), $page_one );
		$this->assertStringNotContainsString( 'Previous page:', $page_one );
		$this->assertSame( 2, substr_count( $page_one, '- Product ' ) );

		$page_two = $this->render( $term, 2 );
		$this->assertStringContainsString( '## Products (page 2 of 2)', $page_two );
		$this->assertStringContainsString( 'Previous page: ' . Router::term_mirror_url( $term, 1 ), $page_two );
		$this->assertStringNotContainsString( 'Next page:', $page_two );
		$this->assertSame( 1, substr_count( $page_two, '- Product ' ) );

		$this->assertNull( $this->render( $term, 3 ) );
	}

	/**
	 * Tags render with their own kind label and no hierarchy sections.
	 */
	public function test_tag_document() {
		$result = wp_insert_term( 'organic', 'product_tag' );
		$term   = get_term( $result['term_id'], 'product_tag' );

		$product = $this->make_product( 'Tagged Product' );
		wp_set_object_terms( $product->get_id(), array( $term->term_id ), 'product_tag' );

		$markdown = $this->render( $term );

		$this->assertMatchesRegularExpression( '/^> organic\. Product tag\. 1 product\.$/m', $markdown );
		$this->assertStringContainsString( 'Tagged Product', $markdown );
		$this->assertStringNotContainsString( '## Subcategories', $markdown );
	}

	/**
	 * Brands render with their kind label (skipped where core brands are absent).
	 */
	public function test_brand_document() {
		if ( ! taxonomy_exists( 'product_brand' ) ) {
			$this->markTestSkipped( 'Core product_brand taxonomy not present.' );
		}

		$result = wp_insert_term( 'Acme', 'product_brand' );
		$term   = get_term( $result['term_id'], 'product_brand' );

		$product = $this->make_product( 'Branded Product' );
		wp_set_object_terms( $product->get_id(), array( $term->term_id ), 'product_brand' );

		$markdown = $this->render( $term );

		$this->assertMatchesRegularExpression( '/^> Acme\. Brand\. 1 product\.$/m', $markdown );
		$this->assertStringContainsString( 'Branded Product', $markdown );
	}

	/**
	 * Empty terms: header only, no Products section, page 2 is out of range.
	 */
	public function test_empty_term() {
		$term     = $this->make_category( 'Empty Cat' );
		$markdown = $this->render( $term );

		$this->assertStringStartsWith( '# Empty Cat', $markdown );
		$this->assertMatchesRegularExpression( '/^> Empty Cat\. Product category\. 0 products\.$/m', $markdown );
		$this->assertStringNotContainsString( '## Products', $markdown );
		$this->assertStringContainsString( 'Canonical: ', $markdown );

		$this->assertNull( $this->render( $term, 2 ) );
	}

	/**
	 * No Description section when the term has no description.
	 */
	public function test_description_omitted_when_empty() {
		$term     = $this->make_category( 'No Desc Cat' );
		$markdown = $this->render( $term );

		$this->assertStringNotContainsString( '## Description', $markdown );
	}

	/**
	 * Term names cannot inject Markdown structure.
	 */
	public function test_term_name_injection_collapsed() {
		$term     = $this->make_category( "Injected\n# Fake" );
		$markdown = $this->render( get_term( $term->term_id, 'product_cat' ) );

		$this->assertStringContainsString( 'Injected', $markdown );
		$this->assertStringNotContainsString( "\n# Fake", $markdown );
	}
}
