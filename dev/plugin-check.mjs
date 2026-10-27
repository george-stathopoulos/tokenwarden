/**
 * Runs the Plugin Check plugin against the release zips on a throwaway site and prints the results.
 * Start the site first:
 *   npx @wp-playground/cli@latest server --php=8.3 --port=9401 \
 *     --mount=dist/tokenwarden:/wordpress/wp-content/plugins/tokenwarden \
 *     --blueprint=dev/plugin-check-server.json --login
 * Then: node dev/plugin-check.mjs
 */
import { chromium } from 'playwright-core';

const BASE = 'http://127.0.0.1:9401';
const browser = await chromium.launch( { channel: 'chrome' } );
const page = await ( await browser.newContext() ).newPage();
await page.goto( `${ BASE }/` );
for ( const plugin of [ 'tokenwarden/tokenwarden.php' ] ) {
	await page.goto( `${ BASE }/wp-admin/tools.php?page=plugin-check&plugin=${ plugin }` );
	if ( page.url().includes( 'wp-login.php' ) ) {
		await page.fill( '#user_login', 'admin' );
		await page.fill( '#user_pass', 'password' );
		await page.click( '#wp-submit' );
		await page.goto( `${ BASE }/wp-admin/tools.php?page=plugin-check&plugin=${ plugin }` );
	}
	// The first admin visit after activation opens Tokenwarden's setup guide once; load the page again.
	if ( ! page.url().includes( 'plugin-check' ) ) {
		await page.goto( `${ BASE }/wp-admin/tools.php?page=plugin-check&plugin=${ plugin }` );
	}
	await page.getByRole( 'button', { name: 'Check it!' } ).click();
	await page.waitForFunction( () => /Checks complete/.test( document.querySelector( '.wrap' )?.innerText || '' ), null, { timeout: 180000 } );
	const text = await page.evaluate( () => {
		const w = document.querySelector( '.wrap' ).innerText;
		const results = document.querySelector( '#plugin-check__results' )?.innerText || '';
		return results + '\n' + w.slice( w.lastIndexOf( 'Checks complete' ) );
	} );
	console.log( `\n=== ${ plugin }\n${ text.trim() }` );
}
await browser.close();
