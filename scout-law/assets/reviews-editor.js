/**
 * "Reviewed by" in the block editor: pick the attorney and mark the page
 * reviewed today. The server writes the date and the record when the page saves.
 */
( function ( wp, data ) {
	if ( ! wp || ! wp.plugins || ! wp.data || ! wp.element || ! data ) {
		return;
	}
	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var editor = wp.editor || {};
	var editPost = wp.editPost || {};
	var Panel = editor.PluginDocumentSettingPanel || editPost.PluginDocumentSettingPanel;
	var BY = '_scout_law_reviewed_by';
	var AT = '_scout_law_reviewed_at';

	function Review() {
		var meta = wp.data.useSelect( function ( select ) {
			return select( 'core/editor' ).getEditedPostAttribute( 'meta' ) || {};
		}, [] );
		var saved = wp.data.useSelect( function ( select ) {
			return select( 'core/editor' ).getCurrentPostAttribute( 'meta' ) || {};
		}, [] );
		var dispatch = wp.data.useDispatch( 'core/editor' );
		var people = data.attorneys || [];
		var byName = {};
		people.forEach( function ( p ) { byName[ p.value ] = p.label; } );
		var pick = wp.element.useState( saved[ BY ] || ( people[ 0 ] && people[ 0 ].value ) || 0 );

		if ( ! people.length ) {
			return el( 'p', {}, __( 'Add an attorney on the Team screen (tick "Attorney") to sign reviews.', 'scout-law' ) );
		}
		var status = saved[ BY ] && saved[ AT ] > 0
			? __( 'Last reviewed by ', 'scout-law' ) + ( byName[ saved[ BY ] ] || '' ) + ', ' + new Date( saved[ AT ] * 1000 ).toLocaleDateString() + '.'
			: __( 'Not reviewed by an attorney yet.', 'scout-law' );
		var marked = 0 === meta[ AT ];

		// Nothing changes on the page until "Reviewed today" is ticked: the name
		// and the date are always saved together. -1 means "put it back as it was".
		function mark( on, who ) {
			var next = {};
			next[ BY ] = on ? who : ( saved[ BY ] || 0 );
			next[ AT ] = on ? 0 : ( saved[ AT ] > 0 ? saved[ AT ] : -1 );
			dispatch.editPost( { meta: next } );
		}

		return el(
			'div',
			{},
			el( 'p', {}, status ),
			el( wp.components.SelectControl, {
				label: __( 'Attorney', 'scout-law' ),
				value: String( pick[ 0 ] ),
				options: people.map( function ( p ) { return { value: String( p.value ), label: p.label }; } ),
				onChange: function ( v ) { var who = parseInt( v, 10 ) || 0; pick[ 1 ]( who ); if ( marked ) { mark( true, who ); } },
				__nextHasNoMarginBottom: true
			} ),
			el( wp.components.CheckboxControl, {
				label: __( 'Reviewed today', 'scout-law' ),
				checked: !! marked,
				onChange: function ( v ) { mark( v, pick[ 0 ] ); },
				__nextHasNoMarginBottom: true
			} ),
			marked ? el( 'p', { style: { fontWeight: 600 } }, __( 'The review is recorded with today\'s date when you save.', 'scout-law' ) ) : null
		);
	}

	if ( Panel ) {
		wp.plugins.registerPlugin( 'scout-law-review', {
			render: function () {
				return el( Panel, { name: 'scout-law-review', title: __( 'Reviewed by', 'scout-law' ) }, el( Review ) );
			}
		} );
	}
} )( window.wp, window.scoutLawReviews );
