/**
 * Reference-image picker for the style editor.
 *
 * The hidden input is the source of truth: one attachment ID or URL per line,
 * exactly what Gemini_Http::load_reference() accepts. The thumbnail strip is a
 * view over it, so a style duplicated from a preset keeps its URL references
 * even as you add attachments alongside them.
 */
( function () {
	var hidden = document.getElementById( 'launchy_style_references' );
	var strip = document.getElementById( 'launchy-refs' );
	var addBtn = document.getElementById( 'launchy-refs-add' );
	var clearBtn = document.getElementById( 'launchy-refs-clear' );
	var l10n = window.launchyStyles || {};

	if ( ! hidden || ! strip ) {
		return;
	}

	function read() {
		return hidden.value.split( /[\r\n]+/ ).map( function ( s ) {
			return s.trim();
		} ).filter( Boolean );
	}

	function write( refs ) {
		hidden.value = refs.join( '\n' );
		render();
	}

	function remove( ref ) {
		write( read().filter( function ( r ) {
			return r !== ref;
		} ) );
	}

	function chip( ref ) {
		var el = document.createElement( 'div' );
		el.className = 'launchy-ref';

		var kill = document.createElement( 'button' );
		kill.type = 'button';
		kill.className = 'launchy-ref-remove';
		kill.setAttribute( 'aria-label', 'Remove reference' );
		kill.textContent = '×';
		kill.addEventListener( 'click', function () {
			remove( ref );
		} );

		if ( /^\d+$/.test( ref ) && window.wp && wp.media ) {
			// Attachment ID: ask the media store for a thumbnail.
			var img = document.createElement( 'img' );
			img.alt = '';
			el.appendChild( img );
			var attachment = wp.media.attachment( ref );
			attachment.fetch().done( function () {
				var sizes = attachment.get( 'sizes' );
				img.src = sizes && sizes.thumbnail ? sizes.thumbnail.url : attachment.get( 'url' );
			} );
		} else {
			// Remote URL: show it directly.
			var remote = document.createElement( 'img' );
			remote.alt = '';
			remote.src = ref;
			el.appendChild( remote );
		}

		el.appendChild( kill );
		return el;
	}

	function render() {
		strip.innerHTML = '';
		read().forEach( function ( ref ) {
			strip.appendChild( chip( ref ) );
		} );
	}

	if ( addBtn ) {
		addBtn.addEventListener( 'click', function ( event ) {
			event.preventDefault();
			if ( ! window.wp || ! wp.media ) {
				return;
			}

			var frame = wp.media( {
				title: l10n.title || 'Choose reference images',
				button: { text: l10n.button || 'Use these references' },
				library: { type: 'image' },
				multiple: true,
			} );

			frame.on( 'select', function () {
				var picked = frame.state().get( 'selection' ).map( function ( item ) {
					return String( item.id );
				} );
				var merged = read();
				picked.forEach( function ( id ) {
					if ( merged.indexOf( id ) === -1 ) {
						merged.push( id );
					}
				} );
				write( merged );
			} );

			frame.open();
		} );
	}

	if ( clearBtn ) {
		clearBtn.addEventListener( 'click', function ( event ) {
			event.preventDefault();
			write( [] );
		} );
	}

	// Guard the delete buttons in the saved-styles table.
	document.querySelectorAll( '.js-launchy-confirm' ).forEach( function ( button ) {
		button.addEventListener( 'click', function ( event ) {
			if ( ! window.confirm( l10n.confirmText || 'Delete this style?' ) ) {
				event.preventDefault();
			}
		} );
	} );

	render();
}() );
