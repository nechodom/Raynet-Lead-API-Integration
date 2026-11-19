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

	load.view = context.window.raynetImportRowView || ( () => null );
	load.aiRun = context.window.raynetImportAiRun;
	load.aiData = context.window.raynetImportAiData;

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

/**
 * A stand-in for the few jQuery calls the AI panel makes.
 */
function element( attrs, present ) {
	const el = {
		length: present ? 1 : 0,
		attrs: Object.assign( {}, attrs || {} ),
		props: { disabled: false, hidden: false },
		value: '',
		children: [],
		handlers: {},
	};
	el.on = ( event, selector, fn ) => {
		el.handlers[ event ] = 'function' === typeof selector ? selector : fn;
		return el;
	};
	el.attr = ( key, value ) => {
		if ( undefined === value ) {
			return el.attrs[ key ];
		}
		el.attrs[ key ] = value;
		return el;
	};
	el.prop = ( key, value ) => {
		if ( undefined === value ) {
			return el.props[ key ];
		}
		el.props[ key ] = value;
		return el;
	};
	el.text = ( value ) => {
		if ( undefined === value ) {
			return el.value;
		}
		el.value = String( value );
		el.children = [];
		return el;
	};
	el.empty = () => {
		el.value = '';
		el.children = [];
		return el;
	};
	el.append = ( ...children ) => {
		el.children.push( ...children );
		return el;
	};
	el.click = () => el.handlers.click && el.handlers.click.call( el, { preventDefault() {} } );
	return el;
}

/**
 * Loads the script against a fake panel and returns its parts.
 */
function loadScreen( count ) {
	const nodes = {
		'#raynet-import-ai': element( { 'data-count': String( count ) }, true ),
		'#raynet-import-ai-stop': element( {}, true ),
		'#raynet-import-ai-forget': element( {}, true ),
		'#raynet-import-ai-progress': element( {}, true ),
	};
	nodes[ '#raynet-import-ai-stop' ].props.hidden = true;
	const requests = [];
	const asked = [];
	const state = { reloads: 0, confirm: true, ready: null };
	const $ = function ( arg ) {
		if ( 'function' === typeof arg ) {
			state.ready = arg;
			return null;
		}
		if ( 0 === String( arg ).indexOf( '<' ) ) {
			return element( { tag: arg }, true );
		}
		return nodes[ arg ] || element( {}, false );
	};
	$.ajax = ( options ) => {
		const request = { data: options.data, done: null, fail: null };
		requests.push( request );
		const chain = {
			done( fn ) {
				request.done = fn;
				return chain;
			},
			fail( fn ) {
				request.fail = fn;
				return chain;
			},
		};
		return chain;
	};
	const window = {
		raynetElementorImport: {
			ajaxUrl: '/wp-admin/admin-ajax.php',
			nonce: 'n0nc3',
			form: '12:abc',
			ai: {
				action: 'raynet_elm_import_ai',
				forgetAction: 'raynet_elm_import_ai_forget',
				label: 'AI (%d)',
				confirm: 'Poslat %d do OpenAI?',
				progress: '%1$d / %2$d',
				summary: 'Hotovo %1$d %2$d %3$d',
				stopping: 'Zastavuji',
				stopped: 'Zastaveno',
				failed: 'Selhalo',
				forgetConfirm: 'Zahodit?',
			},
		},
		confirm: ( text ) => {
			asked.push( text );
			return state.confirm;
		},
		location: {
			reload: () => {
				state.reloads++;
			},
		},
	};

	vm.runInNewContext( SOURCE, { jQuery: $, window, document: {}, Promise, setTimeout } );
	state.ready();

	return { nodes, requests, asked, state };
}

async function screen() {
	// Start: the dialog states the count, one request at a time, nonce sent.
	let s = loadScreen( 5 );
	s.state.confirm = false;
	s.nodes[ '#raynet-import-ai' ].click();
	check( 'panel: bez potvrzení nic neodejde', s.requests.length, 0 );
	s.state.confirm = true;
	s.nodes[ '#raynet-import-ai' ].click();
	check( 'panel: dialog s počtem', s.asked[ 1 ], 'Poslat 5 do OpenAI?' );
	await settle();
	check( 'panel: požadavek s nonce a kurzorem', s.requests.map( ( r ) => r.data ), [ { action: 'raynet_elm_import_ai', _wpnonce: 'n0nc3', form: '12:abc', after: 0 } ] );
	check( 'panel: tlačítko zakázané, Zastavit vidět', [ s.nodes[ '#raynet-import-ai' ].props.disabled, s.nodes[ '#raynet-import-ai-stop' ].props.hidden ], [ true, false ] );
	s.nodes[ '#raynet-import-ai' ].click();
	await settle();
	check( 'panel: dvojklik nespustí druhý běh', [ s.requests.length, s.asked.length ], [ 1, 2 ] );
	s.requests[ 0 ].done( { success: true, data: { done: false, judged: 2, skipped: 0, remaining: 3, import: 2, reject: 0, next: 10, retry_size: 0, retry_until: 0 } } );
	await settle();
	check( 'panel: průběh', s.nodes[ '#raynet-import-ai-progress' ].value, '2 / 5' );
	s.requests[ 1 ].done( { success: true, data: { done: true, judged: 3, skipped: 0, remaining: 0, import: 1, reject: 2, next: 20, retry_size: 0, retry_until: 0 } } );
	await settle();
	check( 'panel: po doběhnutí se stránka načte znovu', s.state.reloads, 1 );

	// An error: shown as text, the count follows what was stored, no reload.
	s = loadScreen( 5 );
	s.nodes[ '#raynet-import-ai' ].click();
	await settle();
	s.requests[ 0 ].done( { success: true, data: { done: false, judged: 2, skipped: 0, remaining: 3, import: 1, reject: 1, next: 10, retry_size: 0, retry_until: 0 } } );
	await settle();
	s.requests[ 1 ].fail( { responseJSON: { success: false, data: { code: 'raynet_ai_unauthorized', message: '<b>OpenAI klíč odmítl</b>' } } } );
	await settle();
	const shown = s.nodes[ '#raynet-import-ai-progress' ].children[ 0 ];
	check( 'panel: chyba jako text', shown ? shown.value : null, '<b>OpenAI klíč odmítl</b>' );
	check( 'panel: po chybě aktuální počet', [ s.nodes[ '#raynet-import-ai' ].attrs[ 'data-count' ], s.nodes[ '#raynet-import-ai' ].value ], [ '3', 'AI (3)' ] );
	check( 'panel: po chybě znovu povolené, Zastavit skryté', [ s.nodes[ '#raynet-import-ai' ].props.disabled, s.nodes[ '#raynet-import-ai-stop' ].props.hidden, s.state.reloads ], [ false, true, 0 ] );
	s.nodes[ '#raynet-import-ai' ].click();
	check( 'panel: další běh hlásí nový počet', s.asked[ 1 ], 'Poslat 3 do OpenAI?' );

	// Stop: the batch on its way finishes, then the page reloads.
	s = loadScreen( 5 );
	s.nodes[ '#raynet-import-ai' ].click();
	await settle();
	s.nodes[ '#raynet-import-ai-stop' ].click();
	check( 'panel: zastavuji', s.nodes[ '#raynet-import-ai-progress' ].value, 'Zastavuji' );
	s.requests[ 0 ].done( { success: true, data: { done: false, judged: 2, skipped: 0, remaining: 3, import: 1, reject: 1, next: 10, retry_size: 0, retry_until: 0 } } );
	await settle();
	check( 'panel: po zastavení žádná další dávka, načtení', [ s.requests.length, s.state.reloads ], [ 1, 1 ] );

	// Forget: asks, posts the forget action with the nonce, reloads.
	s = loadScreen( 0 );
	s.state.confirm = false;
	s.nodes[ '#raynet-import-ai-forget' ].click();
	check( 'panel: zahození bez potvrzení nic', s.requests.length, 0 );
	s.state.confirm = true;
	s.nodes[ '#raynet-import-ai-forget' ].click();
	check( 'panel: zahození pošle akci s nonce', s.requests.map( ( r ) => r.data ), [ { action: 'raynet_elm_import_ai_forget', _wpnonce: 'n0nc3', form: '12:abc' } ] );
	s.requests[ 0 ].done( { success: true, data: { deleted: 4 } } );
	await settle();
	check( 'panel: po zahození se stránka načte', s.state.reloads, 1 );
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

	// The server says itself the outcome is unknown (RAYNET timed out or failed).
	sender = controlled();
	results = [];
	summary = null;
	queue( [ 9 ], sender.sendOne, ( id, result ) => results.push( [ id, result ] ), ( done ) => {
		summary = done;
	} );
	await settle();
	sender.pending[ 0 ].resolve( { ok: false, leadId: 0, code: 'raynet_import_unknown', message: 'Výsledek je neznámý…', unknown: false } );
	await settle();
	check( 'kód neznámého výsledku se počítá jako neznámý', summary, { sent: 0, failed: 0, unknown: 1 } );
	check( 'kód neznámého výsledku: řádek dostane hlášku serveru', results[ 0 ][ 1 ].message, 'Výsledek je neznámý…' );

	// What each answer does to the row's checkbox.
	const view = load.view;
	check( 'řádek: kód neznámého výsledku zakáže zaškrtávátko', view( { ok: false, leadId: 0, code: 'raynet_import_unknown', message: 'x', unknown: false } ), { outcome: 'unknown', box: 'disable' } );
	check( 'řádek: bez odpovědi zakáže zaškrtávátko', view( { ok: false, leadId: 0, code: '', message: '', unknown: true } ), { outcome: 'unknown', box: 'disable' } );
	check( 'řádek: úspěch zaškrtávátko odebere', view( ok( 1 ) ), { outcome: 'sent', box: 'remove' } );
	check( 'řádek: nezapsaný log zaškrtávátko odebere', view( { ok: false, leadId: 5, code: 'raynet_import_unlogged', message: 'x', unknown: false } ), { outcome: 'unlogged', box: 'remove' } );
	check( 'řádek: odmítnutí jen odškrtne', view( { ok: false, leadId: 0, code: 'raynet_api_error', message: 'x', unknown: false } ), { outcome: 'failed', box: 'untick' } );

	// Nothing to send.
	sender = controlled();
	summary = null;
	queue( [], sender.sendOne, () => {}, ( done ) => {
		summary = done;
	} );
	check( 'prázdný seznam: onDone hned', summary, { sent: 0, failed: 0, unknown: 0 } );
	await settle();
	check( 'prázdný seznam: sendOne nikdy', sender.calls, [] );

	// ---------- AI review loop ----------
	const aiRun = load.aiRun;
	const aiData = load.aiData;

	check( 'AI: smyčka je vystavená', typeof aiRun, 'function' );
	check( 'AI: data požadavku nesou nonce a kurzor', aiData( { nonce: 'n0nc3', form: '12:abc', ai: { action: 'raynet_elm_import_ai' } }, 40 ), { action: 'raynet_elm_import_ai', _wpnonce: 'n0nc3', form: '12:abc', after: 40 } );

	function batch( next, extra ) {
		return Object.assign( { ok: true, done: false, judged: 2, remaining: 5, import: 1, reject: 1, next: next, code: '', message: '' }, extra || {} );
	}

	// Runs batch after batch, passing the cursor back, until the server says done.
	let ai = controlled();
	let progress = [];
	let ended = null;
	aiRun( ai.sendOne, ( totals ) => progress.push( totals.judged ), ( end ) => {
		ended = end;
	} );
	await settle();
	check( 'AI: první dávka od začátku', ai.calls, [ 0 ] );
	ai.pending[ 0 ].resolve( batch( 30 ) );
	await settle();
	check( 'AI: další dávka za kurzorem', ai.calls, [ 0, 30 ] );
	check( 'AI: jen jedna dávka naráz', ai.pending.length, 2 );
	ai.pending[ 1 ].resolve( batch( 50, { done: true, judged: 1, remaining: 2, import: 0, reject: 1 } ) );
	await settle();
	check( 'AI: po hotovo žádná další dávka', ai.calls, [ 0, 30 ] );
	check( 'AI: průběh', progress, [ 2, 3 ] );
	check( 'AI: konec hotovo se součty', ended, { status: 'done', code: '', message: '', judged: 3, skipped: 0, import: 1, reject: 2, remaining: 2 } );

	// An error stops the run, with the server's code and message.
	ai = controlled();
	ended = null;
	aiRun( ai.sendOne, () => {}, ( end ) => {
		ended = end;
	} );
	await settle();
	ai.pending[ 0 ].resolve( batch( 20 ) );
	await settle();
	ai.pending[ 1 ].resolve( { ok: false, code: 'raynet_ai_unauthorized', message: 'OpenAI klíč odmítl (401).' } );
	await settle();
	check( 'AI: po chybě nic dalšího', ai.calls, [ 0, 20 ] );
	check( 'AI: konec s chybou', [ ended.status, ended.code, ended.message, ended.judged ], [ 'error', 'raynet_ai_unauthorized', 'OpenAI klíč odmítl (401).', 2 ] );

	// No answer at all is an error too.
	ai = controlled();
	ended = null;
	aiRun( ai.sendOne, () => {}, ( end ) => {
		ended = end;
	} );
	await settle();
	ai.pending[ 0 ].reject( new Error( 'timeout' ) );
	await settle();
	check( 'AI: bez odpovědi konec s chybou', [ ended.status, ai.calls ], [ 'error', [ 0 ] ] );

	// Stop: the batch on its way finishes, no other starts.
	ai = controlled();
	ended = null;
	let handle = aiRun( ai.sendOne, () => {}, ( end ) => {
		ended = end;
	} );
	await settle();
	handle.stop();
	check( 'AI: zastavení nečeká na nic dalšího', ended, null );
	ai.pending[ 0 ].resolve( batch( 10 ) );
	await settle();
	check( 'AI: po zastavení žádná další dávka', ai.calls, [ 0 ] );
	check( 'AI: konec zastaveno', [ ended.status, ended.judged ], [ 'stopped', 2 ] );

	// A cursor that does not move would ask for the same rows again: stop.
	ai = controlled();
	ended = null;
	aiRun( ai.sendOne, () => {}, ( end ) => {
		ended = end;
	} );
	await settle();
	ai.pending[ 0 ].resolve( batch( 0 ) );
	await settle();
	check( 'AI: kurzor na místě ukončí běh', [ ended && ended.status, ai.calls ], [ 'error', [ 0 ] ] );

	// Stop requested, and the server says done with that batch: done wins.
	ai = controlled();
	ended = null;
	handle = aiRun( ai.sendOne, () => {}, ( end ) => {
		ended = end;
	} );
	await settle();
	handle.stop();
	ai.pending[ 0 ].resolve( batch( 10, { done: true } ) );
	await settle();
	check( 'AI: zastavení a hotovo je hotovo', ended.status, 'done' );

	// A refused batch is halved with the cursor unmoved until the cursor
	// passes the refused rows; then the default size is back.
	const sized = [];
	ai = {
		calls: [],
		pending: [],
		sendOne( after, size ) {
			sized.push( [ after, size ] );
			return new Promise( ( resolve, reject ) => ai.pending.push( { resolve, reject } ) );
		},
	};
	ended = null;
	aiRun( ai.sendOne, () => {}, ( end ) => {
		ended = end;
	} );
	await settle();
	ai.pending[ 0 ].resolve( batch( 0, { judged: 0, import: 0, reject: 0, retry_size: 2, retry_until: 50 } ) );
	await settle();
	ai.pending[ 1 ].resolve( batch( 20 ) );
	await settle();
	ai.pending[ 2 ].resolve( batch( 20, { judged: 0, import: 0, reject: 0, retry_size: 1, retry_until: 40 } ) );
	await settle();
	ai.pending[ 3 ].resolve( batch( 30, { judged: 0, skipped: 1, import: 0, reject: 0 } ) );
	await settle();
	ai.pending[ 4 ].resolve( batch( 40, { judged: 1, import: 1, reject: 0 } ) );
	await settle();
	ai.pending[ 5 ].resolve( batch( 60, { done: true, judged: 1, import: 1, reject: 0 } ) );
	await settle();
	check( 'AI: půlení dávky, kurzor stojí, pak výchozí velikost', sized, [ [ 0, 0 ], [ 0, 2 ], [ 20, 2 ], [ 20, 1 ], [ 30, 1 ], [ 40, 0 ] ] );
	check( 'AI: přeskočené v součtu', [ ended.status, ended.judged, ended.skipped ], [ 'done', 4, 1 ] );
	check( 'AI: data nesou velikost jen menší dávky', aiData( { nonce: 'n', form: '1:a', ai: { action: 'x' } }, 5, 2 ).size, 2 );

	// A retry that does not shrink would loop: stop.
	ai = controlled();
	ended = null;
	aiRun( ai.sendOne, () => {}, ( end ) => {
		ended = end;
	} );
	await settle();
	ai.pending[ 0 ].resolve( batch( 0, { retry_size: 1, retry_until: 9 } ) );
	await settle();
	ai.pending[ 1 ].resolve( batch( 0, { retry_size: 1, retry_until: 9 } ) );
	await settle();
	check( 'AI: opakování bez zmenšení ukončí běh', [ ended && ended.status, ai.calls.length ], [ 'error', 2 ] );

	await screen();

	console.log( '\n' + pass + ' passed, ' + fail + ' failed' );
	process.exit( fail > 0 ? 1 : 0 );
}

run();
