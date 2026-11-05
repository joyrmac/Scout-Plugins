/* Scout People: Add a person. Shows one step at a time, picks the photo from
   the media library and ticks "Byline on their posts" when a login is picked.
   Without this script all three steps show and the form still posts. */
( function () {
	'use strict';

	var form = document.querySelector( '[data-st-add]' );
	if ( ! form ) {
		return;
	}

	var steps = [].slice.call( form.querySelectorAll( '[data-st-step]' ) );
	var marks = [].slice.call( form.querySelectorAll( '[data-st-progress]' ) );
	var current = 1;

	form.classList.add( 'is-steps' );
	[].forEach.call( form.querySelectorAll( '[data-st-js]' ), function ( el ) {
		el.hidden = false;
	} );
	// The script ticks the byline itself, so the server need not.
	var auto = form.querySelector( '[data-st-byline-auto]' );
	if ( auto ) {
		auto.parentNode.removeChild( auto );
	}

	/* Every field in a step passes the browser's own checks. */
	function valid( n ) {
		var step = form.querySelector( '[data-st-step="' + n + '"]' );
		var fields = step ? step.querySelectorAll( 'input, textarea, select' ) : [];
		for ( var i = 0; i < fields.length; i++ ) {
			if ( fields[ i ].willValidate && ! fields[ i ].checkValidity() ) {
				fields[ i ].reportValidity();
				return false;
			}
		}
		return true;
	}

	function show( n, focus ) {
		n = Math.max( 1, Math.min( steps.length, n ) );
		// Moving forward checks each step on the way.
		for ( var s = current; s < n; s++ ) {
			if ( ! valid( s ) ) {
				n = s;
				break;
			}
		}
		current = n;
		steps.forEach( function ( step ) {
			step.hidden = Number( step.getAttribute( 'data-st-step' ) ) !== n;
		} );
		marks.forEach( function ( mark ) {
			var m = Number( mark.getAttribute( 'data-st-progress' ) );
			mark.classList.toggle( 'is-current', m === n );
			mark.classList.toggle( 'is-done', m < n );
			if ( m === n ) {
				mark.setAttribute( 'aria-current', 'step' );
			} else {
				mark.removeAttribute( 'aria-current' );
			}
		} );
		if ( focus ) {
			var title = form.querySelector( '#st-step-' + n + '-title' );
			if ( title ) {
				title.focus();
			}
		}
	}

	show( 1, false );

	form.addEventListener( 'click', function ( e ) {
		var go = e.target.closest( '[data-st-next], [data-st-goto]' );
		if ( go ) {
			e.preventDefault();
			show( Number( go.getAttribute( 'data-st-next' ) || go.getAttribute( 'data-st-goto' ) ), true );
		}
	} );

	// Enter in a one-line field goes to the next step instead of saving early.
	form.addEventListener( 'keydown', function ( e ) {
		if ( 'Enter' !== e.key || current === steps.length ) {
			return;
		}
		var t = e.target;
		if ( t && 'INPUT' === t.tagName && 'checkbox' !== t.type && 'radio' !== t.type ) {
			e.preventDefault();
			show( current + 1, true );
		}
	} );

	// Picking a login turns the byline on, as on the edit screen.
	form.addEventListener( 'change', function ( e ) {
		if ( e.target.matches( '#st-user' ) && e.target.value && '0' !== e.target.value ) {
			var byline = document.getElementById( 'st-show-byline' );
			if ( byline ) {
				byline.checked = true;
			}
		}
	} );

	// The alt text field says what it falls back to.
	var name = form.querySelector( '[data-st-name]' );
	var alt = form.querySelector( '[data-st-photo-alt]' );
	if ( name && alt ) {
		name.addEventListener( 'input', function () {
			alt.placeholder = name.value.trim();
		} );
	}

	// The photo, from the media library.
	var pick = form.querySelector( '[data-st-photo-pick]' );
	var clear = form.querySelector( '[data-st-photo-clear]' );
	var idField = form.querySelector( '[data-st-photo-id]' );
	var preview = form.querySelector( '[data-st-photo-preview]' );
	var frame = null;

	function setPhoto( att ) {
		idField.value = att ? String( att.id ) : '';
		preview.textContent = '';
		preview.hidden = ! att;
		if ( clear ) {
			clear.hidden = ! att;
		}
		if ( ! att ) {
			return;
		}
		var size = att.sizes && ( att.sizes.thumbnail || att.sizes.medium ) ? ( att.sizes.thumbnail || att.sizes.medium ) : att;
		var img = document.createElement( 'img' );
		img.src = size.url;
		img.alt = '';
		preview.appendChild( img );
		if ( alt && att.alt && ! alt.value ) {
			alt.value = att.alt;
		}
	}

	if ( pick && window.wp && window.wp.media ) {
		pick.addEventListener( 'click', function () {
			if ( ! frame ) {
				frame = window.wp.media( {
					title: form.getAttribute( 'data-st-media-title' ),
					button: { text: form.getAttribute( 'data-st-media-button' ) },
					library: { type: 'image' },
					multiple: false
				} );
				frame.on( 'select', function () {
					setPhoto( frame.state().get( 'selection' ).first().toJSON() );
				} );
			}
			frame.open();
		} );
	} else if ( pick ) {
		pick.parentNode.hidden = true;
	}
	if ( clear ) {
		clear.addEventListener( 'click', function () {
			setPhoto( null );
			pick.focus();
		} );
	}
}() );
