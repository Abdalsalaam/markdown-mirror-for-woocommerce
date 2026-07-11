<?php
/**
 * Renderer: turns a WC_Product into the Markdown mirror document.
 *
 * @package AgentMint\ProductMarkdownMirror
 */

namespace AgentMint\ProductMarkdownMirror;

use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the Markdown document for a product.
 *
 * Equivalence guard: every value is read from the same product object and
 * store settings that render the HTML page. Sections with no honest data are
 * omitted, never padded. Single-line values have whitespace collapsed so
 * product data cannot inject Markdown structure.
 */
class Renderer {

	/**
	 * Render the full Markdown document for a product.
	 *
	 * @param WC_Product $product Product to mirror.
	 * @param array      $args    Rendering args: include_description (bool).
	 * @return string
	 */
	public function render( WC_Product $product, array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'include_description' => true,
			)
		);

		$sections = array(
			'header'         => $this->section_header( $product ),
			'identifiers'    => $this->section_identifiers( $product ),
			'specifications' => $this->section_specifications( $product ),
			'price'          => $this->section_price( $product ),
			'availability'   => $this->section_availability( $product ),
			'description'    => $args['include_description'] ? $this->section_description( $product ) : '',
			'footer'         => $this->section_footer( $product ),
		);

		/**
		 * Filter the ordered map of document sections before assembly.
		 *
		 * Values are Markdown strings; empty strings are dropped. Adding a
		 * section here is the supported way to extend the mirror (for example
		 * shipping or returns data a site actually holds).
		 *
		 * @since 0.1.0
		 *
		 * @param array<string, string> $sections Section name => Markdown.
		 * @param WC_Product            $product  Product being rendered.
		 */
		$sections = apply_filters( 'product_markdown_mirror_sections', $sections, $product );

		$document = implode( "\n\n", array_filter( array_map( 'trim', $sections ) ) );

		/**
		 * Filter the assembled Markdown document.
		 *
		 * @since 0.1.0
		 *
		 * @param string     $document Full Markdown document.
		 * @param WC_Product $product  Product being rendered.
		 */
		return apply_filters( 'product_markdown_mirror_document', $document, $product );
	}

	/**
	 * H1 plus the factual blockquote line.
	 *
	 * @param WC_Product $product Product.
	 * @return string
	 */
	private function section_header( WC_Product $product ) {
		$name  = $this->single_line( $product->get_name() );
		$parts = array( $name );

		$price_line = $this->display_price( $product );
		if ( '' !== $price_line ) {
			$parts[] = $price_line;
		}

		$parts[] = $this->availability_label( $product );

		return '# ' . $name . "\n\n" . '> ' . implode( '. ', array_filter( $parts ) ) . '.';
	}

	/**
	 * Identifiers: GTIN, SKU, brand. Only real values; nothing invented.
	 *
	 * @param WC_Product $product Product.
	 * @return string
	 */
	private function section_identifiers( WC_Product $product ) {
		$lines = array();

		if ( method_exists( $product, 'get_global_unique_id' ) ) {
			$gtin = $product->get_global_unique_id();
			if ( '' !== (string) $gtin ) {
				$lines[] = '- GTIN: ' . $this->single_line( $gtin );
			}
		}

		$sku = $product->get_sku();
		if ( '' !== (string) $sku ) {
			$lines[] = '- SKU: ' . $this->single_line( $sku );
		}

		$brands = $this->brand_names( $product );
		if ( '' !== $brands ) {
			$lines[] = '- Brand: ' . $brands;
		}

		if ( empty( $lines ) ) {
			return '';
		}

		return "## Identifiers\n" . implode( "\n", $lines );
	}

	/**
	 * Specifications: visible attributes, weight, dimensions.
	 *
	 * @param WC_Product $product Product.
	 * @return string
	 */
	private function section_specifications( WC_Product $product ) {
		$lines = array();

		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! is_object( $attribute ) || ! $attribute->get_visible() ) {
				continue;
			}

			$label  = wc_attribute_label( $attribute->get_name(), $product );
			$values = array();

			if ( $attribute->is_taxonomy() ) {
				$terms = wc_get_product_terms( $product->get_id(), $attribute->get_name(), array( 'fields' => 'names' ) );
				if ( ! is_wp_error( $terms ) ) {
					$values = $terms;
				}
			} else {
				$values = $attribute->get_options();
			}

			$values = array_filter( array_map( array( $this, 'single_line' ), (array) $values ) );
			if ( empty( $values ) ) {
				continue;
			}

			$lines[] = '- ' . $this->single_line( $label ) . ': ' . implode( ', ', $values );
		}

		$weight = $product->get_weight();
		if ( '' !== (string) $weight ) {
			$lines[] = '- Weight: ' . wc_format_decimal( $weight ) . ' ' . get_option( 'woocommerce_weight_unit' );
		}

		if ( $product->has_dimensions() ) {
			$lines[] = '- Dimensions: ' . $this->single_line( wc_format_dimensions( $product->get_dimensions( false ) ) );
		}

		if ( empty( $lines ) ) {
			return '';
		}

		return "## Specifications\n" . implode( "\n", $lines );
	}

	/**
	 * Price: display price, currency, sale window, tax display note.
	 *
	 * @param WC_Product $product Product.
	 * @return string
	 */
	private function section_price( WC_Product $product ) {
		if ( '' === (string) $product->get_price( 'edit' ) ) {
			return '';
		}

		$currency = get_woocommerce_currency();
		$lines    = array( '- Price: ' . $this->display_price( $product ) );

		if ( $product->is_on_sale() && '' !== (string) $product->get_regular_price() ) {
			$regular = wc_format_decimal( $product->get_regular_price(), wc_get_price_decimals() );
			$lines[] = '- Regular price: ' . $regular . ' ' . $currency;

			$sale_end = $product->get_date_on_sale_to();
			if ( $sale_end ) {
				$lines[] = '- Sale ends: ' . $sale_end->date( 'Y-m-d' );
			}
		}

		if ( wc_tax_enabled() ) {
			$display = get_option( 'woocommerce_tax_display_shop', 'excl' );
			$lines[] = '- Tax: ' . ( 'incl' === $display ? 'prices shown include tax' : 'prices shown exclude tax' );
		}

		return "## Price\n" . implode( "\n", $lines );
	}

	/**
	 * Availability from the product stock status.
	 *
	 * @param WC_Product $product Product.
	 * @return string
	 */
	private function section_availability( WC_Product $product ) {
		return "## Availability\n- Availability: " . $this->availability_label( $product );
	}

	/**
	 * Short description as one plain-text block, tags stripped.
	 *
	 * @param WC_Product $product Product.
	 * @return string
	 */
	private function section_description( WC_Product $product ) {
		$text = $this->block_text( $product->get_short_description() );

		if ( '' === $text ) {
			return '';
		}

		return "## Description\n" . $text;
	}

	/**
	 * Footer: canonical HTML URL and the real modified date.
	 *
	 * @param WC_Product $product Product.
	 * @return string
	 */
	private function section_footer( WC_Product $product ) {
		$modified = $product->get_date_modified();

		if ( ! $modified ) {
			$modified = $product->get_date_created();
		}

		$lines = array( '---', 'Canonical: ' . $product->get_permalink() );

		if ( $modified ) {
			$lines[] = 'Last updated: ' . $modified->date( 'Y-m-d' );
		}

		return implode( "\n", $lines );
	}

	/**
	 * The display price with currency code, or empty when priceless.
	 *
	 * @param WC_Product $product Product.
	 * @return string
	 */
	private function display_price( WC_Product $product ) {
		if ( '' === (string) $product->get_price( 'edit' ) ) {
			return '';
		}

		$price = wc_get_price_to_display( $product );

		return wc_format_decimal( $price, wc_get_price_decimals() ) . ' ' . get_woocommerce_currency();
	}

	/**
	 * Human availability label for the stock status.
	 *
	 * @param WC_Product $product Product.
	 * @return string
	 */
	private function availability_label( WC_Product $product ) {
		$status = $product->get_stock_status();

		if ( 'outofstock' === $status ) {
			return 'Out of stock';
		}

		if ( 'onbackorder' === $status ) {
			return 'Available on backorder';
		}

		return 'In stock';
	}

	/**
	 * Brand names from the core product_brand taxonomy, when it exists.
	 *
	 * @param WC_Product $product Product.
	 * @return string
	 */
	private function brand_names( WC_Product $product ) {
		if ( ! taxonomy_exists( 'product_brand' ) ) {
			return '';
		}

		$terms = get_the_terms( $product->get_id(), 'product_brand' );

		if ( ! is_array( $terms ) || empty( $terms ) ) {
			return '';
		}

		$names = array();
		foreach ( $terms as $term ) {
			$names[] = $this->single_line( $term->name );
		}

		return implode( ', ', $names );
	}

	/**
	 * Collapse a value to one plain-text line (no tags, no newlines).
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private function single_line( $value ) {
		$value = wp_strip_all_tags( (string) $value );
		$value = html_entity_decode( $value, ENT_QUOTES, get_bloginfo( 'charset' ) );

		return trim( preg_replace( '/\s+/', ' ', $value ) );
	}

	/**
	 * Plain-text block: tags stripped, whitespace normalized, entities decoded.
	 *
	 * @param string $html Raw HTML.
	 * @return string
	 */
	private function block_text( $html ) {
		$text = wp_strip_all_tags( (string) $html );
		$text = html_entity_decode( $text, ENT_QUOTES, get_bloginfo( 'charset' ) );

		return trim( preg_replace( '/[ \t]*\n[ \t]*/', "\n", preg_replace( '/[ \t]+/', ' ', $text ) ) );
	}
}
