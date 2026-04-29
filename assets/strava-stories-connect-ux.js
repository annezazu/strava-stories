/**
 * Strava Stories — Connect button feedback.
 *
 * Strava's OAuth dance can complete in well under a second when the
 * provider auto-approves a previously authorized app. The resulting
 * visual change can be so brief that it looks like nothing happened.
 * This script gives the Connect button an immediate "Connecting…"
 * state, and on return surfaces a brief highlight on the row.
 */
( function () {
	'use strict';

	function markConnecting( link ) {
		if ( link.dataset.stravaStoriesConnecting === '1' ) {
			return;
		}
		link.dataset.stravaStoriesConnecting = '1';
		link.setAttribute( 'aria-busy', 'true' );
		link.style.pointerEvents = 'none';
		link.style.opacity = '0.7';
		link.textContent = link.dataset.connectingLabel || 'Connecting…';
	}

	function bindConnectButtons() {
		var links = document.querySelectorAll(
			'a.button[href*="strava_stories_connect="]'
		);
		Array.prototype.forEach.call( links, function ( link ) {
			link.addEventListener( 'click', function () {
				markConnecting( link );
			} );
		} );
	}

	function flashConnectedRow() {
		var url = new URL( window.location.href );
		if ( ! url.searchParams.get( 'connected' ) ) {
			return;
		}
		var row = document.querySelector( 'tr[data-strava-stories-row]' );
		if ( ! row ) {
			return;
		}
		row.classList.add( 'strava-stories-row-just-connected' );
		row.scrollIntoView( { behavior: 'smooth', block: 'center' } );
		window.setTimeout( function () {
			row.classList.remove( 'strava-stories-row-just-connected' );
		}, 2400 );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			bindConnectButtons();
			flashConnectedRow();
		} );
	} else {
		bindConnectButtons();
		flashConnectedRow();
	}
} )();
