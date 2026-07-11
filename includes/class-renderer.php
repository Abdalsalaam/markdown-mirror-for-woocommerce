<?php
/**
 * Renderer: turns a WC_Product into the Markdown mirror document.
 *
 * @package AgentMint\MarkdownMirrorWC
 */

namespace AgentMint\MarkdownMirrorWC;

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
				'include_identifiers'       => true,
				'include_classification'    => true,
				'include_specifications'    => true,
				'include_price'             => true,
				'include_availability'      => true,
				'include_variants'          => true,
				'include_reviews'           => true,
				'include_images'            => true,
				'include_short_description' => true,
				'include_full_description'  => true,
			)
		);

		$sections = array(
			'header'         => $this->section_header( $product ),
			'identifiers'    => $args['include_identifiers'] ? $this->section_identifiers( $product ) : '',
			'classification' => $args['include_classification'] ? $this->section_classification( $product ) : '',
			'specifications' => $args['include_specifications'] ? $this->section_specifications( $product ) : '',
			'price'          => $args['include_price'] ? $this->section_price( $product ) : '',
			'availability'   => $args['include_availability'] ? $this->section_availability( $product ) : '',
			'variants'       => $args['include_variants'] ? $this->section_variants( $product ) : '',
			'reviews'        => $args['include_reviews'] ? $this->section_reviews( $product ) : '',
			'images'         => $args['include_images'] ? $this->section_images( $product ) : '',
			'description'    => $this->section_description( $product, $args['include_short_description'], $args['include_full_description'] ),
			'footer'         => $this->section_footer( $product ),
		);

		/**
		 * Filter the ordered map of document sections before assembly.
		 *
		 * Values are Markdown strings; empty strings are dropped. Adding a
		 * section here is the supported way to extend the mirror (for example
		 * shipping or returns data a site actually holds).
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, string> $sections Section name => Markdown.
		 * @param WC_Product            $product  Product being rendered.
		 */
		$sections = apply_filters( 'mdmirwc_sections', $sections, $product );

		$document = implode( "\n\n", array_filter( array_map( 'trim', $sections ) ) );

		/**
		 * Filter the assembled Markdown document.
		 *
		 * @since 1.0.0
		 *
		 * @param string     $document Full Markdown document.
		 * @param WC_Product $product  Product being rendered.
		 */
		return apply_filters( 'mdmirwc_document', $document, $product );
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

		if ( ! $product->is_type( 'variable' ) ) {
			$parts[] = $this->availability_label( $product );
		}

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
	 * Classification: categories (hierarchical paths) and tags with links.
	 *
	 * Links point at the term's .md mirror only when that group is enabled;
	 * otherwise at the always-valid HTML archive, so the mirror never links a
	 * URL that would 404.
	 *
	 * @param WC_Product $product Product.
	 * @return string
	 */
	private function section_classification( WC_Product $product ) {
		$lines = array();

		$categories = $this->term_lines( $product->get_id(), 'product_cat', true );
		if ( '' !== $categories ) {
			$lines[] = '- Categories: ' . $categories;
		}

		$tags = $this->term_lines( $product->get_id(), 'product_tag', false );
		if ( '' !== $tags ) {
			$lines[] = '- Tags: ' . $tags;
		}

		if ( empty( $lines ) ) {
			return '';
		}

		return "## Classification\n" . implode( "\n", $lines );
	}

	/**
	 * Comma-separated term entries for a taxonomy: path name plus link.
	 *
	 * @param int    $product_id   Product ID.
	 * @param string $taxonomy     Taxonomy name.
	 * @param bool   $hierarchical Whether to render ancestor paths.
	 * @return string
	 */
	private function term_lines( $product_id, $taxonomy, $hierarchical ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return '';
		}

		$terms = get_the_terms( $product_id, $taxonomy );

		if ( ! is_array( $terms ) || empty( $terms ) ) {
			return '';
		}

		$entries = array();

		foreach ( $terms as $term ) {
			$path = array( $this->single_line( $term->name ) );

			if ( $hierarchical ) {
				foreach ( get_ancestors( $term->term_id, $taxonomy, 'taxonomy' ) as $ancestor_id ) {
					$ancestor = get_term( $ancestor_id, $taxonomy );

					if ( $ancestor instanceof \WP_Term ) {
						array_unshift( $path, $this->single_line( $ancestor->name ) );
					}
				}
			}

			$link = Settings::term_mirrors_enabled( $taxonomy )
				? Router::term_mirror_url( $term )
				: get_term_link( $term );

			$entry = implode( ' > ', $path );

			if ( is_string( $link ) && '' !== $link ) {
				$entry .= ' (' . $link . ')';
			}

			$entries[] = $entry;
		}

		return implode( ', ', $entries );
	}

	/**
	 * Reviews: real average rating and count, only when reviews exist.
	 *
	 * @param WC_Product $product Product.
	 * @return string
	 */
	private function section_reviews( WC_Product $product ) {
		if ( function_exists( 'wc_review_ratings_enabled' ) && ! wc_review_ratings_enabled() ) {
			return '';
		}

		$count = (int) $product->get_review_count();

		if ( $count < 1 ) {
			return '';
		}

		$average = wc_format_decimal( $product->get_average_rating(), 2, true );

		return "## Reviews\n- Rating: " . $average . " of 5\n- Reviews: " . $count;
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
		if ( $product->is_type( 'variable' ) ) {
			return $this->section_price_variable( $product );
		}

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
	 * Price section for variable products: the real min-max display range.
	 *
	 * @param WC_Product $product Variable product.
	 * @return string
	 */
	private function section_price_variable( WC_Product $product ) {
		$min = $product->get_variation_price( 'min', true );
		$max = $product->get_variation_price( 'max', true );

		if ( '' === (string) $min && '' === (string) $max ) {
			return '';
		}

		$currency = get_woocommerce_currency();
		$decimals = wc_get_price_decimals();

		if ( (float) $min === (float) $max ) {
			$line = '- Price: ' . wc_format_decimal( $min, $decimals ) . ' ' . $currency;
		} else {
			$line = '- Price: ' . wc_format_decimal( $min, $decimals ) . ' to ' . wc_format_decimal( $max, $decimals ) . ' ' . $currency;
		}

		$lines = array( $line );

		if ( wc_tax_enabled() ) {
			$display = get_option( 'woocommerce_tax_display_shop', 'excl' );
			$lines[] = '- Tax: ' . ( 'incl' === $display ? 'prices shown include tax' : 'prices shown exclude tax' );
		}

		return "## Price\n" . implode( "\n", $lines );
	}

	/**
	 * Variants section for variable products: one line per variation.
	 *
	 * @param WC_Product $product Product.
	 * @return string
	 */
	private function section_variants( WC_Product $product ) {
		if ( ! $product->is_type( 'variable' ) ) {
			return '';
		}

		$children = $product->get_children();
		$total    = count( $children );

		if ( 0 === $total ) {
			return '';
		}

		/**
		 * Filter the maximum number of variations rendered in the mirror.
		 *
		 * Bounded output keeps mirrors cheap to read; when the cap applies it
		 * is disclosed in the output rather than silently truncating.
		 *
		 * @since 1.0.0
		 *
		 * @param int        $max_variants Maximum variation lines (default 50).
		 * @param WC_Product $product      Product being rendered.
		 */
		$cap = (int) apply_filters( 'mdmirwc_max_variants', 50, $product );
		$cap = max( 1, $cap );

		$lines = array();

		foreach ( array_slice( $children, 0, $cap ) as $child_id ) {
			$variation = wc_get_product( $child_id );

			if ( ! $variation ) {
				continue;
			}

			$parts = array();

			$attributes = wc_get_formatted_variation( $variation, true, true, false );
			if ( '' !== (string) $attributes ) {
				$parts[] = $this->single_line( $attributes );
			}

			$price = $this->display_price( $variation );
			if ( '' !== $price ) {
				$parts[] = $price;
			}

			$parts[] = $this->availability_label( $variation );

			$sku = $variation->get_sku( 'edit' );
			if ( '' !== (string) $sku ) {
				$parts[] = 'SKU: ' . $this->single_line( $sku );
			}

			if ( method_exists( $variation, 'get_global_unique_id' ) ) {
				$gtin = $variation->get_global_unique_id();
				if ( '' !== (string) $gtin ) {
					$parts[] = 'GTIN: ' . $this->single_line( $gtin );
				}
			}

			$lines[] = '- ' . implode( ' | ', $parts );
		}

		if ( empty( $lines ) ) {
			return '';
		}

		if ( $total > $cap ) {
			/* translators: 1: number of variants shown, 2: total number of variants. */
			$lines[] = '- ' . sprintf( '(showing first %1$d of %2$d variants)', $cap, $total );
		}

		return "## Variants\n" . implode( "\n", $lines );
	}

	/**
	 * Availability from the product stock status.
	 *
	 * @param WC_Product $product Product.
	 * @return string
	 */
	private function section_availability( WC_Product $product ) {
		if ( $product->is_type( 'variable' ) ) {
			return ''; // Availability is stated per variation in the Variants section.
		}

		return "## Availability\n- Availability: " . $this->availability_label( $product );
	}

	/**
	 * Images: main and gallery as Markdown image lines with alt text.
	 *
	 * @param WC_Product $product Product.
	 * @return string
	 */
	private function section_images( WC_Product $product ) {
		$image_ids = array();

		$main_id = (int) $product->get_image_id();
		if ( $main_id ) {
			$image_ids[] = $main_id;
		}

		foreach ( $product->get_gallery_image_ids() as $gallery_id ) {
			$image_ids[] = (int) $gallery_id;
		}

		$lines = array();

		foreach ( array_unique( $image_ids ) as $image_id ) {
			$url = wp_get_attachment_url( $image_id );

			if ( ! $url ) {
				continue;
			}

			$alt     = $this->single_line( get_post_meta( $image_id, '_wp_attachment_image_alt', true ) );
			$lines[] = '![' . $alt . '](' . $url . ')';
		}

		if ( empty( $lines ) ) {
			return '';
		}

		return "## Images\n" . implode( "\n", $lines );
	}

	/**
	 * Short and full descriptions as plain-text blocks, tags stripped.
	 *
	 * @param WC_Product $product       Product.
	 * @param bool       $include_short Whether the short description renders.
	 * @param bool       $include_full  Whether the full description renders.
	 * @return string
	 */
	private function section_description( WC_Product $product, $include_short = true, $include_full = true ) {
		$blocks = array_filter(
			array(
				$include_short ? $this->block_text( $product->get_short_description() ) : '',
				$include_full ? $this->block_text( $product->get_description() ) : '',
			)
		);

		if ( empty( $blocks ) ) {
			return '';
		}

		return "## Description\n" . implode( "\n\n", $blocks );
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
	protected function display_price( WC_Product $product ) {
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
	protected function availability_label( WC_Product $product ) {
		// The page's own availability text (respects the store's stock
		// display settings, including quantities like "12 in stock").
		if ( function_exists( 'wc_format_stock_for_display' ) && $product->managing_stock() && $product->is_in_stock() ) {
			$display = wc_format_stock_for_display( $product );

			if ( is_string( $display ) && '' !== $display ) {
				return $this->single_line( $display );
			}
		}

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
	protected function single_line( $value ) {
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
	protected function block_text( $html ) {
		$text = wp_strip_all_tags( (string) $html );
		$text = html_entity_decode( $text, ENT_QUOTES, get_bloginfo( 'charset' ) );

		return trim( preg_replace( '/[ \t]*\n[ \t]*/', "\n", preg_replace( '/[ \t]+/', ' ', $text ) ) );
	}
}
