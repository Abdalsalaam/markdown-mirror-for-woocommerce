<?php
/**
 * Renderer double that counts render calls (used by CacheTest).
 *
 * @package AgentMint\MarkdownMirrorWC\Tests
 */

use AgentMint\MarkdownMirrorWC\Renderer;

/**
 * Counts how many times render() runs.
 */
class PMM_Counting_Renderer extends Renderer {

	/**
	 * Render invocation count.
	 *
	 * @var int
	 */
	public static $calls = 0;

	/**
	 * Count and delegate.
	 *
	 * @param WC_Product $product Product.
	 * @param array      $args    Args.
	 * @return string
	 */
	public function render( WC_Product $product, array $args = array() ) {
		++self::$calls;
		return parent::render( $product, $args );
	}
}
