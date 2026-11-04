/**
 * The writing room (Scout Writing).
 * Filters, Star / Mark done toggles, the Write panel with autosave, voice memos, Send to Scout and
 * Start a draft. Everything also works as plain links and a form without this script.
 */
( function () {
	'use strict';

	var cfg = window.scoutWriting || {};
	var root = document.querySelector( '.sw' );
	if ( ! root || ! cfg.ajax ) {
		return;
	}
	root.classList.add( 'sw-js' );
	var hasMedia = !! ( cfg.canMemo && window.wp && window.wp.media );
	Array.prototype.forEach.call( root.querySelectorAll( '[data-js-only]' ), function ( el ) {
		el.hidden = el.classList.contains( 'sw-memo' ) ? ! hasMedia : false;
	} );

	var $ = function ( sel, ctx ) { return ( ctx || root ).querySelector( sel ); };
	var $$ = function ( sel, ctx ) { return Array.prototype.slice.call( ( ctx || root ).querySelectorAll( sel ) ); };
	var listStale = false;

	/* ------------------------------------------------------------ requests */

	function post( data ) {
		var body = new FormData();
		body.append( 'action', 'scout_writing' );
		body.append( 'nonce', cfg.nonce );
		Object.keys( data ).forEach( function ( k ) { body.append( k, data[ k ] ); } );
		return fetch( cfg.ajax, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) {
				return r.json().catch( function () { throw new Error( 'The site did not answer properly.' ); } );
			} )
			.then( function ( j ) {
				if ( ! j || ! j.success ) {
					throw new Error( ( j && j.data && j.data.message ) || 'That did not work.' );
				}
				return j.data;
			}, function ( err ) {
				throw new Error( err && err.message && err.message.indexOf( 'site' ) > -1 ? err.message : 'No connection right now.' );
			} );
	}

	var toastEl = document.createElement( 'div' );
	toastEl.className = 'sw-toast';
	toastEl.setAttribute( 'role', 'status' );
	toastEl.hidden = true;
	root.appendChild( toastEl );
	var toastTimer;
	function toast( msg ) {
		toastEl.textContent = msg;
		toastEl.hidden = false;
		clearTimeout( toastTimer );
		toastTimer = setTimeout( function () { toastEl.hidden = true; }, 4200 );
	}

	/* ------------------------------------------------------------ marks + status */

	function prompts( pid ) {
		return $$( '.sw-p[data-pid="' + pid + '"]' );
	}

	function setMark( li, mark, on ) {
		li.classList.toggle( mark === 'star' ? 'is-star' : 'is-done', on );
		var btn = $( '.sw-' + mark, li );
		if ( ! btn ) {
			return;
		}
		btn.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
		$( '.sw-ic', btn ).textContent = mark === 'star' ? ( on ? '★' : '☆' ) : ( on ? '✓' : '○' );
		$( '.sw-lb', btn ).textContent = mark === 'star' ? ( on ? 'Starred' : 'Star' ) : ( on ? 'Done' : 'Mark done' );
	}

	function countDone() {
		var n = $( '#sw-done-n' );
		if ( n ) {
			n.textContent = $$( '.sw-steps .sw-p.is-done' ).length;
		}
		$$( '.sw-sdone' ).forEach( function ( el ) {
			var c = $$( '.sw-steps .sw-p.is-done[data-step="' + el.getAttribute( 'data-done' ) + '"]' ).length;
			el.textContent = c ? c + ' done' : '';
		} );
	}

	function applyPayload( p ) {
		prompts( p.pid ).forEach( function ( li ) {
			setMark( li, 'star', !! p.star );
			setMark( li, 'done', !! p.done );
			var chip = $( '.sw-status', li );
			if ( chip ) {
				chip.className = 'sw-status sw-s-' + ( p.status || '' );
				chip.textContent = p.label || '';
				chip.hidden = ! p.status;
			}
			var w = $( '.sw-write .sw-lb', li );
			if ( w && p.id ) {
				w.textContent = 'Keep writing';
			}
		} );
		countDone();
		var written = $( '#sw-written-n' );
		if ( written && p.id && ! known[ p.pid ] ) {
			// A first answer to this prompt: the number keeps up now, the My writing list on its next load.
			known[ p.pid ] = true;
			written.textContent = parseInt( written.textContent, 10 ) + 1;
		}
	}

	var known = {};
	$$( '.sw-steps .sw-p' ).forEach( function ( li ) {
		var chip = $( '.sw-status', li );
		if ( chip && ! chip.hidden ) {
			known[ li.dataset.pid ] = true;
		}
	} );

	root.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '[data-act="star"], [data-act="done"]' );
		if ( ! btn || ! root.contains( btn ) ) {
			return;
		}
		e.preventDefault();
		var li = btn.closest( '.sw-p' );
		var mark = btn.getAttribute( 'data-act' );
		var on = btn.getAttribute( 'aria-pressed' ) !== 'true';
		prompts( li.dataset.pid ).forEach( function ( x ) { setMark( x, mark, on ); } );
		countDone();
		post( { do: 'mark', mark: mark, pid: li.dataset.pid, on: on ? '1' : '0' } )
			.then( function ( p ) { applyPayload( p ); listStale = true; filter(); } )
			.catch( function ( err ) {
				prompts( li.dataset.pid ).forEach( function ( x ) { setMark( x, mark, ! on ); } );
				countDone();
				toast( err.message + ' Your mark was not saved.' );
			} );
	} );

	/* ------------------------------------------------------------ the Write panel */

	var panels = [];

	function words( s ) {
		var m = s.match( /[\p{L}\p{N}][\p{L}\p{N}'’-]*/gu );
		return m ? m.length : 0;
	}

	function initPanel( panel, pid, loaded ) {
		var ta = $( '.sw-text', panel );
		var saved = $( '.sw-saved', panel );
		var count = $( '.sw-count', panel );
		var msg = $( '.sw-msg', panel );
		var state = { last: ta.value, inflight: null, queued: false, timer: 0, failed: false };
		state.dirty = function () { return ta.value !== state.last || !! state.inflight; };
		panels.push( state );

		function showCount() {
			var n = words( ta.value );
			count.textContent = n ? n + ( n === 1 ? ' word' : ' words' ) : '';
		}
		function say( text ) {
			msg.textContent = text || '';
			msg.hidden = ! text;
		}
		function render( p ) {
			var ul = $( '.sw-memos', panel );
			ul.textContent = '';
			( p.memos || [] ).forEach( function ( m ) {
				var li = document.createElement( 'li' );
				if ( m.audio ) {
					var au = document.createElement( 'audio' );
					au.controls = true;
					au.preload = 'none';
					au.src = m.url;
					li.appendChild( au );
				}
				var a = document.createElement( 'a' );
				a.href = m.url;
				a.target = '_blank';
				a.rel = 'noopener';
				a.textContent = m.title || 'Voice memo';
				li.appendChild( a );
				var rm = document.createElement( 'button' );
				rm.type = 'button';
				rm.className = 'sw-link sw-unmemo';
				rm.textContent = 'Remove';
				rm.setAttribute( 'aria-label', 'Remove ' + ( m.title || 'voice memo' ) + ' from this answer' );
				rm.addEventListener( 'click', function () {
					post( { do: 'unmemo', pid: pid, att: m.id } ).then( function ( q ) { render( q ); applyPayload( q ); } ).catch( function ( err ) { say( err.message ); } );
				} );
				li.appendChild( rm );
				ul.appendChild( li );
			} );
			ul.hidden = ! ( p.memos && p.memos.length );
			var sent = $( '.sw-sent', panel );
			sent.textContent = p.sent || '';
			sent.hidden = ! p.sent;
			var draftBtn = $( '.sw-draft', panel );
			var note = $( '.sw-draftnote', panel );
			if ( draftBtn ) {
				draftBtn.hidden = !! p.draft || ! p.canDraft;
			}
			note.textContent = '';
			if ( p.draft ) {
				note.appendChild( document.createTextNode( 'A draft post was started from this on ' + p.draft.when + '. ' ) );
				if ( p.draft.url ) {
					var a2 = document.createElement( 'a' );
					a2.href = p.draft.url;
					a2.textContent = 'Open the draft';
					note.appendChild( a2 );
				}
			}
			note.hidden = ! p.draft;
			if ( p.saved && ! state.dirty() ) {
				saved.textContent = p.saved;
			}
			say( p.note );
		}

		function save() {
			clearTimeout( state.timer );
			if ( ta.value === state.last && ! state.failed ) {
				return state.inflight || Promise.resolve();
			}
			if ( state.inflight ) {
				state.queued = true;
				return state.inflight;
			}
			var text = ta.value;
			saved.textContent = 'Saving…';
			state.inflight = post( { do: 'save', pid: pid, text: text } )
				.then( function ( p ) {
					state.last = text;
					state.failed = false;
					listStale = true;
					applyPayload( p );
					saved.textContent = ta.value === text ? ( p.saved || 'Saved' ) : 'Saving…';
				} )
				.catch( function ( err ) {
					state.failed = true;
					saved.textContent = 'Not saved yet. ' + err.message + ' It will try again as you type.';
				} )
				.then( function () {
					state.inflight = null;
					if ( state.queued || ta.value !== state.last ) {
						state.queued = false;
						if ( ! state.failed ) {
							return save();
						}
					}
				} );
			return state.inflight;
		}
		state.save = save;

		ta.addEventListener( 'input', function () {
			showCount();
			saved.textContent = 'Writing…';
			clearTimeout( state.timer );
			state.timer = setTimeout( save, 1200 );
		} );
		ta.addEventListener( 'blur', save );

		function act( which, extra ) {
			say( '' );
			return save().then( function () {
				var data = { do: which, pid: pid, text: ta.value };
				Object.keys( extra || {} ).forEach( function ( k ) { data[ k ] = extra[ k ]; } );
				return post( data );
			} ).then( function ( p ) {
				state.last = ta.value;
				listStale = true;
				render( p );
				applyPayload( p );
			} ).catch( function ( err ) { say( err.message ); } );
		}

		$$( 'button[name="do"]', panel ).forEach( function ( b ) {
			b.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				if ( b.value === 'save' ) {
					save();
					return;
				}
				b.disabled = true;
				act( b.value ).then( function () { b.disabled = false; } );
			} );
		} );

		var memo = $( '.sw-memo', panel );
		if ( memo && hasMedia ) {
			memo.hidden = false;
			var frame;
			memo.addEventListener( 'click', function () {
				if ( ! frame ) {
					frame = window.wp.media( {
						title: 'Add a voice memo',
						button: { text: 'Attach to this answer' },
						multiple: false
					} );
					frame.on( 'select', function () {
						var att = frame.state().get( 'selection' ).first();
						if ( att ) {
							act( 'memo', { att: att.get( 'id' ) } );
						}
					} );
				}
				frame.open();
			} );
		} else if ( memo ) {
			memo.hidden = true;
		}

		var close = $( '.sw-close', panel );
		if ( close ) {
			close.addEventListener( 'click', function () {
				save().then( function () {
					var li = panel.closest( '.sw-p' );
					var w = li && $( '.sw-write', li );
					panels.splice( panels.indexOf( state ), 1 );
					panel.remove();
					if ( w ) {
						w.setAttribute( 'aria-expanded', 'false' );
						w.focus();
					}
				} );
			} );
		}

		if ( loaded ) {
			showCount();
			return;
		}
		ta.disabled = true;
		saved.textContent = 'Opening…';
		post( { do: 'get', pid: pid } ).then( function ( p ) {
			ta.value = p.text || '';
			state.last = ta.value;
			ta.disabled = false;
			saved.textContent = p.saved || '';
			showCount();
			render( p );
			ta.focus();
		} ).catch( function ( err ) {
			saved.textContent = err.message + ' Close and try again.';
		} );
	}

	function openPanel( li, focus ) {
		var slot = $( '.sw-slot', li );
		var existing = slot && $( '.sw-panel', slot );
		if ( existing ) {
			if ( focus !== false ) {
				$( '.sw-text', existing ).focus();
			}
			return existing;
		}
		var tpl = document.getElementById( 'sw-panel-tpl' );
		if ( ! slot || ! tpl ) {
			return null;
		}
		var pid = li.dataset.pid;
		slot.innerHTML = tpl.innerHTML.replace( /__PID__/g, pid );
		var panel = $( '.sw-panel', slot );
		var draftBtn = $( '.sw-draft', panel );
		var canDraft = /(^| )(site|news)( |$)/.test( li.dataset.dest || '' );
		if ( draftBtn ) {
			draftBtn.hidden = ! canDraft;
		}
		$( '.sw-write', li ).setAttribute( 'aria-expanded', 'true' );
		initPanel( panel, pid, false );
		return panel;
	}

	root.addEventListener( 'click', function ( e ) {
		var w = e.target.closest( '[data-act="write"]' );
		if ( ! w || ! root.contains( w ) ) {
			return;
		}
		e.preventDefault();
		var li = w.closest( '.sw-p' );
		var panel = $( '.sw-slot .sw-panel', li );
		if ( panel ) {
			var close = $( '.sw-close', panel );
			if ( close ) {
				close.click();
			}
			return;
		}
		openPanel( li );
	} );

	// The one-prompt page renders its panel inside a form.
	$$( '.sw-one .sw-panel' ).forEach( function ( panel ) {
		initPanel( panel, panel.getAttribute( 'data-pid' ), true );
	} );

	window.addEventListener( 'beforeunload', function ( e ) {
		if ( panels.some( function ( s ) { return s.dirty(); } ) ) {
			panels.forEach( function ( s ) { s.save(); } );
			e.preventDefault();
			e.returnValue = '';
		}
	} );

	/* ------------------------------------------------------------ views */

	function showView( view ) {
		$$( '.sw-view' ).forEach( function ( v ) { v.hidden = v.getAttribute( 'data-view' ) !== view; } );
		$$( '.sw-tab' ).forEach( function ( t ) {
			var on = t.getAttribute( 'data-view' ) === view;
			t.classList.toggle( 'is-on', on );
			if ( on ) {
				t.setAttribute( 'aria-current', 'page' );
			} else {
				t.removeAttribute( 'aria-current' );
			}
		} );
		if ( window.history && history.replaceState ) {
			var url = new URL( window.location.href );
			if ( view === 'mine' ) {
				url.searchParams.set( 'view', 'mine' );
			} else {
				url.searchParams.delete( 'view' );
			}
			url.hash = '';
			history.replaceState( null, '', url.toString() );
		}
	}

	$$( '.sw-tab' ).forEach( function ( t ) {
		t.addEventListener( 'click', function ( e ) {
			var view = t.getAttribute( 'data-view' );
			if ( view === 'mine' && listStale ) {
				// Something was written since this page opened: load the list fresh.
				Promise.all( panels.map( function ( s ) { return s.save(); } ) ).then( function () {
					window.location.href = t.href;
				} );
				e.preventDefault();
				return;
			}
			e.preventDefault();
			showView( view );
		} );
	} );

	function openTo( li ) {
		var el = li.parentElement;
		while ( el && el !== root ) {
			if ( el.tagName === 'DETAILS' ) {
				el.open = true;
			}
			el = el.parentElement;
		}
	}

	$$( '[data-open]' ).forEach( function ( a ) {
		a.addEventListener( 'click', function ( e ) {
			var li = document.getElementById( 'sw-p-' + a.getAttribute( 'data-open' ) );
			if ( ! li ) {
				return;
			}
			e.preventDefault();
			showView( 'all' );
			clearFilters();
			openTo( li );
			openPanel( li, false );
			li.scrollIntoView( { block: 'start' } );
		} );
	} );

	/* ------------------------------------------------------------ filters */

	var st = { q: '', step: '', dest: [], time: [], star: false, hide: false };
	var q = $( '#sw-q' );
	var stepSel = $( '#sw-stepsel' );
	var items = $$( '.sw-steps .sw-p' );

	function active() {
		return !! ( st.q || st.step || st.dest.length || st.time.length || st.star || st.hide );
	}

	function filter() {
		if ( ! items.length ) {
			return;
		}
		var terms = st.q.toLowerCase().split( /\s+/ ).filter( Boolean );
		var shown = 0;
		items.forEach( function ( li ) {
			var s = li.getAttribute( 'data-s' ) || '';
			var dest = ( li.getAttribute( 'data-dest' ) || '' ).split( ' ' );
			var ok = terms.every( function ( t ) { return s.indexOf( t ) > -1; } ) &&
				( ! st.step || li.getAttribute( 'data-step' ) === st.step ) &&
				( ! st.dest.length || dest.some( function ( d ) { return st.dest.indexOf( d ) > -1; } ) ) &&
				( ! st.time.length || st.time.indexOf( li.getAttribute( 'data-time' ) ) > -1 ) &&
				( ! st.star || li.classList.contains( 'is-star' ) ) &&
				( ! st.hide || ! li.classList.contains( 'is-done' ) );
			if ( ! ok && $( '.sw-panel', li ) ) {
				ok = true; // Never hide a prompt while its panel is open.
			}
			li.hidden = ! ok;
			if ( ok ) {
				shown++;
			}
		} );
		var on = active();
		$$( '.sw-grp, .sw-block, .sw-step' ).forEach( function ( box ) {
			if ( box.classList.contains( 'sw-step-ref' ) ) {
				box.hidden = on;
				return;
			}
			var any = !! box.querySelector( '.sw-p:not([hidden])' );
			box.hidden = ! any;
			if ( on && any && box.tagName === 'DETAILS' ) {
				box.open = true;
			}
		} );
		$$( '[data-static]' ).forEach( function ( el ) { el.hidden = on; } );
		var showing = $( '#sw-showing' );
		if ( showing ) {
			showing.innerHTML = '';
			showing.appendChild( document.createTextNode( 'Showing ' ) );
			var b = document.createElement( 'b' );
			b.textContent = shown;
			showing.appendChild( b );
			showing.appendChild( document.createTextNode( shown === 1 ? ' prompt' : ' prompts' ) );
		}
		var empty = $( '#sw-empty' );
		if ( empty ) {
			empty.hidden = shown > 0;
		}
	}

	function clearFilters() {
		st = { q: '', step: '', dest: [], time: [], star: false, hide: false };
		if ( q ) {
			q.value = '';
		}
		if ( stepSel ) {
			stepSel.value = '';
		}
		$$( '.sw-tools [aria-pressed]' ).forEach( function ( b ) { b.setAttribute( 'aria-pressed', 'false' ); } );
		filter();
	}

	var qTimer;
	if ( q ) {
		q.addEventListener( 'input', function () {
			clearTimeout( qTimer );
			qTimer = setTimeout( function () { st.q = q.value.trim(); filter(); }, 150 );
		} );
	}
	if ( stepSel ) {
		stepSel.addEventListener( 'change', function () { st.step = stepSel.value; filter(); } );
	}
	$$( '.sw-fbtn[data-dest], .sw-fbtn[data-time]' ).forEach( function ( b ) {
		b.addEventListener( 'click', function () {
			var key = b.hasAttribute( 'data-dest' ) ? 'dest' : 'time';
			var val = b.getAttribute( 'data-' + key );
			var on = b.getAttribute( 'aria-pressed' ) !== 'true';
			b.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
			st[ key ] = st[ key ].filter( function ( v ) { return v !== val; } );
			if ( on ) {
				st[ key ].push( val );
			}
			filter();
		} );
	} );
	[ [ '#sw-only-star', 'star' ], [ '#sw-hide-done', 'hide' ] ].forEach( function ( pair ) {
		var b = $( pair[ 0 ] );
		if ( b ) {
			b.addEventListener( 'click', function () {
				var on = b.getAttribute( 'aria-pressed' ) !== 'true';
				b.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
				st[ pair[ 1 ] ] = on;
				filter();
			} );
		}
	} );
	[ '#sw-clear', '#sw-clear2' ].forEach( function ( id ) {
		var b = $( id );
		if ( b ) {
			b.addEventListener( 'click', clearFilters );
		}
	} );
	[ [ '#sw-open-all', true ], [ '#sw-close-all', false ] ].forEach( function ( pair ) {
		var b = $( pair[ 0 ] );
		if ( b ) {
			b.addEventListener( 'click', function () {
				$$( '.sw-step, .sw-block' ).forEach( function ( d ) { d.open = pair[ 1 ]; } );
			} );
		}
	} );

	// Arriving at #sw-p-... (after a no-JS action, or a shared link): open its step and show it.
	if ( /^#sw-p-[a-z0-9-]+$/.test( window.location.hash ) ) {
		var target = document.getElementById( window.location.hash.slice( 1 ) );
		if ( target ) {
			openTo( target );
			target.scrollIntoView( { block: 'start' } );
		}
	}
}() );
