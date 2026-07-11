<?php
/**
 * PHPUnit bootstrap: WordPress test suite (wp-phpunit) + WooCommerce + this plugin.
 *
 * @package AgentMint\ProductMarkdownMirror\Tests
 */

$pmm_plugin_dir = dirname( __DIR__ );

require_once $pmm_plugin_dir . '/vendor/autoload.php';

if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $pmm_plugin_dir . '/vendor/yoast/phpunit-polyfills' );
}

$pmm_wp_phpunit_dir = getenv( 'WP_PHPUNIT__DIR' );
if ( ! $pmm_wp_phpunit_dir ) {
	$pmm_wp_phpunit_dir = $pmm_plugin_dir . '/vendor/wp-phpunit/wp-phpunit';
}

require_once $pmm_wp_phpunit_dir . '/includes/functions.php';

/**
 * Locate the WooCommerce main file next to this plugin.
 *
 * wp-env mounts zip-sourced plugins under their download basename (for
 * example "woocommerce.latest-stable"), so the directory name varies.
 *
 * @param string $plugins_dir Path to the plugins directory.
 * @return string Path to woocommerce.php.
 */
function pmm_locate_woocommerce( $plugins_dir ) {
	$env_path = getenv( 'WP_TESTS_WC_PATH' );
	if ( $env_path && file_exists( $env_path ) ) {
		return $env_path;
	}

	$candidates = glob( $plugins_dir . '/*/woocommerce.php' );
	if ( ! empty( $candidates ) ) {
		return $candidates[0];
	}

	fwrite( STDERR, "WooCommerce not found under {$plugins_dir}; set WP_TESTS_WC_PATH.\n" );
	exit( 1 );
}

/**
 * Load WooCommerce and the plugin under test as if they were mu-plugins.
 */
tests_add_filter(
	'muplugins_loaded',
	static function () use ( $pmm_plugin_dir ) {
		require_once pmm_locate_woocommerce( dirname( $pmm_plugin_dir ) );
		require_once $pmm_plugin_dir . '/product-markdown-mirror.php';
	}
);

/**
 * Install WooCommerce tables and refresh roles before tests run.
 */
tests_add_filter(
	'setup_theme',
	static function () {
		define( 'WP_UNINSTALL_PLUGIN', false ); // Guard flag some installers check; not our uninstall path.
		WC_Install::install();
		if ( class_exists( '\Automattic\WooCommerce\Internal\Admin\Install' ) ) {
			\Automattic\WooCommerce\Internal\Admin\Install::create_tables();
			\Automattic\WooCommerce\Internal\Admin\Install::create_events();
		}
		$GLOBALS['wp_roles'] = new WP_Roles(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		echo esc_html( 'Installing WooCommerce ' . WC()->version . PHP_EOL );
	}
);

require $pmm_wp_phpunit_dir . '/includes/bootstrap.php';
