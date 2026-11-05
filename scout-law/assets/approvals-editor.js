/**
 * Firm approval in the block editor: a sidebar panel and a pre-publish
 * panel. Recording an approval sets the approver's name; the server keeps
 * the date and the exact version when the post saves.
 */
( function ( wp ) {
	if ( ! wp || ! wp.plugins || ! wp.data || ! wp.element ) {
		return;
	}
	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var __ = wp.i18n.__;
	var editPost = wp.editPost || {};
	var editor = wp.editor || {};
	var SidePanel = editor.PluginDocumentSettingPanel || editPost.PluginDocumentSettingPanel;
	var PrePanel = editor.PluginPrePublishPanel || editPost.PluginPrePublishPanel;
	var BY = '_scout_law_approved_by';
	var AT = '_scout_law_approved_at';

	function Approval() {
		var meta = wp.data.useSelect( function ( select ) {
			return select( 'core/editor' ).getEditedPostAttribute( 'meta' ) || {};
		}, [] );
		var saved = wp.data.useSelect( function ( select ) {
			return select( 'core/editor' ).getCurrentPostAttribute( 'meta' ) || {};
		}, [] );
		var dispatch = wp.data.useDispatch( 'core/editor' );
		var nameState = useState( '' );
		var tickState = useState( false );
		var name = nameState[ 0 ];

		var recorded = saved[ BY ] && saved[ AT ];
		var pending = meta[ BY ] && meta[ BY ] !== saved[ BY ];

		function update( nextName, nextTick ) {
			nameState[ 1 ]( nextName );
			tickState[ 1 ]( nextTick );
			var value = nextTick && nextName.trim() ? nextName.trim() : ( saved[ BY ] || '' );
			var next = {};
			next[ BY ] = value;
			// A fresh approval: the server fills in the date and the exact version on save.
			next[ AT ] = nextTick && nextName.trim() ? 0 : ( saved[ AT ] || 0 );
			dispatch.editPost( { meta: next } );
		}

		var status = recorded
			? __( 'Approved by ', 'scout-law' ) + saved[ BY ] + '. ' + __( 'If you change the words, record a new approval once the firm has looked.', 'scout-law' )
			: __( 'Needs the firm\'s approval before it can be published. Without it, Publish saves it as Pending review.', 'scout-law' );

		return el(
			'div',
			{},
			el( 'p', {}, status ),
			pending ? el( 'p', { style: { fontWeight: 600 } }, __( 'The approval is saved with this version when you save or publish.', 'scout-law' ) ) : null,
			el( wp.components.TextControl, {
				label: __( 'Attorney who approved it', 'scout-law' ),
				value: name,
				onChange: function ( v ) { update( v, tickState[ 0 ] ); },
				__nextHasNoMarginBottom: true
			} ),
			el( wp.components.CheckboxControl, {
				label: __( 'The firm approved this version', 'scout-law' ),
				checked: !! tickState[ 0 ],
				onChange: function ( v ) { update( name, v ); },
				__nextHasNoMarginBottom: true
			} )
		);
	}

	// A publish held for the firm's approval comes back as Pending review. Say so
	// in place of WordPress's "Draft saved", so nobody thinks it went live or got lost.
	( function () {
		var wanted = '';
		var saving = false;
		wp.data.subscribe( function () {
			var ed = wp.data.select( 'core/editor' );
			if ( ! ed || ! ed.isSavingPost ) {
				return;
			}
			// Saving finishes when both the post and any old-style boxes are saved.
			var now = ( ed.isSavingPost() && ! ed.isAutosavingPost() ) || !! ( wp.data.select( 'core/edit-post' ) && wp.data.select( 'core/edit-post' ).isSavingMetaBoxes && wp.data.select( 'core/edit-post' ).isSavingMetaBoxes() );
			if ( now && ! saving ) {
				wanted = ed.getEditedPostAttribute( 'status' );
			}
			var finished = ! now && saving;
			saving = now; // Set before dispatching: a notice changes the store and calls this again.
			if ( finished && 'pending' !== ed.getCurrentPostAttribute( 'status' ) ) {
				wp.data.dispatch( 'core/notices' ).removeNotice( 'scout-law-held' ); // Published now: the warning no longer applies.
			}
			if ( finished && ( 'publish' === wanted || 'future' === wanted ) && 'pending' === ed.getCurrentPostAttribute( 'status' ) ) {
				wanted = '';
				// Run after WordPress adds its own "saved" snackbar, then replace it.
				setTimeout( function () {
					var notices = wp.data.dispatch( 'core/notices' );
					notices.removeNotice( 'editor-save' );
					notices.createWarningNotice(
						__( 'Saved as Pending review, not published. It goes live once an attorney\'s approval is recorded in the Firm approval panel.', 'scout-law' ),
						{ id: 'scout-law-held', isDismissible: true }
					);
				}, 0 );
			}
		} );
	} )();

	wp.plugins.registerPlugin( 'scout-law-approval', {
		render: function () {
			return el(
				wp.element.Fragment,
				{},
				SidePanel ? el( SidePanel, { name: 'scout-law-approval', title: __( 'Firm approval', 'scout-law' ) }, el( Approval ) ) : null,
				PrePanel ? el( PrePanel, { title: __( 'Firm approval', 'scout-law' ), initialOpen: true }, el( Approval ) ) : null
			);
		}
	} );
} )( window.wp );
