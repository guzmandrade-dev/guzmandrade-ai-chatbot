/**
 * Guzmandrade AI Chatbot — Frontend widget.
 *
 * Vanilla JavaScript (no build step required).
 * Creates a floating chat button and panel that communicates with the
 * WordPress REST API. Optionally integrates with Cloudflare Turnstile.
 */

( function () {
	'use strict';

	if ( typeof window.GAIC_DATA === 'undefined' ) {
		return;
	}

	var data      = window.GAIC_DATA;
	var strings   = data.strings || {};
	var container = document.querySelector( '.gaic-chatbot-container' );

	if ( ! container ) {
		return;
	}

	var position        = container.getAttribute( 'data-gaic-position' ) || data.position || 'bottom-right';
	var captchaToken    = null;
	var captchaWidgetId = null;
	var captchaReady    = false;

	var STORAGE_KEY = 'gaic-chat-history-v1';

	var state = {
		isOpen: false,
		isTyping: false,
		messages: loadHistory(),
	};

	// ── History persistence (localStorage) ──────────────────────────

	// Restores a previously stored conversation. Entries are validated so
	// tampered storage cannot inject markup (rendering escapes everything
	// anyway) or off-site citation links.
	function loadHistory() {
		try {
			var raw = window.localStorage.getItem( STORAGE_KEY );
			if ( ! raw ) {
				return [];
			}
			var parsed = JSON.parse( raw );
			if ( ! Array.isArray( parsed ) ) {
				return [];
			}
			var clean = [];
			parsed.forEach(
				function ( m ) {
					if ( ! m || ( m.role !== 'user' && m.role !== 'assistant' ) || typeof m.content !== 'string' || ! m.content.trim() ) {
							return;
					}
					var entry = { role: m.role, content: m.content.slice( 0, 4000 ) };
					if ( Array.isArray( m.sources ) ) {
						entry.sources = m.sources.filter(
							function ( s ) {
								return s && Number.isInteger( s.id ) && typeof s.title === 'string' && typeof s.url === 'string' &&
								/^https?:\/\//i.test( s.url ) && new URL( s.url, window.location.origin ).origin === window.location.origin;
							}
						).slice( 0, 10 ).map(
							function ( s ) {
								return { id: s.id, title: String( s.title ).slice( 0, 200 ), url: s.url };
							}
						);
						if ( ! entry.sources.length ) {
								delete entry.sources;
						}
					}
					clean.push( entry );
				}
			);
			return clean.slice( -20 );
		} catch ( e ) {
			return [];
		}//end try
	}

	function saveHistory() {
		try {
			window.localStorage.setItem( STORAGE_KEY, JSON.stringify( state.messages.slice( -20 ) ) );
		} catch ( e ) {
			// Storage unavailable (private mode, quota) — chat still works for the session.
		}
	}

	// ── DOM construction ────────────────────────────────────────────

	function createButton() {
		var btn       = document.createElement( 'button' );
		btn.className = 'gaic-chat-toggle';
		btn.setAttribute( 'type', 'button' );
		btn.setAttribute( 'aria-label', strings.openChat || 'Open chat' );
		btn.setAttribute( 'aria-expanded', 'false' );
		btn.innerHTML =
			'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
			'<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>' +
			'</svg>';
		return btn;
	}

	function createPanel() {
		var panel       = document.createElement( 'div' );
		panel.className = 'gaic-chat-panel';
		panel.setAttribute( 'role', 'dialog' );
		panel.setAttribute( 'aria-label', data.title || 'Chat' );
		panel.setAttribute( 'aria-modal', 'false' );
		panel.setAttribute( 'hidden', '' );

		// Header.
		var header       = document.createElement( 'div' );
		header.className = 'gaic-chat-header';

		var headerText       = document.createElement( 'div' );
		headerText.className = 'gaic-chat-header-text';

		var titleEl         = document.createElement( 'span' );
		titleEl.className   = 'gaic-chat-title';
		titleEl.textContent = data.title || 'Chat';
		headerText.appendChild( titleEl );

		if ( data.subtitle ) {
			var subtitleEl         = document.createElement( 'span' );
			subtitleEl.className   = 'gaic-chat-subtitle';
			subtitleEl.textContent = data.subtitle;
			headerText.appendChild( subtitleEl );
		}
		header.appendChild( headerText );

		var closeBtn       = document.createElement( 'button' );
		closeBtn.className = 'gaic-chat-close';
		closeBtn.setAttribute( 'type', 'button' );
		closeBtn.setAttribute( 'aria-label', strings.closeChat || 'Close chat' );
		closeBtn.innerHTML =
			'<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
			'<line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line>' +
			'</svg>';
		header.appendChild( closeBtn );

		// Messages area.
		var messagesEl       = document.createElement( 'div' );
		messagesEl.className = 'gaic-chat-messages';
		messagesEl.setAttribute( 'role', 'log' );
		messagesEl.setAttribute( 'aria-live', 'polite' );
		messagesEl.setAttribute( 'aria-atomic', 'false' );

		// Captcha area (between messages and input, shown when enabled).
		var captchaEl = null;
		if ( data.captchaEnabled && data.captchaSiteKey ) {
			captchaEl           = document.createElement( 'div' );
			captchaEl.className = 'gaic-chat-captcha';
		}

		// Input area.
		var inputWrap       = document.createElement( 'div' );
		inputWrap.className = 'gaic-chat-input-area';

		var input       = document.createElement( 'textarea' );
		input.className = 'gaic-chat-input';
		input.setAttribute( 'type', 'text' );
		input.setAttribute( 'placeholder', strings.placeholder || 'Type your message…' );
		input.setAttribute( 'rows', '1' );
		input.setAttribute( 'aria-label', strings.placeholder || 'Type your message…' );

		var sendBtn       = document.createElement( 'button' );
		sendBtn.className = 'gaic-chat-send';
		sendBtn.setAttribute( 'type', 'button' );
		sendBtn.setAttribute( 'aria-label', strings.send || 'Send' );
		sendBtn.innerHTML =
			'<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
			'<line x1="22" y1="2" x2="11" y2="13"></line><polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>' +
			'</svg>';

		inputWrap.appendChild( input );
		inputWrap.appendChild( sendBtn );

		panel.appendChild( header );
		panel.appendChild( messagesEl );
		if ( captchaEl ) {
			panel.appendChild( captchaEl );
		}
		panel.appendChild( inputWrap );

		return {
			panel: panel,
			messagesEl: messagesEl,
			input: input,
			sendBtn: sendBtn,
			closeBtn: closeBtn,
			captchaEl: captchaEl,
		};
	}

	// ── Captcha ──────────────────────────────────────────────────────

	function renderCaptcha() {
		if ( ! data.captchaEnabled || ! data.captchaSiteKey || ! elements.captchaEl ) {
			return;
		}

		// If Turnstile is already loaded, render the widget.
		if ( typeof window.turnstile !== 'undefined' && ! captchaWidgetId ) {
			captchaWidgetId = window.turnstile.render(
				elements.captchaEl,
				{
					sitekey: data.captchaSiteKey,
					callback: function ( token ) {
						captchaToken = token;
						captchaReady = true;
					},
					'expired-callback': function () {
						captchaToken = null;
						captchaReady = false;
					},
					'error-callback': function () {
						captchaToken = null;
						captchaReady = false;
					},
				}
			);
		}
	}

	function resetCaptcha() {
		captchaToken = null;
		captchaReady = false;
		if ( captchaWidgetId !== null && typeof window.turnstile !== 'undefined' ) {
			window.turnstile.reset( captchaWidgetId );
		}
	}

	// ── Message rendering ──────────────────────────────────────────

	function createAvatar( role ) {
		var avatar       = document.createElement( 'span' );
		avatar.className = 'gaic-message-avatar';
		avatar.setAttribute( 'aria-hidden', 'true' );

		if ( role !== 'user' && data.avatarUrl ) {
			var img       = document.createElement( 'img' );
			img.className = 'gaic-message-avatar-img';
			img.setAttribute( 'src', data.avatarUrl );
			img.setAttribute( 'alt', '' );
			img.onerror = function () {
				// If the image fails to load, fall back to the text badge.
				avatar.textContent = 'AI';
			};
			avatar.appendChild( img );
		} else {
			avatar.textContent = role === 'user' ? 'U' : 'AI';
		}

		return avatar;
	}

	function addMessage( role, content, storeInState, sources ) {
		var msg       = document.createElement( 'div' );
		msg.className = 'gaic-message gaic-message--' + role;

		var avatar = createAvatar( role );

		var bubble       = document.createElement( 'div' );
		bubble.className = 'gaic-message-bubble';

		var text       = document.createElement( 'div' );
		text.className = 'gaic-message-text';
		text.innerHTML = formatMessage( content );

		bubble.appendChild( text );

		// Render the cited sources list under the message, when present.
		if ( role === 'assistant' && Array.isArray( sources ) && sources.length ) {
			var sourcesEl       = document.createElement( 'div' );
			sourcesEl.className = 'gaic-message-sources';

			var label         = document.createElement( 'span' );
			label.className   = 'gaic-message-sources-label';
			label.textContent = ( strings.sources || 'Sources' ) + ':';
			sourcesEl.appendChild( label );

			sources.forEach(
				function ( source, i ) {
					var link         = document.createElement( 'a' );
					link.href        = source.url;
					link.target      = '_blank';
					link.rel         = 'noopener noreferrer';
					link.textContent = '[' + source.id + '] ' + source.title;
					sourcesEl.appendChild( link );
					if ( i < sources.length - 1 ) {
							sourcesEl.appendChild( document.createTextNode( ' · ' ) );
					}
				}
			);

			bubble.appendChild( sourcesEl );
		}//end if

		msg.appendChild( avatar );
		msg.appendChild( bubble );

		elements.messagesEl.appendChild( msg );
		elements.messagesEl.scrollTop = elements.messagesEl.scrollHeight;

		// storeInState defaults to true; pass false for display-only
		// messages like the welcome greeting that shouldn't be sent
		// as conversation history to the AI API.
		if ( storeInState !== false ) {
			var entry = { role: role, content: content };
			if ( Array.isArray( sources ) && sources.length ) {
				entry.sources = sources;
			}
			state.messages.push( entry );
			saveHistory();
		}
	}

	function formatMessage( text ) {
		var div = document.createElement( 'div' );
		// Basic XSS protection: escape HTML, then restore formatting.
		div.textContent = text;
		var escaped     = div.innerHTML;
		escaped         = escaped.replace( /\*\*(.+?)\*\*/g, '<strong>$1</strong>' );
		escaped         = escaped.replace( /\[(.+?)\]\((https?:\/\/.+?)\)/g, '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>' );
		escaped         = escaped.replace( /\n/g, '<br>' );
		return escaped;
	}

	function showTyping() {
		var msg       = document.createElement( 'div' );
		msg.className = 'gaic-message gaic-message--assistant gaic-typing-indicator';
		msg.setAttribute( 'aria-label', strings.typing || 'Assistant is typing…' );

		var avatar = createAvatar( 'assistant' );

		var dots       = document.createElement( 'div' );
		dots.className = 'gaic-message-bubble';
		dots.innerHTML = '<span class="gaic-typing-dot"></span><span class="gaic-typing-dot"></span><span class="gaic-typing-dot"></span>';

		msg.appendChild( avatar );
		msg.appendChild( dots );
		elements.messagesEl.appendChild( msg );
		elements.messagesEl.scrollTop = elements.messagesEl.scrollHeight;
		return msg;
	}

	function removeTyping( el ) {
		if ( el && el.parentNode ) {
			el.parentNode.removeChild( el );
		}
	}

	// ── API communication ──────────────────────────────────────────

	function sendMessage( text ) {
		if ( state.isTyping ) {
			return;
		}

		// Check captcha if enabled.
		if ( data.captchaEnabled && ! captchaReady ) {
			addMessage( 'assistant', strings.captchaRequired || 'Please complete the captcha challenge first.' );
			return;
		}

		// Capture history before adding the current user message, so the
		// current message isn't duplicated in both `message` and `history`.
		// Only role/content are sent to the API — stored sources are for display.
		var history = state.messages.slice( -10 ).filter(
			function ( m ) {
				return m.role === 'user' || m.role === 'assistant';
			}
		).map(
			function ( m ) {
				return { role: m.role, content: m.content };
			}
		);

		addMessage( 'user', text );
		elements.input.value = '';
		autoResize();

		state.isTyping            = true;
		elements.sendBtn.disabled = true;
		var typingEl              = showTyping();

		var payload = {
			message: text,
			history: history,
		};

		if ( data.captchaEnabled ) {
			payload.captcha_token = captchaToken;
		}

		var headers = {
			'Content-Type': 'application/json',
		};

		if ( data.nonce ) {
			headers[ 'X-WP-Nonce' ] = data.nonce;
		}

		fetch(
			data.restUrl,
			{
				method: 'POST',
				headers: headers,
				body: JSON.stringify( payload ),
				credentials: 'same-origin',
			}
		)
			.then(
				function ( response ) {
					return response.json().then(
						function ( json ) {
							return { ok: response.ok, json: json };
						}
					);
				}
			)
			.then(
				function ( result ) {
					removeTyping( typingEl );

					if ( result.ok && result.json.reply ) {
							addMessage( 'assistant', result.json.reply, true, result.json.sources );

							// If the AI signaled conversation end, close after a delay.
						if ( result.json.close_chat ) {
							setTimeout(
								function () {
									closeChat();
								},
								3500
							);
						}
					} else {
						var errorMsg = ( result.json && result.json.message ) ? result.json.message : ( strings.error || 'Something went wrong.' );
						if ( result.json && result.json.code === 'gaic_spam_blocked' ) {
							errorMsg = strings.spamBlocked || errorMsg;
						}
						if ( result.json && result.json.code === 'gaic_ai_unavailable' ) {
							errorMsg = strings.aiUnavailable || errorMsg;
						}
						if ( result.json && result.json.code === 'gaic_captcha_failed' ) {
							errorMsg = strings.captchaFailed || errorMsg;
						}
						addMessage( 'assistant', errorMsg );
					}//end if

					// Reset captcha after each message.
					if ( data.captchaEnabled ) {
						resetCaptcha();
					}
				}
			)
			.catch(
				function () {
					removeTyping( typingEl );
					addMessage( 'assistant', strings.error || 'Something went wrong.' );
					if ( data.captchaEnabled ) {
							resetCaptcha();
					}
				}
			)
			.finally(
				function () {
					state.isTyping            = false;
					elements.sendBtn.disabled = false;
					elements.input.focus();
				}
			);
	}

	// ── UI helpers ─────────────────────────────────────────────────

	function openChat() {
		state.isOpen = true;
		elements.panel.removeAttribute( 'hidden' );
		toggleBtn.setAttribute( 'aria-expanded', 'true' );
		toggleBtn.classList.add( 'gaic-chat-toggle--active' );

		// Add welcome message on first open. Don't store it in conversation
		// state — it's a greeting, not part of the chat history, and the AI
		// API requires history to start with a user message.
		if ( state.messages.length === 0 && data.welcomeMessage ) {
			addMessage( 'assistant', data.welcomeMessage, false );
		}

		// Render captcha on first open if enabled.
		if ( data.captchaEnabled && ! captchaWidgetId ) {
			// Turnstile may already be loaded by the Turnstile plugin.
			if ( typeof window.turnstile !== 'undefined' ) {
				renderCaptcha();
			} else {
				// Wait for the Turnstile API to load.
				var checkInterval = setInterval(
					function () {
						if ( typeof window.turnstile !== 'undefined' ) {
								renderCaptcha();
								clearInterval( checkInterval );
						}
					},
					200
				);
				setTimeout(
					function () {
						clearInterval( checkInterval );
					},
					10000
				);
			}
		}//end if

		setTimeout(
			function () {
				elements.input.focus();
			},
			100
		);
	}

	function closeChat() {
		state.isOpen = false;
		elements.panel.setAttribute( 'hidden', '' );
		toggleBtn.setAttribute( 'aria-expanded', 'false' );
		toggleBtn.classList.remove( 'gaic-chat-toggle--active' );
	}

	function autoResize() {
		elements.input.style.height = 'auto';
		elements.input.style.height = Math.min( elements.input.scrollHeight, 120 ) + 'px';
	}

	function handleKeyDown( e ) {
		if ( e.key === 'Enter' && ! e.shiftKey ) {
			e.preventDefault();
			var text = elements.input.value.trim();
			if ( text ) {
				sendMessage( text );
			}
		}
	}

	// ── Initialization ──────────────────────────────────────────────

	container.classList.add( 'gaic-position-' + position );

	var toggleBtn = createButton();
	var elements  = createPanel();

	container.appendChild( toggleBtn );
	container.appendChild( elements.panel );

	// Re-render the persisted conversation (display-only; already stored).
	state.messages.forEach(
		function ( m ) {
			addMessage( m.role, m.content, false, m.sources );
		}
	);

	// Event listeners.
	toggleBtn.addEventListener(
		'click',
		function () {
			if ( state.isOpen ) {
				closeChat();
			} else {
				openChat();
			}
		}
	);

	elements.closeBtn.addEventListener( 'click', closeChat );

	elements.sendBtn.addEventListener(
		'click',
		function () {
			var text = elements.input.value.trim();
			if ( text ) {
				sendMessage( text );
			}
		}
	);

	elements.input.addEventListener( 'input', autoResize );
	elements.input.addEventListener( 'keydown', handleKeyDown );

	// Close on Escape.
	document.addEventListener(
		'keydown',
		function ( e ) {
			if ( e.key === 'Escape' && state.isOpen ) {
				closeChat();
			}
		}
	);
} )();