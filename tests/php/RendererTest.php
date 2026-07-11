<?php
/**
 * Renderer core tests (T-04): simple products to Markdown, equivalence-guarded.
 *
 * @package AgentMint\ProductMarkdownMirror\Tests
 */

use AgentMint\ProductMarkdownMirror\Renderer;

/**
 * Tests for Renderer::render() with simple products.
 */
class RendererTest extends WP_UnitTestCase {

	/**
	 * Build a published simple product with rich data.
	 *
	 * @param array $overrides Property overrides applied after the base setup.
	 * @return WC_Product_Simple
	 */
	private function make_product( array $overrides = array() ) {
		$product = new WC_Product_Simple();
		$product->set_name( 'Ceramic Pour Over Dripper' );
		$product->set_regular_price( '24.50' );
		$product->set_status( 'publish' );
		$product->set_sku( 'DRIP-' . strtoupper( uniqid() ) );
		$product->set_short_description( '<p>Cone dripper for <strong>manual</strong> brewing.</p>' );
		$product->set_weight( '0.4' );
		$product->set_length( '12' );
		$product->set_width( '12' );
		$product->set_height( '10' );

		$attribute = new WC_Product_Attribute();
		$attribute->set_name( 'Material' );
		$attribute->set_options( array( 'Ceramic' ) );
		$attribute->set_visible( true );

		$hidden = new WC_Product_Attribute();
		$hidden->set_name( 'Internal Code' );
		$hidden->set_options( array( 'X99' ) );
		$hidden->set_visible( false );

		$product->set_attributes( array( $attribute, $hidden ) );

		foreach ( $overrides as $setter => $value ) {
			$product->{$setter}( $value );
		}

		$product->save();

		return $product;
	}

	/**
	 * Render helper. Reloads the product from the database first, exactly as
	 * the serving path does, so persisted state (dates) is fully populated.
	 *
	 * @param WC_Product $product Product.
	 * @param array      $args    Renderer args.
	 * @return string
	 */
	private function render( $product, array $args = array() ) {
		$renderer = new Renderer();
		return $renderer->render( wc_get_product( $product->get_id() ), $args );
	}

	/**
	 * Full document: heading, blockquote, identifiers, specs, price, availability, description, footer.
	 */
	public function test_full_document_structure() {
		$product  = $this->make_product();
		$markdown = $this->render( $product );

		$this->assertStringStartsWith( '# Ceramic Pour Over Dripper', $markdown );
		$this->assertMatchesRegularExpression( '/^> .+/m', $markdown );
		$this->assertStringContainsString( '## Identifiers', $markdown );
		$this->assertStringContainsString( '- SKU: ' . $product->get_sku(), $markdown );
		$this->assertStringContainsString( '## Specifications', $markdown );
		$this->assertStringContainsString( 'Material: Ceramic', $markdown );
		$this->assertStringContainsString( '## Price', $markdown );
		$this->assertStringContainsString( '24.50', $markdown );
		$this->assertStringContainsString( get_woocommerce_currency(), $markdown );
		$this->assertStringContainsString( '## Availability', $markdown );
		$this->assertStringContainsString( 'In stock', $markdown );
		$this->assertStringContainsString( '## Description', $markdown );
		$this->assertStringContainsString( 'Cone dripper for manual brewing.', $markdown );
		$this->assertStringContainsString( 'Canonical: ' . $product->get_permalink(), $markdown );
		$this->assertStringContainsString( 'Last updated: ', $markdown );
	}

	/**
	 * Weight and dimensions render with store units.
	 */
	public function test_specifications_include_weight_and_dimensions() {
		$product  = $this->make_product();
		$markdown = $this->render( $product );

		$this->assertStringContainsString( 'Weight: 0.4 ' . get_option( 'woocommerce_weight_unit' ), $markdown );
		$this->assertStringContainsString( get_option( 'woocommerce_dimension_unit' ), $markdown );
		$this->assertStringNotContainsString( '&times;', $markdown, 'HTML entities must be decoded on machine surfaces.' );
	}

	/**
	 * Hidden attributes never render (equivalence with the visible page).
	 */
	public function test_hidden_attribute_is_omitted() {
		$product  = $this->make_product();
		$markdown = $this->render( $product );

		$this->assertStringNotContainsString( 'Internal Code', $markdown );
		$this->assertStringNotContainsString( 'X99', $markdown );
	}

	/**
	 * GTIN renders only when the store data has one; never invented.
	 */
	public function test_gtin_rendered_only_when_present() {
		$product  = $this->make_product();
		$markdown = $this->render( $product );
		$this->assertStringNotContainsString( 'GTIN', $markdown );

		if ( ! method_exists( $product, 'set_global_unique_id' ) ) {
			return;
		}

		$product->set_global_unique_id( '9780201379624' );
		$product->save();

		$markdown = $this->render( $product );
		$this->assertStringContainsString( '- GTIN: 9780201379624', $markdown );
	}

	/**
	 * A product with no price omits the Price section instead of padding it.
	 */
	public function test_price_section_omitted_without_price() {
		$product  = $this->make_product( array( 'set_regular_price' => '' ) );
		$markdown = $this->render( $product );

		$this->assertStringNotContainsString( '## Price', $markdown );
	}

	/**
	 * Out-of-stock and backorder availability states are stated plainly.
	 */
	public function test_availability_states() {
		$product = $this->make_product( array( 'set_stock_status' => 'outofstock' ) );
		$this->assertStringContainsString( 'Out of stock', $this->render( $product ) );

		$product = $this->make_product( array( 'set_stock_status' => 'onbackorder' ) );
		$this->assertStringContainsString( 'backorder', strtolower( $this->render( $product ) ) );
	}

	/**
	 * A scheduled sale renders the sale price and its end date.
	 */
	public function test_sale_window_rendered() {
		$product = $this->make_product(
			array(
				'set_sale_price'      => '19.99',
				'set_date_on_sale_to' => gmdate( 'Y-m-d 23:59:59', strtotime( '+10 days' ) ),
			)
		);

		$markdown = $this->render( $product );

		$this->assertStringContainsString( '19.99', $markdown );
		$this->assertStringContainsString( 'Sale ends: ' . gmdate( 'Y-m-d', strtotime( '+10 days' ) ), $markdown );
	}

	/**
	 * Newlines in single-line fields are collapsed so data cannot inject Markdown structure.
	 */
	public function test_name_cannot_inject_headings() {
		$product  = $this->make_product( array( 'set_name' => "Injected\n# Fake Heading" ) );
		$markdown = $this->render( $product );

		$this->assertStringContainsString( 'Injected', $markdown );
		$this->assertStringNotContainsString( "\n# Fake Heading", $markdown );
	}

	/**
	 * HTML is stripped from the description block.
	 */
	public function test_description_html_stripped() {
		$markdown = $this->render( $this->make_product() );

		$this->assertStringNotContainsString( '<p>', $markdown );
		$this->assertStringNotContainsString( '<strong>', $markdown );
	}

	/**
	 * include_description=false omits the Description section.
	 */
	public function test_description_toggle() {
		$product  = $this->make_product();
		$markdown = $this->render( $product, array( 'include_description' => false ) );

		$this->assertStringNotContainsString( '## Description', $markdown );
	}

	/**
	 * Last updated uses the product's real modified date (no fake freshness).
	 */
	public function test_last_updated_is_product_modified_date() {
		$product  = $this->make_product();
		$reloaded = wc_get_product( $product->get_id() );
		$modified = $reloaded->get_date_modified();

		if ( ! $modified ) {
			$modified = $reloaded->get_date_created();
		}

		$markdown = $this->render( $product );

		$this->assertNotNull( $modified );
		$this->assertStringContainsString( 'Last updated: ' . $modified->date( 'Y-m-d' ), $markdown );
	}

	/**
	 * Categories render as hierarchical paths with archive links.
	 */
	public function test_categories_rendered_with_hierarchy() {
		$parent = wp_insert_term( 'Mirror Gear', 'product_cat' );
		$child  = wp_insert_term( 'Mirror Kettles', 'product_cat', array( 'parent' => $parent['term_id'] ) );

		$product = $this->make_product();
		$product->set_category_ids( array( $child['term_id'] ) );
		$product->save();

		$markdown = $this->render( $product );

		$this->assertStringContainsString( '## Classification', $markdown );
		$this->assertStringContainsString( 'Categories: Mirror Gear > Mirror Kettles', $markdown );

		$term = get_term( $child['term_id'], 'product_cat' );
		$this->assertStringContainsString( (string) get_term_link( $term ), $markdown, 'Category links the HTML archive while term mirrors are off.' );
	}

	/**
	 * Category links switch to .md mirrors when that group is enabled.
	 */
	public function test_category_links_follow_term_mirror_toggle() {
		update_option(
			AgentMint\ProductMarkdownMirror\Settings::OPTION_NAME,
			array( 'mirror_categories' => 'yes' )
		);

		$cat     = wp_insert_term( 'Toggled Cat', 'product_cat' );
		$product = $this->make_product();
		$product->set_category_ids( array( $cat['term_id'] ) );
		$product->save();

		$markdown = $this->render( $product );
		$term     = get_term( $cat['term_id'], 'product_cat' );

		$this->assertStringContainsString( AgentMint\ProductMarkdownMirror\Router::term_mirror_url( $term ), $markdown );

		delete_option( AgentMint\ProductMarkdownMirror\Settings::OPTION_NAME );
	}

	/**
	 * Tags render in the Classification section.
	 */
	public function test_tags_rendered() {
		$tag     = wp_insert_term( 'mirror-organic', 'product_tag' );
		$product = $this->make_product();
		wp_set_object_terms( $product->get_id(), array( $tag['term_id'] ), 'product_tag' );

		$markdown = $this->render( $product );

		$this->assertStringContainsString( 'Tags: mirror-organic', $markdown );
	}

	/**
	 * No Classification section without terms.
	 */
	public function test_classification_omitted_without_terms() {
		$markdown = $this->render( $this->make_product() );

		$this->assertStringNotContainsString( '## Classification', $markdown );
	}

	/**
	 * Review average and count render when real reviews exist.
	 */
	public function test_reviews_rendered_when_present() {
		$product = $this->make_product();

		self::factory()->comment->create(
			array(
				'comment_post_ID'  => $product->get_id(),
				'comment_type'     => 'review',
				'comment_approved' => 1,
				'comment_meta'     => array( 'rating' => 4 ),
			)
		);
		self::factory()->comment->create(
			array(
				'comment_post_ID'  => $product->get_id(),
				'comment_type'     => 'review',
				'comment_approved' => 1,
				'comment_meta'     => array( 'rating' => 5 ),
			)
		);

		WC_Comments::clear_transients( $product->get_id() );

		$markdown = $this->render( $product );

		$this->assertStringContainsString( '## Reviews', $markdown );
		$this->assertStringContainsString( 'Rating: 4.5 of 5', $markdown );
		$this->assertStringContainsString( 'Reviews: 2', $markdown );
	}

	/**
	 * No Reviews section when a product has no reviews.
	 */
	public function test_reviews_omitted_when_none() {
		$markdown = $this->render( $this->make_product() );

		$this->assertStringNotContainsString( '## Reviews', $markdown );
	}

	/**
	 * Main and gallery images render as Markdown images with alt text.
	 */
	public function test_images_rendered_with_alt() {
		$main = self::factory()->attachment->create( array( 'post_mime_type' => 'image/jpeg' ) );
		update_post_meta( $main, '_wp_attached_file', '2026/07/mug-main.jpg' );
		update_post_meta( $main, '_wp_attachment_image_alt', 'White ceramic mug' );

		$gallery = self::factory()->attachment->create( array( 'post_mime_type' => 'image/jpeg' ) );
		update_post_meta( $gallery, '_wp_attached_file', '2026/07/mug-side.jpg' );

		$product = $this->make_product();
		$product->set_image_id( $main );
		$product->set_gallery_image_ids( array( $gallery ) );
		$product->save();

		$markdown = $this->render( $product );

		$this->assertStringContainsString( '## Images', $markdown );
		$this->assertStringContainsString( '![White ceramic mug](' . wp_get_attachment_url( $main ) . ')', $markdown );
		$this->assertStringContainsString( '![](' . wp_get_attachment_url( $gallery ) . ')', $markdown );
	}

	/**
	 * No Images section without images.
	 */
	public function test_images_omitted_when_none() {
		$markdown = $this->render( $this->make_product() );

		$this->assertStringNotContainsString( '## Images', $markdown );
	}

	/**
	 * Stock quantity appears when the store displays it (managed stock).
	 */
	public function test_stock_quantity_shown_when_store_shows_it() {
		update_option( 'woocommerce_stock_format', '' ); // Always show quantity.

		$product = $this->make_product();
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 12 );
		$product->save();

		$markdown = $this->render( $product );

		$this->assertStringContainsString( '12 in stock', $markdown );
	}

	/**
	 * The full description renders after the short description.
	 */
	public function test_full_description_included() {
		$product = $this->make_product();
		$product->set_description( '<p>Full details: fits <em>standard</em> drippers.</p>' );
		$product->save();

		$markdown = $this->render( $product );

		$this->assertStringContainsString( 'Cone dripper for manual brewing.', $markdown );
		$this->assertStringContainsString( 'Full details: fits standard drippers.', $markdown );
		$this->assertStringNotContainsString( '<em>', $markdown );
	}

	/**
	 * The document filter runs and can append content.
	 */
	public function test_document_filter_applies() {
		add_filter(
			'product_markdown_mirror_document',
			static function ( $doc ) {
				return $doc . "\nFILTERED";
			}
		);

		$markdown = $this->render( $this->make_product() );

		$this->assertStringEndsWith( 'FILTERED', $markdown );
	}
}
