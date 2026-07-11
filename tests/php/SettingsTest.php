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
		$this->assertSame( 'yes', $settings['include_full_description'] );
		$this->assertSame( 'yes', $settings['mirror_categories'], 'Everything defaults on (author decision 2026-07-11).' );

		foreach ( Settings::get_defaults() as $key => $value ) {
			$this->assertSame( 'yes', $value, "Default for {$key} must be yes." );
		}
	}

	/**
	 * Stored partial option is merged over defaults.
	 */
	public function test_partial_option_merges_defaults() {
		update_option( Settings::OPTION_NAME, array( 'enabled' => 'no' ) );

		$settings = Settings::get_settings();

		$this->assertSame( 'no', $settings['enabled'] );
		$this->assertSame( 'yes', $settings['include_full_description'] );
	}

	/**
	 * Sanitize keeps only known keys and casts values to yes/no.
	 */
	public function test_sanitize_strips_unknown_keys_and_casts_values() {
		$settings = new Settings();

		$clean = $settings->sanitize(
			array(
				'enabled'        => '1',
				'include_images' => 'off',
				'evil'           => '<script>alert(1)</script>',
			)
		);

		$this->assertSame( array_keys( Settings::get_defaults() ), array_keys( $clean ), 'Sanitize must return exactly the known keys.' );
		$this->assertArrayNotHasKey( 'evil', $clean );
		$this->assertSame( 'yes', $clean['enabled'] );
		$this->assertSame( 'no', $clean['include_images'] );
	}

	/**
	 * Sanitize survives non-array garbage input by returning defaults.
	 */
	public function test_sanitize_handles_non_array_input() {
		$settings = new Settings();

		$clean = $settings->sanitize( 'not-an-array' );

		$this->assertSame( 'yes', $clean['enabled'] );
		$this->assertSame( 'yes', $clean['include_full_description'] );
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
	 * The WooCommerce products tab gains our section.
	 */
	public function test_wc_section_added() {
		$settings = new Settings();

		$sections = $settings->add_wc_section( array( '' => 'General' ) );

		$this->assertArrayHasKey( Settings::SECTION_ID, $sections );
	}

	/**
	 * Our section returns the full field set; other sections pass through.
	 */
	public function test_wc_settings_fields_for_section() {
		$settings = new Settings();

		$passthrough = $settings->add_wc_settings( array( 'untouched' ), 'inventory' );
		$this->assertSame( array( 'untouched' ), $passthrough );

		$fields = $settings->add_wc_settings( array(), Settings::SECTION_ID );
		$ids    = wp_list_pluck( $fields, 'id' );

		$this->assertContains( Settings::OPTION_NAME . '[enabled]', $ids );
		$this->assertContains( Settings::OPTION_NAME . '[include_full_description]', $ids );
		$this->assertContains( Settings::OPTION_NAME . '[include_images]', $ids );
		$this->assertContains( Settings::OPTION_NAME . '[mirror_categories]', $ids );

		if ( taxonomy_exists( 'product_brand' ) ) {
			$this->assertContains( Settings::OPTION_NAME . '[mirror_brands]', $ids );
		} else {
			$this->assertNotContains( Settings::OPTION_NAME . '[mirror_brands]', $ids );
		}
	}

	/**
	 * The plugins screen gets a Settings action link to the WC section.
	 */
	public function test_settings_action_link() {
		$settings = new Settings();

		$links = $settings->add_action_links( array( '<a href="#">Deactivate</a>' ) );

		$this->assertCount( 2, $links );
		$this->assertStringContainsString( 'wc-settings', $links[0] );
		$this->assertStringContainsString( 'section=' . Settings::SECTION_ID, $links[0] );
		$this->assertStringContainsString( '>Settings<', $links[0] );
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
	 * term_mirrors_enabled: defaults on, merchant can disable, unknown taxonomies always false.
	 */
	public function test_term_mirrors_enabled_defaults() {
		$this->assertTrue( Settings::term_mirrors_enabled( 'product_cat' ) );
		$this->assertTrue( Settings::term_mirrors_enabled( 'product_tag' ) );
		$this->assertFalse( Settings::term_mirrors_enabled( 'post_tag' ), 'Unknown taxonomies are never enabled.' );

		update_option( Settings::OPTION_NAME, array( 'mirror_tags' => 'no' ) );

		$this->assertTrue( Settings::term_mirrors_enabled( 'product_cat' ) );
		$this->assertFalse( Settings::term_mirrors_enabled( 'product_tag' ) );
	}

	/**
	 * product_mirror_args mirrors the section toggles.
	 */
	public function test_product_mirror_args_follow_toggles() {
		$args = Settings::product_mirror_args();

		$this->assertTrue( $args['include_images'] );
		$this->assertTrue( $args['include_full_description'] );

		update_option( Settings::OPTION_NAME, array( 'include_images' => 'no' ) );

		$args = Settings::product_mirror_args();

		$this->assertFalse( $args['include_images'] );
		$this->assertTrue( $args['include_reviews'] );
	}

	/**
	 * register_hooks() wires the WooCommerce settings filters.
	 */
	public function test_hooks_are_registered() {
		$settings = new Settings();
		$settings->register_hooks();

		$this->assertNotFalse( has_action( 'admin_init', array( $settings, 'register_settings' ) ) );
		$this->assertNotFalse( has_filter( 'woocommerce_get_sections_products', array( $settings, 'add_wc_section' ) ) );
		$this->assertNotFalse( has_filter( 'woocommerce_get_settings_products', array( $settings, 'add_wc_settings' ) ) );
		$this->assertNotFalse( has_action( 'woocommerce_admin_field_product_markdown_mirror_conflict_status', array( $settings, 'render_conflict_status' ) ) );
	}

	/**
	 * The settings screen carries the conflict status row.
	 */
	public function test_wc_settings_fields_include_conflict_status_row() {
		$settings = new Settings();

		$fields = $settings->add_wc_settings( array(), Settings::SECTION_ID );
		$types  = wp_list_pluck( $fields, 'type' );

		$this->assertContains( 'product_markdown_mirror_conflict_status', $types, 'The settings screen must carry the conflict status row.' );
	}

	/**
	 * Status renders Good when no conflicting plugin is active.
	 */
	public function test_conflict_status_renders_good() {
		$settings = new Settings();

		ob_start();
		$settings->render_conflict_status();
		$html = ob_get_clean();

		$this->assertStringContainsString( '>Good<', $html );
		$this->assertStringNotContainsString( '>Conflict<', $html );
	}

	/**
	 * Status renders Conflict and names the other plugin when one is active.
	 */
	public function test_conflict_status_renders_conflict_with_reason() {
		add_filter(
			'product_markdown_mirror_conflicting_plugins',
			static function ( $slugs ) {
				$slugs[] = 'fake-md-server';
				return $slugs;
			}
		);

		update_option( 'active_plugins', array_merge( (array) get_option( 'active_plugins', array() ), array( 'fake-md-server/fake-md-server.php' ) ) );

		$settings = new Settings();

		ob_start();
		$settings->render_conflict_status();
		$html = ob_get_clean();

		$this->assertStringContainsString( '>Conflict<', $html );
		$this->assertStringContainsString( 'fake-md-server', $html );
		$this->assertStringNotContainsString( '>Good<', $html );
	}
}
