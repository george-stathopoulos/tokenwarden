/**
 * Renders the WordPress.org plugin icon and banner into .wordpress-org/.
 * Run after dev/screenshots.mjs (the banner uses docs/images/overview.png): node dev/wporg-assets.mjs
 */
import { chromium } from 'playwright-core';
import { readFileSync } from 'node:fs';

const MARK = 'M10 2a7 7 0 0 0-7 7v8a1 1 0 0 0 1 1h3v-6a3 3 0 0 1 6 0v6h3a1 1 0 0 0 1-1V9a7 7 0 0 0-7-7Zm0 3.2a.9.9 0 1 1 0 1.8.9.9 0 0 1 0-1.8Z';
const shot = 'data:image/png;base64,' + readFileSync( 'docs/images/overview.png' ).toString( 'base64' );
const BG = 'radial-gradient(120% 120% at 0% 0%, #7a6cf0 0%, transparent 60%), linear-gradient(135deg, #4a3aa7 0%, #2a1f6e 100%)';
const FONT = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";

const icon = ( s ) => `<body style="margin:0"><div style="width:${ s }px;height:${ s }px;background:${ BG };display:grid;place-items:center">
<svg width="${ s * 0.62 }" height="${ s * 0.62 }" viewBox="0 0 20 20"><path fill="#fff" d="${ MARK }"/></svg></div></body>`;

const banner = ( w, h ) => { const k = w / 1544; return `<body style="margin:0"><div style="position:relative;overflow:hidden;width:${ w }px;height:${ h }px;background:${ BG };font-family:${ FONT };color:#fff">
<div style="position:absolute;left:${ 96 * k }px;top:${ 128 * k }px;width:${ 640 * k }px">
 <div style="display:flex;align-items:center;gap:${ 22 * k }px">
  <div style="width:${ 84 * k }px;height:${ 84 * k }px;border-radius:${ 22 * k }px;background:rgba(255,255,255,.14);box-shadow:inset 0 0 0 ${ 2 * k }px rgba(255,255,255,.25);display:grid;place-items:center">
   <svg width="${ 52 * k }" height="${ 52 * k }" viewBox="0 0 20 20"><path fill="#fff" d="${ MARK }"/></svg></div>
  <div style="font-size:${ 76 * k }px;font-weight:700;letter-spacing:-.02em">Tokenwarden</div>
 </div>
 <div style="margin-top:${ 30 * k }px;font-size:${ 36 * k }px;line-height:1.3;font-weight:500;opacity:.92">See, budget and protect every AI call your WordPress site makes.</div>
</div>
<img src="${ shot }" style="position:absolute;left:${ 790 * k }px;top:${ 70 * k }px;width:${ 1100 * k }px;border-radius:${ 18 * k }px;box-shadow:0 ${ 30 * k }px ${ 80 * k }px rgba(0,0,0,.45);transform:perspective(${ 2000 * k }px) rotateY(-14deg) rotateX(4deg);transform-origin:left center">
</div></body>`; };

const b = await chromium.launch( { channel: 'chrome' } );
const page = await b.newPage();
for ( const [ name, html, w, h ] of [
	[ 'icon-256x256.png', icon( 256 ), 256, 256 ],
	[ 'icon-128x128.png', icon( 128 ), 128, 128 ],
	[ 'banner-1544x500.png', banner( 1544, 500 ), 1544, 500 ],
	[ 'banner-772x250.png', banner( 772, 250 ), 772, 250 ],
] ) {
	await page.setViewportSize( { width: w, height: h } );
	await page.setContent( html );
	await page.waitForTimeout( 300 );
	await page.screenshot( { path: `.wordpress-org/${ name }` } );
	console.log( `.wordpress-org/${ name }` );
}
await b.close();
