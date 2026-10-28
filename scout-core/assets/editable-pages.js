/**
 * Scout Core editable pages: the editor side (see includes/editable-pages.php).
 * Hand-written, no build step: plain wp.element calls.
 *
 * Editors see the page as it looks on the site. Words (scout/text) and photos
 * (scout/image) are editable; layout (scout/box), icons (scout/frozen) and
 * theme parts (scout/part) are locked by the page's "contentOnly" lock.
 * Administrators can use WordPress's "Edit pattern" button to move or remove
 * sections; everyone else gets the lock on the whole editor.
 */
( function ( wp ) {
	const { registerBlockType } = wp.blocks;
	const { createElement: el, Fragment } = wp.element;
	const { useBlockProps, useInnerBlocksProps, InnerBlocks, RichText, InspectorControls, BlockControls, MediaUpload, MediaUploadCheck } = wp.blockEditor;
	const { PanelBody, TextareaControl, ToolbarGroup, ToolbarButton } = wp.components;
	const { useSelect, useDispatch } = wp.data;
	const G = window.scoutEditable || { frozen: {}, home: '', theme: '' };

	// Stored content keeps {{home}} and {{theme}} so it moves between staging and production.
	const tok = ( s ) => String( s || '' ).split( '{{home}}' ).join( G.home ).split( '{{theme}}' ).join( G.theme );
	const untok = ( s ) => {
		let out = String( s || '' );
		if ( G.theme ) out = out.split( G.theme ).join( '{{theme}}' );
		if ( G.home ) out = out.split( G.home + '/' ).join( '{{home}}/' );
		return out;
	};

	const RENAME = { class: 'className', for: 'htmlFor', tabindex: 'tabIndex', colspan: 'colSpan', rowspan: 'rowSpan', srcset: 'srcSet', datetime: 'dateTime', readonly: 'readOnly', maxlength: 'maxLength', viewbox: 'viewBox', 'accept-charset': 'acceptCharset', crossorigin: 'crossOrigin', referrerpolicy: 'referrerPolicy', fetchpriority: 'fetchPriority' };
	const BOOL = { open: 1, hidden: 1, disabled: 1, checked: 1, required: 1, selected: 1, inert: 1 };

	function styleObject( css ) {
		const out = {};
		String( css ).split( ';' ).forEach( ( decl ) => {
			const i = decl.indexOf( ':' );
			if ( i < 1 ) return;
			const name = decl.slice( 0, i ).trim();
			const value = decl.slice( i + 1 ).trim();
			out[ name.startsWith( '--' ) ? name : name.replace( /-([a-z])/g, ( m, c ) => c.toUpperCase() ) ] = value;
		} );
		return out;
	}

	/** The approved element's raw attributes as React props. */
	function attrProps( raw ) {
		const props = {};
		if ( ! raw ) return props;
		const t = document.createElement( 'template' );
		t.innerHTML = '<div' + raw + '></div>';
		const node = t.content.firstElementChild;
		if ( ! node ) return props;
		Array.from( node.attributes ).forEach( ( a ) => {
			const name = a.name;
			if ( name.startsWith( 'on' ) ) return;
			if ( name === 'style' ) {
				props.style = styleObject( tok( a.value ) );
			} else if ( BOOL[ name ] ) {
				props[ name ] = true;
			} else {
				props[ RENAME[ name ] || name ] = tok( a.value );
			}
		} );
		return props;
	}

	const VOID = { img: 1, br: 1, hr: 1, input: 1, source: 1, wbr: 1 };

	/**
	 * Render approved markup as the block's own element, so the editor's DOM
	 * matches the site's (the stylesheets use direct-child selectors). A
	 * fragment with more than one top-level node falls back to a wrapper
	 * that takes no space (display: contents).
	 */
	function asElement( html, extra ) {
		const t = document.createElement( 'template' );
		t.innerHTML = html;
		const nodes = Array.from( t.content.childNodes ).filter( ( n ) => n.nodeType !== 3 || n.textContent.trim() );
		if ( nodes.length === 1 && nodes[ 0 ].nodeType === 1 ) {
			const node = nodes[ 0 ];
			const tag = node.tagName.toLowerCase();
			const shell = node.cloneNode( false ).outerHTML;
			const rawAttrs = shell.slice( tag.length + 1, shell.indexOf( '>' ) );
			const props = attrProps( rawAttrs );
			if ( extra && extra.className ) props.className = ( ( props.className || '' ) + ' ' + extra.className ).trim();
			if ( ! VOID[ tag ] ) props.dangerouslySetInnerHTML = { __html: node.innerHTML };
			return { tag, props };
		}
		return { tag: 'span', props: { className: ( extra && extra.className ) || '', style: { display: 'contents' }, dangerouslySetInnerHTML: { __html: html } } };
	}

	const common = {
		apiVersion: 3,
		category: 'design',
		supports: { html: false, reusable: false, inserter: false, customClassName: false, className: false },
	};
	const ws = { type: 'string', default: '' };

	registerBlockType( 'scout/box', Object.assign( {}, common, {
		title: 'Section (locked layout)',
		attributes: {
			tag: { type: 'string', default: '' },
			attrs: { type: 'string', default: '' },
			root: { type: 'boolean', default: false },
			bodyclass: { type: 'string', default: '' },
			templateLock: { type: [ 'string', 'boolean' ], enum: [ 'all', 'insert', 'contentOnly', false ], default: false },
			lock: { type: 'object' },
			ws,
		},
		edit( { attributes: a } ) {
			const tag = a.root ? 'div' : a.tag || 'div';
			const base = a.root ? { className: ( 'scout-root ' + ( a.bodyclass || '' ) ).trim() } : attrProps( a.attrs );
			const blockProps = useBlockProps( base );
			const innerProps = useInnerBlocksProps( blockProps, a.root ? { templateLock: a.templateLock || false, renderAppender: false } : { renderAppender: false } );
			return el( tag, innerProps );
		},
		save() {
			return el( InnerBlocks.Content );
		},
	} ) );

	registerBlockType( 'scout/text', Object.assign( {}, common, {
		title: 'Text',
		attributes: {
			tag: { type: 'string', default: '' },
			attrs: { type: 'string', default: '' },
			content: { type: 'string', default: '', role: 'content' },
			ws,
		},
		edit( { attributes: a, setAttributes } ) {
			const tag = a.tag || 'span';
			const blockProps = useBlockProps( attrProps( a.attrs ) );
			return el( RichText, Object.assign( {}, blockProps, {
				tagName: tag,
				value: tok( a.content ),
				onChange: ( v ) => setAttributes( { content: untok( v ) } ),
				withoutInteractiveFormatting: tag === 'a' || tag === 'summary',
				placeholder: 'Add text',
			} ) );
		},
		save() {
			return null;
		},
	} ) );

	registerBlockType( 'scout/image', Object.assign( {}, common, {
		title: 'Photo',
		attributes: {
			ref: { type: 'string', default: '' },
			id: { type: 'number', role: 'content' },
			alt: { type: 'string', role: 'content' },
			ws,
		},
		edit( { attributes: a, setAttributes } ) {
			const media = useSelect( ( select ) => ( a.id ? select( 'core' ).getMedia( a.id ) : null ), [ a.id ] );
			let html = tok( G.frozen[ a.ref ] || '' );
			const t = document.createElement( 'template' );
			t.innerHTML = html;
			const img = t.content.querySelector( 'img' );
			let originalAlt = '';
			if ( img ) {
				originalAlt = img.getAttribute( 'alt' ) || '';
				if ( media && media.source_url ) {
					t.content.querySelectorAll( 'source' ).forEach( ( s ) => s.remove() );
					img.setAttribute( 'src', media.source_url );
					img.removeAttribute( 'srcset' );
				}
				if ( a.alt !== undefined ) img.setAttribute( 'alt', a.alt );
				html = t.innerHTML;
			}
			const shape = asElement( html, { className: 'scout-photo' } );
			const blockProps = useBlockProps( shape.props );
			const pick = ( m ) => setAttributes( { id: m.id, alt: a.alt !== undefined ? a.alt : ( m.alt || originalAlt ) } );
			return el( Fragment, null,
				el( BlockControls, { group: 'other' },
					el( MediaUploadCheck, null,
						el( MediaUpload, {
							allowedTypes: [ 'image' ],
							value: a.id,
							onSelect: pick,
							render: ( { open } ) => el( ToolbarGroup, null, el( ToolbarButton, { onClick: open }, 'Replace photo' ) ),
						} )
					),
					a.id ? el( ToolbarGroup, null, el( ToolbarButton, { onClick: () => setAttributes( { id: undefined } ) }, 'Use original' ) ) : null
				),
				el( InspectorControls, null,
					el( PanelBody, { title: 'Photo' },
						el( TextareaControl, {
							label: 'Alternative text',
							help: 'Describe the photo for people using screen readers. Leave empty for a purely decorative photo.',
							value: a.alt !== undefined ? a.alt : originalAlt,
							onChange: ( v ) => setAttributes( { alt: v } ),
						} )
					)
				),
				el( shape.tag, blockProps )
			);
		},
		save() {
			return null;
		},
	} ) );

	registerBlockType( 'scout/frozen', Object.assign( {}, common, {
		title: 'Theme element',
		attributes: { ref: { type: 'string', default: '' }, ws },
		edit( { attributes: a } ) {
			const shape = asElement( tok( G.frozen[ a.ref ] || '' ), { className: 'scout-frozen' } );
			return el( shape.tag, useBlockProps( shape.props ) );
		},
		save() {
			return null;
		},
	} ) );

	registerBlockType( 'scout/part', Object.assign( {}, common, {
		title: 'Theme part',
		attributes: { name: { type: 'string', default: '' }, label: { type: 'string', default: '' }, ws },
		edit( { attributes: a } ) {
			return el( 'div', useBlockProps( { className: 'scout-part-note' } ),
				el( 'strong', null, a.label || 'Theme part' ),
				el( 'span', null, 'This part shows here on the site and is managed outside the page editor.' )
			);
		},
		save() {
			return null;
		},
	} ) );

	/**
	 * "Page photos" in the page's settings sidebar: every photo on the page in
	 * one list, including background photos that sit behind text and are hard
	 * to click on the canvas.
	 */
	function photoOf( block ) {
		const t = document.createElement( 'template' );
		t.innerHTML = tok( G.frozen[ block.attributes.ref ] || '' );
		const img = t.content.querySelector( 'img' );
		return {
			src: img ? img.getAttribute( 'src' ) : '',
			alt: img ? img.getAttribute( 'alt' ) || '' : '',
			bg: !! ( img && /(^|\s)bg(\s|$)/.test( img.getAttribute( 'class' ) || '' ) ),
		};
	}

	function PagePhotos() {
		const photos = useSelect( ( select ) => {
			const be = select( 'core/block-editor' );
			return be.getClientIdsWithDescendants().filter( ( id ) => be.getBlockName( id ) === 'scout/image' ).map( ( id ) => {
				const block = be.getBlock( id );
				const media = block.attributes.id ? select( 'core' ).getMedia( block.attributes.id ) : null;
				return { id, block, media };
			} );
		}, [] );
		const { updateBlockAttributes, selectBlock } = useDispatch( 'core/block-editor' );
		if ( ! photos.length ) return null;
		return el( wp.editor.PluginDocumentSettingPanel, { name: 'scout-page-photos', title: 'Page photos', className: 'scout-page-photos' },
			el( 'p', { style: { marginTop: 0 } }, 'Every photo on this page, including backgrounds. Replace one here or click it on the page.' ),
			photos.map( ( p, i ) => {
				const orig = photoOf( p.block );
				const src = p.media && p.media.source_url ? p.media.source_url : orig.src;
				const label = p.block.attributes.alt || orig.alt || ( orig.bg ? 'Background photo' : 'Photo ' + ( i + 1 ) );
				return el( 'div', { key: p.id, style: { display: 'grid', gridTemplateColumns: '64px 1fr', gap: '10px', alignItems: 'center', padding: '8px 0', borderTop: '1px solid #e0e0e0' } },
					el( 'img', { src, alt: '', style: { width: '64px', height: '48px', objectFit: 'cover', borderRadius: '2px', cursor: 'pointer' }, onClick: () => selectBlock( p.id ) } ),
					el( 'div', null,
						el( 'div', { style: { fontSize: '12px', marginBottom: '4px' } }, label + ( orig.bg && label !== 'Background photo' ? ' (background)' : '' ) ),
						el( MediaUploadCheck, null,
							el( MediaUpload, {
								allowedTypes: [ 'image' ],
								value: p.block.attributes.id,
								onSelect: ( m ) => updateBlockAttributes( p.id, { id: m.id } ),
								render: ( { open } ) => el( wp.components.Button, { variant: 'secondary', size: 'small', onClick: open }, 'Replace' ),
							} )
						),
						p.block.attributes.id ? el( wp.components.Button, { variant: 'link', size: 'small', style: { marginLeft: '8px' }, onClick: () => updateBlockAttributes( p.id, { id: undefined } ) }, 'Use original' ) : null
					)
				);
			} )
		);
	}

	if ( wp.plugins && wp.editor && wp.editor.PluginDocumentSettingPanel ) {
		wp.plugins.registerPlugin( 'scout-page-photos', { render: PagePhotos } );
	}
}( window.wp ) );
