/**
 * E2E: the mirror contract on a real WordPress + WooCommerce site.
 *
 * Requires wp-env running (npm run env:start). Seeds its own product via
 * wp-env cli and restores plugin state afterwards.
 */
const { test, expect } = require( '@playwright/test' );
const { execSync } = require( 'child_process' );

const SLUG = 'e2e-mirror-widget';

/**
 * Run a wp-cli command inside the wp-env dev instance.
 *
 * @param {string} command wp-cli command (without the leading "wp").
 * @return {string} Trimmed stdout.
 */
function wp( command ) {
	return execSync( `npx wp-env run cli wp ${ command }`, {
		cwd: `${ __dirname }/../..`,
		encoding: 'utf8',
		stdio: [ 'ignore', 'pipe', 'pipe' ],
	} ).trim();
}

test.describe( 'Product Markdown Mirror', () => {
	test.beforeAll( () => {
		wp( 'plugin activate product-markdown-mirror' );
		wp( 'rewrite structure /%postname%/ --hard' );
		wp( 'option delete product_markdown_mirror_settings' );

		// Idempotent seed: delete leftovers, then create fresh.
		const existing = wp(
			`post list --post_type=product --name=${ SLUG } --field=ID`
		);
		if ( existing ) {
			wp( `post delete ${ existing } --force` );
		}

		wp(
			`wc product create --name="E2E Mirror Widget" --slug=${ SLUG } --type=simple --regular_price=42.00 --sku=E2E-${ Date.now() } --status=publish --user=admin --porcelain`
		);
	} );

	test( 'serves the mirror with the full header contract', async ( {
		request,
	} ) => {
		const response = await request.get( `/product/${ SLUG }.md` );

		expect( response.status() ).toBe( 200 );

		const headers = response.headers();
		expect( headers[ 'content-type' ] ).toContain( 'text/markdown' );
		expect( headers[ 'x-robots-tag' ] ).toContain( 'noindex' );
		expect( headers[ 'x-content-type-options' ] ).toBe( 'nosniff' );
		expect( headers.link ).toContain( 'rel="canonical"' );
		expect( headers.link ).toContain( `/product/${ SLUG }/` );
		expect( headers[ 'cache-control' ] ).toContain( 'max-age=' );

		const body = await response.text();
		expect( body ).toContain( '# E2E Mirror Widget' );
		expect( body ).toContain( '## Price' );
		expect( body ).toContain( '42.00' );
		expect( body ).toContain( `Canonical: ` );
	} );

	test( 'returns an honest 404 for unknown products', async ( {
		request,
	} ) => {
		const response = await request.get( '/product/definitely-not-real.md' );

		expect( response.status() ).toBe( 404 );
	} );

	test( 'product page advertises the mirror via rel=alternate', async ( {
		page,
	} ) => {
		await page.goto( `/product/${ SLUG }/` );

		const alternate = page.locator(
			'link[rel="alternate"][type="text/markdown"]'
		);
		await expect( alternate ).toHaveAttribute(
			'href',
			new RegExp( `/product/${ SLUG }\\.md$` )
		);
	} );

	test( 'master toggle turns the surface off and on', async ( {
		request,
	} ) => {
		wp(
			`option update product_markdown_mirror_settings '{"enabled":"no"}' --format=json`
		);

		const off = await request.get( `/product/${ SLUG }.md` );
		expect( off.status() ).toBe( 404 );

		wp(
			`option update product_markdown_mirror_settings '{"enabled":"yes"}' --format=json`
		);

		const on = await request.get( `/product/${ SLUG }.md` );
		expect( on.status() ).toBe( 200 );
	} );

	test( 'mirror reflects a price change (cache invalidation)', async ( {
		request,
	} ) => {
		// Prime the cache.
		await request.get( `/product/${ SLUG }.md` );

		const id = wp(
			`post list --post_type=product --name=${ SLUG } --field=ID`
		);
		wp( `wc product update ${ id } --regular_price=43.50 --user=admin` );

		const response = await request.get( `/product/${ SLUG }.md` );
		const body = await response.text();

		expect( body ).toContain( '43.50' );
	} );
} );
