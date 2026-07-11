<?php
/**
 * Main plugin bootstrap class.
 *
 * @package AgentMint\MarkdownMirrorWC
 */

namespace AgentMint\MarkdownMirrorWC;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin together once WooCommerce is confirmed active.
 *
 * Components are loaded on plugins_loaded so the plugin has no side effects
 * at file load time beyond hook registration.
 */
final class Main {

	/**
	 * Shared instance.
	 *
	 * @var Main|null
	 */
	private static $instance = null;

	/**
	 * Whether components have been loaded.
	 *
	 * @var bool
	 */
	private $loaded = false;

	/**
	 * Return the shared instance, creating it on first call.
	 *
	 * @return Main
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register lifecycle hooks. Private: use instance().
	 */
	private function __construct() {
		add_action( 'before_woocommerce_init', array( $this, 'declare_woocommerce_compatibility' ) );
		add_action( 'plugins_loaded', array( $this, 'load' ) );
	}

	/**
	 * Declare compatibility with WooCommerce features (HPOS).
	 *
	 * The plugin never touches orders, but declaring compatibility prevents a
	 * false incompatibility warning on stores using custom order tables.
	 *
	 * @return void
	 */
	public function declare_woocommerce_compatibility() {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				MDMIRWC_FILE,
				true
			);
		}
	}

	/**
	 * Load plugin components once all plugins are available.
	 *
	 * Bails with an admin notice when WooCommerce is not active (belt and
	 * suspenders next to the Requires Plugins header).
	 *
	 * @return void
	 */
	public function load() {
		if ( $this->loaded ) {
			return;
		}

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
			return;
		}

		$this->loaded = true;
		$this->includes();
		$this->init_components();
	}

	/**
	 * Require component class files.
	 *
	 * @return void
	 */
	private function includes() {
		require_once MDMIRWC_ABSPATH . 'includes/class-settings.php';
		require_once MDMIRWC_ABSPATH . 'includes/class-renderer.php';
		require_once MDMIRWC_ABSPATH . 'includes/class-term-renderer.php';
		require_once MDMIRWC_ABSPATH . 'includes/class-response.php';
		require_once MDMIRWC_ABSPATH . 'includes/class-router.php';
		require_once MDMIRWC_ABSPATH . 'includes/class-term-router.php';
		require_once MDMIRWC_ABSPATH . 'includes/class-cache.php';
		require_once MDMIRWC_ABSPATH . 'includes/class-head-link.php';
		require_once MDMIRWC_ABSPATH . 'includes/class-conflicts.php';
	}

	/**
	 * Instantiate and hook up components.
	 *
	 * @return void
	 */
	private function init_components() {
		$settings = new Settings();
		$settings->register_hooks();

		$cache = new Cache();
		$cache->register_hooks();

		$router = new Router( new Renderer(), $cache );
		$router->register_hooks();

		$term_router = new Term_Router( new Term_Renderer(), $cache );
		$term_router->register_hooks();

		$head_link = new Head_Link();
		$head_link->register_hooks();
	}

	/**
	 * Whether the plugin finished loading its components.
	 *
	 * @return bool
	 */
	public function is_loaded() {
		return $this->loaded;
	}

	/**
	 * Admin notice shown when WooCommerce is missing.
	 *
	 * Core's Requires Plugins header already blocks activation without
	 * WooCommerce; this covers programmatic deactivation only. Shown to
	 * plugin managers on the Plugins screen alone, nowhere else.
	 *
	 * @return void
	 */
	public function woocommerce_missing_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'plugins' !== $screen->id ) {
			return;
		}

		echo '<div class="notice notice-error"><p>';
		esc_html_e( 'Markdown Mirror for WooCommerce requires WooCommerce to be installed and active.', 'markdown-mirror-for-woocommerce' );
		echo '</p></div>';
	}
}
