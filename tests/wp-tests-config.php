<?php
/**
 * WordPress test-suite configuration for the wp-env "tests" instance.
 *
 * Values match @wordpress/env defaults for the tests environment; each can be
 * overridden with an environment variable for other setups (CI).
 *
 * @package AgentMint\MarkdownMirrorWC\Tests
 */

define( 'DB_NAME', getenv( 'WP_TESTS_DB_NAME' ) ? getenv( 'WP_TESTS_DB_NAME' ) : 'tests-wordpress' );
define( 'DB_USER', getenv( 'WP_TESTS_DB_USER' ) ? getenv( 'WP_TESTS_DB_USER' ) : 'root' );
define( 'DB_PASSWORD', false !== getenv( 'WP_TESTS_DB_PASSWORD' ) ? getenv( 'WP_TESTS_DB_PASSWORD' ) : 'password' );
define( 'DB_HOST', getenv( 'WP_TESTS_DB_HOST' ) ? getenv( 'WP_TESTS_DB_HOST' ) : 'tests-mysql' );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );

$table_prefix = 'wptests_'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

define( 'ABSPATH', getenv( 'WP_TESTS_ABSPATH' ) ? getenv( 'WP_TESTS_ABSPATH' ) : '/var/www/html/' );

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Test Blog' );
define( 'WP_PHP_BINARY', 'php' );

define( 'WP_DEBUG', true );
