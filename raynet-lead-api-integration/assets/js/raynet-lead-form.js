/**
 * Front end handler for [raynet_lead_form].
 *
 * Posts to admin-ajax.php; the RAYNET credentials never reach the browser.
 */
(function () {
	'use strict';

	var config = window.raynetLeadForm || {};

	/**
	 * Shows a message inside the form's notification area.
	 *
	 * @param {HTMLFormElement} form  Form element.
	 * @param {string}          text  Message text.
	 * @param {string}          state Either "success" or "error".
	 */
	function notify( form, text, state ) {
		var box = form.querySelector( '.raynet-lead-form__notification' );

		if ( ! box ) {
			return;
		}

		box.textContent = text;
		box.className = state
			? 'raynet-lead-form__notification is-' + state
			: 'raynet-lead-form__notification';
	}

	/**
	 * Sends the form data once.
	 *
	 * @param {HTMLFormElement} form Form element.
	 * @return {Promise<Object>} Parsed JSON response.
	 */
	function send( form ) {
		var data = new FormData( form );
		data.append( 'action', config.action );

		return fetch( config.ajaxUrl, {
			method: 'POST',
			body: data,
			credentials: 'same-origin'
		} ).then( function ( response ) {
			return response.json().catch( function () {
				return { success: false, data: { message: config.genericError } };
			} );
		} );
	}

	/**
	 * Fetches a fresh nonce, for pages served from a full page cache.
	 *
	 * @return {Promise<string|null>} New nonce, or null.
	 */
	function refreshNonce() {
		var data = new FormData();
		data.append( 'action', config.nonceAction );

		return fetch( config.ajaxUrl, {
			method: 'POST',
			body: data,
			credentials: 'same-origin'
		} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( payload ) {
				return payload && payload.success && payload.data ? payload.data.nonce : null;
			} )
			.catch( function () {
				return null;
			} );
	}

	/**
	 * Wires a single form.
	 *
	 * @param {HTMLFormElement} form Form element.
	 */
	function bind( form ) {
		var button = form.querySelector( '.raynet-lead-form__submit' );
		var label = button ? button.textContent : '';
		var busy = false;

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();

			if ( busy ) {
				return;
			}

			if ( ! form.checkValidity() ) {
				form.reportValidity();
				return;
			}

			busy = true;
			notify( form, '', '' );

			if ( button ) {
				button.disabled = true;
				button.textContent = config.sending || label;
			}

			send( form )
				.then( function ( payload ) {
					var isExpired = payload && ! payload.success && payload.data && 'raynet_nonce_expired' === payload.data.code;

					if ( ! isExpired ) {
						return payload;
					}

					// The page was probably served from a cache with a stale nonce.
					return refreshNonce().then( function ( nonce ) {
						var field = form.querySelector( 'input[name="raynet_nonce"]' );

						if ( ! nonce || ! field ) {
							return payload;
						}

						field.value = nonce;

						return send( form );
					} );
				} )
				.then( function ( payload ) {
					if ( payload && payload.success ) {
						notify( form, payload.data.message, 'success' );
						form.reset();

						// The shortcode's own redirect attribute wins over the global setting.
						var redirect = form.getAttribute( 'data-redirect' ) || payload.data.redirect;

						if ( redirect ) {
							window.location.assign( redirect );
						}

						return;
					}

					var message = payload && payload.data && payload.data.message
						? payload.data.message
						: config.genericError;

					notify( form, message, 'error' );
				} )
				.catch( function () {
					notify( form, config.genericError, 'error' );
				} )
				.then( function () {
					busy = false;

					if ( button ) {
						button.disabled = false;
						button.textContent = label;
					}
				} );
		} );
	}

	/**
	 * Binds every form on the page.
	 */
	function init() {
		var forms = document.querySelectorAll( '.raynet-lead-form' );
		Array.prototype.forEach.call( forms, bind );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
})();
