<?php
/**
 * Settings component tests (T-03).
 *
 * @package AgentMint\ProductMarkdownMirror\Tests
 */

use AgentMint\ProductMarkdownMirror\Settings;

/**
 * Tests for the Settings class: defaults, sanitization, registration, menu, capability.
 */
class SettingsTest extends WP_UnitTestCase {

	/**
	 * Clean the option between tests.
	 */
	public function set_up() {
		parent::set_up();
		delete_option( Settings::OPTION_NAME );
	}

	/**
	 * Missing option returns full defaults: enabled=yes, include_description=yes.
	 */
	public function test_defaults_when_option_missing() {
		$settings = Settings::get_settings();

		$this->assertSame( 'yes', $settings['enabled'] );
		$this->assertSame( 'yes', $settings['include_description'] );
	}

	/**
	 * Stored partial option is merged over defaults.
	 */
	public function test_partial_option_merges_defaults() {
		update_option( Settings::OPTION_NAME, array( 'enabled' => 'no' ) );

		$settings = Settings::get_settings();

		$this->assertSame( 'no', $settings['enabled'] );
		$this->assertSame( 'yes', $settings['include_description'] );
	}

	/**
	 * Sanitize keeps only known keys and casts values to yes/no.
	 */
	public function test_sanitize_strips_unknown_keys_and_casts_values() {
		$settings = new Settings();

		$clean = $settings->sanitize(
			array(
				'enabled'             => '1',
				'include_description' => 'off',
				'evil'                => '<script>alert(1)</script>',
			)
		);

		$this->assertSame( array( 'enabled', 'include_description' ), array_keys( $clean ) );
		$this->assertSame( 'yes', $clean['enabled'] );
		$this->assertSame( 'no', $clean['include_description'] );
	}

	/**
	 * Sanitize survives non-array garbage input by returning defaults.
	 */
	public function test_sanitize_handles_non_array_input() {
		$settings = new Settings();

		$clean = $settings->sanitize( 'not-an-array' );

		$this->assertSame( 'yes', $clean['enabled'] );
		$this->assertSame( 'yes', $clean['include_description'] );
	}

	/**
	 * Helper predicates: mirrors_enabled() / include_description() read the option.
	 */
	public function test_helper_predicates() {
		$this->assertTrue( Settings::mirrors_enabled() );

		update_option( Settings::OPTION_NAME, array( 'enabled' => 'no' ) );

		$this->assertFalse( Settings::mirrors_enabled() );
	}

	/**
	 * register_settings() registers the option with our sanitize callback wired.
	 *
	 * Calls the method directly: firing the global admin_init action would
	 * drag every plugin's admin bootstrap into the test context.
	 */
	public function test_setting_is_registered() {
		$settings = new Settings();
		$settings->register_settings();

		$registered = get_registered_settings();

		$this->assertArrayHasKey( Settings::OPTION_NAME, $registered );

		// The sanitize callback is attached to the option's sanitize filter.
		$this->assertTrue( (bool) has_filter( 'sanitize_option_' . Settings::OPTION_NAME ) );

		unregister_setting( Settings::OPTION_GROUP, Settings::OPTION_NAME );
	}

	/**
	 * add_menu() registers the submenu page under WooCommerce.
	 */
	public function test_menu_registered_under_woocommerce() {
		global $submenu;

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$settings = new Settings();
		$settings->add_menu();

		$found = false;
		if ( isset( $submenu['woocommerce'] ) ) {
			foreach ( $submenu['woocommerce'] as $item ) {
				if ( 'product-markdown-mirror' === $item[2] ) {
					$found = true;
				}
			}
		}

		$this->assertTrue( $found, 'Submenu page product-markdown-mirror not found under WooCommerce menu.' );
	}

	/**
	 * register_hooks() wires the admin actions.
	 */
	public function test_hooks_are_registered() {
		$settings = new Settings();
		$settings->register_hooks();

		$this->assertNotFalse( has_action( 'admin_init', array( $settings, 'register_settings' ) ) );
		$this->assertNotFalse( has_action( 'admin_menu', array( $settings, 'add_menu' ) ) );
	}
}
