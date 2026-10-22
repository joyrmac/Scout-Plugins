/* Scout Team: add and remove repeatable rows, keep the preview card live. */
( function () {
	'use strict';

	var counter = Date.now();

	document.addEventListener( 'click', function ( e ) {
		var add = e.target.closest( '[data-st-add]' );
		if ( add ) {
			var group = add.getAttribute( 'data-st-add' );
			var tpl = document.getElementById( 'st-' + group + '-template' );
			var rows = document.getElementById( 'st-' + group + '-rows' );
			if ( ! tpl || ! rows ) {
				return;
			}
			counter++;
			var html = tpl.innerHTML.split( '__i__' ).join( String( counter ) );
			rows.insertAdjacentHTML( 'beforeend', html );
			var last = rows.lastElementChild;
			var first = last && last.querySelector( 'input' );
			if ( first ) {
				first.focus();
			}
			return;
		}
		var remove = e.target.closest( '[data-st-remove]' );
		if ( remove ) {
			var row = remove.closest( '.st-row' );
			var list = row && row.parentNode;
			if ( ! row ) {
				return;
			}
			if ( list && list.children.length === 1 ) {
				// Keep one empty row so the group never disappears.
				[].forEach.call( row.querySelectorAll( 'input' ), function ( i ) { i.value = ''; } );
				var keep = row.querySelector( 'input' );
				if ( keep ) {
					keep.focus();
				}
				return;
			}
			var group2 = row.closest( '[data-st-repeater]' );
			row.parentNode.removeChild( row );
			var next = group2 && group2.querySelector( '[data-st-add]' );
			if ( next ) {
				next.focus();
			}
		}
	} );

	document.addEventListener( 'change', function ( e ) {
		var t = e.target.closest( '[data-st-toggle]' );
		if ( ! t ) {
			return;
		}
		var box = document.querySelector( '[data-st-show="' + t.getAttribute( 'data-st-toggle' ) + '"]' );
		if ( box ) {
			box.hidden = ! t.checked;
		}
	} );

	// Live preview card.
	function text( sel ) {
		var el = document.querySelector( sel );
		return el ? el.value.trim() : '';
	}
	function setOut( key, value ) {
		var el = document.querySelector( '[data-st-out="' + key + '"]' );
		if ( el ) {
			el.textContent = value || el.getAttribute( 'data-st-fallback' ) || '';
		}
	}
	function refresh() {
		setOut( 'name', text( '#title' ) );
		setOut( 'role', text( '#st-role' ) );
		var branch = document.getElementById( 'st-branch' );
		var label = branch && branch.value ? branch.options[ branch.selectedIndex ].text : '';
		var years = text( '#st-years' );
		setOut( 'service', label ? ( years ? label + ', ' + years : label ) : '' );
	}
	document.addEventListener( 'input', function ( e ) {
		if ( e.target.matches( '#title, #st-role, #st-years' ) ) {
			refresh();
		}
	} );
	document.addEventListener( 'change', function ( e ) {
		if ( e.target.matches( '#st-branch' ) ) {
			refresh();
		}
	} );
}() );
