// Capture the wordpress.org screenshots from a disposable site.
//   PW_DIR=/path/to/dir/with/node_modules/playwright node capture.mjs <baseUrl> <outDir> [--only=1,2,9]
// Logs in as the disposable site's own admin (AAFM_SHOTS_USER / AAFM_SHOTS_PASS, default admin/admin).
import { createRequire } from 'node:module';
import { existsSync, mkdirSync, readFileSync } from 'node:fs';
import path from 'node:path';

const args = process.argv.slice( 2 );
const only = args.find( ( a ) => a.startsWith( '--only=' ) )?.slice( 7 ).split( ',' ).map( Number );
const [ baseUrl, outDir ] = args.filter( ( a ) => ! a.startsWith( '--' ) );
if ( ! baseUrl || ! outDir || ! process.env.PW_DIR ) {
	console.error( 'usage: PW_DIR=<dir with node_modules/playwright> node capture.mjs <baseUrl> <outDir> [--only=1,2,9]' );
	process.exit( 2 );
}
const require = createRequire( `${ process.env.PW_DIR }/` );
const { chromium } = require( 'playwright' );
const root = baseUrl.replace( /\/$/, '' );
const plugin = ( query ) => `${ root }/wp-admin/admin.php?page=agent-abilities-for-mcp&${ query }`;

// Text that must never appear in a published frame. The generic patterns live here; the real client
// and account names live in an untracked file (one regular expression per line, # for comments), because
// this script is committed to a public repository. A missing file is an error, not a pass.
const DENY = [ /ddev\.site/i, /gmail\.com/i, /search engines blocked/i, /store coming soon/i, /query monitor/i ];
const denyFile = process.env.AAFM_SHOTS_DENY_FILE ?? '.claude/screenshots-deny.txt';
if ( ! existsSync( denyFile ) ) {
	console.error( `missing ${ denyFile }: create it (one regex per line) before shooting anything` );
	process.exit( 2 );
}
readFileSync( denyFile, 'utf8' )
	.split( '\n' )
	.map( ( line ) => line.trim() )
	.filter( ( line ) => line !== '' && ! line.startsWith( '#' ) )
	.forEach( ( line ) => DENY.push( new RegExp( line, 'i' ) ) );

const click = async ( locator ) => {
	await locator.first().click( { timeout: 4000 } ).catch( () => {} );
};
// Scroll an element to the top of the frame, then back up by `offset` px so its card heading stays visible.
const top = ( page, selector, offset = 60 ) =>
	page
		.locator( selector )
		.first()
		.evaluate( ( el, by ) => {
			el.scrollIntoView( { block: 'start' } );
			window.scrollBy( 0, -by );
		}, offset )
		.catch( () => {} );
// Open the WooCommerce card and centre its first padlocked ability, so ordinary writes sit around it.
const padlock = async ( page ) => {
	await click( page.locator( '.aafm-integration-woocommerce > summary' ) );
	await page
		.locator( '.aafm-integration-woocommerce .aafm-ability-locked-note' )
		.first()
		.evaluate( ( el ) => el.scrollIntoView( { block: 'center' } ) )
		.catch( () => {} );
};

// Each shot: file number, URL, an optional prep step. Adjust a prep step only after looking at the PNG.
const SHOTS = [
	{
		n: 1,
		url: plugin( 'tab=dashboard&aafm_shot=wizard' ),
		// The wizard moves focus to its heading, which draws a focus ring in the frame.
		prep: async ( page ) => {
			await page.waitForTimeout( 600 );
			await page.evaluate( () => document.activeElement?.blur() );
		},
	},
	{ n: 2, url: plugin( 'tab=abilities' ), prep: ( page ) => click( page.getByRole( 'tab', { name: /^Menus/ } ) ) },
	{ n: 3, url: plugin( 'tab=abilities&aafm_shot=readonly' ) },
	{ n: 4, url: plugin( 'tab=settings&aafm_shot=readonly' ), prep: ( page ) => top( page, 'input[name="aafm_read_only_mode"]', 130 ) },
	{ n: 5, url: plugin( 'tab=integrations' ), prep: padlock },
	{ n: 6, url: plugin( 'tab=integrations' ) },
	{ n: 7, url: plugin( 'tab=bridge' ) },
	{ n: 8, url: plugin( 'tab=connection' ) },
	{ n: 9, url: plugin( 'tab=activity' ), prep: ( page ) => page.waitForSelector( '.aafm-log-table tbody tr' ) },
	{ n: 10, url: plugin( 'tab=dashboard' ) },
	{ n: 11, url: plugin( 'tab=settings' ), prep: ( page ) => top( page, '#aafm-site-brief', 200 ) },
	{
		n: 12,
		url: plugin( 'tab=connection' ),
		prep: async ( page ) => {
			await click( page.locator( '#aafm-doctor-run' ) );
			await page.waitForTimeout( 6000 );
			await top( page, '#aafm-doctor-run', 150 );
		},
	},
	{ n: 13, url: plugin( 'tab=dashboard' ), prep: ( page ) => top( page, '#aafm-review-inbox' ) },
	{ n: 14, url: plugin( 'tab=settings' ), prep: ( page ) => top( page, '#aafm-notify-mode', 130 ) },
];

mkdirSync( outDir, { recursive: true } );
const browser = await chromium.launch();
const context = await browser.newContext( { viewport: { width: 1440, height: 900 }, deviceScaleFactor: 1 } );
const page = await context.newPage();

await page.goto( `${ root }/wp-login.php` );
await page.fill( '#user_login', process.env.AAFM_SHOTS_USER ?? 'admin' );
await page.fill( '#user_pass', process.env.AAFM_SHOTS_PASS ?? 'admin' );
await Promise.all( [ page.waitForURL( /wp-admin/ ), page.click( '#wp-submit' ) ] );

let failed = 0;
for ( const shot of SHOTS.filter( ( s ) => ! only || only.includes( s.n ) ) ) {
	await page.goto( shot.url, { waitUntil: 'networkidle' } );
	if ( shot.prep ) {
		await shot.prep( page );
	}
	await page.waitForTimeout( 400 );
	const text = await page.locator( 'body' ).innerText();
	const hit = DENY.find( ( re ) => re.test( text ) );
	if ( hit ) {
		console.error( `screenshot-${ shot.n }: refused, the page text matches ${ hit }` );
		failed += 1;
		continue;
	}
	const file = path.join( outDir, `screenshot-${ shot.n }.png` );
	await page.screenshot( { path: file, fullPage: false } );
	process.stdout.write( `wrote ${ file }\n` );
}
await browser.close();
process.exit( failed ? 1 : 0 );
