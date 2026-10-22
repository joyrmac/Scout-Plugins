/* Scout Redirects: ask before removing things. No dependencies. */
( function () {
	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;
		if ( ! form || ! form.getAttribute ) {
			return;
		}
		var question = form.getAttribute( 'data-confirm' );
		if ( question && ! window.confirm( question ) ) {
			event.preventDefault();
		}
	} );
} )();

/* Coming from "Forward it": the old address is filled in, so put the cursor in the new one. */
( function () {
	// After load, so WordPress's own scripts and the #anchor jump have settled.
	window.addEventListener( 'load', function () {
		var field = document.querySelector( '[data-sr-focus]' );
		if ( field ) {
			field.focus( { preventScroll: true } );
		}
	} );
} )();
