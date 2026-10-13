/**
 * Reviews > Ask for reviews: draws the QR codes, copies text, downloads the
 * QR, and prints one card layout at a time.
 */
( function () {
	'use strict';

	var svgCache = {};

	function qrSvg( url ) {
		if ( svgCache[ url ] ) {
			return svgCache[ url ];
		}
		var q = window.qrcode( 0, 'M' );
		q.addData( url );
		q.make();
		svgCache[ url ] = q.createSvgTag( { cellSize: 8, margin: 2, scalable: true } );
		return svgCache[ url ];
	}

	function drawAll() {
		if ( ! window.qrcode ) {
			return;
		}
		document.querySelectorAll( '[data-qr-url]' ).forEach( function ( box ) {
			box.innerHTML = qrSvg( box.getAttribute( 'data-qr-url' ) );
			var svg = box.querySelector( 'svg' );
			if ( svg ) {
				svg.setAttribute( 'aria-hidden', 'true' );
			}
		} );
	}

	function flash( button, text ) {
		var old = button.textContent;
		button.textContent = text;
		setTimeout( function () {
			button.textContent = old;
		}, 1600 );
	}

	function copy( button ) {
		var el = document.querySelector( button.getAttribute( 'data-copy' ) );
		if ( ! el ) {
			return;
		}
		var value = 'value' in el ? el.value : el.textContent;
		var done = function () {
			flash( button, 'Copied' );
		};
		var fallback = function () {
			if ( 'select' in el ) {
				el.select();
			} else {
				var range = document.createRange();
				range.selectNodeContents( el );
				var sel = window.getSelection();
				sel.removeAllRanges();
				sel.addRange( range );
			}
			try {
				document.execCommand( 'copy' );
				done();
			} catch ( e ) {
				flash( button, 'Press Ctrl+C' );
			}
		};
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( value ).then( done, fallback );
		} else {
			fallback();
		}
	}

	function save( blob, name ) {
		var a = document.createElement( 'a' );
		a.href = URL.createObjectURL( blob );
		a.download = name;
		document.body.appendChild( a );
		a.click();
		setTimeout( function () {
			URL.revokeObjectURL( a.href );
			a.remove();
		}, 500 );
	}

	function download( kind ) {
		var box = document.querySelector( '.srk-qr-lg' );
		if ( ! box || ! window.qrcode ) {
			return;
		}
		var svg = qrSvg( box.getAttribute( 'data-qr-url' ) ).replace( '<svg ', '<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="1200" ' );
		if ( 'svg' === kind ) {
			save( new Blob( [ svg ], { type: 'image/svg+xml' } ), 'review-qr-code.svg' );
			return;
		}
		var img = new Image();
		img.onload = function () {
			var canvas = document.createElement( 'canvas' );
			canvas.width = 1200;
			canvas.height = 1200;
			var ctx = canvas.getContext( '2d' );
			ctx.fillStyle = '#ffffff';
			ctx.fillRect( 0, 0, 1200, 1200 );
			ctx.drawImage( img, 0, 0, 1200, 1200 );
			canvas.toBlob( function ( blob ) {
				save( blob, 'review-qr-code.png' );
			}, 'image/png' );
		};
		img.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent( svg );
	}

	// Prints one sheet from a hidden frame that holds nothing else, so every
	// browser (Safari included) prints exactly one page at full size.
	function print( layout ) {
		var sheet = document.querySelector( '.srk-sheet[data-layout="' + layout + '"]' );
		if ( ! sheet ) {
			return;
		}
		var wrap   = sheet.closest( '.srk' );
		var accent = wrap ? getComputedStyle( wrap ).getPropertyValue( '--srk-accent' ) : '';
		var links  = Array.prototype.map.call(
			document.querySelectorAll( 'link[rel="stylesheet"][id^="scout-reviews-"]' ),
			function ( l ) { return '<link rel="stylesheet" href="' + l.href + '">'; }
		).join( '' );
		var old = document.getElementById( 'srk-print-frame' );
		if ( old ) {
			old.remove();
		}
		var frame = document.createElement( 'iframe' );
		frame.id = 'srk-print-frame';
		frame.setAttribute( 'aria-hidden', 'true' );
		frame.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;visibility:hidden';
		document.body.appendChild( frame );
		var doc = frame.contentDocument;
		doc.open();
		doc.write(
			'<!doctype html><html><head><meta charset="utf-8"><title>Review cards</title>' + links +
			'<style>@page{size:letter;margin:.4in}html,body{margin:0;padding:0;background:#fff}</style></head>' +
			'<body class="srk-print-frame"><div class="srk recon-theme" style="max-width:none;margin:0;padding:0;background:#fff;--srk-accent:' + accent.trim() + '">' +
			sheet.outerHTML + '</div></body></html>'
		);
		doc.close();
		var done = false;
		var go = function () {
			if ( done ) {
				return;
			}
			done = true;
			var win = frame.contentWindow;
			var fonts = doc.fonts && doc.fonts.ready ? doc.fonts.ready : Promise.resolve();
			fonts.then( function () {
				win.focus();
				win.print();
			} );
		};
		frame.onload = go;
		setTimeout( go, 1500 ); // Safety net if load already fired.
	}
	document.addEventListener( 'click', function ( e ) {
		var t = e.target.closest( '[data-copy],[data-qr-download],[data-print]' );
		if ( ! t ) {
			return;
		}
		if ( t.hasAttribute( 'data-copy' ) ) {
			copy( t );
		} else if ( t.hasAttribute( 'data-qr-download' ) ) {
			download( t.getAttribute( 'data-qr-download' ) );
		} else {
			print( t.getAttribute( 'data-print' ) );
		}
	} );

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', drawAll );
	} else {
		drawAll();
	}
} )();
