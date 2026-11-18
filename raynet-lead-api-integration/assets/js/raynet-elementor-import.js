/**
 * Import screen for stored Elementor submissions: sends the ticked rows to
 * RAYNET one by one and shows each result in its row.
 */
( function ( $ ) {
	'use strict';

	var config = window.raynetElementorImport || {};

	/**
	 * Replaces %d / %1$d / %2$d in a translated text, in order.
	 *
	 * @param {string} text   Text with placeholders.
	 * @param {Array}  values Values.
	 * @return {string} Text.
	 */
	function format( text, values ) {
		var next = 0;

		return String( text || '' ).replace( /%(?:(\d)\$)?d/g, function ( match, position ) {
			var index = position ? parseInt( position, 10 ) - 1 : next++;

			return String( values[ index ] );
		} );
	}

	/**
	 * What one answer means for its row.
	 *
	 * A lead that was created but could not be marked as sent (code
	 * raynet_import_unlogged) counts as sent: it must not be retried. No
	 * answer, or the server saying RAYNET timed out or failed on its side
	 * (code raynet_import_unknown), is an unknown outcome: the lead may exist,
	 * so the row cannot be ticked again until the page is reloaded.
	 *
	 * @param {Object} result Result of sendOne().
	 * @return {Object} { outcome: sent|unlogged|unknown|failed, box: remove|disable|untick }.
	 */
	function view( result ) {
		if ( result.ok ) {
			return { outcome: 'sent', box: 'remove' };
		}

		if ( 'raynet_import_unlogged' === result.code ) {
			return { outcome: 'unlogged', box: 'remove' };
		}

		if ( result.unknown || 'raynet_import_unknown' === result.code ) {
			return { outcome: 'unknown', box: 'disable' };
		}

		return { outcome: 'failed', box: 'untick' };
	}

	window.raynetImportRowView = view;

	/**
	 * Sends submissions one after another, never two at once.
	 *
	 * sendOne( id ) returns a Promise of { ok, leadId, code, message, unknown };
	 * a rejected Promise means the server did not answer, so the outcome is
	 * unknown. Each result is counted by view().
	 *
	 * @param {number[]} ids      Submission ids, in the order to send them.
	 * @param {Function} sendOne  Sends one id.
	 * @param {Function} onResult Called with ( id, result, done, total ) after each.
	 * @param {Function} onDone   Called with { sent, failed, unknown } at the end.
	 * @return {void}
	 */
	function queue( ids, sendOne, onResult, onDone ) {
		var summary = { sent: 0, failed: 0, unknown: 0 };
		var position = 0;

		function next() {
			if ( position >= ids.length ) {
				onDone( summary );
				return;
			}

			var id = ids[ position++ ];
			var request;

			try {
				request = Promise.resolve( sendOne( id ) );
			} catch ( error ) {
				request = Promise.reject( error );
			}

			request
				.then( null, function () {
					return { ok: false, leadId: 0, code: '', message: '', unknown: true };
				} )
				.then( function ( result ) {
					result = result || { ok: false, leadId: 0, code: '', message: '', unknown: true };

					var outcome = view( result ).outcome;

					if ( 'unknown' === outcome ) {
						summary.unknown++;
					} else if ( 'failed' === outcome ) {
						summary.failed++;
					} else {
						summary.sent++;
					}

					onResult( id, result, position, ids.length );
					next();
				} );
		}

		next();
	}

	window.raynetImportQueue = queue;

	/**
	 * Reads one answer of the AJAX handler.
	 *
	 * @param {Object} json Response body.
	 * @return {Object} Result for the queue.
	 */
	function readAnswer( json ) {
		var data = json.data || {};

		if ( json.success ) {
			return { ok: true, leadId: parseInt( data.lead_id, 10 ) || 0, code: '', message: '', unknown: false };
		}

		return {
			ok: false,
			leadId: parseInt( data.lead_id, 10 ) || 0,
			code: String( data.code || '' ),
			message: String( data.message || '' ),
			unknown: false
		};
	}

	/**
	 * Sends one submission to the server.
	 *
	 * Only a JSON answer from the handler counts as an answer. A timeout, a
	 * dropped connection or an error page may have come after the lead was
	 * created, so those reject and the row says the outcome is unknown.
	 *
	 * @param {number} id Submission id.
	 * @return {Promise} Result.
	 */
	function sendOne( id ) {
		return new Promise( function ( resolve, reject ) {
			$.ajax( {
				url: config.ajaxUrl,
				type: 'POST',
				dataType: 'json',
				timeout: 120000,
				data: {
					action: 'raynet_elm_import_one',
					_wpnonce: config.nonce,
					form: config.form,
					submission: id,
					tag: $( '#raynet-import-tag' ).val() || ''
				}
			} )
				.done( function ( json ) {
					if ( json && 'boolean' === typeof json.success ) {
						resolve( readAnswer( json ) );
					} else {
						reject( new Error( 'unreadable' ) );
					}
				} )
				.fail( function ( xhr ) {
					var json = xhr && xhr.responseJSON;

					if ( json && 'boolean' === typeof json.success && json.data ) {
						resolve( readAnswer( json ) );
					} else {
						reject( new Error( 'no answer' ) );
					}
				} );
		} );
	}

	/**
	 * Wires the import table.
	 *
	 * @return {void}
	 */
	function init() {
		var $table = $( 'table.raynet-import' );
		var $send = $( '#raynet-import-send' );
		var $all = $( '#raynet-import-all' );
		var $progress = $( '#raynet-import-progress' );
		var running = false;

		if ( ! $table.length ) {
			return;
		}

		function boxes() {
			return $table.find( 'input[name="submissions[]"]' );
		}

		function updateCount() {
			var count = boxes().filter( ':checked' ).length;

			$send.text( format( config.sendLabel, [ count ] ) ).prop( 'disabled', running || 0 === count );
		}

		function showResult( id, result ) {
			var $row = $table.find( 'tr[data-submission="' + id + '"]' );
			var $cell = $row.find( '.raynet-import__result' );
			var $box = $row.find( 'input[name="submissions[]"]' );

			var shown = view( result );

			$row.removeClass( 'is-sent is-warning is-error is-unknown' );

			if ( 'remove' === shown.box ) {
				$box.remove();
			} else if ( 'disable' === shown.box ) {
				// RAYNET may have the lead: unticked until the page is reloaded.
				$box.prop( 'checked', false ).prop( 'disabled', true );
			} else {
				$box.prop( 'checked', false );
			}

			if ( 'sent' === shown.outcome ) {
				$row.addClass( 'is-sent' );
				$cell.text( format( config.sent, [ result.leadId ] ) );
			} else if ( 'unlogged' === shown.outcome ) {
				// The lead exists: never offer it again from this page.
				$row.addClass( 'is-warning' );
				$cell.empty().append(
					$( '<strong>' ).text( format( config.sent, [ result.leadId ] ) ),
					' ',
					$( '<span>' ).text( result.message )
				);
			} else if ( 'unknown' === shown.outcome ) {
				$row.addClass( 'is-unknown' );
				$cell.text( result.message || config.unknown );
			} else {
				$row.addClass( 'is-error' );
				$cell.text( result.message || config.failed );
			}
		}

		$table.on( 'click', '.raynet-import__toggle', function () {
			var $button = $( this );
			var $detail = $table.find( 'tr[data-detail="' + parseInt( $button.attr( 'data-toggle' ), 10 ) + '"]' );
			var open = $detail.prop( 'hidden' );

			$detail.prop( 'hidden', ! open );
			$button.attr( 'aria-expanded', open ? 'true' : 'false' );
		} );

		$all.on( 'change', function () {
			boxes().not( ':disabled' ).prop( 'checked', this.checked );
			updateCount();
		} );

		$table.on( 'change', 'input[name="submissions[]"]', updateCount );

		$send.on( 'click', function ( event ) {
			var ids = boxes().filter( ':checked' ).map( function () {
				return parseInt( this.value, 10 );
			} ).get();

			event.preventDefault();

			if ( running || ! ids.length || ! window.confirm( format( config.confirm, [ ids.length ] ) ) ) {
				return;
			}

			running = true;
			$all.prop( 'disabled', true );
			$progress.text( format( config.progress, [ 0, ids.length ] ) );
			updateCount();

			queue(
				ids,
				sendOne,
				function ( id, result, done, total ) {
					showResult( id, result );
					$progress.text( format( config.progress, [ done, total ] ) );
				},
				function ( summary ) {
					running = false;
					$all.prop( 'disabled', false ).prop( 'checked', false );
					$progress.text( format( config.summary, [ summary.sent, summary.failed, summary.unknown ] ) );
					updateCount();
				}
			);
		} );

		updateCount();
	}

	$( init );
}( jQuery ) );
