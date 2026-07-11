<?php
/**
 * Settings: option schema, sanitization, and the WooCommerce settings section.
 *
 * @package AgentMint\ProductMarkdownMirror
 */

namespace AgentMint\ProductMarkdownMirror;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin settings backed by one option, rendered as a native WooCommerce
 * settings section (WooCommerce, Settings, Products, Markdown mirrors).
 *
 * The option deliberately holds only inclusion toggles. No setting can alter
 * mirror CONTENT beyond omitting sections: the equivalence guard (mirrors say
 * exactly what the page says) is structural and not configurable. Everything
 * defaults on; the merchant chooses what to switch off.
 */
class Settings {

	/**
	 * Option name holding all plugin settings.
	 *
	 * @var string
	 */
	const OPTION_NAME = 'product_markdown_mirror_settings';

	/**
	 * Settings group used with register_setting (sanitize wiring).
	 *
	 * @var string
	 */
	const OPTION_GROUP = 'product_markdown_mirror';

	/**
	 * WooCommerce products-tab section id.
	 *
	 * @var string
	 */
	const SECTION_ID = 'markdown-mirror';

	/**
	 * Default values for every known setting: everything on.
	 *
	 * @return array<string, string>
	 */
	public static function get_defaults() {
		$defaults = array(
			'enabled'           => 'yes',
			'mirror_categories' => 'yes',
			'mirror_brands'     => 'yes',
			'mirror_tags'       => 'yes',
		);

		foreach ( array_keys( self::product_section_fields() ) as $key ) {
			$defaults[ $key ] = 'yes';
		}

		return $defaults;
	}

	/**
	 * The toggleable product mirror sections: option key => field definition.
	 *
	 * The header and the canonical footer are the document's skeleton and are
	 * not toggleable.
	 *
	 * @return array<string, array{label: string, description: string}>
	 */
	public static function product_section_fields() {
		return array(
			'include_identifiers'       => array(
				'label'       => __( 'Identifiers', 'product-markdown-mirror' ),
				'description' => __( 'GTIN, SKU, and brand', 'product-markdown-mirror' ),
			),
			'include_classification'    => array(
				'label'       => __( 'Classification', 'product-markdown-mirror' ),
				'description' => __( 'Categories and tags with links', 'product-markdown-mirror' ),
			),
			'include_specifications'    => array(
				'label'       => __( 'Specifications', 'product-markdown-mirror' ),
				'description' => __( 'Visible attributes, weight, dimensions', 'product-markdown-mirror' ),
			),
			'include_price'             => array(
				'label'       => __( 'Price', 'product-markdown-mirror' ),
				'description' => __( 'Price, currency, sale windows, tax display', 'product-markdown-mirror' ),
			),
			'include_availability'      => array(
				'label'       => __( 'Availability', 'product-markdown-mirror' ),
				'description' => __( 'Stock status as your store displays it', 'product-markdown-mirror' ),
			),
			'include_variants'          => array(
				'label'       => __( 'Variants', 'product-markdown-mirror' ),
				'description' => __( 'Per-variation lines for variable products', 'product-markdown-mirror' ),
			),
			'include_reviews'           => array(
				'label'       => __( 'Reviews', 'product-markdown-mirror' ),
				'description' => __( 'Average rating and review count', 'product-markdown-mirror' ),
			),
			'include_images'            => array(
				'label'       => __( 'Images', 'product-markdown-mirror' ),
				'description' => __( 'Main and gallery image URLs with alt text', 'product-markdown-mirror' ),
			),
			'include_short_description' => array(
				'label'       => __( 'Short description', 'product-markdown-mirror' ),
				'description' => __( 'The product short description as plain text', 'product-markdown-mirror' ),
			),
			'include_full_description'  => array(
				'label'       => __( 'Full description', 'product-markdown-mirror' ),
				'description' => __( 'The full product description as plain text', 'product-markdown-mirror' ),
			),
		);
	}

	/**
	 * Renderer args derived from the section toggles.
	 *
	 * @return array<string, bool>
	 */
	public static function product_mirror_args() {
		$settings = self::get_settings();
		$args     = array();

		foreach ( array_keys( self::product_section_fields() ) as $key ) {
			$args[ $key ] = 'yes' === $settings[ $key ];
		}

		return $args;
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
	 * Whether term mirrors are enabled for a taxonomy.
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
	 * URL of the plugin's WooCommerce settings section.
	 *
	 * @return string
	 */
	public static function settings_url() {
		return admin_url( 'admin.php?page=wc-settings&tab=products&section=' . self::SECTION_ID );
	}

	/**
	 * Hook registration.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_filter( 'woocommerce_get_sections_products', array( $this, 'add_wc_section' ) );
		add_filter( 'woocommerce_get_settings_products', array( $this, 'add_wc_settings' ), 10, 2 );
		add_filter( 'plugin_action_links_' . plugin_basename( PRODUCT_MARKDOWN_MIRROR_FILE ), array( $this, 'add_action_links' ) );

		// Toggling groups adds or removes public routes, so a deferred rewrite
		// flush is queued whenever the option changes (update_option_* skips
		// first-time saves, hence both hooks).
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
	 * Register the option so every save path runs through our sanitizer.
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
	}

	/**
	 * Add the section to WooCommerce, Settings, Products.
	 *
	 * @param array $sections Existing sections.
	 * @return array
	 */
	public function add_wc_section( $sections ) {
		$sections[ self::SECTION_ID ] = __( 'Markdown mirrors', 'product-markdown-mirror' );

		return $sections;
	}

	/**
	 * The WooCommerce settings fields for our section.
	 *
	 * @param array  $settings        Settings passed through the filter.
	 * @param string $current_section Current section id.
	 * @return array
	 */
	public function add_wc_settings( $settings, $current_section ) {
		if ( self::SECTION_ID !== $current_section ) {
			return $settings;
		}

		$fields = array(
			array(
				'title' => __( 'Markdown mirrors', 'product-markdown-mirror' ),
				'type'  => 'title',
				'id'    => 'product_markdown_mirror_title',
				'desc'  => __( 'Serves a read-only Markdown copy of product and archive pages at the page URL plus .md. Mirrors always carry exactly the facts the page shows; nothing here can make them differ. Sections with no data are omitted automatically.', 'product-markdown-mirror' ),
			),
			array(
				'title'   => __( 'Enable mirrors', 'product-markdown-mirror' ),
				'desc'    => __( 'Serve .md mirrors for published products', 'product-markdown-mirror' ),
				'id'      => self::OPTION_NAME . '[enabled]',
				'type'    => 'checkbox',
				'default' => 'yes',
			),
		);

		$first = true;
		foreach ( self::product_section_fields() as $key => $field ) {
			$fields[] = array(
				'title'         => $first ? __( 'Mirror content', 'product-markdown-mirror' ) : '',
				'desc'          => $field['label'] . ' - ' . $field['description'],
				'id'            => self::OPTION_NAME . '[' . $key . ']',
				'type'          => 'checkbox',
				'default'       => 'yes',
				'checkboxgroup' => $first ? 'start' : '',
			);
			$first    = false;
		}

		$fields[ count( $fields ) - 1 ]['checkboxgroup'] = 'end';

		$taxonomy_toggles = array(
			'mirror_categories' => array( 'product_cat', __( 'Category archive mirrors (hierarchical paths included)', 'product-markdown-mirror' ) ),
			'mirror_brands'     => array( 'product_brand', __( 'Brand archive mirrors', 'product-markdown-mirror' ) ),
			'mirror_tags'       => array( 'product_tag', __( 'Tag archive mirrors', 'product-markdown-mirror' ) ),
		);

		$first = true;
		foreach ( $taxonomy_toggles as $key => $toggle ) {
			if ( ! taxonomy_exists( $toggle[0] ) ) {
				continue;
			}

			$fields[] = array(
				'title'         => $first ? __( 'Taxonomy mirrors', 'product-markdown-mirror' ) : '',
				'desc'          => $toggle[1],
				'id'            => self::OPTION_NAME . '[' . $key . ']',
				'type'          => 'checkbox',
				'default'       => 'yes',
				'checkboxgroup' => $first ? 'start' : '',
			);
			$first    = false;
		}

		$fields[ count( $fields ) - 1 ]['checkboxgroup'] = 'end';

		$fields[] = array(
			'type' => 'sectionend',
			'id'   => 'product_markdown_mirror_sectionend',
		);

		return $fields;
	}

	/**
	 * Add the Settings action link on the plugins screen.
	 *
	 * @param array $links Existing action links.
	 * @return array
	 */
	public function add_action_links( $links ) {
		array_unshift(
			$links,
			'<a href="' . esc_url( self::settings_url() ) . '">' . esc_html__( 'Settings', 'product-markdown-mirror' ) . '</a>'
		);

		return $links;
	}

	/**
	 * Sanitize the raw option input: known keys only, values cast to yes/no.
	 *
	 * @param mixed $input Raw input.
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
}
