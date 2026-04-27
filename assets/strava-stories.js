/**
 * Strava Stories dashboard widget client.
 *
 * Fetches activities from /strava-stories/v1/activities, renders one at a time
 * with prev/next nav, and posts to /strava-stories/v1/blog when the user
 * clicks "Let's blog it".
 */
( function () {
	'use strict';

	const root = document.querySelector( '.strava-stories-widget' );
	if ( ! root ) {
		return;
	}

	// If the widget rendered in its empty/disconnected state, the user may
	// have completed OAuth in another tab since the dashboard was last loaded.
	// When the dashboard tab regains focus, ping the activities endpoint;
	// success means we're connected now and should re-render.
	if ( root.dataset.connected !== '1' ) {
		document.addEventListener( 'visibilitychange', function () {
			if ( document.visibilityState !== 'visible' ) { return; }
			fetch( ( root.dataset.restRoot || '' ) + 'activities', {
				method: 'GET',
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': root.dataset.nonce || '' },
			} ).then( function ( res ) {
				if ( res.status === 200 ) {
					window.location.reload();
				}
			} ).catch( function () {} );
		} );
		return;
	}

	const stage  = root.querySelector( '.strava-stories-widget__stage' );
	const prev   = root.querySelector( '.strava-stories-widget__nav--prev' );
	const next   = root.querySelector( '.strava-stories-widget__nav--next' );
	const pager  = root.querySelector( '.strava-stories-widget__pager' );
	const blog   = root.querySelector( '.strava-stories-widget__blog' );

	let activities = [];
	let index      = 0;

	function restRoot() { return root.dataset.restRoot || ''; }
	function nonce()    { return root.dataset.nonce || ''; }

	function request( method, path, body ) {
		return fetch( restRoot() + path, {
			method: method,
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': nonce(),
			},
			body: method === 'GET' ? undefined : JSON.stringify( body || {} ),
		} ).then( function ( res ) {
			return res.json().then( function ( data ) {
				return { status: res.status, data: data };
			} );
		} );
	}

	function showError( message ) {
		stage.dataset.state = 'error';
		stage.innerHTML = '';
		const p = document.createElement( 'p' );
		p.className = 'strava-stories-widget__error';
		p.setAttribute( 'role', 'alert' );
		p.textContent = message;
		stage.appendChild( p );
	}

	function showEmpty() {
		stage.dataset.state = 'empty';
		stage.innerHTML = '';
		const p = document.createElement( 'p' );
		p.className = 'strava-stories-widget__muted';
		p.textContent = __( 'No recent Strava activities found.' );
		stage.appendChild( p );
	}

	function escapeHtml( str ) {
		return String( str ).replace( /[&<>"']/g, function ( c ) {
			return ( { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ] );
		} );
	}

	function formatDate( iso ) {
		if ( ! iso ) { return ''; }
		try {
			return new Date( iso ).toLocaleDateString( undefined, {
				month: 'short',
				day: 'numeric',
				year: 'numeric',
			} );
		} catch ( e ) {
			return iso;
		}
	}

	function renderActivity( activity ) {
		stage.dataset.state = 'loaded';
		stage.dataset.activityId = String( activity.id );
		stage.innerHTML = '';

		const card = document.createElement( 'article' );
		card.className = 'strava-stories-widget__card';

		const header = document.createElement( 'header' );
		header.className = 'strava-stories-widget__card-header';
		const title = document.createElement( 'h4' );
		title.className = 'strava-stories-widget__title';
		const a = document.createElement( 'a' );
		a.href = activity.url;
		a.target = '_blank';
		a.rel = 'noopener noreferrer';
		a.textContent = activity.name || activity.sport_label;
		title.appendChild( a );
		header.appendChild( title );
		const date = document.createElement( 'span' );
		date.className = 'strava-stories-widget__date';
		date.textContent = formatDate( activity.start_date );
		header.appendChild( date );
		card.appendChild( header );

		// Subtle sport pill — sits above the title, light gray with a small
		// Strava-orange dot. WP admin uses similar pills for status badges.
		const pill = document.createElement( 'span' );
		pill.className = 'strava-stories-widget__type';
		const dot = document.createElement( 'span' );
		dot.className = 'strava-stories-widget__type-dot';
		dot.setAttribute( 'aria-hidden', 'true' );
		pill.appendChild( dot );
		pill.appendChild( document.createTextNode( activity.sport_label ) );
		card.insertBefore( pill, card.firstChild );

		if ( activity.stats && activity.stats.length ) {
			const stats = document.createElement( 'dl' );
			stats.className = 'strava-stories-widget__stats';
			activity.stats.forEach( function ( stat ) {
				const dt = document.createElement( 'dt' );
				dt.textContent = stat.label;
				const dd = document.createElement( 'dd' );
				dd.textContent = stat.value;
				stats.appendChild( dt );
				stats.appendChild( dd );
			} );
			card.appendChild( stats );
		}

		if ( activity.description_short ) {
			const desc = document.createElement( 'p' );
			desc.className = 'strava-stories-widget__description';
			desc.textContent = activity.description_short;
			card.appendChild( desc );
		}

		stage.appendChild( card );
	}


	function updateChrome() {
		if ( ! activities.length ) {
			prev.disabled = true;
			next.disabled = true;
			blog.disabled = true;
			pager.textContent = '';
			return;
		}
		prev.disabled = index <= 0;
		next.disabled = index >= activities.length - 1;
		blog.disabled = false;
		pager.textContent = sprintf( __( '%1$d of %2$d' ), index + 1, activities.length );
	}

	function show( i ) {
		if ( i < 0 || i >= activities.length ) { return; }
		index = i;
		renderActivity( activities[ index ] );
		updateChrome();
	}

	function load() {
		request( 'GET', 'activities' ).then( function ( res ) {
			if ( res.status !== 200 || ! res.data || ! res.data.ok ) {
				showError(
					( res.data && res.data.error )
						? __( 'Strava error: ' ) + res.data.error
						: __( 'Could not load Strava activities.' )
				);
				return;
			}
			activities = ( res.data && res.data.activities ) || [];
			if ( ! activities.length ) {
				showEmpty();
				updateChrome();
				return;
			}
			show( 0 );
		} ).catch( function () {
			showError( __( 'Network error loading Strava activities.' ) );
		} );
	}

	prev.addEventListener( 'click', function () { show( index - 1 ); } );
	next.addEventListener( 'click', function () { show( index + 1 ); } );

	root.addEventListener( 'keydown', function ( ev ) {
		if ( ev.target.tagName === 'IFRAME' || ev.target.tagName === 'INPUT' ) { return; }
		if ( ev.key === 'ArrowLeft' )  { show( index - 1 ); }
		if ( ev.key === 'ArrowRight' ) { show( index + 1 ); }
	} );

	blog.addEventListener( 'click', function () {
		if ( ! activities.length ) { return; }
		const activity = activities[ index ];
		const original = blog.textContent.trim();
		blog.disabled = true;
		blog.textContent = __( 'Creating draft…' );

		request( 'POST', 'blog', { activity_id: activity.id } ).then( function ( res ) {
			if ( res.status === 201 && res.data && res.data.ok && res.data.edit_url ) {
				window.location.assign( res.data.edit_url );
				return;
			}
			blog.disabled = false;
			blog.textContent = original;
			showError(
				( res.data && res.data.error )
					? __( 'Could not create draft: ' ) + res.data.error
					: __( 'Could not create draft. Try again.' )
			);
		} ).catch( function () {
			blog.disabled = false;
			blog.textContent = original;
			showError( __( 'Network error. Try again.' ) );
		} );
	} );

	function __( s ) {
		if ( window.wp && window.wp.i18n && window.wp.i18n.__ ) {
			return window.wp.i18n.__( s, 'strava-stories' );
		}
		return s;
	}

	function sprintf( fmt, a, b ) {
		return String( fmt )
			.replace( '%1$d', String( a ) )
			.replace( '%2$d', String( b ) );
	}

	load();
} )();
