/**
 * Keeps the RAYNET field mapping in the Elementor editor up to date.
 *
 * The mapping control's rows come from PHP as a default, and Elementor only
 * uses a default for a form that has never been saved with the action. A form
 * mapped before a custom field existed in RAYNET would never show it. When the
 * RAYNET section opens, the rows are rebuilt from the current list; Elementor's
 * own updateMap() keeps every choice already made.
 *
 * @package RaynetLeadApiIntegration
 */

/* global jQuery, elementor, raynetElementorEditor */

( function ( $ ) {
	'use strict';

	var SECTION = 'section_raynet_crm';
	var CONTROL = 'raynet_crm_fields_map';
	var NOTICE_CONTROL = 'raynet_crm_notice_fields';
	var CUSTOM_PREFIX = 'cf:';
	var UNSENDABLE = [ 'upload', 'password', 'recaptcha', 'recaptcha_v3', 'honeypot', 'html', 'step' ];

	function signature( rows ) {
		return rows.map( function ( row ) {
			return row.remote_id + '|' + row.remote_label;
		} ).join( '\n' );
	}

	function sync( editor ) {
		if ( ! editor || ! editor.collection || ! editor.children ) {
			return;
		}

		var model = editor.collection.findWhere( { name: CONTROL } );
		var view = model ? editor.children.findByModelCid( model.cid ) : null;

		if ( ! view || 'function' !== typeof view.updateMap || ! view.collection ) {
			return;
		}

		var wanted = ( raynetElementorEditor.rows || [] ).slice();
		var known = {};

		wanted.forEach( function ( row ) {
			known[ row.remote_id ] = true;
		} );

		// A custom field this site has not fetched yet — or failed to fetch —
		// would otherwise lose its mapping the moment the section opens.
		view.collection.each( function ( row ) {
			var id = row.get( 'remote_id' );

			if ( id && ! known[ id ] && 0 === id.indexOf( CUSTOM_PREFIX ) && row.get( 'local_id' ) ) {
				wanted.push( {
					remote_id: id,
					remote_label: row.get( 'remote_label' ) || id,
					remote_type: 'text',
				} );
			}
		} );

		var current = view.collection.map( function ( row ) {
			return { remote_id: row.get( 'remote_id' ), remote_label: row.get( 'remote_label' ) };
		} );

		// Rebuilding marks the page as changed, so only do it when it matters.
		if ( signature( current ) === signature( wanted ) ) {
			return;
		}

		view.updateMap( wanted );
	}

	/*
	 * The note picker lists the form's own fields. PHP registers the control
	 * once for every form, so the options can only come from here.
	 */
	function fillNoticeOptions( editor ) {
		var model = editor.collection.findWhere( { name: NOTICE_CONTROL } );
		var view = model ? editor.children.findByModelCid( model.cid ) : null;
		var element = editor.getOption( 'editedElementView' );

		if ( ! view || ! element ) {
			return;
		}

		var fields = element.getEditModel().get( 'settings' ).get( 'form_fields' );
		var options = {};

		if ( fields && fields.models ) {
			fields.models.forEach( function ( field, index ) {
				var id = field.get( 'custom_id' );

				if ( id && -1 === UNSENDABLE.indexOf( field.get( 'field_type' ) ) ) {
					options[ id ] = field.get( 'field_label' ) || ( 'Pole #' + ( index + 1 ) );
				}
			} );
		}

		if ( JSON.stringify( model.get( 'options' ) ) === JSON.stringify( options ) ) {
			return;
		}

		model.set( 'options', options );
		view.render();
	}

	$( window ).on( 'elementor:init', function () {
		elementor.channels.editor.on( 'section:activated', function ( sectionName, editor ) {
			if ( SECTION === sectionName && editor && editor.collection && editor.children ) {
				sync( editor );
				fillNoticeOptions( editor );
			}
		} );
	} );
}( jQuery ) );
