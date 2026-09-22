/**
 * The form builder screen.
 *
 * The hidden JSON input is the source of truth; everything here reads from it
 * and writes back to it, so a plain form submit saves whatever is on screen.
 */
(function () {
	'use strict';

	var config = window.raynetFormBuilder || {};
	var i18n = config.i18n || {};

	var store = document.getElementById( 'raynet-form-fields-json' );
	var list = document.getElementById( 'raynet-builder-list' );
	var addSelect = document.getElementById( 'raynet-builder-add' );
	var addButton = document.getElementById( 'raynet-builder-add-button' );
	var preview = document.getElementById( 'raynet-builder-preview' );

	if ( ! store || ! list ) {
		return;
	}

	var fields = [];
	var openId = null;
	var dragFrom = null;
	var previewTimer = null;

	/**
	 * Makes an element with attributes and children in one call.
	 *
	 * @param {string} tag   Tag name.
	 * @param {Object} attrs Attributes; `text` sets textContent, `onClick` binds a handler.
	 * @param {Array}  kids  Child nodes.
	 * @return {HTMLElement} The element.
	 */
	function el( tag, attrs, kids ) {
		var node = document.createElement( tag );

		Object.keys( attrs || {} ).forEach( function ( key ) {
			var value = attrs[ key ];

			if ( 'text' === key ) {
				node.textContent = value;
			} else if ( 'onClick' === key ) {
				node.addEventListener( 'click', value );
			} else if ( 'onInput' === key ) {
				node.addEventListener( 'input', value );
			} else if ( 'onChange' === key ) {
				node.addEventListener( 'change', value );
			} else if ( true === value ) {
				node.setAttribute( key, key );
			} else if ( false !== value && null !== value && undefined !== value ) {
				node.setAttribute( key, value );
			}
		} );

		( kids || [] ).forEach( function ( kid ) {
			if ( kid ) {
				node.appendChild( kid );
			}
		} );

		return node;
	}

	/**
	 * Finds a source's catalogue entry.
	 *
	 * @param {string} source Source key.
	 * @return {Object|null} Catalogue entry.
	 */
	function sourceMeta( source ) {
		var found = null;

		( config.sources || [] ).forEach( function ( item ) {
			if ( item.source === source ) {
				found = item;
			}
		} );

		return found;
	}

	/**
	 * Writes the state back to the hidden input and refreshes the preview.
	 */
	function persist() {
		store.value = JSON.stringify( fields );

		window.clearTimeout( previewTimer );
		previewTimer = window.setTimeout( refreshPreview, 400 );
	}

	/**
	 * Re-renders the list from state.
	 */
	function render() {
		list.textContent = '';

		if ( ! fields.length ) {
			list.appendChild( el( 'p', { 'class': 'raynet-builder__empty', text: i18n.empty || '' } ) );
		}

		fields.forEach( function ( field, index ) {
			list.appendChild( rowEl( field, index ) );
		} );

		renderAddOptions();
		renderWarnings();
	}

	/**
	 * Builds one row.
	 *
	 * @param {Object} field Field definition.
	 * @param {number} index Position.
	 * @return {HTMLElement} Row.
	 */
	function rowEl( field, index ) {
		var isOpen = openId === field.id;

		var header = el( 'div', {
			'class': 'raynet-builder__row-head',
			draggable: 'true'
		}, [
			el( 'span', { 'class': 'raynet-builder__grip', 'aria-hidden': 'true', text: '⠿' } ),
			el( 'button', {
				type: 'button',
				'class': 'raynet-builder__row-toggle',
				'aria-expanded': isOpen ? 'true' : 'false',
				text: field.label || '',
				onClick: function () {
					openId = isOpen ? null : field.id;
					render();
				}
			} ),
			badgeEl( field ),
			el( 'button', {
				type: 'button',
				'class': 'button-link raynet-builder__move',
				title: i18n.moveUp || '',
				'aria-label': ( i18n.moveUp || '' ) + ': ' + ( field.label || '' ),
				text: '↑',
				onClick: function () {
					move( index, index - 1 );
				}
			} ),
			el( 'button', {
				type: 'button',
				'class': 'button-link raynet-builder__move',
				title: i18n.moveDown || '',
				'aria-label': ( i18n.moveDown || '' ) + ': ' + ( field.label || '' ),
				text: '↓',
				onClick: function () {
					move( index, index + 1 );
				}
			} )
		] );

		header.addEventListener( 'dragstart', function ( event ) {
			dragFrom = index;
			event.dataTransfer.effectAllowed = 'move';
			// Firefox refuses to start a drag without payload.
			event.dataTransfer.setData( 'text/plain', String( index ) );
		} );

		header.addEventListener( 'dragover', function ( event ) {
			event.preventDefault();
			event.dataTransfer.dropEffect = 'move';
		} );

		header.addEventListener( 'drop', function ( event ) {
			event.preventDefault();

			if ( null !== dragFrom ) {
				move( dragFrom, index );
				dragFrom = null;
			}
		} );

		var row = el( 'div', {
			'class': 'raynet-builder__row' + ( isOpen ? ' is-open' : '' )
		}, [ header ] );

		if ( isOpen ) {
			row.appendChild( editorEl( field, index ) );
		}

		return row;
	}

	/**
	 * Builds the badge showing where a field goes.
	 *
	 * @param {Object} field Field definition.
	 * @return {HTMLElement} Badge.
	 */
	function badgeEl( field ) {
		var text = field.source;
		var cls = 'raynet-builder__badge';

		if ( 'custom' === field.source ) {
			text = i18n.customLabel || 'custom';
			cls += ' is-custom';
		} else if ( 'consent' === field.source ) {
			cls += ' is-consent';
		}

		var wrap = el( 'span', { 'class': 'raynet-builder__badges' }, [
			el( 'span', { 'class': cls, text: text } )
		] );

		if ( field.required ) {
			wrap.appendChild( el( 'span', {
				'class': 'raynet-builder__badge is-required',
				text: i18n.required || ''
			} ) );
		}

		return wrap;
	}

	/**
	 * Builds the expanded editor for a field.
	 *
	 * @param {Object} field Field definition.
	 * @param {number} index Position.
	 * @return {HTMLElement} Editor.
	 */
	function editorEl( field, index ) {
		var rows = [];
		var uid = 'raynet-f-' + field.id;

		function set( key, value ) {
			fields[ index ][ key ] = value;
			persist();
		}

		// Label. Consent wording is long and may carry a link, so it gets a textarea.
		if ( 'consent' === field.source ) {
			rows.push( fieldGroup( uid + '-label', i18n.label, el( 'textarea', {
				id: uid + '-label',
				rows: '3',
				'class': 'widefat',
				onInput: function ( e ) {
					set( 'label', e.target.value );
					headText( field.id, e.target.value );
				}
			} ), field.label, i18n.consentNote ) );
		} else {
			rows.push( fieldGroup( uid + '-label', i18n.label, el( 'input', {
				type: 'text',
				id: uid + '-label',
				'class': 'widefat',
				onInput: function ( e ) {
					set( 'label', e.target.value );
					headText( field.id, e.target.value );
				}
			} ), field.label ) );
		}

		if ( 'custom' === field.source ) {
			var typeSelect = el( 'select', {
				id: uid + '-type',
				'class': 'widefat',
				onChange: function ( e ) {
					set( 'type', e.target.value );
					render();
				}
			}, ( config.customTypes || [] ).map( function ( option ) {
				return el( 'option', {
					value: option.value,
					text: option.label,
					selected: option.value === field.type
				} );
			} ) );

			rows.push( fieldGroup( uid + '-type', i18n.type, typeSelect, null, i18n.customNote ) );

			if ( 'select' === field.type ) {
				rows.push( fieldGroup( uid + '-options', i18n.options, el( 'textarea', {
					id: uid + '-options',
					rows: '4',
					'class': 'widefat',
					onInput: function ( e ) {
						set( 'options', e.target.value.split( '\n' ).map( function ( line ) {
							return line.trim();
						} ).filter( Boolean ) );
					}
				} ), ( field.options || [] ).join( '\n' ) ) );
			}
		}

		if ( 'consent' !== field.source && 'checkbox' !== field.type ) {
			rows.push( fieldGroup( uid + '-ph', i18n.placeholder, el( 'input', {
				type: 'text',
				id: uid + '-ph',
				'class': 'widefat',
				onInput: function ( e ) {
					set( 'placeholder', e.target.value );
				}
			} ), field.placeholder ) );
		}

		if ( 'consent' !== field.source ) {
			rows.push( fieldGroup( uid + '-help', i18n.help, el( 'input', {
				type: 'text',
				id: uid + '-help',
				'class': 'widefat',
				onInput: function ( e ) {
					set( 'help', e.target.value );
				}
			} ), field.help ) );
		}

		var controls = el( 'div', { 'class': 'raynet-builder__controls' } );

		if ( 'consent' !== field.source && 'textarea' !== field.type && 'checkbox' !== field.type ) {
			var widthSelect = el( 'select', {
				id: uid + '-width',
				onChange: function ( e ) {
					set( 'width', e.target.value );
				}
			}, [
				el( 'option', { value: 'full', text: i18n.widthFull, selected: 'full' === field.width } ),
				el( 'option', { value: 'half', text: i18n.widthHalf, selected: 'half' === field.width } )
			] );

			controls.appendChild( el( 'span', { 'class': 'raynet-builder__control' }, [
				el( 'label', { 'for': uid + '-width', text: i18n.width } ),
				widthSelect
			] ) );
		}

		if ( 'consent' !== field.source ) {
			controls.appendChild( el( 'label', { 'class': 'raynet-builder__control' }, [
				el( 'input', {
					type: 'checkbox',
					checked: field.required,
					onChange: function ( e ) {
						set( 'required', e.target.checked );
						render();
					}
				} ),
				document.createTextNode( ' ' + ( i18n.required || '' ) )
			] ) );
		}

		controls.appendChild( el( 'button', {
			type: 'button',
			'class': 'button-link raynet-builder__remove',
			text: i18n.remove || '',
			onClick: function () {
				fields.splice( index, 1 );
				openId = null;
				persist();
				render();
			}
		} ) );

		rows.push( controls );

		if ( 'custom' !== field.source && 'consent' !== field.source ) {
			rows.push( el( 'p', {
				'class': 'raynet-builder__note',
				text: ( i18n.mappedNote || '%s' ).replace( '%s', field.source )
			} ) );
		}

		return el( 'div', { 'class': 'raynet-builder__editor' }, rows );
	}

	/**
	 * Builds a labelled control.
	 *
	 * @param {string}      id      Control id.
	 * @param {string}      label   Label text.
	 * @param {HTMLElement} control The input.
	 * @param {string}      value   Initial value.
	 * @param {string}      note    Optional description.
	 * @return {HTMLElement} Group.
	 */
	function fieldGroup( id, label, control, value, note ) {
		if ( null !== value && undefined !== value ) {
			control.value = value;
		}

		var kids = [
			el( 'label', { 'for': id, text: label || '' } ),
			control
		];

		if ( note ) {
			kids.push( el( 'p', { 'class': 'raynet-builder__note', text: note } ) );
		}

		return el( 'div', { 'class': 'raynet-builder__group' }, kids );
	}

	/**
	 * Updates a row's title while its label is being typed.
	 *
	 * @param {string} id   Field id.
	 * @param {string} text New label.
	 */
	function headText( id, text ) {
		var row = list.querySelector( '[aria-expanded="true"]' );

		if ( row && openId === id ) {
			row.textContent = text;
		}
	}

	/**
	 * Moves a field, clamping to the ends.
	 *
	 * @param {number} from Source index.
	 * @param {number} to   Target index.
	 */
	function move( from, to ) {
		if ( to < 0 || to >= fields.length || from === to ) {
			return;
		}

		var moved = fields.splice( from, 1 )[ 0 ];
		fields.splice( to, 0, moved );
		persist();
		render();
	}

	/**
	 * Fills the "add field" dropdown with what is still available.
	 */
	function renderAddOptions() {
		if ( ! addSelect ) {
			return;
		}

		var used = fields.map( function ( f ) {
			return f.source;
		} );

		addSelect.textContent = '';

		( config.sources || [] ).forEach( function ( item ) {
			if ( used.indexOf( item.source ) !== -1 ) {
				return;
			}

			addSelect.appendChild( el( 'option', { value: item.source, text: item.label } ) );
		} );

		addSelect.appendChild( el( 'option', { value: 'custom', text: i18n.customOption || '' } ) );
	}

	/**
	 * Shows the warnings that would otherwise only surface as a failed submission.
	 */
	function renderWarnings() {
		var existing = document.getElementById( 'raynet-builder-warning' );

		if ( existing ) {
			existing.remove();
		}

		var sources = fields.map( function ( f ) {
			return f.source;
		} );

		if ( sources.indexOf( 'email' ) !== -1 || sources.indexOf( 'phone' ) !== -1 ) {
			return;
		}

		list.parentNode.insertBefore(
			el( 'div', {
				id: 'raynet-builder-warning',
				'class': 'notice notice-warning inline',
				text: i18n.noContact || ''
			} ),
			list
		);
	}

	/**
	 * Asks the server to render the current definition.
	 */
	function refreshPreview() {
		if ( ! preview ) {
			return;
		}

		var body = new FormData();
		body.append( 'action', 'raynet_form_preview' );
		body.append( 'nonce', config.previewNonce );
		body.append( 'fields', store.value );

		fetch( config.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( payload ) {
				if ( payload && payload.success ) {
					// Server-rendered markup from our own renderer.
					preview.innerHTML = payload.data.html;
					return;
				}

				preview.textContent = i18n.previewFailed || '';
			} )
			.catch( function () {
				preview.textContent = i18n.previewFailed || '';
			} );
	}

	/**
	 * Adds the field chosen in the dropdown.
	 */
	function addField() {
		var source = addSelect ? addSelect.value : '';

		if ( ! source ) {
			return;
		}

		var meta = sourceMeta( source );

		fields.push( {
			id: 'new_' + Date.now() + '_' + fields.length,
			source: source,
			type: 'custom' === source ? 'text' : ( meta ? meta.type : 'text' ),
			label: 'custom' === source ? ( i18n.customLabel || '' ) : ( meta ? meta.label : source ),
			placeholder: '',
			help: '',
			required: 'consent' === source,
			width: 'full',
			options: []
		} );

		openId = fields[ fields.length - 1 ].id;
		persist();
		render();
	}

	try {
		fields = JSON.parse( store.value ) || [];
	} catch ( e ) {
		fields = [];
	}

	if ( addButton ) {
		addButton.addEventListener( 'click', addField );
	}

	render();
	refreshPreview();
})();
