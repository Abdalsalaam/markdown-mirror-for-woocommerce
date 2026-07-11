<?php
/**
 * Term router: .md routes for product taxonomy archives.
 *
 * @package AgentMint\MarkdownMirrorWC
 */

namespace AgentMint\MarkdownMirrorWC;

use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * Maps {term-archive-url}[/page/N].md onto Markdown responses.
 *
 * Rules register only for taxonomy groups the merchant enabled (all off by
 * default). Hierarchical category paths are verified against the term's real
 * archive path, so wrong-parent aliases 404 instead of serving duplicates.
 */
class Term_Router {

	/**
	 * Query var: taxonomy name.
	 *
	 * @var string
	 */
	const QUERY_VAR_TAX = 'mdmirwc_tax';

	/**
	 * Query var: requested term path (slug, or hierarchy path for categories).
	 *
	 * @var string
	 */
	const QUERY_VAR_TERM = 'mdmirwc_term';

	/**
	 * Query var: mirror page number.
	 *
	 * @var string
	 */
	const QUERY_VAR_PAGE = 'mdmirwc_pg';

	/**
	 * Taxonomies this router may ever serve.
	 *
	 * @var string[]
	 */
	const TAXONOMIES = array( 'product_cat', 'product_brand', 'product_tag' );

	/**
	 * Renderer for term documents.
	 *
	 * @var Term_Renderer
	 */
	private $renderer;

	/**
	 * Cache for rendered term pages.
	 *
	 * @var Cache
	 */
	private $cache;

	/**
	 * Constructor.
	 *
	 * @param Term_Renderer|null $renderer Renderer; a fresh one is created when omitted.
	 * @param Cache|null         $cache    Cache; a fresh one is created when omitted.
	 */
	public function __construct( ?Term_Renderer $renderer = null, ?Cache $cache = null ) {
		$this->renderer = $renderer ? $renderer : new Term_Renderer();
		$this->cache    = $cache ? $cache : new Cache();
	}

	/**
	 * Hook registration.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'init', array( $this, 'add_rules' ) );
		add_filter( 'query_vars', array( $this, 'register_query_vars' ) );
		add_action( 'template_redirect', array( $this, 'maybe_serve' ), 5 );
	}

	/**
	 * Register .md rewrite rules for every enabled taxonomy group.
	 *
	 * @return void
	 */
	public function add_rules() {
		foreach ( self::TAXONOMIES as $taxonomy ) {
			if ( ! taxonomy_exists( $taxonomy ) || ! Settings::term_mirrors_enabled( $taxonomy ) ) {
				continue;
			}

			$taxonomy_object = get_taxonomy( $taxonomy );
			$base            = isset( $taxonomy_object->rewrite['slug'] ) ? trim( $taxonomy_object->rewrite['slug'], '/' ) : '';

			if ( '' === $base ) {
				continue;
			}

			$quoted  = preg_quote( $base, '#' );
			$capture = is_taxonomy_hierarchical( $taxonomy ) ? '(.+?)' : '([^/]+)';

			// Page rule FIRST: top rules keep insertion order and the first
			// match wins, so /page/N.md must register before the greedy
			// path capture or the plain rule swallows pagination URLs.
			add_rewrite_rule(
				'^' . $quoted . '/' . $capture . '/page/([0-9]+)\.md$',
				'index.php?' . self::QUERY_VAR_TAX . '=' . $taxonomy . '&' . self::QUERY_VAR_TERM . '=$matches[1]&' . self::QUERY_VAR_PAGE . '=$matches[2]',
				'top'
			);

			add_rewrite_rule(
				'^' . $quoted . '/' . $capture . '\.md$',
				'index.php?' . self::QUERY_VAR_TAX . '=' . $taxonomy . '&' . self::QUERY_VAR_TERM . '=$matches[1]',
				'top'
			);
		}
	}

	/**
	 * Expose the query vars.
	 *
	 * @param array $vars Public query vars.
	 * @return array
	 */
	public function register_query_vars( $vars ) {
		$vars[] = self::QUERY_VAR_TAX;
		$vars[] = self::QUERY_VAR_TERM;
		$vars[] = self::QUERY_VAR_PAGE;

		return $vars;
	}

	/**
	 * Serve the term mirror when our query vars are present.
	 *
	 * @return void
	 */
	public function maybe_serve() {
		$taxonomy = (string) get_query_var( self::QUERY_VAR_TAX );

		if ( '' === $taxonomy ) {
			return;
		}

		$path = (string) get_query_var( self::QUERY_VAR_TERM );
		$page = (int) get_query_var( self::QUERY_VAR_PAGE );

		$this->handle_term_request( $taxonomy, $path, $page ? $page : 1 )->send();
	}

	/**
	 * Resolve a taxonomy + term path + page to a response (or honest 404).
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @param string $path     Requested term path (may contain hierarchy segments).
	 * @param int    $page     Mirror page number.
	 * @return Response
	 */
	public function handle_term_request( $taxonomy, $path, $page = 1 ) {
		if ( ! in_array( $taxonomy, self::TAXONOMIES, true )
			|| ! taxonomy_exists( $taxonomy )
			|| ! Settings::mirrors_enabled()
			|| ! Settings::term_mirrors_enabled( $taxonomy ) ) {
			return Response::not_found();
		}

		$term = $this->resolve_term( $taxonomy, $path );

		if ( ! $term ) {
			return Response::not_found();
		}

		/**
		 * Filter whether a term archive gets a Markdown mirror.
		 *
		 * @since 1.0.0
		 *
		 * @param bool    $mirrored Whether the term is mirrored.
		 * @param WP_Term $term     Term being requested.
		 */
		if ( ! apply_filters( 'mdmirwc_term_is_mirrored', true, $term ) ) {
			return Response::not_found();
		}

		$page     = max( 1, (int) $page );
		$markdown = $this->cache->get_term_mirror( $term, $page );

		if ( false === $markdown ) {
			$markdown = $this->renderer->render_term( $term, $page );

			if ( null === $markdown ) {
				return Response::not_found();
			}

			$this->cache->set_term_mirror( $term, $page, $markdown );
		}

		return new Response( 200, $this->term_headers( $term ), $markdown . "\n" );
	}

	/**
	 * Resolve the requested path to a term, verifying the full archive path.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @param string $path     Requested path.
	 * @return WP_Term|null
	 */
	private function resolve_term( $taxonomy, $path ) {
		$segments = array_filter( array_map( 'sanitize_title', explode( '/', trim( (string) $path, '/' ) ) ) );

		if ( empty( $segments ) ) {
			return null;
		}

		$slug = end( $segments );
		$term = get_term_by( 'slug', $slug, $taxonomy );

		if ( ! $term instanceof WP_Term ) {
			return null;
		}

		// The requested segments must be the term's real ancestor slug chain
		// (structural check, independent of link format): aliases with wrong
		// or missing parent segments 404 instead of serving duplicates.
		$expected = array( $term->slug );

		if ( is_taxonomy_hierarchical( $taxonomy ) ) {
			foreach ( get_ancestors( $term->term_id, $taxonomy, 'taxonomy' ) as $ancestor_id ) {
				$ancestor = get_term( $ancestor_id, $taxonomy );

				if ( $ancestor instanceof WP_Term ) {
					array_unshift( $expected, $ancestor->slug );
				}
			}
		}

		if ( array_values( $segments ) !== $expected ) {
			return null;
		}

		return $term;
	}

	/**
	 * Headers for a term mirror response. No Last-Modified: terms carry no
	 * honest modified date, and we never fabricate freshness.
	 *
	 * @param WP_Term $term Term being served.
	 * @return array<string, string>
	 */
	private function term_headers( WP_Term $term ) {
		/**
		 * Filter the Cache-Control max-age for term mirror responses, in seconds.
		 *
		 * @since 1.0.0
		 *
		 * @param int     $max_age Seconds (default 300).
		 * @param WP_Term $term    Term being served.
		 */
		$max_age = (int) apply_filters( 'mdmirwc_term_cache_max_age', 300, $term );

		$link = get_term_link( $term );

		$headers = array(
			'Content-Type'           => 'text/markdown; charset=UTF-8',
			'X-Content-Type-Options' => 'nosniff',
			'X-Robots-Tag'           => 'noindex',
			'Cache-Control'          => 'public, max-age=' . max( 0, $max_age ),
		);

		if ( ! is_wp_error( $link ) ) {
			$headers['Link'] = '<' . $link . '>; rel="canonical"';
		}

		return $headers;
	}
}
