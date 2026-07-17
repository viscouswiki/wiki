/*!
 * DeadlockBuilds — page-wide "goo bubble" layer.
 *
 * One shared field of bubbles lives in *document* coordinates and drifts slowly
 * upward. Every card (hero, feature cards, build cards) gets a <canvas> that
 * renders only the bubbles overlapping that card, offset into the card's local
 * space. Because the field is shared, a bubble that rises out of one card's top
 * reappears at the bottom of the card above it; in the gaps between cards, where
 * no canvas covers it, it simply isn't drawn. The canvas sits at z-index -1
 * inside each card, so bubbles float above the card's background but below its
 * text — exactly where the old CSS bubbles used to be.
 */
( function () {
	'use strict';

	var SELECTOR = '.vw-hero, .vw-cards a, .dlb-index-card';
	var reduce = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

	var cards = [];
	var bubbles = [];
	var dpr = Math.max( 1, window.devicePixelRatio || 1 );
	var docH = 0;
	var lastT = 0;
	var resizeTimer = null;

	function rand( a, b ) {
		return a + Math.random() * ( b - a );
	}

	function vw() {
		return window.innerWidth || document.documentElement.clientWidth || 1200;
	}

	function makeBubble( y ) {
		return {
			x: rand( 0, vw() ),        // viewport-x (no horizontal scroll on the wiki)
			y: y,                      // document-y
			r: rand( 3, 11 ),
			speed: rand( 9, 30 ),      // px/sec upward
			drift: rand( 4, 12 ),      // horizontal sway amplitude
			phase: rand( 0, Math.PI * 2 ),
			omega: rand( 0.3, 0.9 ),   // sway speed
			alpha: rand( 0.12, 0.32 )
		};
	}

	function seed() {
		docH = Math.max(
			document.documentElement.scrollHeight,
			document.body ? document.body.scrollHeight : 0,
			window.innerHeight
		);
		var target = Math.min( 240, Math.max( 24, Math.round( docH / 34 ) ) );
		bubbles = [];
		for ( var i = 0; i < target; i++ ) {
			bubbles.push( makeBubble( rand( 0, docH ) ) );
		}
	}

	function collectCards() {
		cards = [];
		var els = document.querySelectorAll( SELECTOR );
		Array.prototype.forEach.call( els, function ( el ) {
			var cv = el.querySelector( 'canvas.vw-bubbles' );
			if ( cv && cv.parentNode !== el ) {
				cv = null; // belongs to a nested card, not this one
			}
			if ( !cv ) {
				cv = document.createElement( 'canvas' );
				cv.className = 'vw-bubbles';
				cv.setAttribute( 'aria-hidden', 'true' );
				if ( getComputedStyle( el ).position === 'static' ) {
					el.style.position = 'relative';
				}
				el.insertBefore( cv, el.firstChild );
			}
			cards.push( { el: el, cv: cv, ctx: cv.getContext( '2d' ) } );
		} );
	}

	function sizeCanvases() {
		cards.forEach( function ( c ) {
			var r = c.el.getBoundingClientRect();
			var w = Math.max( 1, Math.round( r.width ) );
			var h = Math.max( 1, Math.round( r.height ) );
			if ( c.cv.width !== w * dpr || c.cv.height !== h * dpr ) {
				c.cv.width = w * dpr;
				c.cv.height = h * dpr;
			}
		} );
	}

	function frame( t ) {
		if ( !lastT ) {
			lastT = t;
		}
		var dt = Math.min( 0.05, ( t - lastT ) / 1000 );
		lastT = t;

		var i, b;
		for ( i = 0; i < bubbles.length; i++ ) {
			b = bubbles[ i ];
			b.y -= b.speed * dt;
			b.phase += b.omega * dt;
			if ( b.y < -20 ) {
				// Recycle to the bottom of the document as a fresh bubble.
				b.y = docH + rand( 0, 60 );
				b.x = rand( 0, vw() );
				b.r = rand( 3, 11 );
				b.speed = rand( 9, 30 );
				b.alpha = rand( 0.12, 0.32 );
			}
		}

		var scrollY = window.pageYOffset || document.documentElement.scrollTop || 0;
		var scrollX = window.pageXOffset || document.documentElement.scrollLeft || 0;
		var vh = window.innerHeight;

		for ( var ci = 0; ci < cards.length; ci++ ) {
			var c = cards[ ci ];
			var r = c.el.getBoundingClientRect();
			var ctx = c.ctx;
			ctx.setTransform( dpr, 0, 0, dpr, 0, 0 );
			ctx.clearRect( 0, 0, r.width, r.height );
			if ( r.bottom < -60 || r.top > vh + 60 || r.width < 1 ) {
				continue; // card off-screen — skip drawing
			}
			var cardTop = r.top + scrollY;
			var cardLeft = r.left + scrollX;
			for ( var bi = 0; bi < bubbles.length; bi++ ) {
				b = bubbles[ bi ];
				var lx = ( b.x - cardLeft ) + Math.sin( b.phase ) * b.drift;
				var ly = b.y - cardTop;
				if ( ly < -b.r || ly > r.height + b.r || lx < -b.r || lx > r.width + b.r ) {
					continue;
				}
				var g = ctx.createRadialGradient( lx, ly, 0, lx, ly, b.r );
				g.addColorStop( 0, 'rgba(180,255,212,' + b.alpha + ')' );
				g.addColorStop( 0.55, 'rgba(90,220,150,' + ( b.alpha * 0.34 ) + ')' );
				g.addColorStop( 1, 'rgba(90,220,150,0)' );
				ctx.fillStyle = g;
				ctx.beginPath();
				ctx.arc( lx, ly, b.r, 0, Math.PI * 2 );
				ctx.fill();
			}
		}

		window.requestAnimationFrame( frame );
	}

	function relayout() {
		collectCards();
		sizeCanvases();
		seed();
	}

	function start() {
		collectCards();
		if ( !cards.length ) {
			return;
		}
		sizeCanvases();
		seed();
		if ( reduce ) {
			// Draw a single static frame, no animation.
			frame( 0 );
			return;
		}
		lastT = 0;
		window.requestAnimationFrame( frame );
		window.addEventListener( 'resize', function () {
			clearTimeout( resizeTimer );
			resizeTimer = setTimeout( relayout, 200 );
		} );
		// Cards/images can change the page height after load; re-seed once settled.
		window.addEventListener( 'load', function () {
			setTimeout( relayout, 300 );
		} );
	}

	if ( document.readyState !== 'loading' ) {
		start();
	} else {
		document.addEventListener( 'DOMContentLoaded', start );
	}
}() );
