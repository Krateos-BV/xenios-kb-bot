/* Xenios KB Bot — front-end chat widget
 * Ported from the XeniaCloud support widget. The in-memory CSRF token system is
 * gone — the WordPress REST nonce (X-WP-Nonce) handles CSRF. Bot name and
 * welcome message come from xenios_kb_bot_cfg. No honeypot, no transcript email.
 */
( function () {
	'use strict';

	var cfg = window.xenios_kb_bot_cfg || {};
	var CHAT_URL = cfg.rest_url ? cfg.rest_url + 'chat' : '';
	var SESSION_URL = cfg.rest_url ? cfg.rest_url + 'session' : '';

	var lang = ( navigator.language || 'en' ).toLowerCase().indexOf( 'nl' ) === 0 ? 'nl' : 'en';
	var PLACEHOLDER = { en: 'Type your message…', nl: 'Typ je bericht…' };

	var sessionId = null;
	var idleTimer = null;

	var bubble   = document.getElementById( 'xkb-bubble' );
	var win      = document.getElementById( 'xkb-window' );
	var title    = document.getElementById( 'xkb-title' );
	var messages = document.getElementById( 'xkb-messages' );
	var input    = document.getElementById( 'xkb-input' );
	var sendBtn  = document.getElementById( 'xkb-send' );
	var endBtn   = document.getElementById( 'xkb-end' );
	var minBtn   = document.getElementById( 'xkb-minimize' );

	if ( ! bubble || ! win || ! messages || ! input || ! sendBtn ) {
		return;
	}

	if ( title && cfg.bot_name ) {
		title.textContent = cfg.bot_name;
	}
	input.placeholder = PLACEHOLDER[ lang ];

	// ── Session id ────────────────────────────────────────────────────────────
	function genId() {
		if ( window.crypto && typeof window.crypto.randomUUID === 'function' ) {
			return window.crypto.randomUUID();
		}
		return 'xkb-' + Date.now().toString( 36 ) + '-' + Math.random().toString( 36 ).slice( 2, 10 );
	}

	// ── Rendering ─────────────────────────────────────────────────────────────
	function sanitise( str ) {
		return String( str )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' );
	}

	function mdToHtml( text ) {
		return sanitise( text )
			.replace( /\*\*(.+?)\*\*/g, '<strong>$1</strong>' )
			.replace( /\*(.+?)\*/g, '<em>$1</em>' )
			.replace( /`(.+?)`/g, '<code>$1</code>' )
			.replace( /\n/g, '<br>' );
	}

	function addMsg( text, role ) {
		var div = document.createElement( 'div' );
		div.className = 'xkb-msg xkb-' + role;
		div.innerHTML = mdToHtml( text );
		messages.appendChild( div );
		messages.scrollTop = messages.scrollHeight;
	}

	var typingEl = null;
	function showTyping() {
		typingEl = document.createElement( 'div' );
		typingEl.className = 'xkb-msg xkb-agent xkb-typing';
		typingEl.innerHTML = '<span></span><span></span><span></span>';
		messages.appendChild( typingEl );
		messages.scrollTop = messages.scrollHeight;
	}
	function hideTyping() {
		if ( typingEl ) { typingEl.remove(); typingEl = null; }
	}

	function setLoading( on ) {
		sendBtn.disabled = on;
		sendBtn.textContent = on ? '…' : 'Send';
	}

	function errorText() {
		return lang === 'nl'
			? 'Er ging iets mis. Probeer het opnieuw.'
			: 'Something went wrong. Please try again.';
	}

	// ── Networking ────────────────────────────────────────────────────────────
	function sendMessage( text ) {
		if ( ! sessionId ) { sessionId = genId(); }
		setLoading( true );
		showTyping();

		fetch( CHAT_URL, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': cfg.nonce || ''
			},
			body: JSON.stringify( { message: text, session_id: sessionId } )
		} ).then( function ( res ) {
			if ( ! res.ok ) { throw new Error( 'HTTP ' + res.status ); }
			return res.json();
		} ).then( function ( data ) {
			hideTyping();
			if ( data && data.session_id ) { sessionId = data.session_id; }
			addMsg( data && data.response ? data.response : errorText(), 'agent' );
			setLoading( false );
		} ).catch( function () {
			hideTyping();
			addMsg( errorText(), 'agent' );
			setLoading( false );
		} );
	}

	function handleSend() {
		var text = input.value.trim();
		if ( ! text ) { return; }
		input.value = '';
		resetIdle();
		addMsg( text, 'user' );
		sendMessage( text );
	}

	function doEndChat() {
		if ( ! sessionId ) { return Promise.resolve(); }
		var sid = sessionId;
		return fetch( SESSION_URL, {
			method: 'DELETE',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': cfg.nonce || ''
			},
			body: JSON.stringify( { session_id: sid } )
		} ).catch( function () { /* silent */ } );
	}

	function resetWidget() {
		sessionId = null;
		messages.innerHTML = '';
		input.disabled = false;
		sendBtn.disabled = false;
		input.placeholder = PLACEHOLDER[ lang ];
	}

	function resetIdle() {
		clearTimeout( idleTimer );
		idleTimer = setTimeout( function () {
			doEndChat();
			resetWidget();
			win.classList.remove( 'xkb-open' );
		}, 1800000 ); // 30 min
	}

	// ── Events ────────────────────────────────────────────────────────────────
	function openWidget() {
		win.classList.toggle( 'xkb-open' );
		if ( win.classList.contains( 'xkb-open' ) && messages.children.length === 0 ) {
			addMsg( cfg.welcome || 'Hi! How can I help you today?', 'agent' );
			resetIdle();
		}
	}

	bubble.addEventListener( 'click', openWidget );
	bubble.addEventListener( 'keydown', function ( e ) {
		if ( e.key === 'Enter' || e.key === ' ' ) { e.preventDefault(); openWidget(); }
	} );

	sendBtn.addEventListener( 'click', handleSend );
	input.addEventListener( 'keydown', function ( e ) { if ( e.key === 'Enter' ) { handleSend(); } } );
	input.addEventListener( 'input', resetIdle );

	if ( endBtn ) {
		endBtn.addEventListener( 'click', function () {
			doEndChat();
			resetWidget();
			win.classList.remove( 'xkb-open' );
		} );
	}

	if ( minBtn ) {
		minBtn.addEventListener( 'click', function ( e ) {
			e.stopPropagation();
			win.classList.remove( 'xkb-open' );
		} );
	}

	// ── Left-edge drag-to-resize ──────────────────────────────────────────────
	( function () {
		var handle = document.querySelector( '.xkb-resize-handle' );
		if ( ! handle || ! win ) { return; }
		handle.addEventListener( 'mousedown', function ( e ) {
			e.preventDefault();
			var startX = e.clientX;
			var startW = win.offsetWidth;
			function onMove( ev ) {
				var delta = startX - ev.clientX; // dragging left edge leftward = wider
				var newW  = Math.min( Math.max( 300, startW + delta ), window.innerWidth * 0.9 );
				win.style.width = newW + 'px';
			}
			function onUp() {
				document.removeEventListener( 'mousemove', onMove );
				document.removeEventListener( 'mouseup', onUp );
			}
			document.addEventListener( 'mousemove', onMove );
			document.addEventListener( 'mouseup', onUp );
		} );
	} )();
} )();
