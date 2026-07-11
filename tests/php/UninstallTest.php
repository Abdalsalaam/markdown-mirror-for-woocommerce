<?php
/**
 * Uninstall behavior tests (T-09).
 *
 * @package AgentMint\MarkdownMirrorWC\Tests
 */

use AgentMint\MarkdownMirrorWC\Cache;
use AgentMint\MarkdownMirrorWC\Settings;

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
	 * Uninstall removes options, transients, and term meta; nothing else.
	 */
	public function test_uninstall_removes_all_plugin_data() {
		global $wpdb;

		// Seed everything the plugin can persist.
		update_option( Settings::OPTION_NAME, array( 'enabled' => 'no' ) );
		update_option( 'mdmirwc_flush_needed', 'yes', false );
		update_option( Cache::GENERATION_OPTION, 7, false );
		set_transient( 'mdmirwc_7_123', 'BODY', 300 );

		$term_result = wp_insert_term( 'Uninstall Term', 'product_cat' );
		update_term_meta( $term_result['term_id'], 'mdmirwc_ver', 5 );

		// Unrelated data that must survive.
		update_option( 'unrelated_option', 'keep-me' );
		set_transient( 'unrelated_transient', 'keep-me', 300 );

		mdmirwc_uninstall_site();

		$this->assertFalse( get_option( Settings::OPTION_NAME ) );
		$this->assertFalse( get_option( 'mdmirwc_flush_needed' ) );
		$this->assertFalse( get_option( Cache::GENERATION_OPTION ) );
		$this->assertFalse( get_transient( 'mdmirwc_7_123' ) );
		$this->assertSame( '', (string) get_term_meta( $term_result['term_id'], 'mdmirwc_ver', true ) );

		$leftover = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( '_transient_mdmirwc_' ) . '%'
			)
		);
		$this->assertSame( '0', (string) $leftover );

		$this->assertSame( 'keep-me', get_option( 'unrelated_option' ) );
		$this->assertSame( 'keep-me', get_transient( 'unrelated_transient' ) );
	}
}
