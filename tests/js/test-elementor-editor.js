/**
 * Tests for the owner picker in assets/js/raynet-elementor-editor.js.
 *
 * Runs the editor script against small stand-ins for jQuery and Elementor's
 * editor and checks what the owner select ends up showing.
 *
 * Usage: node tests/js/test-elementor-editor.js
 */

'use strict';

const fs = require( 'fs' );
const path = require( 'path' );
const vm = require( 'vm' );

const SOURCE = fs.readFileSync( path.join( __dirname, '../../raynet-lead-api-integration/assets/js/raynet-elementor-editor.js' ), 'utf8' );

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

// A select as jQuery sees it: options in order and one selected value.
function fakeSelect() {
	const select = {
		length: 1,
		options: [],
		value: '',
		empty() {
			this.options = [];
			this.value = '';
			return this;
		},
		append( option ) {
			this.options.push( option );
			return this;
		},
		val( value ) {
			// Like a real select: a value with no option selects nothing.
			this.value = this.options.some( ( option ) => option.value === value ) ? value : '';
			return this;
		},
		first() {
			return this;
		},
	};
	return select;
}

/**
 * Loads the script and opens a RAYNET section whose owner control is saved
 * with the given value.
 */
function open( saved, options ) {
	options = options || {};

	const handlers = {};
	const select = fakeSelect();
	const settingsWrites = [];
	const settings = {
		get: ( name ) => ( 'raynet_crm_owner' === name ? saved : undefined ),
		set: ( name, value ) => settingsWrites.push( [ name, value ] ),
	};
	const ownerModel = {
		cid: 'c1',
		get: ( key ) => ( 'type' === key ? ( options.type || 'select' ) : undefined ),
	};
	const editor = {
		collection: {
			findWhere: ( query ) => ( 'raynet_crm_owner' === query.name ? ownerModel : undefined ),
		},
		children: {
			findByModelCid: ( cid ) => ( 'c1' === cid ? { $el: { find: () => select } } : null ),
		},
		getOption: ( name ) => ( 'editedElementView' === name ? { getEditModel: () => ( { get: () => settings } ) } : null ),
	};
	const jQuery = ( target ) => ( {
		on: ( event, handler ) => {
			handlers[ event ] = handler;
		},
		target,
	} );
	const context = {
		jQuery,
		window: {},
		Option: function ( text, value ) {
			this.text = String( text );
			this.value = String( value );
		},
		elementor: {
			channels: {
				editor: {
					on: ( event, handler ) => {
						handlers[ event ] = handler;
					},
				},
			},
		},
		raynetElementorEditor: {
			rows: [],
			owners: 'owners' in options ? options.owners : [
				{ id: '11', label: 'Jana Nováková' },
				{ id: '9', label: 'Petr Svoboda' },
				{ id: '102', label: 'Adam Černý' },
			],
			ownerInherit: 'Zdědit z nastavení pluginu',
			ownerUnknown: 'ID %s (není mezi načtenými uživateli)',
		},
	};

	vm.runInNewContext( SOURCE, context );
	handlers[ 'elementor:init' ]();
	handlers[ 'section:activated' ]( options.section || 'section_raynet_crm', editor );

	return {
		values: select.options.map( ( option ) => option.value ),
		labels: select.options.map( ( option ) => option.text ),
		selected: select.value,
		writes: settingsWrites,
	};
}

// Known owner: inherit first, people in the order PHP sent them.
let shown = open( '9' );
check( 'zdědit je první', shown.labels[ 0 ], 'Zdědit z nastavení pluginu' );
check( 'pořadí podle PHP, ne podle čísla', shown.values, [ '', '11', '9', '102' ] );
check( 'jména u lidí', shown.labels, [ 'Zdědit z nastavení pluginu', 'Jana Nováková', 'Petr Svoboda', 'Adam Černý' ] );
check( 'uložený vlastník vybraný', shown.selected, '9' );
check( 'nic se neuloží', shown.writes, [] );

// Elementor may hold the saved value as a number.
shown = open( 9 );
check( 'číselná hodnota vybraná', shown.selected, '9' );
check( 'číselná hodnota nepřidá neznámého', shown.values.length, 4 );

// An owner nobody listed stays visible and selected.
shown = open( '14' );
check( 'neznámý vlastník přidán na konec', shown.values, [ '', '11', '9', '102', '14' ] );
check( 'neznámý vlastník popsaný', shown.labels[ 4 ], 'ID 14 (není mezi načtenými uživateli)' );
check( 'neznámý vlastník vybraný', shown.selected, '14' );
check( 'neznámý vlastník se neuloží', shown.writes, [] );

// Nothing set, in each form Elementor may keep it.
[ '', 0, '0', null, undefined ].forEach( ( saved ) => {
	shown = open( saved );
	check( 'bez vlastníka (' + JSON.stringify( saved ) + ') je zdědit', shown.selected, '' );
	check( 'bez vlastníka (' + JSON.stringify( saved ) + ') nic nepřidá', shown.values.length, 4 );
} );

// No people listed: the saved owner is still the one shown.
shown = open( '14', { owners: [] } );
check( 'bez seznamu jen zdědit a uložený', shown.values, [ '', '14' ] );
check( 'bez seznamu vybraný uložený', shown.selected, '14' );

// A number box (no users fetched) is left alone.
shown = open( '14', { type: 'number' } );
check( 'číselné pole se nemění', shown.values, [] );

// Another section of the form does not touch the owner.
shown = open( '9', { section: 'section_email' } );
check( 'jiná sekce se nemění', shown.values, [] );

console.log( '\n' + pass + ' passed, ' + fail + ' failed' );
process.exit( fail > 0 ? 1 : 0 );
