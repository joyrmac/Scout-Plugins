/**
 * Scout Reviews block, editor side. Plain script, no build step: the block is
 * rendered on the server, so the editor shows the real output and a sidebar of
 * options.
 */
( function ( wp ) {
	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var PanelBody = wp.components.PanelBody;
	var SelectControl = wp.components.SelectControl;
	var RangeControl = wp.components.RangeControl;
	var ToggleControl = wp.components.ToggleControl;
	var TextControl = wp.components.TextControl;
	var Placeholder = wp.components.Placeholder;
	var ServerSideRender = wp.serverSideRender;

	var SOURCES = [
		{ label: __( 'All sources', 'scout-reviews' ), value: '' },
		{ label: 'Google', value: 'google' },
		{ label: 'Facebook', value: 'facebook' },
		{ label: 'Yelp', value: 'yelp' },
		{ label: 'Clutch', value: 'clutch' },
		{ label: 'UpCity', value: 'upcity' },
		{ label: 'DesignRush', value: 'designrush' },
		{ label: 'BBB', value: 'bbb' },
		{ label: 'Avvo', value: 'avvo' },
		{ label: __( 'Client testimonials', 'scout-reviews' ), value: 'direct' }
	];

	function Empty() {
		return el( Placeholder, {
			icon: 'star-filled',
			label: __( 'Scout Reviews', 'scout-reviews' ),
			instructions: __( 'No published reviews match these settings yet. Add reviews under Reviews in the admin menu.', 'scout-reviews' )
		} );
	}

	wp.blocks.registerBlockType( 'scout/reviews', {
		edit: function ( props ) {
			var a = props.attributes;
			var set = props.setAttributes;

			return el(
				'div',
				useBlockProps(),
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Reviews', 'scout-reviews' ) },
						el( SelectControl, {
							label: __( 'Layout', 'scout-reviews' ),
							value: a.layout,
							options: [
								{ label: __( 'Grid', 'scout-reviews' ), value: 'grid' },
								{ label: __( 'Scrolling row', 'scout-reviews' ), value: 'row' },
								{ label: __( 'Single column', 'scout-reviews' ), value: 'list' }
							],
							onChange: function ( v ) { set( { layout: v } ); }
						} ),
						el( SelectControl, {
							label: __( 'Source', 'scout-reviews' ),
							value: a.source,
							options: SOURCES,
							onChange: function ( v ) { set( { source: v } ); }
						} ),
						el( TextControl, {
							label: __( 'Topic (optional)', 'scout-reviews' ),
							help: __( 'A topic slug from Reviews > Topics, such as law-firms. Shows featured reviews if the topic has none yet.', 'scout-reviews' ),
							value: a.topic,
							onChange: function ( v ) { set( { topic: v } ); }
						} ),
						el( RangeControl, {
							label: __( 'How many (0 shows all)', 'scout-reviews' ),
							value: a.count,
							min: 0,
							max: 24,
							onChange: function ( v ) { set( { count: v || 0 } ); }
						} ),
						el( RangeControl, {
							label: __( 'Lowest star rating to show (0 shows all)', 'scout-reviews' ),
							value: a.minRating,
							min: 0,
							max: 5,
							onChange: function ( v ) { set( { minRating: v || 0 } ); }
						} ),
						el( ToggleControl, {
							label: __( 'Featured reviews only', 'scout-reviews' ),
							checked: a.featured,
							onChange: function ( v ) { set( { featured: v } ); }
						} ),
						el( ToggleControl, {
							label: __( 'Show the rating summary', 'scout-reviews' ),
							checked: a.showSummary,
							onChange: function ( v ) { set( { showSummary: v } ); }
						} )
					)
				),
				el( ServerSideRender, {
					block: 'scout/reviews',
					attributes: a,
					EmptyResponsePlaceholder: Empty
				} )
			);
		},
		save: function () {
			return null; // Rendered on the server.
		}
	} );
} )( window.wp );
