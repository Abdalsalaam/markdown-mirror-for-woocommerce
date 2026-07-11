<?php
/**
 * Term renderer: taxonomy archive mirrors (categories, brands, tags).
 *
 * @package AgentMint\ProductMarkdownMirror
 */

namespace AgentMint\ProductMarkdownMirror;

use WP_Query;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the Markdown mirror for a product taxonomy term archive.
 *
 * Equivalence guard, archive edition: the mirror lists what the archive
 * communicates, so catalog-hidden and search-only products are excluded
 * (archive visibility rules, which differ from single-product pages).
 * Product lists are paginated, never silently truncated, and terms carry no
 * fabricated freshness date.
 */
class Term_Renderer extends Renderer {

	/**
	 * Render the mirror document for a term archive page.
	 *
	 * @param WP_Term $term Term to mirror.
	 * @param int     $page Mirror page number (products pagination).
	 * @return string|null Null when the page number is out of range.
	 */
	public function render_term( WP_Term $term, $page = 1 ) {
		$page = max( 1, (int) $page );

		/**
		 * Filter the number of product lines per term mirror page.
		 *
		 * @since 1.1.0
		 *
		 * @param int     $page_size Products per page (default 100).
		 * @param WP_Term $term      Term being rendered.
		 */
		$page_size = max( 1, (int) apply_filters( 'product_markdown_mirror_term_page_size', 100, $term ) );

		$query = new WP_Query(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'has_password'   => false,
				'posts_per_page' => $page_size,
				'paged'          => $page,
				'orderby'        => array(
					'menu_order' => 'ASC',
					'title'      => 'ASC',
				),
				'fields'         => 'ids',
				'no_found_rows'  => false,
				'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Term archives are tax queries by definition; bounded by posts_per_page.
					'relation' => 'AND',
					array(
						'taxonomy' => $term->taxonomy,
						'field'    => 'term_id',
						'terms'    => array( (int) $term->term_id ),
					),
					array(
						'taxonomy' => 'product_visibility',
						'field'    => 'name',
						'terms'    => array( 'exclude-from-catalog' ),
						'operator' => 'NOT IN',
					),
				),
			)
		);

		$total       = (int) $query->found_posts;
		$total_pages = $total > 0 ? (int) ceil( $total / $page_size ) : 1;

		if ( $page > $total_pages ) {
			return null;
		}

		$sections = array(
			'header'        => $this->section_term_header( $term, $total ),
			'description'   => $this->section_term_description( $term ),
			'subcategories' => $this->section_subcategories( $term ),
			'products'      => $this->section_products( $term, $query->posts, $page, $total_pages ),
			'footer'        => $this->section_term_footer( $term, $page, $total_pages ),
		);

		/**
		 * Filter the ordered map of term mirror sections before assembly.
		 *
		 * @since 1.1.0
		 *
		 * @param array<string, string> $sections Section name => Markdown.
		 * @param WP_Term               $term     Term being rendered.
		 * @param int                   $page     Mirror page number.
		 */
		$sections = apply_filters( 'product_markdown_mirror_term_sections', $sections, $term, $page );

		$document = implode( "\n\n", array_filter( array_map( 'trim', $sections ) ) );

		/**
		 * Filter the assembled term mirror document.
		 *
		 * @since 1.1.0
		 *
		 * @param string  $document Full Markdown document.
		 * @param WP_Term $term     Term being rendered.
		 * @param int     $page     Mirror page number.
		 */
		return apply_filters( 'product_markdown_mirror_term_document', $document, $term, $page );
	}

	/**
	 * H1, factual blockquote, and the parent-category line where one exists.
	 *
	 * @param WP_Term $term  Term.
	 * @param int     $total Visible product count.
	 * @return string
	 */
	private function section_term_header( WP_Term $term, $total ) {
		$name = $this->single_line( $term->name );

		/* translators: %d: number of products in the term. */
		$count_text = sprintf( _n( '%d product', '%d products', $total, 'product-markdown-mirror' ), $total );

		$block = '# ' . $name . "\n\n" . '> ' . $name . '. ' . $this->kind_label( $term->taxonomy ) . '. ' . $count_text . '.';

		if ( $term->parent && is_taxonomy_hierarchical( $term->taxonomy ) ) {
			$parent = get_term( $term->parent, $term->taxonomy );

			if ( $parent && ! is_wp_error( $parent ) ) {
				$block .= "\n\n" . 'Parent category: ' . $this->single_line( $parent->name ) . ' (' . Router::term_mirror_url( $parent ) . ')';
			}
		}

		return $block;
	}

	/**
	 * Term description as plain text.
	 *
	 * @param WP_Term $term Term.
	 * @return string
	 */
	private function section_term_description( WP_Term $term ) {
		$text = $this->block_text( $term->description );

		if ( '' === $text ) {
			return '';
		}

		return "## Description\n" . $text;
	}

	/**
	 * Direct child terms with their mirror URLs (hierarchical taxonomies only).
	 *
	 * @param WP_Term $term Term.
	 * @return string
	 */
	private function section_subcategories( WP_Term $term ) {
		if ( ! is_taxonomy_hierarchical( $term->taxonomy ) ) {
			return '';
		}

		$children = get_terms(
			array(
				'taxonomy'   => $term->taxonomy,
				'parent'     => $term->term_id,
				'hide_empty' => false,
			)
		);

		if ( is_wp_error( $children ) || empty( $children ) ) {
			return '';
		}

		$lines = array();
		foreach ( $children as $child ) {
			$lines[] = '- ' . $this->single_line( $child->name ) . ' (' . Router::term_mirror_url( $child ) . ')';
		}

		return "## Subcategories\n" . implode( "\n", $lines );
	}

	/**
	 * Product lines for this page: name, price, availability, mirror URL.
	 *
	 * @param WP_Term $term        Term.
	 * @param int[]   $product_ids Product IDs on this page.
	 * @param int     $page        Page number.
	 * @param int     $total_pages Total pages.
	 * @return string
	 */
	private function section_products( WP_Term $term, array $product_ids, $page, $total_pages ) {
		if ( empty( $product_ids ) ) {
			return '';
		}

		$lines = array();

		foreach ( $product_ids as $product_id ) {
			$product = wc_get_product( $product_id );

			if ( ! $product ) {
				continue;
			}

			$parts = array( $this->single_line( $product->get_name() ) );

			$price = $this->display_price( $product );
			if ( '' !== $price ) {
				$parts[] = $price;
			}

			$parts[] = $this->availability_label( $product );
			$parts[] = Router::mirror_url( $product );

			$lines[] = '- ' . implode( ' | ', $parts );
		}

		if ( empty( $lines ) ) {
			return '';
		}

		$heading = sprintf( '## Products (page %1$d of %2$d)', (int) $page, (int) $total_pages );

		return $heading . "\n" . implode( "\n", $lines );
	}

	/**
	 * Footer: canonical archive URL plus previous/next mirror page links.
	 *
	 * No last-updated line: terms carry no honest modified date.
	 *
	 * @param WP_Term $term        Term.
	 * @param int     $page        Page number.
	 * @param int     $total_pages Total pages.
	 * @return string
	 */
	private function section_term_footer( WP_Term $term, $page, $total_pages ) {
		$link  = get_term_link( $term );
		$lines = array( '---' );

		if ( ! is_wp_error( $link ) ) {
			$lines[] = 'Canonical: ' . $link;
		}

		if ( $page > 1 ) {
			$lines[] = 'Previous page: ' . Router::term_mirror_url( $term, $page - 1 );
		}

		if ( $page < $total_pages ) {
			$lines[] = 'Next page: ' . Router::term_mirror_url( $term, $page + 1 );
		}

		return implode( "\n", $lines );
	}

	/**
	 * Human label for the taxonomy kind.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return string
	 */
	private function kind_label( $taxonomy ) {
		if ( 'product_cat' === $taxonomy ) {
			return __( 'Product category', 'product-markdown-mirror' );
		}

		if ( 'product_tag' === $taxonomy ) {
			return __( 'Product tag', 'product-markdown-mirror' );
		}

		if ( 'product_brand' === $taxonomy ) {
			return __( 'Brand', 'product-markdown-mirror' );
		}

		return __( 'Product taxonomy', 'product-markdown-mirror' );
	}
}
