<?php
/**
 * Uninstall behavior tests (T-09).
 *
 * @package AgentMint\ProductMarkdownMirror\Tests
 */

use AgentMint\ProductMarkdownMirror\Cache;
use AgentMint\ProductMarkdownMirror\Conflicts;
use AgentMint\ProductMarkdownMirror\Settings;

/**
 * Tests that uninstall removes every trace the plugin can create.
 */
class UninstallTest extends WP_UnitTestCase {

	/**
	 * Load the uninstall file once (bootstrap defines WP_UNINSTALL_PLUGIN).
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		require_once dirname( dirname( __DIR__ ) ) . '/uninstall.php';
	}

	/**
	 * Uninstall removes options, transients, and user meta; nothing else.
	 */
	public function test_uninstall_removes_all_plugin_data() {
		global $wpdb;

		// Seed everything the plugin can persist.
		update_option( Settings::OPTION_NAME, array( 'enabled' => 'no' ) );
		update_option( 'product_markdown_mirror_flush_needed', 'yes', false );
		update_option( Cache::GENERATION_OPTION, 7, false );
		set_transient( 'product_markdown_mirror_7_123', 'BODY', 300 );

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		update_user_meta( $user_id, Conflicts::DISMISS_META, '1' );

		$term_result = wp_insert_term( 'Uninstall Term', 'product_cat' );
		update_term_meta( $term_result['term_id'], 'product_markdown_mirror_ver', 5 );

		// Unrelated data that must survive.
		update_option( 'unrelated_option', 'keep-me' );
		set_transient( 'unrelated_transient', 'keep-me', 300 );

		product_markdown_mirror_uninstall_site();

		$this->assertFalse( get_option( Settings::OPTION_NAME ) );
		$this->assertFalse( get_option( 'product_markdown_mirror_flush_needed' ) );
		$this->assertFalse( get_option( Cache::GENERATION_OPTION ) );
		$this->assertFalse( get_transient( 'product_markdown_mirror_7_123' ) );
		$this->assertSame( '', (string) get_user_meta( $user_id, Conflicts::DISMISS_META, true ) );
		$this->assertSame( '', (string) get_term_meta( $term_result['term_id'], 'product_markdown_mirror_ver', true ) );

		$leftover = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( '_transient_product_markdown_mirror_' ) . '%'
			)
		);
		$this->assertSame( '0', (string) $leftover );

		$this->assertSame( 'keep-me', get_option( 'unrelated_option' ) );
		$this->assertSame( 'keep-me', get_transient( 'unrelated_transient' ) );
	}
}
