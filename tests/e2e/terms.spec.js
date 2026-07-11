/**
 * E2E: taxonomy mirrors (categories, tags) on a real site.
 *
 * Requires wp-env running. Seeds terms and products via wp-env cli.
 */
const { test, expect } = require( '@playwright/test' );
const { execSync } = require( 'child_process' );

const PARENT = 'e2e-gear';
const CHILD = 'e2e-kettles';
const TAG = 'e2e-fresh';

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

/**
 * Last line of a wp-cli porcelain response (wp-env prefixes its own info line).
 *
 * @param {string} output Raw output.
 * @return {string}
 */
function lastLine( output ) {
	const lines = ( output || '' ).split( '\n' ).filter( ( l ) => l.trim() );
	return lines.length ? lines[ lines.length - 1 ].trim() : '';
}

test.describe( 'Taxonomy mirrors', () => {
	test.beforeAll( () => {
		wp( 'plugin activate product-markdown-mirror' );
		wp(
			`option update product_markdown_mirror_settings '{"enabled":"yes","mirror_categories":"yes","mirror_tags":"yes"}' --format=json`
		);
		wp( 'rewrite structure /%postname%/ --hard' );

		// Idempotent seed: remove leftovers.
		for ( const slug of [ CHILD, PARENT ] ) {
			const existing = lastLine(
				wp( `term list product_cat --slug=${ slug } --field=term_id` ) || ''
			);
			if ( existing && /^[0-9]+$/.test( existing ) ) {
				wp( `term delete product_cat ${ existing }` );
			}
		}

		const parentId = lastLine(
			wp( `term create product_cat "E2E Gear" --slug=${ PARENT } --porcelain` )
		);
		const childId = lastLine(
			wp(
				`term create product_cat "E2E Kettles" --slug=${ CHILD } --parent=${ parentId } --porcelain`
			)
		);

		const productId = lastLine(
			wp(
				`wc product create --name="E2E Kettle One" --type=simple --regular_price=59.00 --status=publish --user=admin --porcelain`
			)
		);
		wp( `wc product update ${ productId } --categories='[{"id":${ childId }}]' --user=admin` );
		wp( `post term set ${ productId } product_tag ${ TAG }` );
	} );

	test( 'category mirror serves with the term header contract', async ( {
		request,
	} ) => {
		const response = await request.get(
			`/product-category/${ PARENT }/${ CHILD }.md`
		);

		expect( response.status() ).toBe( 200 );

		const headers = response.headers();
		expect( headers[ 'content-type' ] ).toContain( 'text/markdown' );
		expect( headers[ 'x-robots-tag' ] ).toContain( 'noindex' );
		expect( headers[ 'x-content-type-options' ] ).toBe( 'nosniff' );
		expect( headers.link ).toContain( 'rel="canonical"' );
		expect( headers[ 'last-modified' ] ).toBeUndefined();

		const body = await response.text();
		expect( body ).toContain( '# E2E Kettles' );
		expect( body ).toContain( 'Product category' );
		expect( body ).toContain( 'E2E Kettle One' );
		expect( body ).toContain( 'Parent category: E2E Gear' );
	} );

	test( 'parent category lists the child in Subcategories', async ( {
		request,
	} ) => {
		const response = await request.get( `/product-category/${ PARENT }.md` );

		expect( response.status() ).toBe( 200 );
		const body = await response.text();
		expect( body ).toContain( '## Subcategories' );
		expect( body ).toContain( `${ CHILD }.md` );
	} );

	test( 'wrong-parent alias returns an honest 404', async ( { request } ) => {
		const response = await request.get(
			`/product-category/wrong-parent/${ CHILD }.md`
		);

		expect( response.status() ).toBe( 404 );
	} );

	test( 'tag mirror serves', async ( { request } ) => {
		const response = await request.get( `/product-tag/${ TAG }.md` );

		expect( response.status() ).toBe( 200 );
		const body = await response.text();
		expect( body ).toContain( 'Product tag' );
		expect( body ).toContain( 'E2E Kettle One' );
	} );

	test( 'out-of-range mirror page 404s honestly', async ( { request } ) => {
		const response = await request.get(
			`/product-category/${ PARENT }/${ CHILD }/page/99.md`
		);

		expect( response.status() ).toBe( 404 );
	} );

	test( 'archive page advertises the mirror via rel=alternate', async ( {
		page,
	} ) => {
		await page.goto( `/product-category/${ PARENT }/${ CHILD }/` );

		const alternate = page.locator(
			'link[rel="alternate"][type="text/markdown"]'
		);
		await expect( alternate ).toHaveAttribute(
			'href',
			new RegExp( `${ CHILD }\\.md$` )
		);
	} );

	test( 'group toggle gates serving without a flush', async ( {
		request,
	} ) => {
		wp(
			`option update product_markdown_mirror_settings '{"enabled":"yes","mirror_categories":"no","mirror_tags":"yes"}' --format=json`
		);

		const off = await request.get( `/product-category/${ PARENT }.md` );
		expect( off.status() ).toBe( 404 );

		wp(
			`option update product_markdown_mirror_settings '{"enabled":"yes","mirror_categories":"yes","mirror_tags":"yes"}' --format=json`
		);

		const on = await request.get( `/product-category/${ PARENT }.md` );
		expect( on.status() ).toBe( 200 );
	} );
} );
