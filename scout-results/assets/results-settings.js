/* Scout Case Results settings: the placeholder and the sample card follow the
   chosen state and the wording as it is typed. */
( function () {
	'use strict';
	var defaults = window.scoutResultsDefaults || {};
	var state = document.getElementById( 'sr-state' );
	var text = document.getElementById( 'sr-disclaimer' );
	var sample = document.querySelector( '.sr-sample .scout-result__disclaimer' );
	if ( ! state || ! text ) {
		return;
	}
	function update() {
		var fallback = defaults[ state.value ] || '';
		text.placeholder = fallback;
		if ( sample ) {
			sample.textContent = text.value.trim() || fallback;
		}
	}
	state.addEventListener( 'change', update );
	text.addEventListener( 'input', update );
	update();
}() );
