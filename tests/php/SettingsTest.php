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

		$this->assertSame( array_keys( Settings::get_defaults() ), array_keys( $clean ), 'Sanitize must return exactly the known keys.' );
		$this->assertArrayNotHasKey( 'evil', $clean );
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
	 * Taxonomy toggle fields register for existing taxonomies.
	 */
	public function test_taxonomy_toggle_fields_registered() {
		global $wp_settings_fields;

		$settings = new Settings();
		$settings->register_settings();

		$fields = isset( $wp_settings_fields[ Settings::PAGE_SLUG ]['product_markdown_mirror_terms'] )
			? $wp_settings_fields[ Settings::PAGE_SLUG ]['product_markdown_mirror_terms']
			: array();

		$this->assertArrayHasKey( 'mirror_categories', $fields );
		$this->assertArrayHasKey( 'mirror_tags', $fields );

		if ( taxonomy_exists( 'product_brand' ) ) {
			$this->assertArrayHasKey( 'mirror_brands', $fields );
		} else {
			$this->assertArrayNotHasKey( 'mirror_brands', $fields );
		}

		unregister_setting( Settings::OPTION_GROUP, Settings::OPTION_NAME );
	}

	/**
	 * Saving settings queues the deferred rewrite flush (routes may change).
	 */
	public function test_settings_change_queues_rewrite_flush() {
		delete_option( 'product_markdown_mirror_flush_needed' );

		$settings = new Settings();
		$settings->register_hooks();

		// First save fires add_option_*.
		update_option( Settings::OPTION_NAME, array( 'mirror_categories' => 'yes' ) );
		$this->assertSame( 'yes', get_option( 'product_markdown_mirror_flush_needed' ) );

		delete_option( 'product_markdown_mirror_flush_needed' );

		// Subsequent change fires update_option_*.
		update_option( Settings::OPTION_NAME, array( 'mirror_categories' => 'no' ) );
		$this->assertSame( 'yes', get_option( 'product_markdown_mirror_flush_needed' ) );
	}

	/**
	 * term_mirrors_enabled: defaults off, unknown taxonomies always false.
	 */
	public function test_term_mirrors_enabled_defaults() {
		$this->assertFalse( Settings::term_mirrors_enabled( 'product_cat' ) );
		$this->assertFalse( Settings::term_mirrors_enabled( 'product_tag' ) );
		$this->assertFalse( Settings::term_mirrors_enabled( 'post_tag' ) );

		update_option( Settings::OPTION_NAME, array( 'mirror_categories' => 'yes' ) );

		$this->assertTrue( Settings::term_mirrors_enabled( 'product_cat' ) );
		$this->assertFalse( Settings::term_mirrors_enabled( 'product_tag' ) );
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
