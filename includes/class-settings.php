<?php
/**
 * Settings: option schema, sanitization, and the WooCommerce settings section.
 *
 * @package AgentMint\MarkdownMirrorWC
 */

namespace AgentMint\MarkdownMirrorWC;

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
	const OPTION_NAME = 'mdmirwc_settings';

	/**
	 * Settings group used with register_setting (sanitize wiring).
	 *
	 * @var string
	 */
	const OPTION_GROUP = 'mdmirwc';

	/**
	 * WooCommerce products-tab section id.
	 *
	 * @var string
	 */
	const SECTION_ID = 'mdmirwc';

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
				'label'       => __( 'Identifiers', 'markdown-mirror-for-woocommerce' ),
				'description' => __( 'GTIN, SKU, and brand', 'markdown-mirror-for-woocommerce' ),
			),
			'include_classification'    => array(
				'label'       => __( 'Classification', 'markdown-mirror-for-woocommerce' ),
				'description' => __( 'Categories and tags with links', 'markdown-mirror-for-woocommerce' ),
			),
			'include_specifications'    => array(
				'label'       => __( 'Specifications', 'markdown-mirror-for-woocommerce' ),
				'description' => __( 'Visible attributes, weight, dimensions', 'markdown-mirror-for-woocommerce' ),
			),
			'include_price'             => array(
				'label'       => __( 'Price', 'markdown-mirror-for-woocommerce' ),
				'description' => __( 'Price, currency, sale windows, tax display', 'markdown-mirror-for-woocommerce' ),
			),
			'include_availability'      => array(
				'label'       => __( 'Availability', 'markdown-mirror-for-woocommerce' ),
				'description' => __( 'Stock status as your store displays it', 'markdown-mirror-for-woocommerce' ),
			),
			'include_variants'          => array(
				'label'       => __( 'Variants', 'markdown-mirror-for-woocommerce' ),
				'description' => __( 'Per-variation lines for variable products', 'markdown-mirror-for-woocommerce' ),
			),
			'include_reviews'           => array(
				'label'       => __( 'Reviews', 'markdown-mirror-for-woocommerce' ),
				'description' => __( 'Average rating and review count', 'markdown-mirror-for-woocommerce' ),
			),
			'include_images'            => array(
				'label'       => __( 'Images', 'markdown-mirror-for-woocommerce' ),
				'description' => __( 'Main and gallery image URLs with alt text', 'markdown-mirror-for-woocommerce' ),
			),
			'include_short_description' => array(
				'label'       => __( 'Short description', 'markdown-mirror-for-woocommerce' ),
				'description' => __( 'The product short description as plain text', 'markdown-mirror-for-woocommerce' ),
			),
			'include_full_description'  => array(
				'label'       => __( 'Full description', 'markdown-mirror-for-woocommerce' ),
				'description' => __( 'The full product description as plain text', 'markdown-mirror-for-woocommerce' ),
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
		add_action( 'woocommerce_admin_field_mdmirwc_conflict_status', array( $this, 'render_conflict_status' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( MDMIRWC_FILE ), array( $this, 'add_action_links' ) );

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
		update_option( 'mdmirwc_flush_needed', 'yes', false );
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
		$sections[ self::SECTION_ID ] = __( 'Markdown mirrors', 'markdown-mirror-for-woocommerce' );

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
				'title' => __( 'Markdown mirrors', 'markdown-mirror-for-woocommerce' ),
				'type'  => 'title',
				'id'    => 'mdmirwc_title',
				'desc'  => __( 'Serves a read-only Markdown copy of product and archive pages at the page URL plus .md. Mirrors always carry exactly the facts the page shows; nothing here can make them differ. Sections with no data are omitted automatically.', 'markdown-mirror-for-woocommerce' ),
			),
			array(
				'type' => 'mdmirwc_conflict_status',
				'id'   => 'mdmirwc_conflict_status',
			),
			array(
				'title'   => __( 'Enable mirrors', 'markdown-mirror-for-woocommerce' ),
				'desc'    => __( 'Serve .md mirrors for published products', 'markdown-mirror-for-woocommerce' ),
				'id'      => self::OPTION_NAME . '[enabled]',
				'type'    => 'checkbox',
				'default' => 'yes',
			),
		);

		$first = true;
		foreach ( self::product_section_fields() as $key => $field ) {
			$fields[] = array(
				'title'         => $first ? __( 'Mirror content', 'markdown-mirror-for-woocommerce' ) : '',
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
			'mirror_categories' => array( 'product_cat', __( 'Category archive mirrors (hierarchical paths included)', 'markdown-mirror-for-woocommerce' ) ),
			'mirror_brands'     => array( 'product_brand', __( 'Brand archive mirrors', 'markdown-mirror-for-woocommerce' ) ),
			'mirror_tags'       => array( 'product_tag', __( 'Tag archive mirrors', 'markdown-mirror-for-woocommerce' ) ),
		);

		$first = true;
		foreach ( $taxonomy_toggles as $key => $toggle ) {
			if ( ! taxonomy_exists( $toggle[0] ) ) {
				continue;
			}

			$fields[] = array(
				'title'         => $first ? __( 'Taxonomy mirrors', 'markdown-mirror-for-woocommerce' ) : '',
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
			'id'   => 'mdmirwc_sectionend',
		);

		return $fields;
	}

	/**
	 * Render the status row on the settings screen.
	 *
	 * This is the plugin's only conflict reporting surface (no admin
	 * notices): Good when this plugin is the sole .md server, Conflict
	 * naming the other plugin and the reason otherwise.
	 *
	 * @return void
	 */
	public function render_conflict_status() {
		$conflicts = new Conflicts();
		$detected  = $conflicts->detect();
		?>
		<tr class="markdown-mirror-for-woocommerce-status">
			<th scope="row" class="titledesc"><?php esc_html_e( 'Status', 'markdown-mirror-for-woocommerce' ); ?></th>
			<td class="forminp">
				<?php if ( empty( $detected ) ) : ?>
					<strong style="color: #00a32a;"><?php esc_html_e( 'Good', 'markdown-mirror-for-woocommerce' ); ?></strong>
					<p class="description"><?php esc_html_e( 'No conflicts detected. This plugin is the only active plugin serving .md URLs.', 'markdown-mirror-for-woocommerce' ); ?></p>
				<?php else : ?>
					<strong style="color: #d63638;"><?php esc_html_e( 'Conflict', 'markdown-mirror-for-woocommerce' ); ?></strong>
					<p class="description">
						<?php
						printf(
							/* translators: %s: comma-separated plugin slugs. */
							esc_html__( 'Another active plugin also serves .md URLs (%s). Only one plugin should own that suffix: which one answers depends on rewrite rule order, so mirrors may be served by the other plugin. Keep one and deactivate the other.', 'markdown-mirror-for-woocommerce' ),
							esc_html( implode( ', ', $detected ) )
						);
						?>
					</p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
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
			'<a href="' . esc_url( self::settings_url() ) . '">' . esc_html__( 'Settings', 'markdown-mirror-for-woocommerce' ) . '</a>'
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
