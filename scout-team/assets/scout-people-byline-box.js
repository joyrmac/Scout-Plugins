/* Scout People: the "Written by" box in the classic editor. Adds the person
   picked in the list straight away, and gives each picked person Move up,
   Move down and Remove. Without this script the box still works on save. */
( function () {
	'use strict';

	var box = document.querySelector( '[data-spb]' );
	if ( ! box ) {
		return;
	}
	var list = box.querySelector( '[data-spb-list]' );
	var tpl = box.querySelector( '[data-spb-template]' );
	var select = box.querySelector( '[data-spb-select]' );
	var addBtn = box.querySelector( '[data-spb-add]' );
	if ( ! list || ! tpl ) {
		return;
	}

	function tools( row ) {
		var t = row.querySelector( '[data-spb-tools]' );
		if ( t ) {
			t.hidden = false;
		}
	}
	Array.prototype.forEach.call( list.querySelectorAll( '[data-spb-row]' ), tools );

	function has( id ) {
		return !! list.querySelector( '[data-spb-id][value="' + id + '"]' );
	}

	function add() {
		if ( ! select || ! select.value || has( select.value ) ) {
			return;
		}
		var opt = select.options[ select.selectedIndex ];
		var holder = document.createElement( 'ul' );
		holder.innerHTML = tpl.innerHTML;
		var row = holder.firstElementChild;
		if ( ! row ) {
			return;
		}
		row.querySelector( '[data-spb-id]' ).value = select.value;
		row.querySelector( '[data-spb-name]' ).textContent = opt.getAttribute( 'data-name' ) || opt.textContent;
		var note = row.querySelector( '[data-spb-note]' );
		if ( note && opt.getAttribute( 'data-off' ) === '1' ) {
			note.textContent = box.getAttribute( 'data-spb-offnote' ) || note.textContent;
			note.hidden = false;
		}
		tools( row );
		list.appendChild( row );
		select.value = '';
		row.querySelector( '[data-spb-remove]' ).focus();
	}

	if ( select && addBtn ) {
		// A person picked but not added yet is still added on save.
		addBtn.hidden = false;
		addBtn.addEventListener( 'click', add );
	}

	box.addEventListener( 'click', function ( e ) {
		var row = e.target.closest( '[data-spb-row]' );
		if ( ! row ) {
			return;
		}
		if ( e.target.closest( '[data-spb-remove]' ) ) {
			var next = row.nextElementSibling || row.previousElementSibling;
			row.parentNode.removeChild( row );
			var focus = next && next.querySelector( '[data-spb-remove]' );
			( focus || select || box ).focus();
		} else if ( e.target.closest( '[data-spb-up]' ) && row.previousElementSibling ) {
			list.insertBefore( row, row.previousElementSibling );
			e.target.focus();
		} else if ( e.target.closest( '[data-spb-down]' ) && row.nextElementSibling ) {
			list.insertBefore( row.nextElementSibling, row );
			e.target.focus();
		}
	} );
} )();
