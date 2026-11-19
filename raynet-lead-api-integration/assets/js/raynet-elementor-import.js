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
	 * POST data of one AI review request.
	 *
	 * @param {Object} settings Localized config.
	 * @param {number} after    Cursor: only submissions above this id.
	 * @param {number} size     Smaller batch the server asked for; 0 for its default.
	 * @return {Object} Data.
	 */
	function aiData( settings, after, size ) {
		var data = {
			action: ( settings.ai || {} ).action,
			_wpnonce: settings.nonce,
			form: settings.form,
			after: after
		};

		if ( size > 0 ) {
			data.size = size;
		}

		return data;
	}

	window.raynetImportAiData = aiData;

	/**
	 * Has the server judge batch after batch, never two at once.
	 *
	 * sendBatch( after, size ) returns a Promise of { ok, done, judged,
	 * skipped, remaining, import, reject, next, retry_size, retry_until,
	 * code, message }; a rejected Promise is an error. size is 0 for the
	 * server's default batch.
	 *
	 * The server answers a batch the model refused as a whole with a smaller
	 * retry_size and the cursor unmoved: the same rows are asked for again in
	 * smaller batches until the cursor passes retry_until, then the default
	 * size is back. The run ends when the server says done, on the first
	 * error, after stop() once the batch on its way is back, and when the
	 * cursor does not move without the batch getting smaller (the same rows
	 * would be asked for again).
	 *
	 * @param {Function} sendBatch  Sends one batch.
	 * @param {Function} onProgress Called with the totals after each batch.
	 * @param {Function} onDone     Called once with { status: done|stopped|error, code, message, judged, skipped, import, reject, remaining }.
	 * @return {Object} { stop() }.
	 */
	function aiRun( sendBatch, onProgress, onDone ) {
		var totals = { status: '', code: '', message: '', judged: 0, skipped: 0, import: 0, reject: 0, remaining: 0 };
		var stopped = false;
		var size = 0;
		var until = 0;

		function end( status, code, message ) {
			totals.status = status;
			totals.code = code || '';
			totals.message = message || '';
			onDone( totals );
		}

		function next( after ) {
			var request;

			try {
				request = Promise.resolve( sendBatch( after, size ) );
			} catch ( error ) {
				request = Promise.reject( error );
			}

			request
				.then( null, function () {
					return { ok: false, code: '', message: '' };
				} )
				.then( function ( result ) {
					result = result || { ok: false, code: '', message: '' };

					if ( ! result.ok ) {
						end( 'error', result.code, result.message );
						return;
					}

					totals.judged += parseInt( result.judged, 10 ) || 0;
					totals.skipped += parseInt( result.skipped, 10 ) || 0;
					totals.import += parseInt( result.import, 10 ) || 0;
					totals.reject += parseInt( result.reject, 10 ) || 0;
					totals.remaining = parseInt( result.remaining, 10 ) || 0;
					onProgress( totals );

					var cursor = parseInt( result.next, 10 ) || 0;
					var retry = parseInt( result.retry_size, 10 ) || 0;

					if ( result.done ) {
						end( 'done' );
					} else if ( stopped ) {
						end( 'stopped' );
					} else if ( retry > 0 ) {
						// Only ever smaller: a retry that does not shrink would loop.
						if ( size > 0 && retry >= size ) {
							end( 'error', 'raynet_ai_no_progress', '' );
							return;
						}

						size = retry;
						until = parseInt( result.retry_until, 10 ) || 0;
						next( after );
					} else if ( cursor <= after ) {
						end( 'error', 'raynet_ai_no_progress', '' );
					} else {
						if ( cursor >= until ) {
							size = 0;
							until = 0;
						}

						next( cursor );
					}
				} );
		}

		next( 0 );

		return {
			stop: function () {
				stopped = true;
			}
		};
	}

	window.raynetImportAiRun = aiRun;

	/**
	 * Reads one answer of an AI handler.
	 *
	 * @param {Object} json Response body.
	 * @return {Object} Result for aiRun().
	 */
	function readAiAnswer( json ) {
		var data = json.data || {};

		if ( json.success ) {
			return {
				ok: true,
				done: !! data.done,
				judged: data.judged,
				skipped: data.skipped,
				remaining: data.remaining,
				retry_size: data.retry_size,
				retry_until: data.retry_until,
				import: data.import,
				reject: data.reject,
				next: data.next,
				deleted: data.deleted,
				code: '',
				message: ''
			};
		}

		return { ok: false, code: String( data.code || '' ), message: String( data.message || '' ) };
	}

	/**
	 * Posts to an AI handler of the screen.
	 *
	 * @param {Object} data POST data.
	 * @return {Promise} Result of readAiAnswer().
	 */
	function postAi( data ) {
		return new Promise( function ( resolve, reject ) {
			$.ajax( {
				url: config.ajaxUrl,
				type: 'POST',
				dataType: 'json',
				// One batch is one OpenAI call of up to 60 s, plus the index;
				// the server's lock expires after 150 s.
				timeout: 150000,
				data: data
			} )
				.done( function ( json ) {
					if ( json && 'boolean' === typeof json.success ) {
						resolve( readAiAnswer( json ) );
					} else {
						reject( new Error( 'unreadable' ) );
					}
				} )
				.fail( function ( xhr ) {
					var json = xhr && xhr.responseJSON;

					if ( json && 'boolean' === typeof json.success && json.data ) {
						resolve( readAiAnswer( json ) );
					} else {
						reject( new Error( 'no answer' ) );
					}
				} );
		} );
	}

	/**
	 * Wires the AI review controls.
	 *
	 * @return {void}
	 */
	function initAi() {
		var ai = config.ai || {};
		var $start = $( '#raynet-import-ai' );
		var $stop = $( '#raynet-import-ai-stop' );
		var $forget = $( '#raynet-import-ai-forget' );
		var $progress = $( '#raynet-import-ai-progress' );
		var run = null;

		function fail( message ) {
			$progress.empty().append( $( '<span class="raynet-import__ai-error">' ).text( message || ai.failed ) );
		}

		$start.on( 'click', function ( event ) {
			var count = parseInt( $start.attr( 'data-count' ), 10 ) || 0;

			event.preventDefault();

			if ( run || ! count || ! window.confirm( format( ai.confirm, [ count ] ) ) ) {
				return;
			}

			$start.prop( 'disabled', true );
			$forget.prop( 'disabled', true );
			$stop.prop( 'hidden', false ).prop( 'disabled', false );
			$progress.text( format( ai.progress, [ 0, count ] ) );

			run = aiRun(
				function ( after, size ) {
					return postAi( aiData( config, after, size ) );
				},
				function ( totals ) {
					$progress.text( format( ai.progress, [ totals.judged + totals.skipped, count ] ) );
				},
				function ( end ) {
					$stop.prop( 'hidden', true );

					if ( 'error' === end.status ) {
						// What was judged before the error is stored: the
						// count on the button must not offer it again.
						var left = Math.max( 0, count - end.judged - end.skipped );

						run = null;
						$start.attr( 'data-count', String( left ) ).text( format( ai.label, [ left ] ) ).prop( 'disabled', 0 === left );
						$forget.prop( 'disabled', false );
						fail( end.message );
						return;
					}

					$progress.text( 'done' === end.status ? format( ai.summary, [ end.import, end.reject, end.remaining ] ) : ai.stopped );
					window.location.reload();
				}
			);
		} );

		$stop.on( 'click', function ( event ) {
			event.preventDefault();

			if ( run ) {
				run.stop();
				$stop.prop( 'disabled', true );
				$progress.text( ai.stopping );
			}
		} );

		$forget.on( 'click', function ( event ) {
			event.preventDefault();

			if ( run || ! window.confirm( ai.forgetConfirm ) ) {
				return;
			}

			$forget.prop( 'disabled', true );

			postAi( { action: ai.forgetAction, _wpnonce: config.nonce, form: config.form } ).then(
				function ( result ) {
					if ( result.ok ) {
						window.location.reload();
					} else {
						$forget.prop( 'disabled', false );
						fail( result.message );
					}
				},
				function () {
					$forget.prop( 'disabled', false );
					fail( '' );
				}
			);
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

		// The panel is shown on an empty page of a tab too.
		initAi();

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
