/**
 * Tests for the send queue in assets/js/raynet-elementor-import.js.
 *
 * Loads the import screen script against empty stand-ins for jQuery and the
 * document, then drives window.raynetImportQueue with promises the test
 * settles itself.
 *
 * Usage: node tests/js/test-elementor-import.js
 */

'use strict';

const fs = require( 'fs' );
const path = require( 'path' );
const vm = require( 'vm' );

const SOURCE = fs.readFileSync( path.join( __dirname, '../../raynet-lead-api-integration/assets/js/raynet-elementor-import.js' ), 'utf8' );

let pass = 0;
let fail = 0;

function check( label, got, want ) {
	const ok = JSON.stringify( got ) === JSON.stringify( want );
	ok ? pass++ : fail++;
	console.log( ( ok ? 'ok   ' : 'FAIL ' ) + label );
	if ( ! ok ) {
		console.log( '     got:  ' + JSON.stringify( got ) );
		console.log( '     want: ' + JSON.stringify( want ) );
	}
}

/**
 * Loads the script and returns its queue.
 */
function load() {
	// Anything the script asks of jQuery or the document gets a harmless object.
	const inert = function () {
		return inert;
	};
	const context = {
		jQuery: inert,
		document: {},
		window: {},
		raynetElementorImport: {},
	};

	vm.runInNewContext( SOURCE, context );

	return context.window.raynetImportQueue;
}

// Lets every pending promise callback run.
function settle() {
	return new Promise( ( resolve ) => setImmediate( resolve ) );
}

/**
 * A sendOne whose answers the test hands out one by one.
 */
function controlled() {
	const calls = [];
	const pending = [];

	return {
		calls,
		pending,
		sendOne( id ) {
			calls.push( id );
			return new Promise( ( resolve, reject ) => pending.push( { id, resolve, reject } ) );
		},
	};
}

function ok( id ) {
	return { ok: true, leadId: 1000 + id, code: '', message: '', unknown: false };
}

async function run() {
	const queue = load();

	check( 'fronta je vystavená', typeof queue, 'function' );

	// Serial, in order, one at a time.
	let sender = controlled();
	let results = [];
	let summary = null;

	queue( [ 1, 2, 3 ], sender.sendOne, ( id, result ) => results.push( [ id, result ] ), ( done ) => {
		summary = done;
	} );
	await settle();
	check( 'začne prvním', sender.calls, [ 1 ] );

	await settle();
	check( 'druhé nezačne, dokud první neskončí', sender.calls, [ 1 ] );

	sender.pending[ 0 ].resolve( ok( 1 ) );
	await settle();
	check( 'po prvním druhé', sender.calls, [ 1, 2 ] );
	check( 'výsledek prvního předán', results.map( ( r ) => r[ 0 ] ), [ 1 ] );

	// A failure does not stop the queue.
	sender.pending[ 1 ].resolve( { ok: false, leadId: 0, code: 'raynet_api_error', message: 'RAYNET odmítl', unknown: false } );
	await settle();
	check( 'po chybě pokračuje třetím', sender.calls, [ 1, 2, 3 ] );
	check( 'onDone ještě ne', summary, null );

	sender.pending[ 2 ].resolve( ok( 3 ) );
	await settle();
	check( 'tři výsledky v pořadí', results.map( ( r ) => r[ 0 ] ), [ 1, 2, 3 ] );
	check( 'výsledek chyby nese kód', results[ 1 ][ 1 ].code, 'raynet_api_error' );
	check( 'výsledek nese číslo leadu', results[ 2 ][ 1 ].leadId, 1003 );
	check( 'souhrn', summary, { sent: 2, failed: 1, unknown: 0 } );

	// No answer from the server: the result is unknown, and the queue goes on.
	sender = controlled();
	results = [];
	summary = null;
	queue( [ 7, 8 ], sender.sendOne, ( id, result ) => results.push( [ id, result ] ), ( done ) => {
		summary = done;
	} );
	await settle();
	sender.pending[ 0 ].reject( new Error( 'timeout' ) );
	await settle();
	check( 'po odmítnuté Promise pokračuje', sender.calls, [ 7, 8 ] );
	check( 'odmítnutá Promise je neznámý výsledek', results[ 0 ][ 1 ].unknown, true );
	check( 'neznámý výsledek není úspěch', results[ 0 ][ 1 ].ok, false );
	sender.pending[ 1 ].resolve( ok( 8 ) );
	await settle();
	check( 'souhrn s neznámým', summary, { sent: 1, failed: 0, unknown: 1 } );

	// The lead exists although the submission could not be marked: it counts as sent.
	sender = controlled();
	summary = null;
	queue( [ 5 ], sender.sendOne, () => {}, ( done ) => {
		summary = done;
	} );
	await settle();
	sender.pending[ 0 ].resolve( { ok: false, leadId: 4242, code: 'raynet_import_unlogged', message: 'Lead 4242 byl založen…', unknown: false } );
	await settle();
	check( 'nezapsaný log se počítá jako odeslaný', summary, { sent: 1, failed: 0, unknown: 0 } );

	// Nothing to send.
	sender = controlled();
	summary = null;
	queue( [], sender.sendOne, () => {}, ( done ) => {
		summary = done;
	} );
	check( 'prázdný seznam: onDone hned', summary, { sent: 0, failed: 0, unknown: 0 } );
	await settle();
	check( 'prázdný seznam: sendOne nikdy', sender.calls, [] );

	console.log( '\n' + pass + ' passed, ' + fail + ' failed' );
	process.exit( fail > 0 ? 1 : 0 );
}

run();
