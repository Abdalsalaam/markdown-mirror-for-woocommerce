<?php
/**
 * Variable-product rendering tests (T-05).
 *
 * @package AgentMint\MarkdownMirrorWC\Tests
 */

use AgentMint\MarkdownMirrorWC\Renderer;

/**
 * Tests for the Variants section and variable-product price ranges.
 */
class VariantsTest extends WP_UnitTestCase {

	/**
	 * Build a published variable product with Size S/M variations.
	 *
	 * @param array $variation_specs Per-variation overrides: array of arrays with keys size, price, stock_status.
	 * @return WC_Product_Variable
	 */
	private function make_variable_product( array $variation_specs = array() ) {
		if ( empty( $variation_specs ) ) {
			$variation_specs = array(
				array(
					'size'  => 'S',
					'price' => '15.00',
				),
				array(
					'size'  => 'M',
					'price' => '20.00',
				),
			);
		}

		$attribute = new WC_Product_Attribute();
		$attribute->set_name( 'Size' );
		$attribute->set_options( wp_list_pluck( $variation_specs, 'size' ) );
		$attribute->set_visible( true );
		$attribute->set_variation( true );

		$parent = new WC_Product_Variable();
		$parent->set_name( 'Variable Tee' );
		$parent->set_status( 'publish' );
		$parent->set_attributes( array( $attribute ) );
		$parent->save();

		foreach ( $variation_specs as $spec ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $parent->get_id() );
			$variation->set_attributes( array( 'size' => $spec['size'] ) );
			$variation->set_regular_price( $spec['price'] );
			$variation->set_status( 'publish' );

			if ( isset( $spec['stock_status'] ) ) {
				$variation->set_stock_status( $spec['stock_status'] );
			}
			if ( isset( $spec['sku'] ) ) {
				$variation->set_sku( $spec['sku'] );
			}

			$variation->save();
		}

		return wc_get_product( $parent->get_id() );
	}

	/**
	 * Render helper (reloads like the serving path).
	 *
	 * @param WC_Product $product Product.
	 * @return string
	 */
	private function render( $product ) {
		$renderer = new Renderer();
		return $renderer->render( wc_get_product( $product->get_id() ) );
	}

	/**
	 * The price section shows the real min-max range with currency.
	 */
	public function test_variable_price_range() {
		$markdown = $this->render( $this->make_variable_product() );

		$this->assertStringContainsString( '## Price', $markdown );
		$this->assertStringContainsString( '15.00', $markdown );
		$this->assertStringContainsString( '20.00', $markdown );
		$this->assertStringContainsString( get_woocommerce_currency(), $markdown );
	}

	/**
	 * Every variation renders one line with its attributes and price.
	 */
	public function test_variants_section_lists_each_variation() {
		$markdown = $this->render( $this->make_variable_product() );

		$this->assertStringContainsString( '## Variants', $markdown );
		$this->assertStringContainsString( 'Size: S', $markdown );
		$this->assertStringContainsString( 'Size: M', $markdown );
	}

	/**
	 * Out-of-stock variations say so on their line.
	 */
	public function test_variant_stock_state_rendered() {
		$product = $this->make_variable_product(
			array(
				array(
					'size'         => 'S',
					'price'        => '15.00',
					'stock_status' => 'outofstock',
				),
				array(
					'size'  => 'M',
					'price' => '20.00',
				),
			)
		);

		$markdown = $this->render( $product );

		$this->assertStringContainsString( 'Out of stock', $markdown );
		$this->assertStringContainsString( 'In stock', $markdown );
	}

	/**
	 * Variation SKUs render when set.
	 */
	public function test_variant_sku_rendered_when_set() {
		$product = $this->make_variable_product(
			array(
				array(
					'size'  => 'S',
					'price' => '15.00',
					'sku'   => 'TEE-' . strtoupper( uniqid() ),
				),
				array(
					'size'  => 'M',
					'price' => '20.00',
				),
			)
		);

		$markdown = $this->render( $product );

		$this->assertMatchesRegularExpression( '/SKU: TEE-[A-Z0-9]+/', $markdown );
	}

	/**
	 * The variant list is capped via filter and the cap is disclosed.
	 */
	public function test_variant_cap_disclosed() {
		$product = $this->make_variable_product(
			array(
				array(
					'size'  => 'S',
					'price' => '15.00',
				),
				array(
					'size'  => 'M',
					'price' => '20.00',
				),
				array(
					'size'  => 'L',
					'price' => '25.00',
				),
			)
		);

		add_filter(
			'mdmirwc_max_variants',
			static function () {
				return 2;
			}
		);

		$markdown = $this->render( $product );

		$this->assertStringContainsString( 'first 2 of 3 variants', $markdown );
		$this->assertStringNotContainsString( 'Size: L', $markdown );
	}
}
