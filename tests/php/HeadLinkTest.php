<?php
/**
 * Head link and conflict detection tests (T-07).
 *
 * @package AgentMint\ProductMarkdownMirror\Tests
 */

use AgentMint\ProductMarkdownMirror\Conflicts;
use AgentMint\ProductMarkdownMirror\Head_Link;
use AgentMint\ProductMarkdownMirror\Router;
use AgentMint\ProductMarkdownMirror\Settings;

/**
 * Tests for the rel=alternate head link and the conflict detector.
 */
class HeadLinkTest extends WP_UnitTestCase {

	/**
	 * Reset settings between tests.
	 */
	public function set_up() {
		parent::set_up();
		delete_option( Settings::OPTION_NAME );
	}

	/**
	 * Create a published product, reloaded so the slug is real.
	 *
	 * @return WC_Product
	 */
	private function make_product() {
		$product = new WC_Product_Simple();
		$product->set_name( 'Head Link Widget' );
		$product->set_regular_price( '5.00' );
		$product->set_status( 'publish' );
		$product->save();

		return wc_get_product( $product->get_id() );
	}

	/**
	 * The mirror URL is the permalink with a .md suffix, no trailing slash.
	 */
	public function test_mirror_url_shape() {
		$product = $this->make_product();

		$url = Router::mirror_url( $product );

		$this->assertSame( untrailingslashit( $product->get_permalink() ) . '.md', $url );
		$this->assertStringEndsWith( '.md', $url );
	}

	/**
	 * render_for() outputs one alternate link tag with the mirror URL.
	 */
	public function test_render_for_outputs_alternate_link() {
		$product = $this->make_product();

		$head_link = new Head_Link();

		ob_start();
		$head_link->render_for( $product );
		$output = ob_get_clean();

		$this->assertStringContainsString( '<link rel="alternate"', $output );
		$this->assertStringContainsString( 'type="text/markdown"', $output );
		$this->assertStringContainsString( esc_url( Router::mirror_url( $product ) ), $output );
	}

	/**
	 * Nothing renders when mirrors are disabled.
	 */
	public function test_no_output_when_disabled() {
		update_option( Settings::OPTION_NAME, array( 'enabled' => 'no' ) );

		$product   = $this->make_product();
		$head_link = new Head_Link();

		ob_start();
		$head_link->maybe_render();
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	/**
	 * Nothing renders outside single-product context.
	 */
	public function test_no_output_outside_product_context() {
		$this->make_product();
		$this->go_to( home_url( '/' ) );

		$head_link = new Head_Link();

		ob_start();
		$head_link->maybe_render();
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	/**
	 * Products excluded via the is_mirrored filter get no head link either.
	 */
	public function test_no_output_for_excluded_product() {
		$product = $this->make_product();

		add_filter( 'product_markdown_mirror_is_mirrored', '__return_false' );

		$head_link = new Head_Link();

		ob_start();
		$head_link->render_for( $product );
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	/**
	 * Term archives get an alternate link when their group is enabled.
	 */
	public function test_term_alternate_link_when_enabled() {
		update_option( Settings::OPTION_NAME, array( 'mirror_categories' => 'yes' ) );

		$result = wp_insert_term( 'Head Link Cat', 'product_cat' );
		$term   = get_term( $result['term_id'], 'product_cat' );

		$head_link = new Head_Link();

		ob_start();
		$head_link->render_for_term( $term );
		$output = ob_get_clean();

		$this->assertStringContainsString( '<link rel="alternate"', $output );
		$this->assertStringContainsString( esc_url( Router::term_mirror_url( $term ) ), $output );
	}

	/**
	 * No term link while the group toggle is off.
	 */
	public function test_no_term_link_when_group_disabled() {
		update_option( Settings::OPTION_NAME, array( 'mirror_categories' => 'no' ) );

		$result = wp_insert_term( 'Disabled Cat', 'product_cat' );
		$term   = get_term( $result['term_id'], 'product_cat' );

		$head_link = new Head_Link();

		ob_start();
		$head_link->render_for_term( $term );
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	/**
	 * maybe_render() emits the term link on a real term archive query.
	 */
	public function test_maybe_render_on_term_archive() {
		update_option( Settings::OPTION_NAME, array( 'mirror_categories' => 'yes' ) );

		$result = wp_insert_term( 'Archive Context Cat', 'product_cat' );
		$term   = get_term( $result['term_id'], 'product_cat' );

		$this->go_to( '?product_cat=' . $term->slug );

		$head_link = new Head_Link();

		ob_start();
		$head_link->maybe_render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'type="text/markdown"', $output );
	}

	/**
	 * Conflict detection matches active plugins from the filterable list.
	 */
	public function test_conflict_detection() {
		$conflicts = new Conflicts();

		$this->assertSame( array(), $conflicts->detect() );

		add_filter(
			'product_markdown_mirror_conflicting_plugins',
			static function ( $slugs ) {
				$slugs[] = 'fake-md-server';
				return $slugs;
			}
		);

		update_option( 'active_plugins', array_merge( (array) get_option( 'active_plugins', array() ), array( 'fake-md-server/fake-md-server.php' ) ) );

		$detected = $conflicts->detect();

		$this->assertContains( 'fake-md-server', $detected );
	}
}
