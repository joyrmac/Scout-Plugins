/* Scout People: the "Written by" panel in the block editor's post sidebar.
   Picks one or more people from People as the post's byline, in order, and
   saves them with the post as _scout_people_authors. No build step. */
( function ( wp, data ) {
	'use strict';

	if ( ! wp || ! wp.plugins || ! wp.data || ! wp.element || ! wp.components || ! data ) {
		return;
	}
	var Panel = ( wp.editor && wp.editor.PluginDocumentSettingPanel ) || ( wp.editPost && wp.editPost.PluginDocumentSettingPanel );
	if ( ! Panel ) {
		return;
	}
	var el = wp.element.createElement;
	var useSelect = wp.data.useSelect;
	var useDispatch = wp.data.useDispatch;
	var Button = wp.components.Button;
	var Combo = wp.components.ComboboxControl;
	var text = data.text || {};
	var key = data.key;

	var people = {};
	( data.people || [] ).forEach( function ( p ) {
		people[ p.id ] = p;
	} );

	function move( list, from, to ) {
		var next = list.slice();
		var item = next.splice( from, 1 )[ 0 ];
		next.splice( to, 0, item );
		return next;
	}

	function Row( props ) {
		var id = props.id;
		var p = people[ id ];
		var note = ! p ? text.gone : ( p.byline ? '' : text.offNote );
		var name = p ? p.name : '#' + id;
		return el(
			'li',
			{ className: 'scout-people-byline__row', style: { marginBottom: '8px' } },
			el( 'strong', null, name ),
			note ? el( 'p', { className: 'components-form-token-field__help', style: { margin: '2px 0' } }, note ) : null,
			el(
				'div',
				null,
				el( Button, { variant: 'link', disabled: props.index === 0, onClick: props.onUp, 'aria-label': text.up + ': ' + name }, text.up ),
				' ',
				el( Button, { variant: 'link', disabled: props.last, onClick: props.onDown, 'aria-label': text.down + ': ' + name }, text.down ),
				' ',
				el( Button, { variant: 'link', isDestructive: true, onClick: props.onRemove, 'aria-label': text.remove + ': ' + name }, text.remove )
			)
		);
	}

	function WrittenBy() {
		var postType = useSelect( function ( select ) {
			return select( 'core/editor' ).getCurrentPostType();
		}, [] );
		var meta = useSelect( function ( select ) {
			return select( 'core/editor' ).getEditedPostAttribute( 'meta' ) || {};
		}, [] );
		var editPost = useDispatch( 'core/editor' ).editPost;

		if ( ( data.types || [] ).indexOf( postType ) === -1 || ! meta || ! ( key in meta ) ) {
			return null;
		}
		var ids = ( Array.isArray( meta[ key ] ) ? meta[ key ] : [] ).map( Number ).filter( Boolean );

		function save( next ) {
			var change = {};
			change[ key ] = next;
			editPost( { meta: change } );
		}

		var options = ( data.people || [] )
			.filter( function ( p ) {
				return ids.indexOf( p.id ) === -1;
			} )
			.map( function ( p ) {
				return { value: String( p.id ), label: p.byline ? p.name : p.name + ' (' + text.off + ')' };
			} );

		return el(
			Panel,
			{ name: 'scout-people-written-by', title: text.title, className: 'scout-people-byline' },
			el( 'p', null, text.help ),
			text.setting ? el( 'p', null, el( 'strong', null, text.setting ) ) : null,
			ids.length
				? el(
						'ul',
						{ style: { margin: '0 0 12px' } },
						ids.map( function ( id, i ) {
							return el( Row, {
								key: id,
								id: id,
								index: i,
								last: i === ids.length - 1,
								onUp: function () {
									save( move( ids, i, i - 1 ) );
								},
								onDown: function () {
									save( move( ids, i, i + 1 ) );
								},
								onRemove: function () {
									save( ids.filter( function ( x ) {
										return x !== id;
									} ) );
								},
							} );
						} )
				  )
				: null,
			options.length || ids.length
				? el( Combo, {
						label: text.add,
						value: null,
						options: options,
						onChange: function ( value ) {
							var id = Number( value );
							if ( id && ids.indexOf( id ) === -1 ) {
								save( ids.concat( [ id ] ) );
							}
						},
				  } )
				: el( 'p', null, text.none )
		);
	}

	wp.plugins.registerPlugin( 'scout-people-written-by', { render: WrittenBy, icon: null } );
} )( window.wp, window.scoutPeopleByline );
