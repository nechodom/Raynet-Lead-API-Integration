/**
 * Admin helper: tests the stored RAYNET credentials and lists code list IDs.
 */
(function () {
	'use strict';

	var config = window.raynetLeadAdmin || {};

	/**
	 * Renders the code lists returned by the connection test.
	 *
	 * @param {Object} lists Label => { id: name } map.
	 * @return {string} HTML.
	 */
	function renderLists( lists ) {
		var html = '';

		Object.keys( lists || {} ).forEach( function ( label ) {
			var rows = lists[ label ];
			var items = Object.keys( rows ).map( function ( id ) {
				return '<li><code>' + id + '</code> — ' + rows[ id ] + '</li>';
			} );

			if ( items.length ) {
				html += '<p><strong>' + label + '</strong></p><ul class="raynet-code-list">' + items.join( '' ) + '</ul>';
			}
		} );

		return html;
	}

	/**
	 * Wires the "test connection" button.
	 */
	function init() {
		var button = document.getElementById( 'raynet-test-connection' );
		var output = document.getElementById( 'raynet-test-result' );

		if ( ! button || ! output ) {
			return;
		}

		button.addEventListener( 'click', function () {
			button.disabled = true;
			output.className = 'raynet-test-result';
			output.textContent = config.testing || '';

			var data = new FormData();
			data.append( 'action', 'raynet_lead_test_connection' );
			data.append( 'nonce', config.nonce );

			fetch( config.ajaxUrl, {
				method: 'POST',
				body: data,
				credentials: 'same-origin'
			} )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( payload ) {
					if ( payload && payload.success ) {
						output.className = 'raynet-test-result is-success';
						output.innerHTML = '<p>' + payload.data.message + '</p>' + renderLists( payload.data.codeLists );
						return;
					}

					output.className = 'raynet-test-result is-error';
					output.textContent = payload && payload.data && payload.data.message
						? payload.data.message
						: config.failed;
				} )
				.catch( function () {
					output.className = 'raynet-test-result is-error';
					output.textContent = config.failed;
				} )
				.then( function () {
					button.disabled = false;
				} );
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
})();
