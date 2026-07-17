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
	var ro = null;

	function rand( a, b ) {
		return a + Math.random() * ( b - a );
	}

	var R_MIN = 2.5, R_MAX = 12;

	function vw() {
		return window.innerWidth || document.documentElement.clientWidth || 1200;
	}

	/**
	 * Rise speed as a function of size, like a bubble in water: buoyancy grows
	 * with volume, so big bubbles surge upward while tiny ones barely drift. The
	 * squared term keeps the small end genuinely slow; the jitter stops
	 * same-size bubbles from moving in lockstep.
	 */
	function speedFor( r ) {
		var t = ( r - R_MIN ) / ( R_MAX - R_MIN ); // 0 (tiny) .. 1 (big)
		return ( 3 + t * t * 17 ) * rand( 0.85, 1.15 );
	}

	// Roughly half the field is the crisp "shiny" bubble, half the soft glow.
	function pickType() {
		return Math.random() < 0.5 ? 'shiny' : 'soft';
	}

	// Soft glowing blob — the original ambient look.
	function drawSoft( ctx, x, y, r, alpha ) {
		var g = ctx.createRadialGradient( x, y, 0, x, y, r );
		g.addColorStop( 0, 'rgba(180,255,212,' + alpha + ')' );
		g.addColorStop( 0.55, 'rgba(90,220,150,' + ( alpha * 0.34 ) + ')' );
		g.addColorStop( 1, 'rgba(90,220,150,0)' );
		ctx.fillStyle = g;
		ctx.beginPath();
		ctx.arc( x, y, r, 0, Math.PI * 2 );
		ctx.fill();
	}

	// A more "real" bubble: near-hollow body with a bright rim and a small
	// specular highlight toward the upper-left, like light catching a bubble.
	function drawShiny( ctx, x, y, r, alpha ) {
		// Body: faint inside, brightening to a glassy rim.
		var body = ctx.createRadialGradient( x, y, r * 0.2, x, y, r );
		body.addColorStop( 0, 'rgba(150,240,196,' + ( alpha * 0.10 ) + ')' );
		body.addColorStop( 0.74, 'rgba(120,235,182,' + ( alpha * 0.16 ) + ')' );
		body.addColorStop( 0.9, 'rgba(205,255,228,' + ( alpha * 0.85 ) + ')' );
		body.addColorStop( 1, 'rgba(120,235,182,0)' );
		ctx.fillStyle = body;
		ctx.beginPath();
		ctx.arc( x, y, r, 0, Math.PI * 2 );
		ctx.fill();

		// Crisp thin rim for a rounder, glassier edge.
		ctx.lineWidth = Math.max( 0.6, r * 0.07 );
		ctx.strokeStyle = 'rgba(215,255,232,' + Math.min( 0.7, alpha * 1.6 ) + ')';
		ctx.stroke();

		// Specular highlight dot near the top-left.
		var hx = x - r * 0.33, hy = y - r * 0.33, hr = r * 0.4;
		var hi = ctx.createRadialGradient( hx, hy, 0, hx, hy, hr );
		hi.addColorStop( 0, 'rgba(255,255,255,' + Math.min( 0.92, alpha * 2.6 ) + ')' );
		hi.addColorStop( 1, 'rgba(255,255,255,0)' );
		ctx.fillStyle = hi;
		ctx.beginPath();
		ctx.arc( hx, hy, hr, 0, Math.PI * 2 );
		ctx.fill();
	}

	function makeBubble( y ) {
		var r = rand( R_MIN, R_MAX );
		return {
			x: rand( 0, vw() ),          // viewport-x (no horizontal scroll on the wiki)
			y: y,                        // document-y
			r: r,
			speed: speedFor( r ),        // px/sec upward — bigger rises faster
			drift: rand( 3, 6 ) + r * 0.5, // bigger bubbles wobble a touch more
			phase: rand( 0, Math.PI * 2 ),
			omega: rand( 0.3, 0.9 ),     // sway speed
			alpha: rand( 0.12, 0.32 ),
			type: pickType()
		};
	}

	function updateDocH() {
		docH = Math.max(
			document.documentElement.scrollHeight,
			document.body ? document.body.scrollHeight : 0,
			window.innerHeight
		);
	}

	function targetCount() {
		return Math.min( 480, Math.max( 48, Math.round( docH / 17 ) ) );
	}

	function seed() {
		updateDocH();
		var target = targetCount();
		bubbles = [];
		for ( var i = 0; i < target; i++ ) {
			bubbles.push( makeBubble( rand( 0, docH ) ) );
		}
	}

	/**
	 * Re-fit to a layout change WITHOUT regenerating the field: resize canvases,
	 * refresh docH, and only add/remove bubbles to reach the new target count.
	 * Existing bubbles keep their positions, so nothing visibly "resets". This is
	 * important on mobile, where the URL bar hiding on scroll fires resize events.
	 */
	function reflow() {
		collectCards();
		observeCards();
		sizeCanvases();
		updateDocH();
		var target = targetCount();
		while ( bubbles.length < target ) {
			bubbles.push( makeBubble( rand( 0, docH ) ) );
		}
		if ( bubbles.length > target ) {
			bubbles.length = target;
		}
	}

	function observeCards() {
		if ( !ro ) {
			return;
		}
		cards.forEach( function ( c ) {
			ro.observe( c.el ); // observing twice is a no-op
		} );
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
				b.r = rand( R_MIN, R_MAX );
				b.speed = speedFor( b.r );
				b.drift = rand( 3, 6 ) + b.r * 0.5;
				b.alpha = rand( 0.12, 0.32 );
				b.type = pickType();
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
				if ( b.type === 'shiny' ) {
					drawShiny( ctx, lx, ly, b.r, b.alpha );
				} else {
					drawSoft( ctx, lx, ly, b.r, b.alpha );
				}
			}
		}

		window.requestAnimationFrame( frame );
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

		function scheduleReflow() {
			clearTimeout( resizeTimer );
			resizeTimer = setTimeout( reflow, 200 );
		}

		// Re-fit only when a card's actual box size changes (responsive reflow,
		// rotation, late-loading images). Crucially, a ResizeObserver does NOT
		// fire on scroll or when the mobile URL bar shows/hides — those change
		// the viewport height (and, on some iPhones, window.innerWidth) but not
		// the cards' layout size. Listening to window.resize instead made the
		// field flicker whenever the URL bar toggled on scroll-direction change.
		if ( typeof ResizeObserver !== 'undefined' ) {
			ro = new ResizeObserver( scheduleReflow );
			observeCards();
		} else {
			// Fallback for very old browsers: width-only resize guard.
			var lastW = window.innerWidth;
			window.addEventListener( 'resize', function () {
				if ( window.innerWidth === lastW ) {
					return;
				}
				lastW = window.innerWidth;
				scheduleReflow();
			} );
		}
		// Late layout shifts (images) also change the page height; top up once.
		window.addEventListener( 'load', function () {
			setTimeout( reflow, 300 );
		} );
	}

	if ( document.readyState !== 'loading' ) {
		start();
	} else {
		document.addEventListener( 'DOMContentLoaded', start );
	}
}() );
