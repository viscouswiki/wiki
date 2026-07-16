/*!
 * DeadlockBuilds — "Share" buttons: copy a build's canonical link to the
 * clipboard, with a brief "Copied!" confirmation.
 */
( function () {
	'use strict';

	function fallbackCopy( text ) {
		var ta = document.createElement( 'textarea' );
		ta.value = text;
		ta.setAttribute( 'readonly', '' );
		ta.style.position = 'absolute';
		ta.style.left = '-9999px';
		document.body.appendChild( ta );
		ta.select();
		try { document.execCommand( 'copy' ); } catch ( e ) {}
		document.body.removeChild( ta );
	}

	function flash( btn ) {
		if ( btn.dataset.dlbBusy ) { return; }
		btn.dataset.dlbBusy = '1';
		var label = btn.querySelector( '.dlb-share-label' );
		var prev = label ? label.textContent : null;
		if ( label ) { label.textContent = 'Copied!'; }
		btn.classList.add( 'dlb-share-done' );
		setTimeout( function () {
			if ( label && prev !== null ) { label.textContent = prev; }
			btn.classList.remove( 'dlb-share-done' );
			delete btn.dataset.dlbBusy;
		}, 1500 );
	}

	function onClick( ev ) {
		var btn = ev.currentTarget;
		var url = btn.getAttribute( 'data-dlb-url' ) || window.location.href;
		if ( window.navigator && navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( url ).then(
				function () { flash( btn ); },
				function () { fallbackCopy( url ); flash( btn ); }
			);
		} else {
			fallbackCopy( url );
			flash( btn );
		}
	}

	function init() {
		var btns = document.querySelectorAll( '[data-dlb-share]' );
		Array.prototype.forEach.call( btns, function ( btn ) {
			if ( btn.dataset.dlbBound ) { return; }
			btn.dataset.dlbBound = '1';
			// Defensive fallback: if a stale cached page rendered the button
			// without a .dlb-share-label span, wrap its trailing text (minus
			// the icon glyph) so flash() has something to swap for "Copied!".
			if ( !btn.querySelector( '.dlb-share-label' ) ) {
				var ico = btn.querySelector( '.dlb-share-ico' );
				var full = ( btn.textContent || '' );
				var icoText = ico ? ico.textContent : '';
				var text = full.replace( icoText, '' ).trim() || 'Share';
				btn.innerHTML = '';
				if ( ico ) { btn.appendChild( ico ); }
				var span = document.createElement( 'span' );
				span.className = 'dlb-share-label';
				span.textContent = text;
				btn.appendChild( span );
			}
			btn.addEventListener( 'click', onClick );
		} );
	}

	if ( document.readyState !== 'loading' ) {
		init();
	} else {
		document.addEventListener( 'DOMContentLoaded', init );
	}
}() );
