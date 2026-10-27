/**
 * Builds docs/tokenwarden-docs.html: every page in docs/ as one self-contained, offline HTML page,
 * with screenshots embedded (JPEG) so they show in any viewer. Run: node dev/build-docs.mjs
 */
import MarkdownIt from 'markdown-it';
import { readFileSync, writeFileSync, mkdirSync, existsSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const ORDER = [ 'README', 'getting-started', 'overview', 'sources-and-budgets', 'requests', 'privacy', 'brand-brief', 'settings', 'caching', 'costs-and-pricing', 'data', 'faq', 'troubleshooting', 'developers' ];
const version = /Stable tag:\s*(\S+)/.exec( readFileSync( 'readme.txt', 'utf8' ) )[ 1 ];
const tmp = join( tmpdir(), 'tokenwarden-docs-img' );
mkdirSync( tmp, { recursive: true } );

const slug = ( t ) => t.toLowerCase().replace( /<[^>]+>/g, '' ).replace( /[^\p{L}\p{N}\s-]/gu, '' ).trim().replace( /\s+/g, '-' );
const esc = ( s ) => s.replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( /"/g, '&quot;' );

const imageCache = {};
function embed( src ) {
	if ( imageCache[ src ] ) return imageCache[ src ];
	const file = join( 'docs', src );
	if ( ! existsSync( file ) ) return src;
	const out = join( tmp, src.replace( /[/\\]/g, '_' ).replace( /\.png$/, '.jpg' ) );
	execFileSync( 'sips', [ '-s', 'format', 'jpeg', '-s', 'formatOptions', '78', '-Z', '1600', file, '--out', out ], { stdio: 'ignore' } );
	return ( imageCache[ src ] = 'data:image/jpeg;base64,' + readFileSync( out ).toString( 'base64' ) );
}

const nav = [];
const sections = ORDER.map( ( name ) => {
	const md = new MarkdownIt( { html: false, linkify: false, typographer: false } );
	const used = {};
	md.core.ruler.push( 'tokenwarden', ( state ) => {
		state.tokens.forEach( ( tok, i ) => {
			if ( tok.type === 'heading_open' ) {
				const text = state.tokens[ i + 1 ].content;
				let id = slug( text );
				used[ id ] = ( used[ id ] || 0 ) + 1;
				if ( used[ id ] > 1 ) id += '-' + ( used[ id ] - 1 );
				tok.attrSet( 'id', tok.tag === 'h1' ? name : `${ name }--${ id }` );
				if ( tok.tag === 'h1' ) nav.push( { id: name, text } );
			}
			( tok.children || [] ).forEach( ( c ) => {
				if ( c.type === 'image' ) {
					c.attrSet( 'src', embed( c.attrGet( 'src' ) ) );
					c.attrSet( 'loading', 'lazy' );
				}
				if ( c.type === 'link_open' ) {
					const href = c.attrGet( 'href' );
					const m = /^([a-z0-9-]+)\.md(?:#(.+))?$/i.exec( href );
					if ( m ) c.attrSet( 'href', '#' + ( m[ 2 ] ? `${ m[ 1 ] }--${ m[ 2 ] }` : m[ 1 ] ) );
					else if ( /^#/.test( href ) ) c.attrSet( 'href', `#${ name }--${ href.slice( 1 ) }` );
					else if ( /^https?:/.test( href ) ) { c.attrSet( 'target', '_blank' ); c.attrSet( 'rel', 'noopener' ); }
				}
			} );
		} );
	} );
	const html = md.render( readFileSync( `docs/${ name }.md`, 'utf8' ) )
		.replace( /<p>(<img [^>]*alt="([^"]*)"[^>]*>)<\/p>/g, '<figure>$1<figcaption>$2</figcaption></figure>' );
	return `<section class="doc" aria-labelledby="${ name }">${ html }</section>`;
} );

const page = `<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Tokenwarden Docs</title>
<style>
:root{--bg:#fbfbf9;--surface:#ffffff;--ink:#141413;--ink-2:#3d3d3a;--muted:#6b6963;--line:#e6e4dd;--accent:#4a3aa7;--accent-soft:rgba(74,58,167,.08);--code:#f3f2ee}
@media (prefers-color-scheme:dark){:root:not([data-theme="light"]){--bg:#0d0d0d;--surface:#1a1a19;--ink:#f5f4ee;--ink-2:#c3c2b7;--muted:#a3a19a;--line:#2c2c2a;--accent:#a59cf0;--accent-soft:rgba(165,156,240,.12);--code:#222221}}
:root[data-theme="dark"]{--bg:#0d0d0d;--surface:#1a1a19;--ink:#f5f4ee;--ink-2:#c3c2b7;--muted:#a3a19a;--line:#2c2c2a;--accent:#a59cf0;--accent-soft:rgba(165,156,240,.12);--code:#222221}
*{box-sizing:border-box}
html{scroll-behavior:smooth;scroll-padding-top:16px}
@media (prefers-reduced-motion:reduce){html{scroll-behavior:auto}}
body{margin:0;background:var(--bg);color:var(--ink-2);font:16px/1.65 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}
.skip{position:absolute;left:-999px;top:8px;background:var(--accent);color:#fff;padding:8px 12px;border-radius:8px;z-index:10}
.skip:focus{left:8px}
.layout{display:grid;grid-template-columns:270px minmax(0,1fr);max-width:1240px;margin:0 auto}
nav{position:sticky;top:0;align-self:start;height:100vh;overflow:auto;padding:28px 18px;border-right:1px solid var(--line)}
.brand{display:flex;align-items:center;gap:10px;font-weight:650;color:var(--ink);margin:0 0 4px;font-size:17px}
.brand i{width:26px;height:26px;border-radius:8px;background:linear-gradient(135deg,#4a3aa7,#2a1f6e);display:inline-block}
.ver{color:var(--muted);font-size:13px;margin:0 0 20px 36px}
nav ol{list-style:none;margin:0;padding:0}
nav a{display:block;padding:6px 10px;border-radius:8px;color:var(--ink-2);text-decoration:none;font-size:14.5px}
nav a:hover,nav a[aria-current]{background:var(--accent-soft);color:var(--accent)}
nav a:focus-visible,main a:focus-visible,button:focus-visible{outline:2px solid var(--accent);outline-offset:2px}
main{padding:28px 48px 120px;min-width:0}
.doc{background:var(--surface);border:1px solid var(--line);border-radius:16px;padding:8px 40px 32px;margin:0 0 28px}
h1,h2,h3,h4{color:var(--ink);line-height:1.25;letter-spacing:-.01em}
h1{font-size:30px;margin:28px 0 12px}
h2{font-size:22px;margin:36px 0 8px;padding-top:12px;border-top:1px solid var(--line)}
h3{font-size:17.5px;margin:26px 0 6px}
a{color:var(--accent);text-underline-offset:2px}
code{background:var(--code);border-radius:5px;padding:1px 6px;font:13.5px/1.5 ui-monospace,SFMono-Regular,Menlo,monospace;overflow-wrap:anywhere}
pre{background:var(--code);border-radius:10px;padding:14px 16px;overflow:auto}
pre code{padding:0;background:none}
table{border-collapse:collapse;width:100%;margin:14px 0;font-size:15px;display:block;overflow-x:auto}
th,td{border-bottom:1px solid var(--line);padding:8px 10px;text-align:left;vertical-align:top}
th{color:var(--ink);font-weight:600}
blockquote{margin:16px 0;padding:10px 16px;border-left:3px solid var(--accent);background:var(--accent-soft);border-radius:0 10px 10px 0}
blockquote p{margin:4px 0}
figure{margin:20px 0 26px}
figure img{display:block;width:100%;height:auto;border:1px solid var(--line);border-radius:12px;box-shadow:0 1px 2px rgba(0,0,0,.05),0 12px 32px -16px rgba(0,0,0,.25)}
figcaption{color:var(--muted);font-size:13.5px;margin-top:8px;text-align:center}
img{max-width:100%}
.theme{position:fixed;right:16px;top:16px;border:1px solid var(--line);background:var(--surface);color:var(--ink);border-radius:999px;padding:6px 12px;font:inherit;font-size:13px;cursor:pointer}
@media (max-width:900px){.layout{grid-template-columns:1fr}nav{position:static;height:auto;border-right:0;border-bottom:1px solid var(--line);padding:16px}nav ol{display:flex;flex-wrap:wrap;gap:2px}main{padding:16px}.doc{padding:4px 18px 20px;border-radius:12px}h1{font-size:25px}.theme{top:auto;bottom:16px}}
@media print{nav,.theme,.skip{display:none}.layout{display:block}.doc{border:0;break-before:page}}
</style>
</head>
<body>
<a class="skip" href="#content">Skip to content</a>
<button class="theme" type="button" aria-label="Switch light or dark theme">◐ Theme</button>
<div class="layout">
<nav aria-label="Documentation">
<p class="brand"><i aria-hidden="true"></i>Tokenwarden</p>
<p class="ver">Documentation · version ${ esc( version ) }</p>
<ol>${ nav.map( ( n ) => `<li><a href="#${ n.id }">${ esc( n.id === 'README' ? 'Introduction' : n.text ) }</a></li>` ).join( '' ) }</ol>
</nav>
<main id="content">
${ sections.join( '\n' ) }
</main>
</div>
<script>
(function(){
var root=document.documentElement,btn=document.querySelector('.theme');
try{var t=localStorage.getItem('tokenwarden-docs-theme');if(t)root.dataset.theme=t;}catch(e){}
btn.addEventListener('click',function(){var dark=root.dataset.theme?root.dataset.theme==='dark':matchMedia('(prefers-color-scheme: dark)').matches;root.dataset.theme=dark?'light':'dark';try{localStorage.setItem('tokenwarden-docs-theme',root.dataset.theme);}catch(e){}});
var links=[].slice.call(document.querySelectorAll('nav a'));
var io=new IntersectionObserver(function(es){es.forEach(function(e){if(e.isIntersecting){links.forEach(function(a){a.removeAttribute('aria-current');});var a=document.querySelector('nav a[href="#'+e.target.querySelector('h1').id+'"]');if(a)a.setAttribute('aria-current','true');}});},{rootMargin:'-20% 0px -70% 0px'});
document.querySelectorAll('.doc').forEach(function(s){io.observe(s);});
})();
</script>
</body>
</html>
`;
writeFileSync( 'docs/tokenwarden-docs.html', page );
console.log( `docs/tokenwarden-docs.html  ${ ( page.length / 1048576 ).toFixed( 1 ) } MB, ${ nav.length } pages, ${ Object.keys( imageCache ).length } screenshots` );
