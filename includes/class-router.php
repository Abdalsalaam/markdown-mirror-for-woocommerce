<?php
/**
 * Router: rewrite rules, query var, and the request handler for .md mirrors.
 *
 * @package AgentMint\ProductMarkdownMirror
 */

namespace AgentMint\ProductMarkdownMirror;

use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Maps {product-url}.md onto a Markdown response.
 *
 * Virtual routes only: nothing is written to disk. Honest HTTP: real 404s
 * for anything not publicly reachable, canonical Link header pointing at the
 * HTML page, noindex so the mirror never competes with it in search.
 */
class Router {

	/**
	 * Query var carrying the requested product slug.
	 *
	 * @var string
	 */
	const QUERY_VAR = 'product_markdown_mirror';

	/**
	 * Renderer used for mirror bodies.
	 *
	 * @var Renderer
	 */
	private $renderer;

	/**
	 * Constructor.
	 *
	 * @param Renderer|null $renderer Renderer; a fresh one is created when omitted.
	 */
	public function __construct( ?Renderer $renderer = null ) {
		$this->renderer = $renderer ? $renderer : new Renderer();
	}

	/**
	 * The mirror URL for a product: its permalink with a .md suffix.
	 *
	 * @param WC_Product $product Product.
	 * @return string
	 */
	public static function mirror_url( WC_Product $product ) {
		return untrailingslashit( $product->get_permalink() ) . '.md';
	}

	/**
	 * Hook registration.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'init', array( $this, 'add_rules' ) );
		add_action( 'init', array( $this, 'maybe_flush_rules' ), 20 );
		add_filter( 'query_vars', array( $this, 'register_query_var' ) );
		add_action( 'template_redirect', array( $this, 'maybe_serve' ), 5 );
	}

	/**
	 * Register the .md rewrite rule derived from the product permalink base.
	 *
	 * @return void
	 */
	public function add_rules() {
		$base = $this->product_base();

		add_rewrite_rule(
			'^' . $base . '/([^/]+)\.md/?$',
			'index.php?' . self::QUERY_VAR . '=$matches[1]',
			'top'
		);
	}

	/**
	 * Flush rewrite rules once after activation (deferred via flag option).
	 *
	 * @return void
	 */
	public function maybe_flush_rules() {
		if ( 'yes' !== get_option( 'product_markdown_mirror_flush_needed' ) ) {
			return;
		}

		delete_option( 'product_markdown_mirror_flush_needed' );
		flush_rewrite_rules();
	}

	/**
	 * Expose the query var.
	 *
	 * @param array $vars Public query vars.
	 * @return array
	 */
	public function register_query_var( $vars ) {
		$vars[] = self::QUERY_VAR;

		return $vars;
	}

	/**
	 * Serve the mirror when the query var is present.
	 *
	 * @return void
	 */
	public function maybe_serve() {
		$slug = get_query_var( self::QUERY_VAR );

		if ( '' === (string) $slug ) {
			return;
		}

		$this->handle_request( (string) $slug )->send();
	}

	/**
	 * Resolve a product slug to a mirror response (or an honest 404).
	 *
	 * @param string $slug Product slug from the URL.
	 * @return Response
	 */
	public function handle_request( $slug ) {
		if ( ! Settings::mirrors_enabled() ) {
			return $this->not_found();
		}

		$product = $this->resolve_product( $slug );

		if ( ! $product ) {
			return $this->not_found();
		}

		$markdown = $this->renderer->render(
			$product,
			array(
				'include_description' => Settings::include_description(),
			)
		);

		return new Response( 200, $this->mirror_headers( $product ), $markdown . "\n" );
	}

	/**
	 * Find the product for a slug, applying the public-reachability gate.
	 *
	 * @param string $slug Product slug.
	 * @return WC_Product|null
	 */
	private function resolve_product( $slug ) {
		$post = get_page_by_path( sanitize_title( $slug ), OBJECT, 'product' );

		if ( ! $post || 'publish' !== $post->post_status || '' !== (string) $post->post_password ) {
			return null;
		}

		$product = wc_get_product( $post->ID );

		if ( ! $product ) {
			return null;
		}

		/**
		 * Filter whether a product gets a Markdown mirror.
		 *
		 * The default is every publicly reachable product (equivalence with
		 * the HTML page). Return false to exclude a product.
		 *
		 * @since 0.1.0
		 *
		 * @param bool       $mirrored Whether the product is mirrored.
		 * @param WC_Product $product  Product being requested.
		 */
		if ( ! apply_filters( 'product_markdown_mirror_is_mirrored', true, $product ) ) {
			return null;
		}

		return $product;
	}

	/**
	 * Headers for a successful mirror response.
	 *
	 * @param WC_Product $product Product being served.
	 * @return array<string, string>
	 */
	private function mirror_headers( WC_Product $product ) {
		/**
		 * Filter the Cache-Control max-age for mirror responses, in seconds.
		 *
		 * Short by default: price and availability live on this surface, so a
		 * long-cached mirror could quote an offer the store no longer shows.
		 *
		 * @since 0.1.0
		 *
		 * @param int        $max_age Seconds (default 300).
		 * @param WC_Product $product Product being served.
		 */
		$max_age = (int) apply_filters( 'product_markdown_mirror_cache_max_age', 300, $product );

		$modified = $product->get_date_modified();
		if ( ! $modified ) {
			$modified = $product->get_date_created();
		}

		$headers = array(
			'Content-Type'  => 'text/markdown; charset=UTF-8',
			'Link'          => '<' . $product->get_permalink() . '>; rel="canonical"',
			'X-Robots-Tag'  => 'noindex',
			'Cache-Control' => 'public, max-age=' . max( 0, $max_age ),
		);

		if ( $modified ) {
			$headers['Last-Modified'] = gmdate( 'D, d M Y H:i:s', $modified->getTimestamp() ) . ' GMT';
		}

		return $headers;
	}

	/**
	 * An honest 404 response.
	 *
	 * @return Response
	 */
	private function not_found() {
		return new Response(
			404,
			array(
				'Content-Type'  => 'text/plain; charset=UTF-8',
				'X-Robots-Tag'  => 'noindex',
				'Cache-Control' => 'no-cache',
			),
			"Not found.\n"
		);
	}

	/**
	 * The product permalink base, with any taxonomy placeholders widened.
	 *
	 * @return string
	 */
	private function product_base() {
		$permalinks = wc_get_permalink_structure();
		$base       = isset( $permalinks['product_rewrite_slug'] ) ? $permalinks['product_rewrite_slug'] : 'product';
		$base       = trim( $base, '/' );

		// A %product_cat% placeholder means category segments precede the slug.
		if ( false !== strpos( $base, '%product_cat%' ) ) {
			$base = str_replace( '%product_cat%', '.+', $base );
		}

		return $base;
	}
}
