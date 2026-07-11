/**
 * Playwright config for E2E tests against the wp-env dev site.
 */
const { defineConfig } = require( '@playwright/test' );

module.exports = defineConfig( {
	testDir: __dirname,
	timeout: 30000,
	retries: 0,
	workers: 1,
	reporter: [ [ 'list' ] ],
	use: {
		baseURL: process.env.WP_BASE_URL || 'http://localhost:8890',
	},
} );
