/**
 * Accessibility audit (axe-core, WCAG 2.2 A/AA) of every Tokenwarden screen, light and dark.
 * Run with the local dev site up: node dev/a11y.mjs
 */
import { chromium } from 'playwright-core';
import { readFileSync } from 'node:fs';

const BASE = 'http://127.0.0.1:9400';
const AXE = readFileSync( 'node_modules/axe-core/axe.min.js', 'utf8' );
const ROUTES = [ 'overview', 'sources', 'requests', 'privacy', 'brief', 'caching', 'settings', 'help', 'welcome' ];

const browser = await chromium.launch( { channel: 'chrome' } );
let total = 0;
for ( const theme of [ 'light', 'dark' ] ) {
	const ctx = await browser.newContext( { viewport: { width: 1440, height: 900 }, colorScheme: theme } );
	const page = await ctx.newPage();
	await page.goto( `${ BASE }/` );
	await page.goto( `${ BASE }/wp-admin/admin.php?page=tokenwarden` );
	if ( page.url().includes( 'wp-login.php' ) ) {
		await page.fill( '#user_login', 'admin' );
		await page.fill( '#user_pass', 'password' );
		await page.click( '#wp-submit' );
	}
	await page.evaluate( ( t ) => localStorage.setItem( 'tokenwarden-theme', t ), theme );
	for ( const route of ROUTES ) {
		await page.goto( `${ BASE }/wp-admin/admin.php?page=tokenwarden&a=${ Date.now() }#/${ route }` );
		await page.waitForSelector( '.tokenwarden-shell .tokenwarden-card', { timeout: 30000 } ).catch( () => {} );
		await page.waitForTimeout( 1200 );
		await page.addScriptTag( { content: AXE } );
		const result = await page.evaluate( async () =>
			window.axe.run( document.querySelector( '.tokenwarden-root' ) || document, {
				runOnly: { type: 'tag', values: [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa' ] },
			} )
		);
		for ( const v of result.violations ) {
			total += v.nodes.length;
			console.log( `[${ theme }] ${ route }: ${ v.id } (${ v.impact }) × ${ v.nodes.length } — ${ v.help }` );
			v.nodes.slice( 0, 3 ).forEach( ( n ) => console.log( `     ${ n.target.join( ' ' ) } :: ${ ( n.failureSummary || '' ).split( '\n' )[ 1 ] || '' }` ) );
		}
	}
	await ctx.close();
}
console.log( `\nTotal issues: ${ total }` );
await browser.close();
