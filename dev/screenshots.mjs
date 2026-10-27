/**
 * Captures documentation screenshots from the local dev site (dev/blueprint.json).
 *
 * Usage: node dev/screenshots.mjs [baseUrl]
 * Writes PNGs to docs/images/ and the WordPress.org screenshots to .wordpress-org/.
 *
 * The dev site uses a placeholder Anthropic key, so the "provider not connected" banner is
 * hidden on page screenshots and captured on its own for the troubleshooting docs.
 */
import { chromium } from 'playwright-core';
import { mkdirSync, copyFileSync } from 'node:fs';

const BASE = process.argv[ 2 ] || 'http://127.0.0.1:9400';
const OUT = 'docs/images';
mkdirSync( OUT, { recursive: true } );
mkdirSync( '.wordpress-org', { recursive: true } );

const browser = await chromium.launch( { channel: 'chrome' } );

async function newPage( { width = 1440, height = 900, theme = 'light' } = {} ) {
	const context = await browser.newContext( {
		viewport: { width, height },
		deviceScaleFactor: 2,
		locale: 'en-US',
		colorScheme: theme === 'dark' ? 'dark' : 'light',
	} );
	const page = await context.newPage();
	// Visiting the front end triggers Playground's automatic login.
	await page.goto( `${ BASE }/` );
	await page.goto( `${ BASE }/wp-admin/admin.php?page=tokenwarden` );
	if ( page.url().includes( 'wp-login.php' ) ) {
		// Playground's documented default credentials for local test sites.
		await page.fill( '#user_login', 'admin' );
		await page.fill( '#user_pass', 'password' );
		await page.click( '#wp-submit' );
		await page.waitForURL( /page=tokenwarden/ );
	}
	await page.evaluate( ( t ) => {
		localStorage.setItem( 'tokenwarden-theme', t );
		localStorage.setItem( 'tokenwarden-range', '30' );
	}, theme );
	return page;
}

async function open( page, route ) {
	await page.goto( `${ BASE }/wp-admin/admin.php?page=tokenwarden&shot=${ Date.now() }#/${ route }` );
	await page.waitForSelector( '.tokenwarden-shell .tokenwarden-card', { timeout: 30000 } );
	await page.waitForFunction( () => ! document.querySelector( '.tokenwarden-skeleton, .tokenwarden-boot' ) );
	await page.waitForTimeout( 900 );
}

async function hideProviderBanner( page ) {
	await page.evaluate( () => {
		document.querySelectorAll( '.tokenwarden-banner' ).forEach( ( b ) => {
			if ( /installed but not connected|No AI provider/.test( b.textContent ) ) {
				b.remove();
			}
		} );
	} );
}

// Centre the element first so the sticky header never covers it.
async function clickCentered( locator ) {
	await locator.evaluate( ( el ) => el.scrollIntoView( { block: 'center' } ) );
	await locator.click();
}

async function shot( page, name, opts = {} ) {
	// Park the pointer on the page title, away from menus and hover effects.
	await page.mouse.move( 700, 150 );
	await page.waitForTimeout( 300 );
	if ( opts.fullPage ) {
		// Grow the window to the page height so wp-admin's fixed sidebar spans the whole image.
		const size = page.viewportSize();
		const height = await page.evaluate( () => document.documentElement.scrollHeight );
		await page.setViewportSize( { width: size.width, height } );
		await page.waitForTimeout( 500 );
		await page.screenshot( { path: `${ OUT }/${ name }.png` } );
		await page.setViewportSize( size );
	} else {
		await page.screenshot( { path: `${ OUT }/${ name }.png`, ...opts } );
	}
	console.log( `${ OUT }/${ name }.png` );
}

const page = await newPage();

// Overview.
await open( page, 'overview' );
await shot( page, 'provider-not-connected', { clip: { x: 160, y: 32, width: 1280, height: 330 } } );
await hideProviderBanner( page );
await shot( page, 'overview' );
await shot( page, 'overview-full', { fullPage: true } );

// Chart tooltip.
const chart = page.locator( '.tokenwarden-chart svg' ).first();
const box = await chart.boundingBox();
await page.mouse.move( box.x + box.width * 0.72, box.y + box.height * 0.6 );
await page.waitForTimeout( 400 );
await page.screenshot( { path: `${ OUT }/overview-tooltip.png`, clip: { x: box.x - 30, y: box.y - 230, width: box.width + 60, height: box.height + 260 } } );
console.log( `${ OUT }/overview-tooltip.png` );

// Budget + attention cards.
const side = page.locator( '.tokenwarden-span-4' ).first();
await side.screenshot( { path: `${ OUT }/overview-budget.png` } );
console.log( `${ OUT }/overview-budget.png` );

// Sources and policy drawer.
await open( page, 'sources' );
await hideProviderBanner( page );
await shot( page, 'sources' );
await clickCentered( page.locator( 'tr', { hasText: 'Helpdesk Bot' } ).getByRole( 'button', { name: 'Policy' } ) );
await page.waitForSelector( '.tokenwarden-drawer' );
await page.waitForTimeout( 500 );
await shot( page, 'source-policy' );
await page.keyboard.press( 'Escape' );

// Requests and detail drawer.
await open( page, 'requests' );
await shot( page, 'requests' );
await clickCentered( page.locator( 'tbody tr.is-clickable' ).first() );
await page.waitForSelector( '.tokenwarden-drawer' );
await page.waitForTimeout( 500 );
await shot( page, 'request-detail' );
await page.keyboard.press( 'Escape' );

// Privacy: report by plugin, data map, tester.
await open( page, 'privacy' );
await shot( page, 'privacy' );
await shot( page, 'privacy-full', { fullPage: true } );
await page.locator( '.tokenwarden-card', { hasText: 'Personal data by plugin' } ).screenshot( { path: `${ OUT }/privacy-report.png` } );
console.log( `${ OUT }/privacy-report.png` );
await page.locator( '.tokenwarden-card', { hasText: 'AI data map' } ).screenshot( { path: `${ OUT }/data-map.png` } );
console.log( `${ OUT }/data-map.png` );
const tester = page.locator( '.tokenwarden-card', { hasText: 'Try it' } );
await tester.screenshot( { path: `${ OUT }/privacy-tester.png` } );
console.log( `${ OUT }/privacy-tester.png` );

// Settings, with automatic price updates on (downloads the real price list).
await page.evaluate( () => wp.apiFetch( { path: '/tokenwarden/v1/settings', method: 'POST', data: { prices_auto: true } } ) );
await open( page, 'settings' );
await shot( page, 'settings', { fullPage: true } );
const prices = page.locator( '.tokenwarden-card', { hasText: 'Model prices' } );
await prices.screenshot( { path: `${ OUT }/settings-prices.png` } );
console.log( `${ OUT }/settings-prices.png` );

// Unsaved-changes bar.
await clickCentered( page.getByRole( 'switch', { name: 'Store excerpts' } ) );
await page.waitForSelector( '.tokenwarden-savebar' );
await page.waitForTimeout( 400 );
await page.locator( '.tokenwarden-savebar' ).screenshot( { path: `${ OUT }/save-bar.png` } );
console.log( `${ OUT }/save-bar.png` );

// Setup guide, every step.
const guide = await newPage();
await guide.goto( `${ BASE }/wp-admin/admin.php?page=tokenwarden&shot=${ Date.now() }#/welcome` );
await guide.waitForSelector( '.tokenwarden-wizard__card' );
await guide.waitForTimeout( 600 );
for ( let i = 1; i <= 5; i++ ) {
	await shot( guide, `setup-${ i }` );
	const next = guide.locator( '.tokenwarden-wizard__foot .tokenwarden-btn.is-primary' );
	if ( ! ( await next.count() ) ) {
		break;
	}
	const before = await guide.locator( '[aria-current="step"]' ).innerText();
	await next.click();
	await guide.waitForFunction( ( b ) => document.querySelector( '[aria-current="step"]' )?.innerText !== b, before, { timeout: 30000 } );
	await guide.waitForTimeout( 500 );
}

// Help page and a tooltip.
await open( page, 'help' );
await shot( page, 'help', { fullPage: true } );
await open( page, 'overview' );
await hideProviderBanner( page );
const tipButton = page.locator( '.tokenwarden-stat .tokenwarden-tip__btn' ).first();
await tipButton.evaluate( ( el ) => el.scrollIntoView( { block: 'center' } ) );
await tipButton.click();
await page.waitForTimeout( 300 );
const tipBox = await page.locator( '.tokenwarden-stat' ).first().boundingBox();
await page.screenshot( { path: `${ OUT }/tooltip.png`, clip: { x: tipBox.x - 10, y: tipBox.y - 10, width: 420, height: tipBox.height + 120 } } );
console.log( `${ OUT }/tooltip.png` );

// Demo mode on, then back off.
const demo = await newPage();
await open( demo, 'overview' );
await demo.locator( '.tokenwarden-demo-toggle input' ).click();
await demo.waitForSelector( '.tokenwarden-demo-bar', { timeout: 120000 } );
await demo.waitForTimeout( 1500 );
await hideProviderBanner( demo );
await shot( demo, 'demo-mode' );
await demo.getByRole( 'button', { name: 'Back to my data' } ).click();
await demo.waitForTimeout( 3000 );

// First run: the overview as a fresh install renders it (API answer emptied in the browser only).
const empty = await newPage();
await empty.route( '**/tokenwarden/v1/overview**', async ( route ) => {
	const res = await route.fetch();
	const json = await res.json();
	json.kpis = { ...json.kpis, requests: 0, unpriced: 0 };
	json.recent = [];
	await route.fulfill( { response: res, json } );
} );
await open( empty, 'overview' );
await hideProviderBanner( empty );
await shot( empty, 'first-run' );

// Dark mode.
const dark = await newPage( { theme: 'dark' } );
await open( dark, 'overview' );
await hideProviderBanner( dark );
await shot( dark, 'overview-dark' );

// Phone.
const phone = await newPage( { width: 390, height: 844 } );
await open( phone, 'overview' );
await hideProviderBanner( phone );
await shot( phone, 'mobile' );

await browser.close();

// WordPress.org listing screenshots, matching the captions in readme.txt.
[ 'overview', 'sources', 'requests', 'privacy', 'settings' ].forEach( ( name, i ) => {
	copyFileSync( `${ OUT }/${ name }.png`, `.wordpress-org/screenshot-${ i + 1 }.png` );
} );
console.log( '.wordpress-org/screenshot-1..5.png' );
