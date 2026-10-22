/* Scout Case Results: practice-area filters. The buttons are plain links
   (?result_area=slug) that work without this script; with it, the grid
   filters in place and announces how many results show. */
( function () {
	'use strict';
	document.querySelectorAll( '.scout-results' ).forEach( function ( root ) {
		var links = root.querySelectorAll( '.scout-results__filter' );
		var cards = root.querySelectorAll( '.scout-result[data-area]' );
		var status = root.querySelector( '.scout-results__status' );
		if ( ! links.length ) {
			return;
		}
		links.forEach( function ( link ) {
			link.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				var slug = link.getAttribute( 'data-filter' );
				var shown = 0;
				links.forEach( function ( l ) {
					if ( l === link ) {
						l.setAttribute( 'aria-current', 'true' );
					} else {
						l.removeAttribute( 'aria-current' );
					}
				} );
				cards.forEach( function ( card ) {
					var match = ! slug || card.getAttribute( 'data-area' ) === slug;
					card.hidden = ! match;
					if ( match ) {
						shown++;
					}
				} );
				if ( status ) {
					status.textContent = 1 === shown ? status.getAttribute( 'data-one' ) : status.getAttribute( 'data-many' ).replace( '%d', shown );
				}
				if ( window.history && window.history.replaceState ) {
					window.history.replaceState( null, '', link.href );
				}
				if ( window.scoutTrack ) {
					window.scoutTrack( 'results_filter', { link_label: ( link.textContent || '' ).trim().slice( 0, 80 ) } );
				}
			} );
		} );
	} );
}() );
