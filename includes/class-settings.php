<?php
/**
 * Settings: option schema, sanitization, and the WooCommerce submenu page.
 *
 * @package AgentMint\ProductMarkdownMirror
 */

namespace AgentMint\ProductMarkdownMirror;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin settings backed by one option, rendered via the Settings API.
 *
 * The option deliberately holds only presentation toggles. No setting can
 * alter mirror CONTENT: the equivalence guard (mirrors say exactly what the
 * page says) is structural and not configurable.
 */
class Settings {

	/**
	 * Option name holding all plugin settings.
	 *
	 * @var string
	 */
	const OPTION_NAME = 'product_markdown_mirror_settings';

	/**
	 * Settings group used with the Settings API.
	 *
	 * @var string
	 */
	const OPTION_GROUP = 'product_markdown_mirror';

	/**
	 * Admin page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'product-markdown-mirror';

	/**
	 * Default values for every known setting.
	 *
	 * @return array<string, string>
	 */
	public static function get_defaults() {
		return array(
			'enabled'             => 'yes',
			'include_description' => 'yes',
			'mirror_categories'   => 'no',
			'mirror_brands'       => 'no',
			'mirror_tags'         => 'no',
		);
	}

	/**
	 * Whether term mirrors are enabled for a taxonomy.
	 *
	 * Off by default for every group: enabling public routes is the
	 * merchant's explicit choice.
	 *
	 * @param string $taxonomy Taxonomy name (product_cat, product_brand, product_tag).
	 * @return bool
	 */
	public static function term_mirrors_enabled( $taxonomy ) {
		$map = array(
			'product_cat'   => 'mirror_categories',
			'product_brand' => 'mirror_brands',
			'product_tag'   => 'mirror_tags',
		);

		if ( ! isset( $map[ $taxonomy ] ) ) {
			return false;
		}

		$settings = self::get_settings();

		return 'yes' === $settings[ $map[ $taxonomy ] ];
	}

	/**
	 * Current settings merged over defaults.
	 *
	 * @return array<string, string>
	 */
	public static function get_settings() {
		$stored = get_option( self::OPTION_NAME, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return wp_parse_args( $stored, self::get_defaults() );
	}

	/**
	 * Whether the mirror surface is enabled at all.
	 *
	 * @return bool
	 */
	public static function mirrors_enabled() {
		$settings = self::get_settings();

		return 'yes' === $settings['enabled'];
	}

	/**
	 * Whether mirrors include the short description section.
	 *
	 * @return bool
	 */
	public static function include_description() {
		$settings = self::get_settings();

		return 'yes' === $settings['include_description'];
	}

	/**
	 * Hook registration for admin screens.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_filter( 'option_page_capability_' . self::OPTION_GROUP, array( $this, 'option_page_capability' ) );

		// Toggling taxonomy groups adds or removes public routes, so a
		// deferred rewrite flush is queued whenever the option changes
		// (update_option_* skips first-time saves, hence both hooks).
		add_action( 'update_option_' . self::OPTION_NAME, array( $this, 'queue_rewrite_flush' ) );
		add_action( 'add_option_' . self::OPTION_NAME, array( $this, 'queue_rewrite_flush' ) );
	}

	/**
	 * Queue the deferred rewrite flush consumed on the next request's init.
	 *
	 * @return void
	 */
	public function queue_rewrite_flush() {
		update_option( 'product_markdown_mirror_flush_needed', 'yes', false );
	}

	/**
	 * Align the save capability with the menu capability.
	 *
	 * The options.php handler requires manage_options by default; the page is
	 * offered to manage_woocommerce users, so saving must be too.
	 *
	 * @return string
	 */
	public function option_page_capability() {
		return 'manage_woocommerce';
	}

	/**
	 * Sanitize the raw option input: known keys only, values cast to yes/no.
	 *
	 * @param mixed $input Raw input from the Settings API.
	 * @return array<string, string>
	 */
	public function sanitize( $input ) {
		$defaults = self::get_defaults();

		if ( ! is_array( $input ) ) {
			return $defaults;
		}

		$clean = array();

		foreach ( $defaults as $key => $default_value ) {
			if ( ! array_key_exists( $key, $input ) ) {
				$clean[ $key ] = 'no'; // Unchecked checkboxes are absent from the request.
				continue;
			}

			$clean[ $key ] = $this->to_yes_no( $input[ $key ] );
		}

		return $clean;
	}

	/**
	 * Cast a submitted value to the 'yes'/'no' vocabulary.
	 *
	 * @param mixed $value Submitted value.
	 * @return string
	 */
	private function to_yes_no( $value ) {
		if ( is_string( $value ) ) {
			$value = strtolower( trim( $value ) );
		}

		$truthy = in_array( $value, array( 'yes', '1', 1, true, 'true', 'on' ), true );

		return $truthy ? 'yes' : 'no';
	}

	/**
	 * Register the setting, section, and fields.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => self::get_defaults(),
			)
		);

		add_settings_section(
			'product_markdown_mirror_main',
			__( 'Mirror output', 'product-markdown-mirror' ),
			array( $this, 'render_section_intro' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'enabled',
			__( 'Serve product mirrors', 'product-markdown-mirror' ),
			array( $this, 'render_enabled_field' ),
			self::PAGE_SLUG,
			'product_markdown_mirror_main'
		);

		add_settings_field(
			'include_description',
			__( 'Include short description', 'product-markdown-mirror' ),
			array( $this, 'render_include_description_field' ),
			self::PAGE_SLUG,
			'product_markdown_mirror_main'
		);

		add_settings_section(
			'product_markdown_mirror_terms',
			__( 'Taxonomy mirrors', 'product-markdown-mirror' ),
			array( $this, 'render_terms_section_intro' ),
			self::PAGE_SLUG
		);

		foreach ( $this->taxonomy_fields() as $key => $field ) {
			if ( ! taxonomy_exists( $field['taxonomy'] ) ) {
				continue;
			}

			add_settings_field(
				$key,
				$field['label'],
				array( $this, 'render_taxonomy_field' ),
				self::PAGE_SLUG,
				'product_markdown_mirror_terms',
				array(
					'key'         => $key,
					'description' => $field['description'],
				)
			);
		}
	}

	/**
	 * The taxonomy toggle field definitions.
	 *
	 * @return array<string, array{taxonomy: string, label: string, description: string}>
	 */
	private function taxonomy_fields() {
		return array(
			'mirror_categories' => array(
				'taxonomy'    => 'product_cat',
				'label'       => __( 'Category mirrors', 'product-markdown-mirror' ),
				'description' => __( 'Serve .md mirrors for product category archives', 'product-markdown-mirror' ),
			),
			'mirror_brands'     => array(
				'taxonomy'    => 'product_brand',
				'label'       => __( 'Brand mirrors', 'product-markdown-mirror' ),
				'description' => __( 'Serve .md mirrors for brand archives', 'product-markdown-mirror' ),
			),
			'mirror_tags'       => array(
				'taxonomy'    => 'product_tag',
				'label'       => __( 'Tag mirrors', 'product-markdown-mirror' ),
				'description' => __( 'Serve .md mirrors for product tag archives', 'product-markdown-mirror' ),
			),
		);
	}

	/**
	 * Taxonomy mirrors section intro.
	 *
	 * @return void
	 */
	public function render_terms_section_intro() {
		echo '<p>';
		esc_html_e( 'Each group adds public .md mirrors for its archive pages: term name, description, subcategories, and a paginated product list (100 per page). All groups are off until you enable them.', 'product-markdown-mirror' );
		echo '</p>';
	}

	/**
	 * Render one taxonomy toggle field.
	 *
	 * @param array $args Field args: key, description.
	 * @return void
	 */
	public function render_taxonomy_field( $args ) {
		$key      = isset( $args['key'] ) ? (string) $args['key'] : '';
		$settings = self::get_settings();

		if ( '' === $key || ! array_key_exists( $key, $settings ) ) {
			return;
		}

		printf(
			'<label><input type="checkbox" name="%1$s[%2$s]" value="yes" %3$s /> %4$s</label>',
			esc_attr( self::OPTION_NAME ),
			esc_attr( $key ),
			checked( 'yes', $settings[ $key ], false ),
			esc_html( isset( $args['description'] ) ? (string) $args['description'] : '' )
		);
	}

	/**
	 * Add the settings page under the WooCommerce menu.
	 *
	 * @return void
	 */
	public function add_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Product Markdown Mirror', 'product-markdown-mirror' ),
			__( 'Markdown Mirror', 'product-markdown-mirror' ),
			'manage_woocommerce',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Section intro: what the plugin does and its honest boundary.
	 *
	 * @return void
	 */
	public function render_section_intro() {
		echo '<p>';
		esc_html_e( 'Serves a read-only Markdown copy of each product page at {product-url}.md. The mirror always carries exactly the facts the product page shows; nothing here can make them differ.', 'product-markdown-mirror' );
		echo '</p><p>';
		esc_html_e( 'Honest boundary: no verified public source shows a shopping agent fetches product Markdown today. Treat mirrors as inexpensive, standards-shaped groundwork, not a proven ranking lever.', 'product-markdown-mirror' );
		echo '</p>';
	}

	/**
	 * Render the master toggle field.
	 *
	 * @return void
	 */
	public function render_enabled_field() {
		$settings = self::get_settings();
		printf(
			'<label><input type="checkbox" name="%1$s[enabled]" value="yes" %2$s /> %3$s</label>',
			esc_attr( self::OPTION_NAME ),
			checked( 'yes', $settings['enabled'], false ),
			esc_html__( 'Serve .md mirrors for published products', 'product-markdown-mirror' )
		);
	}

	/**
	 * Render the description toggle field.
	 *
	 * @return void
	 */
	public function render_include_description_field() {
		$settings = self::get_settings();
		printf(
			'<label><input type="checkbox" name="%1$s[include_description]" value="yes" %2$s /> %3$s</label>',
			esc_attr( self::OPTION_NAME ),
			checked( 'yes', $settings['include_description'], false ),
			esc_html__( 'Append the product short description to each mirror', 'product-markdown-mirror' )
		);
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		echo '<div class="wrap"><h1>';
		esc_html_e( 'Product Markdown Mirror', 'product-markdown-mirror' );
		echo '</h1><form method="post" action="options.php">';

		settings_fields( self::OPTION_GROUP );
		do_settings_sections( self::PAGE_SLUG );
		submit_button();

		echo '</form></div>';
	}
}
