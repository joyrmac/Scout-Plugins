/**
 * Newsletter sign-up block: a preview of the [scout_newsletter] box with its
 * few settings in the sidebar. The server draws it, so the editor and the
 * page always match.
 */
( function ( wp ) {
	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var PanelBody = wp.components.PanelBody;
	var TextControl = wp.components.TextControl;
	var ToggleControl = wp.components.ToggleControl;
	var ServerSideRender = wp.serverSideRender;

	wp.blocks.registerBlockType( 'scout/newsletter', {
		apiVersion: 3,
		title: __( 'Newsletter sign-up', 'scout-newsletter' ),
		category: 'widgets',
		icon: 'email',
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
						{ title: __( 'Sign-up box', 'scout-newsletter' ) },
						el( TextControl, { label: __( 'Heading', 'scout-newsletter' ), value: a.heading, onChange: function ( v ) { set( { heading: v } ); } } ),
						el( TextControl, { label: __( 'Button words', 'scout-newsletter' ), value: a.button, onChange: function ( v ) { set( { button: v } ); } } ),
						el( ToggleControl, { label: __( 'Ask for a first name', 'scout-newsletter' ), checked: !! a.name, onChange: function ( v ) { set( { name: v } ); } } ),
						el( TextControl, { label: __( 'Extra newsletter tag', 'scout-newsletter' ), help: __( 'Optional. Added beside the Form tag.', 'scout-newsletter' ), value: a.tag, onChange: function ( v ) { set( { tag: v } ); } } )
					)
				),
				el( ServerSideRender, { block: 'scout/newsletter', attributes: a } )
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp );
