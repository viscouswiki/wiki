/*
 * Dedicated buttons in Citizen's persistent left rail. The rail's
 * bottom-anchored group (.citizen-header__end) holds the preferences and
 * personal-menu icons; we prepend our own icons there so they sit in the
 * bottom-left and look native — Viscord (Discord) on top, then The Squid.
 * If the rail structure ever changes, fall back to fixed bottom-left buttons.
 */
( function () {
	'use strict';

	function railLink( opts ) {
		var a = document.createElement( 'a' );
		a.className = opts.cls + ' citizen-header__item';
		a.href = opts.href;
		a.title = opts.title;
		a.setAttribute( 'aria-label', opts.title );
		if ( opts.external ) {
			a.target = '_blank';
			a.rel = 'noopener noreferrer';
		}
		var ico = document.createElement( 'span' );
		ico.className = opts.iconCls;
		ico.style.backgroundImage = 'url(' + opts.icon + ')';
		a.appendChild( ico );
		if ( opts.active ) {
			a.className += ' ' + opts.active;
		}
		return a;
	}

	function buildSquid() {
		return railLink( {
			cls: 'vw-squid-nav',
			iconCls: 'vw-squid-nav-icon',
			href: mw.util.getUrl( 'The Squid' ),
			icon: 'https://media.viscous.wiki/squid/octopus3.png',
			title: 'The Squid',
			active: mw.config.get( 'wgPageName' ) === 'The_Squid' ? 'vw-squid-nav-active' : ''
		} );
	}

	function buildViscord() {
		return railLink( {
			cls: 'vw-viscord-nav',
			iconCls: 'vw-viscord-nav-icon',
			href: 'https://discord.gg/viscord',
			icon: 'https://media.viscous.wiki/branding/discord-viscous.svg',
			title: 'Join the Viscord',
			external: true
		} );
	}

	function inject() {
		if ( document.querySelector( '.vw-squid-nav' ) ) {
			return;
		}
		var end = document.querySelector( '.citizen-header__end' );
		if ( end ) {
			// Squid becomes the first child, then Viscord is inserted before it
			// so the final order is: Viscord (top), Squid, preferences, personal.
			end.insertBefore( buildSquid(), end.firstChild );
			end.insertBefore( buildViscord(), end.firstChild );
		} else {
			var squid = buildSquid();
			squid.className += ' vw-squid-nav-fab';
			document.body.appendChild( squid );
			var viscord = buildViscord();
			viscord.className += ' vw-squid-nav-fab';
			viscord.style.bottom = '66px';
			document.body.appendChild( viscord );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', inject );
	} else {
		inject();
	}
}() );
