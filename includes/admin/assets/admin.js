/* global aafmAdmin */
/**
 * Admin UI for Agent Abilities for MCP.
 *
 * Every value that comes back from an AJAX response is treated as untrusted and reaches
 * the DOM through textContent only - this file never assigns innerHTML, so there is no
 * raw-HTML sink to audit. All requests carry the admin nonce and same-origin credentials.
 */
( () => {
	'use strict';

	class AafmAdmin {
		#ajaxUrl = aafmAdmin.ajaxUrl;
		#nonce = aafmAdmin.nonce;

		/**
		 * Read a localized string, falling back to its English source when the
		 * bag is missing (keeps the UI legible even if wp_localize_script fails).
		 *
		 * @param {string} key      Key in the aafmAdmin.i18n bag.
		 * @param {string} fallback English source string.
		 * @return {string} The localized string, or the fallback.
		 */
		#t( key, fallback ) {
			return aafmAdmin?.i18n?.[ key ] ?? fallback;
		}

		/**
		 * Fill a printf-style template (%s, %d, %1$s, %2$s) with positional values.
		 * Mirrors the sprintf flavours used in the PHP-side translations so the
		 * rendered English stays byte-identical to the old hardcoded strings.
		 *
		 * @param {string}        template printf-style template.
		 * @param {...(string|number)} args Positional substitutions.
		 * @return {string} The formatted string.
		 */
		#format( template, ...args ) {
			let auto = 0;
			return template.replace( /%%|%(\d+\$)?[sd]/g, ( match, pos ) => {
				if ( '%%' === match ) {
					return '%';
				}
				const index = pos ? Number( pos.slice( 0, -1 ) ) - 1 : auto++;
				return String( args[ index ] ?? '' );
			} );
		}

		constructor() {
			this.#bindCopy();
			this.#bindOsTabs();
			this.#bindClientPicker();
			this.#bindOauthClientPicker();
			this.#bindSubjectTabs();
			this.#bindAbilitiesSearch();
			this.#bindSectionToggles();
			this.#bindEnableReads();
			this.#bindEnableWrites();
			this.#bindIntegrationToggles();
			this.#bindIntegrationFilters();
			this.#bindSaveAbilities();
			this.#bindSaveIntegrations();
			this.#bindSaveBridge();
			this.#bindBridgeFilter();
			this.#bindBridgeConfirm();
			this.#bindBridgeBulk();
			this.#bindSavePostTypes();
			this.#bindSaveMetaKeys();
			this.#bindSaveUserMetaKeys();
			this.#bindSaveTermMetaKeys();
			this.#bindSaveSettings();
			this.#bindMetaChips();
			this.#bindCreateUser();
			this.#bindTestConnection();
			this.#bindClearLog();
			this.#bindLogPaginationAndFilters();
			this.#bindResetPlugin();
			this.#bindOauthRevoke();
			this.#bindOauthPagination();
			this.#bindClientAgentToggle();
			this.#bindAllowlist();
			this.#bindQuickConnect();
		}

		/**
		 * Wire the client picker (the .aafm-client cards in #aafm-clients).
		 *
		 * Clicking a card marks it .on (clearing its siblings) and swaps the primary
		 * config blocks to that client's snippet. The per-client unix payload already
		 * lives on the matching .aafm-quickstart-card's data-config, so the unix block
		 * is taken verbatim from there. Clients differ only by the JSON root key
		 * (VS Code uses "servers"; everyone else uses "mcpServers"), so the windows
		 * block - which has no per-client payload in the markup - is reconciled by
		 * rewriting just that root key. Both updates touch textContent / data-copy
		 * only, never innerHTML.
		 *
		 * @param {string} text   A rendered snippet (JSON text).
		 * @param {string} client The selected client slug.
		 * @return {string} The snippet with its root key matched to the client.
		 */
		#applyRootKey( text, client ) {
			const wanted = 'vscode' === client ? 'servers' : 'mcpServers';
			// Rewrite only a "servers"/"mcpServers" that is acting as an OBJECT KEY - i.e.
			// immediately followed by a colon and an opening brace (allowing whitespace).
			// Matching the key role rather than the bare token means an arbitrary server
			// name or value that happens to contain the word "servers" is never rewritten.
			// The capture group preserves the original colon/brace spacing.
			return text.replace(
				/"(?:mcp)?[sS]ervers"(\s*:\s*\{)/,
				`"${ wanted }"$1`
			);
		}

		/**
		 * Rewrite the WP_API_USERNAME value in a rendered snippet to a new agent login.
		 *
		 * The App-Password config snippets are rendered server-side naming the agent login known
		 * at page load (the 'mcp-agent' seed on a fresh install). After the operator creates the
		 * agent user in the same session without reloading, those snippets still name the seed, a
		 * user that does not exist. This swaps the value in place so a copied config names the real
		 * account. Only the "WP_API_USERNAME" value is touched, matched by its key role so an
		 * unrelated value that happens to contain the same text is never rewritten. The login is
		 * JSON-escaped so a value carrying a quote or backslash cannot break the JSON.
		 *
		 * @param {string} text  A rendered snippet (JSON text).
		 * @param {string} login The agent login to write in.
		 * @return {string} The snippet with its WP_API_USERNAME value replaced.
		 */
		#applyAgentLogin( text, login ) {
			const value = JSON.stringify( login ).slice( 1, -1 );
			return text.replace(
				/("WP_API_USERNAME"\s*:\s*")(?:[^"\\]|\\.)*(")/,
				( _match, before, after ) => `${ before }${ value }${ after }`
			);
		}

		/**
		 * Repoint every App-Password config snippet at a just-created agent login.
		 *
		 * Mirrors the server render's substitution for the same-session create flow: the default
		 * OS blocks and every quickstart card are rewritten in place (textContent, data-copy, and
		 * the card's data-config), scoped strictly to the App-Password fallback so no OAuth snippet
		 * is touched. Both updates touch textContent / dataset only, never innerHTML. A no-op when
		 * the login is empty or the fallback subtree is not on the page.
		 *
		 * @param {string} login The login the server just created and marked.
		 */
		#retargetSnippetLogin( login ) {
			if ( ! login ) {
				return;
			}
			const root = document.querySelector( '.aafm-app-password-fallback' );
			if ( ! root ) {
				return;
			}
			// Default OS blocks: the <pre> plus its copy button's data-copy.
			root.querySelectorAll( '.aafm-snippet[data-os]' ).forEach( ( block ) => {
				const pre = block.querySelector( 'pre' );
				if ( ! pre ) {
					return;
				}
				const next = this.#applyAgentLogin( pre.textContent, login );
				pre.textContent = next;
				const copy = block.querySelector( '.aafm-copy' );
				if ( copy ) {
					copy.dataset.copy = next;
				}
			} );
			// Quickstart cards: the data-config payload the client picker reads, plus the card's
			// own <pre> and copy button.
			root.querySelectorAll( '.aafm-quickstart-card' ).forEach( ( card ) => {
				if ( card.dataset.config ) {
					card.dataset.config = this.#applyAgentLogin(
						card.dataset.config,
						login
					);
				}
				const pre = card.querySelector( 'pre' );
				if ( ! pre ) {
					return;
				}
				const next = this.#applyAgentLogin( pre.textContent, login );
				pre.textContent = next;
				const copy = card.querySelector( '.aafm-copy' );
				if ( copy ) {
					copy.dataset.copy = next;
				}
			} );
		}

		#bindClientPicker() {
			// Scope strictly to the App-Password fallback subtree. The OAuth picker has
			// its own #bindOauthClientPicker and must never be touched here.
			const root = document.querySelector( '.aafm-app-password-fallback' );
			const cards = root
				? root.querySelectorAll( '#aafm-clients .aafm-client' )
				: document.querySelectorAll( '#aafm-clients .aafm-client' );
			if ( ! cards.length ) {
				return;
			}
			cards.forEach( ( card ) => {
				card.addEventListener( 'click', () => {
					cards.forEach( ( c ) => {
						const on = c === card;
						c.classList.toggle( 'on', on );
						// The cards are toggle buttons; aria-pressed carries the same
						// selected state to assistive tech that .on carries visually.
						c.setAttribute( 'aria-pressed', String( on ) );
					} );

					const client = card.dataset.client ?? '';
					// The matching quickstart card carries the ready-made unix snippet.
					// Search within the fallback root only - never touch OAuth elements.
					const searchRoot = root ?? document;
					const source = searchRoot.querySelector(
						`.aafm-quickstart-card[data-client="${ client }"]`
					);
					const unixCfg = source?.dataset.config ?? '';

					// Update only snippet blocks within the App-Password fallback, never
					// the OAuth card's .aafm-snippet elements.
					searchRoot
						.querySelectorAll( '.aafm-snippet[data-os]' )
						.forEach( ( block ) => {
							const pre = block.querySelector( 'pre' );
							const copy = block.querySelector( '.aafm-copy' );
							if ( ! pre ) {
								return;
							}
							let next;
							if ( 'unix' === block.dataset.os && unixCfg ) {
								next = unixCfg;
							} else {
								// No per-client windows payload exists; reconcile the
								// already-rendered block's root key to the client.
								next = this.#applyRootKey( pre.textContent, client );
							}
							pre.textContent = next;
							if ( copy ) {
								copy.dataset.copy = next;
							}
						} );
				} );
			} );
		}

		/**
		 * Wire the OAuth client picker (the .aafm-client cards in #aafm-oauth-clients).
		 *
		 * Clicking a card marks it .on (clearing its siblings) and shows the matching
		 * .aafm-oauth-panel[data-client] while hiding all others. The panels already
		 * contain the correct pre-rendered snippet for each client, so no DOM rewriting
		 * is needed beyond the visibility toggle.
		 */
		#bindOauthClientPicker() {
			const cards = document.querySelectorAll( '#aafm-oauth-clients .aafm-client' );
			if ( ! cards.length ) {
				return;
			}
			cards.forEach( ( card ) => {
				card.addEventListener( 'click', () => {
					cards.forEach( ( c ) => {
						const on = c === card;
						c.classList.toggle( 'on', on );
						c.setAttribute( 'aria-pressed', String( on ) );
					} );

					const client = card.dataset.client ?? '';
					document
						.querySelectorAll( '.aafm-oauth-panel[data-client]' )
						.forEach( ( panel ) => {
							panel.hidden = panel.dataset.client !== client;
						} );
				} );
			} );
		}

		/**
		 * Wire Arrow/Home/End keyboard navigation across one WAI-ARIA tablist.
		 *
		 * Implements the roving-tabindex contract: exactly one tab is in the tab
		 * sequence (tabindex 0) at a time, the rest are -1. Left/Up selects the
		 * previous tab, Right/Down the next (both wrap), Home/End jump to the ends.
		 * Each keyboard move calls activate() so the matching panel shows and moves
		 * focus to the now-current tab - automatic activation, the common pattern
		 * for a small static tab set.
		 *
		 * @param {NodeListOf<HTMLElement>|Array<HTMLElement>} tabs     The tab buttons, in DOM order.
		 * @param {(tab: HTMLElement) => void}                 activate Selects a tab (updates state + panels).
		 */
		#wireTablistKeys( tabs, activate ) {
			const list = Array.from( tabs );
			list.forEach( ( tab, index ) => {
				tab.addEventListener( 'keydown', ( e ) => {
					let target = null;
					switch ( e.key ) {
						case 'ArrowLeft':
						case 'ArrowUp':
							target = list[ ( index - 1 + list.length ) % list.length ];
							break;
						case 'ArrowRight':
						case 'ArrowDown':
							target = list[ ( index + 1 ) % list.length ];
							break;
						case 'Home':
							target = list[ 0 ];
							break;
						case 'End':
							target = list[ list.length - 1 ];
							break;
						default:
							return;
					}
					e.preventDefault();
					activate( target );
					target.focus();
				} );
			} );
		}

		/**
		 * Apply roving tabindex across a tablist after a selection change: the active
		 * tab is the only one reachable with Tab (tabindex 0); the rest are -1.
		 *
		 * @param {Array<HTMLElement>} list   The tab buttons.
		 * @param {HTMLElement}        active The now-selected tab.
		 */
		#rovingTabindex( list, active ) {
			list.forEach( ( t ) => {
				t.setAttribute( 'tabindex', t === active ? '0' : '-1' );
			} );
		}

		#bindSubjectTabs() {
			const tabs = document.querySelectorAll( '.aafm-subject-tab' );
			if ( ! tabs.length ) {
				return;
			}
			const list = Array.from( tabs );
			const activate = ( tab ) => {
				const subject = tab.dataset.subject;
				list.forEach( ( t ) => {
					const active = t === tab;
					t.classList.toggle( 'is-active', active );
					t.setAttribute( 'aria-selected', active ? 'true' : 'false' );
				} );
				this.#rovingTabindex( list, tab );
				document
					.querySelectorAll( '.aafm-subject-panel[data-subject]' )
					.forEach( ( panel ) => {
						panel.hidden = panel.dataset.subject !== subject;
					} );
			};
			list.forEach( ( tab ) => {
				tab.addEventListener( 'click', () => activate( tab ) );
			} );
			this.#wireTablistKeys( list, activate );
		}

		/**
		 * Get-or-create the small subject label admin.js shows above a panel's own heading
		 * while a cross-tab search has more than one panel visible at once. The panel's own
		 * <h2> is only ever a count ("27 / 27 enabled"), never the subject name, so there is
		 * nothing on the panel itself to point at - built from data-subject-label
		 * (page.php), which page.php escapes and the browser decodes like any other
		 * attribute, so this is textContent on trusted data, not a new HTML sink.
		 *
		 * @param {HTMLElement} panel A .aafm-subject-panel.
		 * @return {HTMLElement|null} The label element, or null if the panel has no label to show.
		 */
		#abilitiesPanelLabel( panel ) {
			const text = panel.dataset.subjectLabel;
			if ( ! text ) {
				return null;
			}
			let label = panel.querySelector( ':scope > .aafm-subject-search-label' );
			if ( ! label ) {
				label = document.createElement( 'p' );
				label.className = 'aafm-subject-search-label';
				label.textContent = text;
				panel.prepend( label );
			}
			return label;
		}

		/**
		 * Search field on the Abilities tab: filters every .aafm-ability-row across every
		 * sub-tab by its own text, the same row.textContent match #bindBridgeFilter() already
		 * uses for the bridge directory. Unlike that filter, a match here can live on a panel
		 * other than the one currently open, so a query reveals every panel with at least one
		 * match instead of only filtering within the active panel.
		 *
		 * Hiding is via the `hidden` attribute only, on rows and panels that already exist in
		 * the DOM - never moved, cloned, or disabled - so a hidden-by-search checkbox keeps its
		 * name and checked state and still submits with the rest of the form
		 * (aafm_render_abilities_tab()'s own docblock states every panel submits regardless of
		 * visibility).
		 */
		#bindAbilitiesSearch() {
			const search = document.getElementById( 'aafm-abilities-search' );
			const form = document.getElementById( 'aafm-abilities-form' );
			if ( ! search || ! form ) {
				return;
			}
			const status = document.getElementById( 'aafm-abilities-search-status' );
			const panels = Array.from( form.querySelectorAll( '.aafm-subject-panel' ) );
			const tabs = document.querySelectorAll( '.aafm-subject-tab' );

			// Everything in a panel that is not a row container (.aafm-ability-list, one per
			// Reads/Writes group) or the subject label: the heading, the bulk-toggle buttons,
			// the Reads/Writes group headings, and (on some panels) the post-types or meta-key
			// cards. A query hides all of it, `hidden` only, on elements already in the DOM -
			// never moved, cloned, or disabled - so what remains visible is a flat run of
			// ability rows with nothing to separate one subject's matches from the next. Written
			// as a query rather than a fixed class list so a future flat "All abilities" tab can
			// reuse it without maintaining a second copy.
			const panelChrome = ( panel ) =>
				panel.querySelectorAll( ':scope > :not(.aafm-ability-list):not(.aafm-subject-search-label)' );

			// The sub-tab a plain click would show right now, so clearing the query restores
			// exactly that panel rather than whichever one a match happened to leave open.
			const activeSubject = () =>
				document.querySelector( '.aafm-subject-tab.is-active' )?.dataset.subject ?? null;

			const setBulkButtonsDisabled = ( panel, disabled ) => {
				panel.querySelectorAll( '.aafm-section-toggle button' ).forEach( ( btn ) => {
					btn.disabled = disabled;
				} );
			};

			const clear = () => {
				const subject = activeSubject();
				panels.forEach( ( panel ) => {
					panel.hidden = panel.dataset.subject !== subject;
					panel.querySelectorAll( '.aafm-ability-row' ).forEach( ( row ) => {
						row.hidden = false;
					} );
					panelChrome( panel ).forEach( ( el ) => {
						el.hidden = false;
					} );
					setBulkButtonsDisabled( panel, false );
					const label = panel.querySelector( ':scope > .aafm-subject-search-label' );
					if ( label ) {
						label.hidden = true;
					}
				} );
				if ( status ) {
					status.textContent = '';
				}
			};

			const apply = () => {
				const query = search.value.trim().toLowerCase();
				if ( '' === query ) {
					clear();
					return;
				}

				let matchCount = 0;
				panels.forEach( ( panel ) => {
					let panelMatches = 0;
					panel.querySelectorAll( '.aafm-ability-row' ).forEach( ( row ) => {
						const isMatch = row.textContent.toLowerCase().includes( query );
						row.hidden = ! isMatch;
						if ( isMatch ) {
							panelMatches += 1;
						}
					} );
					panel.hidden = 0 === panelMatches;
					panelChrome( panel ).forEach( ( el ) => {
						el.hidden = true;
					} );
					setBulkButtonsDisabled( panel, true );
					const label = this.#abilitiesPanelLabel( panel );
					if ( label ) {
						label.hidden = 0 === panelMatches;
					}
					matchCount += panelMatches;
				} );

				if ( status ) {
					status.textContent =
						0 === matchCount
							? this.#t( 'abilitiesSearchNone', 'No abilities match.' )
							: this.#format(
									this.#t( 'abilitiesSearchCount', '%s abilities match.' ),
									new Intl.NumberFormat().format( matchCount )
							  );
				}
			};

			search.addEventListener( 'input', apply );

			// A direct sub-tab click is a request to see only that one tab, the way it always
			// has been - reconcile the search box with it rather than leaving a stale query
			// active over a view that no longer matches what it filtered.
			tabs.forEach( ( tab ) => {
				tab.addEventListener( 'click', () => {
					if ( '' !== search.value ) {
						search.value = '';
						clear();
					}
				} );
			} );
		}

		#bindOsTabs() {
			// There are two independent OS-tab groups on the Connection tab: one inside
			// .aafm-oauth-picker and one inside .aafm-app-password-fallback. Each click
			// must only affect the tabs and snippet blocks within its own group's container
			// so the two pickers operate independently of one another.
			const tabs = document.querySelectorAll( '.aafm-os-tab' );
			if ( ! tabs.length ) {
				return;
			}

			// The closest container that holds this tab's sibling tabs and snippet blocks.
			// The OAuth OS tabs sit in .aafm-oauth-picker, but the OAuth snippet blocks live
			// in the sibling .aafm-oauth-panels - both inside .aafm-oauth-card, so scope to
			// the card to reach the snippets while staying clear of the App-Password fallback
			// (a sibling, not nested).
			const containerOf = ( tab ) =>
				tab.closest( '.aafm-oauth-card' ) ??
				tab.closest( '.aafm-app-password-fallback' ) ??
				tab.closest( '.aafm-card' ) ??
				document;

			const activate = ( tab ) => {
				const os = tab.dataset.os;
				const container = containerOf( tab );
				const siblings = Array.from(
					container.querySelectorAll( '.aafm-os-tab' )
				);
				// Update active state only for sibling tabs in the same container.
				siblings.forEach( ( t ) => {
					const active = t === tab;
					t.classList.toggle( 'is-active', active );
					t.setAttribute( 'aria-selected', active ? 'true' : 'false' );
				} );
				// Roving tabindex: only the active tab is in the tab sequence.
				this.#rovingTabindex( siblings, tab );
				// Toggle snippet visibility only within this container.
				container
					.querySelectorAll( '.aafm-snippet[data-os]' )
					.forEach( ( box ) => {
						box.hidden = box.dataset.os !== os;
					} );
			};

			// Set the initial roving tabindex per container so keyboard focus enters at the
			// active tab, then wire click + Arrow/Home/End within each container's tab group.
			const seen = new Set();
			tabs.forEach( ( tab ) => {
				tab.addEventListener( 'click', () => activate( tab ) );

				const container = containerOf( tab );
				if ( seen.has( container ) ) {
					return;
				}
				seen.add( container );
				const group = Array.from(
					container.querySelectorAll( '.aafm-os-tab' )
				);
				const current =
					group.find( ( t ) => t.classList.contains( 'is-active' ) ) ?? group[ 0 ];
				this.#rovingTabindex( group, current );
				this.#wireTablistKeys( group, activate );
			} );
		}

		/**
		 * POST an admin-ajax action with the nonce attached. Returns the parsed JSON,
		 * or a synthetic failure object so callers never have to try/catch the transport.
		 *
		 * @param {string} action admin-ajax action name.
		 * @param {Object} data   Extra form fields.
		 * @return {Promise<Object>} The decoded JSON response.
		 */
		async #post( action, data = {} ) {
			const body = new URLSearchParams( { action, nonce: this.#nonce, ...data } );
			try {
				const res = await fetch( this.#ajaxUrl, {
					method: 'POST',
					body,
					credentials: 'same-origin',
				} );
				return await res.json();
			} catch {
				return {
					success: false,
					data: { message: this.#t( 'requestFailed', 'Request failed.' ) },
				};
			}
		}

		#bindCopy() {
			document.querySelectorAll( '.aafm-copy' ).forEach( ( btn ) => {
				// Swap only the label so a leading SVG icon is preserved across the
				// "Copied" flash; fall back to the button itself for icon-less buttons.
				const label = btn.querySelector( '.aafm-copy-label' ) ?? btn;
				const original = label.textContent;
				let revertTimer = null;
				btn.addEventListener( 'click', async () => {
					try {
						await navigator.clipboard.writeText( btn.dataset.copy ?? '' );
						label.textContent = this.#t( 'copyCopied', 'Copied' );
					} catch {
						label.textContent = this.#t( 'copyFallback', 'Press Ctrl+C' );
					}
					// Clear any pending revert from a quick second click, then restore the label.
					if ( revertTimer ) {
						clearTimeout( revertTimer );
					}
					revertTimer = setTimeout( () => {
						label.textContent = original;
						revertTimer = null;
					}, 1500 );
				} );
			} );
		}

		/**
		 * "Enable all reads" on both the Abilities and Integrations tabs.
		 *
		 * Read-only mode is a ceiling, not a bulk action: turning it on deliberately enables
		 * nothing. This is the explicit, operator-initiated convenience that replaces what it
		 * refuses to do for you - it ticks every read in the section or card it sits in and
		 * nothing else, one time, visibly, and undoable by unticking.
		 *
		 * Scoped by walking up to the panel (Abilities) or the card (Integrations and Bridge,
		 * whose group <details> carries .aafm-integration-card too) that the button renders
		 * inside, so one binding serves all three tabs. Every ability row on every tab carries
		 * data-risk, so "read" is read off the server-rendered row rather than guessed from a
		 * label. A locked row emits no checkbox at all, so this can never sweep one in.
		 *
		 * Both field names are matched because the Bridge tab posts bridged_abilities[] through
		 * its own AJAX save while the other two post aafm_abilities[]. Disabled inputs are
		 * excluded in the selector, matching #bindBridgeBulk. No confirm strip needs hiding the
		 * way the bridge bulk control does: a row is only data-risk="read" when its annotations
		 * say readonly and not destructive, and the confirm strip only renders on a destructive
		 * row, so a read row never has one.
		 */
		#bindEnableReads() {
			document.querySelectorAll( '.aafm-enable-reads' ).forEach( ( btn ) => {
				btn.addEventListener( 'click', () => {
					const scope = btn.closest(
						'.aafm-subject-panel, .aafm-integration-card'
					);
					if ( ! scope ) {
						return;
					}
					const boxes = scope.querySelectorAll(
						'.aafm-ability-row[data-risk="read"] input[type="checkbox"][name="aafm_abilities[]"]:not([disabled]), .aafm-ability-row[data-risk="read"] input[type="checkbox"][name="bridged_abilities[]"]:not([disabled])'
					);
					boxes.forEach( ( b ) => {
						b.checked = true;
					} );
				} );
			} );
		}

		/**
		 * "Enable all writes" on the Abilities tab.
		 *
		 * The write-side counterpart to #bindEnableReads, and it exists because the bulk
		 * affordance used to cover only the safe half: an operator who wanted reads plus a
		 * couple of writes had to tick each write by hand, which is how a site ends up with
		 * "Upload media" on and "Update media" off and an agent that cannot set alt text.
		 *
		 * Scoped the same way and additive the same way - it ticks, never unticks, so it can
		 * never silently switch off a working configuration, and unticking undoes it.
		 *
		 * What counts as a write is read off the server-rendered row, never guessed: data-risk
		 * is exactly "write", so destructive rows are excluded by class, and data-high-risk is
		 * absent, so the nine money-and-identity abilities stay out even after the operator
		 * unlocks that category (all nine are risk="write", so risk alone would sweep them in).
		 * A locked row emits no checkbox at all, so read-only mode and a shut high-risk floor
		 * are already unreachable here. That leaves nothing destructive or high-risk in range,
		 * which is why this control needs no confirm where "Enable all" does.
		 *
		 * Only the Abilities tab renders the button today, so only aafm_abilities[] is matched;
		 * the scope roots mirror #bindEnableReads so the binding still works if the control is
		 * ever placed on an integration card.
		 */
		#bindEnableWrites() {
			document.querySelectorAll( '.aafm-enable-writes' ).forEach( ( btn ) => {
				btn.addEventListener( 'click', () => {
					const scope = btn.closest(
						'.aafm-subject-panel, .aafm-integration-card'
					);
					if ( ! scope ) {
						return;
					}
					const boxes = scope.querySelectorAll(
						'.aafm-ability-row[data-risk="write"]:not([data-high-risk]) input[type="checkbox"][name="aafm_abilities[]"]:not([disabled])'
					);
					boxes.forEach( ( b ) => {
						b.checked = true;
					} );
				} );
			} );
		}

		/**
		 * Per-section "Enable all / Disable all" buttons on the Abilities tab.
		 * Toggles every ability checkbox inside the button's subject panel. When the
		 * action would enable a section that holds a destructive ability, confirm first.
		 */
		#bindSectionToggles() {
			const buttons = document.querySelectorAll( '.aafm-section-toggle-all' );
			buttons.forEach( ( btn ) => {
				btn.addEventListener( 'click', () => {
					const subject = btn.dataset.subject;
					const panel = document.querySelector(
						`.aafm-subject-panel[data-subject="${ subject }"]`
					);
					if ( ! panel ) {
						return;
					}
					const boxes = panel.querySelectorAll(
						'input[type="checkbox"][name="aafm_abilities[]"]'
					);
					const enabling = Array.from( boxes ).some( ( b ) => ! b.checked );
					if ( enabling && btn.dataset.hasDestructive === '1' ) {
						const msg = this.#t(
							'sectionToggleConfirm',
							'This section includes destructive abilities (trash/delete). Enable all of them?'
						);
						if ( ! window.confirm( msg ) ) {
							return;
						}
					}
					boxes.forEach( ( b ) => {
						b.checked = enabling;
					} );
				} );
			} );
		}

		/**
		 * Per-integration "Enable all / Disable all" buttons on the Integrations tab.
		 * Toggles every ability checkbox inside the button's integration card. When the
		 * action would enable a card that holds a PII/destructive ability, confirm first.
		 */
		#bindIntegrationToggles() {
			const buttons = document.querySelectorAll(
				'.aafm-integration-toggle-all'
			);
			buttons.forEach( ( btn ) => {
				btn.addEventListener( 'click', () => {
					const subject = btn.dataset.subject;
					const card = document.querySelector(
						`.aafm-integration-${ subject }`
					);
					if ( ! card ) {
						return;
					}
					const boxes = card.querySelectorAll(
						'input[type="checkbox"][name="aafm_abilities[]"]'
					);
					const enabling = Array.from( boxes ).some(
						( b ) => ! b.checked
					);
					if ( enabling && btn.dataset.hasSensitive === '1' ) {
						const msg = this.#t(
							'integrationToggleConfirm',
							'These abilities can read and change personal data such as customer details and orders. Turn all of them on?'
						);
						if ( ! window.confirm( msg ) ) {
							return;
						}
					}
					boxes.forEach( ( b ) => {
						b.checked = enabling;
					} );
				} );
			} );
		}

		/**
		 * Per-card "Search abilities" + All/Read Only/Write filter on the Integrations tab.
		 * Scoped to each .aafm-integration-card: the search query and the chosen risk are
		 * ANDed, and matching is done against each row's data-risk plus its textContent (label,
		 * name, and hint are all server-rendered text, so reading textContent is safe). Hiding
		 * is via the `hidden` attribute only - no markup is built from data, so there is no
		 * HTML sink. The filter works the same on inactive cards (the rows are disabled but
		 * still in the DOM). Filter controls never touch the form submit.
		 */
		#bindIntegrationFilters() {
			const filters = document.querySelectorAll( '.aafm-integration-filter' );
			filters.forEach( ( filter ) => {
				const card = filter.closest( '.aafm-integration-card' );
				if ( ! card ) {
					return;
				}
				const search = filter.querySelector( '.aafm-integration-search' );
				const riskButtons = filter.querySelectorAll( '.aafm-filter-btn' );
				const rows = card.querySelectorAll( '.aafm-ability-row' );

				let query = '';
				let risk = 'all';

				const apply = () => {
					rows.forEach( ( row ) => {
						const rowRisk = row.dataset.risk ?? 'read';
						// "write" groups both write and destructive; "read" matches read only.
						const riskOk =
							'all' === risk ||
							( 'read' === risk && 'read' === rowRisk ) ||
							( 'write' === risk && 'read' !== rowRisk );
						const textOk =
							'' === query ||
							row.textContent.toLowerCase().includes( query );
						row.hidden = ! ( riskOk && textOk );
					} );
				};

				if ( search ) {
					search.addEventListener( 'input', () => {
						query = search.value.trim().toLowerCase();
						apply();
					} );
				}

				riskButtons.forEach( ( btn ) => {
					btn.addEventListener( 'click', () => {
						risk = btn.dataset.filterRisk ?? 'all';
						riskButtons.forEach( ( b ) => {
							const on = b === btn;
							b.classList.toggle( 'is-active', on );
							b.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
						} );
						apply();
					} );
				} );
			} );
		}

		/**
		 * Patch the enabled-count numbers in a saved form back to what the server actually
		 * persisted, instead of leaving them showing whatever was on the page before the save
		 * (e.g. a WooCommerce card stuck on "0 / 52" right after a successful save).
		 *
		 * Membership in `enabledList` - the AJAX response's authoritative enabled list - is
		 * what decides each count, never a checkbox's own .checked state: a save can legally
		 * persist something other than exactly what was submitted (a locked high-risk ability
		 * silently dropped, an off-scope ability preserved from the stored option), so reading
		 * .checked would still show intent instead of reality in those cases. The set of
		 * checkbox VALUES within each scope (the denominator) is stable and safe to read
		 * straight off the DOM; only membership in the enabled set comes from the server.
		 *
		 * Three scopes are patched, each carrying its own `.aafm-enabled-num` span server-side:
		 * an integration card's header (Integrations tab), a subject panel's heading, and a
		 * Reads/Writes group's heading (both Abilities tab). A card/panel/group with no such
		 * span (the Bridge tab's cards show no enabled count at all) is left alone.
		 *
		 * @param {Element}  scopeRoot   The form to search within.
		 * @param {string[]} enabledList The enabled ability names the server just confirmed.
		 */
		#refreshLocalCounts( scopeRoot, enabledList ) {
			const enabledSet = new Set( enabledList );
			const countEnabled = ( container ) =>
				[
					...container.querySelectorAll(
						'input[name="aafm_abilities[]"]'
					),
				].filter( ( box ) => enabledSet.has( box.value ) ).length;

			scopeRoot
				.querySelectorAll( '.aafm-integration-card' )
				.forEach( ( card ) => {
					const num = card.querySelector(
						'.aafm-integration-count .aafm-enabled-num'
					);
					if ( num ) {
						num.textContent = String( countEnabled( card ) );
					}
				} );

			scopeRoot
				.querySelectorAll( '.aafm-subject-panel' )
				.forEach( ( panel ) => {
					const num = panel.querySelector(
						'.aafm-subject-heading .aafm-enabled-num'
					);
					if ( num ) {
						num.textContent = String( countEnabled( panel ) );
					}
				} );

			scopeRoot
				.querySelectorAll( '.aafm-ability-group-head' )
				.forEach( ( head ) => {
					const num = head.querySelector( '.aafm-enabled-num' );
					const list = head.nextElementSibling;
					if ( num && list ) {
						num.textContent = String( countEnabled( list ) );
					}
				} );
		}

		/**
		 * Save the Integrations tab's per-ability toggles. Reuses the same
		 * aafm_save_abilities action and flat aafm_abilities[] contract as the
		 * Abilities tab; the stored option is one shared enabled-ability list.
		 */
		#bindSaveIntegrations() {
			const form = document.querySelector( '#aafm-integrations-form' );
			if ( ! form ) {
				return;
			}
			form.addEventListener( 'submit', async ( e ) => {
				e.preventDefault();
				const status = form.querySelector( '.aafm-save-status' );
				const enabled = [
					...form.querySelectorAll(
						'input[name="aafm_abilities[]"]:checked'
					),
				].map( ( i ) => i.value );

				const body = new URLSearchParams();
				body.append( 'action', 'aafm_save_abilities' );
				body.append( 'nonce', this.#nonce );
				enabled.forEach( ( v ) =>
					body.append( 'aafm_abilities[]', v )
				);
				// Send the tab's scope (the integration subjects it owns) so the
				// server merges only these and preserves every off-tab ability from
				// the persisted option - no off-tab state is trusted from the client.
				[
					...form.querySelectorAll( 'input[name="aafm_scope[]"]' ),
				].forEach( ( i ) => body.append( 'aafm_scope[]', i.value ) );

				if ( status ) {
					status.textContent = this.#t( 'saving', 'Saving…' );
				}
				let json;
				try {
					const res = await fetch( this.#ajaxUrl, {
						method: 'POST',
						body,
						credentials: 'same-origin',
					} );
					json = await res.json();
				} catch {
					json = { success: false };
				}
				if ( json?.success ) {
					this.#refreshLocalCounts(
						form,
						json.data?.enabled ?? []
					);
				}
				if ( status ) {
					status.textContent = json?.success
						? this.#t( 'saved', 'Saved' )
						: this.#t( 'errorSaving', 'Error saving' );
				}
			} );
		}

		/**
		 * Save the "Other plugins" toggles. Posts to its OWN action
		 * (aafm_save_bridged_abilities) with its own bridged_abilities[] field, so it never
		 * touches the native enabled-abilities option.
		 */
		#bindSaveBridge() {
			const form = document.querySelector( '#aafm-bridge-form' );
			if ( ! form ) {
				return;
			}
			form.addEventListener( 'submit', async ( e ) => {
				e.preventDefault();
				const status = form.querySelector( '.aafm-save-status' );
				const enabled = [
					...form.querySelectorAll(
						'input[name="bridged_abilities[]"]:checked'
					),
				].map( ( i ) => i.value );

				const body = new URLSearchParams();
				body.append( 'action', 'aafm_save_bridged_abilities' );
				body.append( 'nonce', this.#nonce );
				enabled.forEach( ( v ) =>
					body.append( 'bridged_abilities[]', v )
				);

				if ( status ) {
					status.textContent = this.#t( 'saving', 'Saving…' );
				}
				let json;
				try {
					const res = await fetch( this.#ajaxUrl, {
						method: 'POST',
						body,
						credentials: 'same-origin',
					} );
					json = await res.json();
				} catch {
					json = { success: false };
				}
				if ( status ) {
					status.textContent = json?.success
						? this.#t( 'saved', 'Saved' )
						: this.#t( 'errorSaving', 'Error saving' );
				}
			} );
		}

		/**
		 * Global search + All/Read Only/Write filter for the bridge directory.
		 *
		 * Unlike the per-card integrations filter, this one filter drives every row across all
		 * groups: the query and the chosen risk are ANDed against each row's data-risk plus its
		 * textContent (server-rendered text), and any group (<details>) that contains a match is
		 * auto-opened so results are not hidden inside a collapsed card. "write" groups both write
		 * and destructive; hiding is via the hidden attribute only, so there is no HTML sink.
		 */
		#bindBridgeFilter() {
			const filter = document.querySelector( '#aafm-bridge-filter' );
			const form = document.querySelector( '#aafm-bridge-form' );
			if ( ! filter || ! form ) {
				return;
			}
			const search = filter.querySelector( '.aafm-integration-search' );
			const riskButtons = filter.querySelectorAll( '.aafm-filter-btn' );
			const rows = form.querySelectorAll( '.aafm-ability-row' );
			const groups = form.querySelectorAll( '.aafm-integration-card' );

			let query = '';
			let risk = 'all';

			const apply = () => {
				rows.forEach( ( row ) => {
					const rowRisk = row.dataset.risk ?? 'read';
					const riskOk =
						'all' === risk ||
						( 'read' === risk && 'read' === rowRisk ) ||
						( 'write' === risk && 'read' !== rowRisk );
					const textOk =
						'' === query ||
						row.textContent.toLowerCase().includes( query );
					row.hidden = ! ( riskOk && textOk );
				} );
				// Auto-open groups that contain a visible row (only while filtering).
				const filtering = '' !== query || 'all' !== risk;
				groups.forEach( ( group ) => {
					if ( ! filtering ) {
						return;
					}
					const hasMatch = [
						...group.querySelectorAll( '.aafm-ability-row' ),
					].some( ( r ) => ! r.hidden );
					if ( hasMatch ) {
						group.open = true;
					}
				} );
			};

			if ( search ) {
				search.addEventListener( 'input', () => {
					query = search.value.trim().toLowerCase();
					apply();
				} );
			}
			riskButtons.forEach( ( btn ) => {
				btn.addEventListener( 'click', () => {
					risk = btn.dataset.filterRisk ?? 'all';
					riskButtons.forEach( ( b ) => {
						const on = b === btn;
						b.classList.toggle( 'is-active', on );
						b.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
					} );
					apply();
				} );
			} );
		}

		/**
		 * Destructive-ability confirm for the bridge directory: an inline reveal, never a JS
		 * modal. Flipping a destructive switch on shows the row's .aafm-bridge-confirm strip;
		 * "Enable anyway" keeps it on and hides the strip, "Cancel" flips the switch back off.
		 */
		#bindBridgeConfirm() {
			const form = document.querySelector( '#aafm-bridge-form' );
			if ( ! form ) {
				return;
			}
			form.querySelectorAll(
				'input[name="bridged_abilities[]"][data-destructive="1"]'
			).forEach( ( box ) => {
				const row = box.closest( '.aafm-ability-row' );
				const confirm = row?.querySelector( '.aafm-bridge-confirm' );
				if ( ! confirm ) {
					return;
				}
				const yes = confirm.querySelector( '.aafm-bridge-confirm-yes' );
				const no = confirm.querySelector( '.aafm-bridge-confirm-no' );

				box.addEventListener( 'change', () => {
					if ( box.checked ) {
						confirm.hidden = false;
					} else {
						confirm.hidden = true;
					}
				} );
				if ( yes ) {
					yes.addEventListener( 'click', () => {
						confirm.hidden = true;
					} );
				}
				if ( no ) {
					no.addEventListener( 'click', () => {
						box.checked = false;
						confirm.hidden = true;
					} );
				}
			} );
		}

		/**
		 * Per-group Enable all / Disable all for the bridge directory. Flips every ability
		 * checkbox inside the button's own plugin card (skipping disabled/orphan rows). Bulk
		 * enable is a deliberate action, so it turns destructive abilities on directly and hides
		 * any open per-row confirm strip rather than firing a modal. The save reads whatever is
		 * checked, so no other wiring is needed.
		 */
		#bindBridgeBulk() {
			const form = document.querySelector( '#aafm-bridge-form' );
			if ( ! form ) {
				return;
			}
			form.querySelectorAll( '[data-bridge-bulk]' ).forEach( ( btn ) => {
				btn.addEventListener( 'click', () => {
					const group = btn.closest( 'details' );
					if ( ! group ) {
						return;
					}
					const enable = 'enable' === btn.dataset.bridgeBulk;
					group
						.querySelectorAll(
							'input[name="bridged_abilities[]"]:not([disabled])'
						)
						.forEach( ( box ) => {
							box.checked = enable;
							const confirm = box
								.closest( '.aafm-ability-row' )
								?.querySelector( '.aafm-bridge-confirm' );
							if ( confirm ) {
								confirm.hidden = true;
							}
						} );
				} );
			} );
		}

		#bindSaveAbilities() {
			const form = document.querySelector( '#aafm-abilities-form' );
			if ( ! form ) {
				return;
			}
			form.addEventListener( 'submit', async ( e ) => {
				e.preventDefault();
				const status = form.querySelector( '.aafm-save-status' );
				const enabled = [
					...form.querySelectorAll( 'input[name="aafm_abilities[]"]:checked' ),
				].map( ( i ) => i.value );

				const body = new URLSearchParams();
				body.append( 'action', 'aafm_save_abilities' );
				body.append( 'nonce', this.#nonce );
				enabled.forEach( ( v ) => body.append( 'aafm_abilities[]', v ) );
				// Send the tab's scope (the core subjects it owns) so the server
				// merges only these and preserves every off-tab ability - e.g.
				// enabled integration (WooCommerce/Yoast/ACF) abilities - from the
				// persisted option. No off-tab state is trusted from the client.
				[
					...form.querySelectorAll( 'input[name="aafm_scope[]"]' ),
				].forEach( ( i ) => body.append( 'aafm_scope[]', i.value ) );

				if ( status ) {
					status.textContent = this.#t( 'saving', 'Saving…' );
				}
				let json;
				try {
					const res = await fetch( this.#ajaxUrl, {
						method: 'POST',
						body,
						credentials: 'same-origin',
					} );
					json = await res.json();
				} catch {
					json = { success: false };
				}
				if ( json?.success ) {
					this.#refreshLocalCounts( form, json.data?.enabled ?? [] );
					const statTotal = document.querySelector(
						'.aafm-stat-enabled-num'
					);
					if (
						statTotal &&
						undefined !== json.data?.ability_enabled_total
					) {
						statTotal.textContent = String(
							json.data.ability_enabled_total
						);
					}
				}
				if ( status ) {
					status.textContent = json?.success
						? this.#t( 'saved', 'Saved' )
						: this.#t( 'errorSaving', 'Error saving' );
				}
			} );
		}

		#bindSavePostTypes() {
			const btn = document.querySelector( '#aafm-post-types-save' );
			const root = document.querySelector( '#aafm-post-types-form' );
			if ( ! btn || ! root ) {
				return;
			}
			btn.addEventListener( 'click', async () => {
				const status = root.querySelector( '.aafm-post-types-status' );
				const types = [
					...root.querySelectorAll( 'input[name="aafm_post_types[]"]:checked' ),
				].map( ( i ) => i.value );

				const body = new URLSearchParams();
				body.append( 'action', 'aafm_save_post_types' );
				body.append( 'nonce', this.#nonce );
				types.forEach( ( v ) => body.append( 'aafm_post_types[]', v ) );

				if ( status ) {
					status.textContent = this.#t( 'saving', 'Saving…' );
				}
				let json;
				try {
					const res = await fetch( this.#ajaxUrl, {
						method: 'POST',
						body,
						credentials: 'same-origin',
					} );
					json = await res.json();
				} catch {
					json = { success: false };
				}
				if ( status ) {
					status.textContent = json?.success
						? this.#t( 'saved', 'Saved' )
						: this.#t( 'errorSaving', 'Error saving' );
				}
			} );
		}
		#bindSaveMetaKeys() {
			const btn = document.querySelector( '#aafm-meta-keys-save' );
			const root = document.querySelector( '#aafm-meta-keys-form' );
			if ( ! btn || ! root ) {
				return;
			}
			// Exposed and Deny share one Save button and are now persisted in a single request,
			// matching the user-meta/term-meta single-handler pattern. The previous split (two
			// actions, two handlers) let the deny-list save fail silently inside an empty
			// catch{} while the exposed-list handler still printed "Saved" - so a dropped deny
			// list read as success. One request + one status assignment removes that gap.
			btn.addEventListener( 'click', async () => {
				const status = root.querySelector( '.aafm-meta-keys-status' );
				const textarea = root.querySelector( 'textarea[name="aafm_meta_keys"]' );
				const deny = root.querySelector( 'textarea[name="aafm_deny_meta_keys"]' );
				if ( status ) {
					status.textContent = this.#t( 'saving', 'Saving…' );
				}
				const json = await this.#post( 'aafm_save_meta_keys', {
					aafm_meta_keys: textarea?.value ?? '',
					aafm_deny_meta_keys: deny?.value ?? '',
				} );
				if ( status ) {
					status.textContent = json?.success
						? this.#t( 'saved', 'Saved' )
						: this.#t( 'errorSaving', 'Error saving' );
				}
			} );
		}

		#bindSaveUserMetaKeys() {
			const btn = document.querySelector( '#aafm-user-meta-keys-save' );
			const root = document.querySelector( '#aafm-user-meta-keys-form' );
			if ( ! btn || ! root ) {
				return;
			}
			btn.addEventListener( 'click', async () => {
				const status = root.querySelector( '.aafm-user-meta-keys-status' );
				const exposed = root.querySelector(
					'textarea[name="aafm_exposed_user_meta_keys"]'
				);
				const deny = root.querySelector(
					'textarea[name="aafm_denied_user_meta_keys"]'
				);
				if ( status ) {
					status.textContent = this.#t( 'saving', 'Saving…' );
				}
				const json = await this.#post( 'aafm_save_user_meta_keys', {
					aafm_exposed_user_meta_keys: exposed?.value ?? '',
					aafm_denied_user_meta_keys: deny?.value ?? '',
				} );
				if ( status ) {
					status.textContent = json?.success
						? this.#t( 'saved', 'Saved' )
						: this.#t( 'errorSaving', 'Error saving' );
				}
			} );
		}

		#bindSaveTermMetaKeys() {
			const btn = document.querySelector( '#aafm-term-meta-keys-save' );
			const root = document.querySelector( '#aafm-term-meta-keys-form' );
			if ( ! btn || ! root ) {
				return;
			}
			btn.addEventListener( 'click', async () => {
				const status = root.querySelector( '.aafm-term-meta-keys-status' );
				const exposed = root.querySelector(
					'textarea[name="aafm_exposed_term_meta_keys"]'
				);
				const deny = root.querySelector(
					'textarea[name="aafm_denied_term_meta_keys"]'
				);
				if ( status ) {
					status.textContent = this.#t( 'saving', 'Saving…' );
				}
				const json = await this.#post( 'aafm_save_term_meta_keys', {
					aafm_exposed_term_meta_keys: exposed?.value ?? '',
					aafm_denied_term_meta_keys: deny?.value ?? '',
				} );
				if ( status ) {
					status.textContent = json?.success
						? this.#t( 'saved', 'Saved' )
						: this.#t( 'errorSaving', 'Error saving' );
				}
			} );
		}

		#bindSaveSettings() {
			const form = document.querySelector( '#aafm-settings-form' );
			if ( ! form ) {
				return;
			}
			form.addEventListener( 'submit', async ( e ) => {
				e.preventDefault();
				const status = form.querySelector( '.aafm-save-status' );
				const rate = form.querySelector( 'input[name="aafm_rate_limit_per_min"]' );
				const title = form.querySelector( 'input[name="aafm_max_title_len"]' );
				const retention = form.querySelector(
					'input[name="aafm_log_retention_days"]'
				);
				const draft = form.querySelector( 'input[name="aafm_force_draft"]' );
				const blockGuardStrict = form.querySelector(
					'input[name="aafm_block_guard_strict"]'
				);
				const deleteOnUninstall = form.querySelector(
					'input[name="aafm_delete_data_on_uninstall"]'
				);
				const oauthEnabled = form.querySelector(
					'input[name="aafm_oauth_enabled"]'
				);
				const oauthDcrEnabled = form.querySelector(
					'input[name="aafm_oauth_dcr_enabled"]'
				);
				const readOnlyMode = form.querySelector(
					'input[name="aafm_read_only_mode"]'
				);
				const highRiskUnlocked = form.querySelector(
					'input[name="aafm_high_risk_abilities_unlocked"]'
				);
				const allowlist = form.querySelector( 'textarea[name="aafm_ip_allowlist"]' );

				const body = new URLSearchParams();
				body.append( 'action', 'aafm_save_settings' );
				body.append( 'nonce', this.#nonce );
				body.append( 'aafm_rate_limit_per_min', rate?.value ?? '0' );
				body.append( 'aafm_max_title_len', title?.value ?? '0' );
				body.append( 'aafm_log_retention_days', retention?.value ?? '30' );
				// Every checkbox in this form is forwarded the same way: append '1' when checked,
				// omit it when unchecked. The server sanitizer reads an absent field as off, so a
				// checkbox that is left out of the payload is never persisted as on. Leaving any of
				// these out (the pre-fix bug for the OAuth, DCR, and strict-block toggles) made the
				// server coerce them off on every save, so toggling one on never stuck.
				if ( draft?.checked ) {
					body.append( 'aafm_force_draft', '1' );
				}
				if ( blockGuardStrict?.checked ) {
					body.append( 'aafm_block_guard_strict', '1' );
				}
				if ( deleteOnUninstall?.checked ) {
					body.append( 'aafm_delete_data_on_uninstall', '1' );
				}
				if ( oauthEnabled?.checked ) {
					body.append( 'aafm_oauth_enabled', '1' );
				}
				if ( oauthDcrEnabled?.checked ) {
					body.append( 'aafm_oauth_dcr_enabled', '1' );
				}
				if ( readOnlyMode?.checked ) {
					body.append( 'aafm_read_only_mode', '1' );
				}
				if ( highRiskUnlocked?.checked ) {
					body.append( 'aafm_high_risk_abilities_unlocked', '1' );
				}
				body.append( 'aafm_ip_allowlist', allowlist?.value ?? '' );

				if ( status ) {
					status.textContent = this.#t( 'saving', 'Saving…' );
				}
				let json;
				try {
					const res = await fetch( this.#ajaxUrl, {
						method: 'POST',
						body,
						credentials: 'same-origin',
					} );
					json = await res.json();
				} catch {
					json = { success: false };
				}
				if ( status ) {
					if ( ! json?.success ) {
						// A rejected save never wrote anything, so the generic line is right for it.
						// The exception is a server message: the governance switches report a change
						// that did not take (a stale persistent object cache) after every other
						// setting was stored, and that message names the switch and the remedy.
						status.textContent =
							json?.data?.message ??
							this.#t(
								'settingsNotSaved',
								'Could not save - your previous settings are still in effect.'
							);
					} else {
						const dropped = Number( json.data?.aafm_ip_dropped ?? 0 );
						const kept = Array.isArray( json.data?.aafm_ip_allowlist )
							? json.data.aafm_ip_allowlist.length
							: 0;
						if ( dropped > 0 && kept === 0 ) {
							// Every line was invalid: the list is now empty, which means allow-all.
							status.textContent = this.#t(
								'allowlistEmptied',
								'Saved, but every line was dropped as invalid. The allowlist is now empty, so connections from anywhere are allowed.'
							);
						} else if ( dropped > 0 ) {
							status.textContent = this.#format(
								this.#t(
									'allowlistDropped',
									'Saved. Dropped %d line(s) that were not a valid IP or range - check the allowlist.'
								),
								dropped
							);
						} else {
							status.textContent = this.#t( 'saved', 'Saved' );
						}
					}
				}
				// Reflect the cleaned allowlist so any dropped (invalid) lines visibly disappear.
				// Assigned via .value (never innerHTML), so the server echo is never an HTML sink.
				if ( json?.success && allowlist && typeof json.data?.aafm_ip_allowlist_text === 'string' ) {
					allowlist.value = json.data.aafm_ip_allowlist_text;
				}
			} );
		}

		#bindMetaChips() {
			const root = document.querySelector( '#aafm-meta-keys-form' );
			if ( ! root ) {
				return;
			}
			const textarea = root.querySelector( 'textarea[name="aafm_meta_keys"]' );
			root.querySelectorAll( '.aafm-meta-chip' ).forEach( ( chip ) => {
				chip.addEventListener( 'click', () => {
					const key = chip.dataset.key ?? '';
					if ( ! key || ! textarea ) {
						return;
					}
					const lines = textarea.value
						.split( '\n' )
						.map( ( l ) => l.trim() )
						.filter( Boolean );
					if ( ! lines.includes( key ) ) {
						textarea.value = (
							textarea.value.replace( /\n+$/, '' ) +
							'\n' +
							key
						).replace( /^\n/, '' );
					}
				} );
			} );
		}

		#bindCreateUser() {
			const btn = document.querySelector( '#aafm-create-user' );
			if ( ! btn ) {
				return;
			}
			btn.addEventListener( 'click', async () => {
				const login = document.querySelector( '#aafm-agent-login' )?.value ?? '';
				const status = document.querySelector( '.aafm-user-status' );
				if ( status ) {
					status.textContent = this.#t( 'creating', 'Creating…' );
				}
				const json = await this.#post( 'aafm_create_agent_user', { login } );
				if ( ! status ) {
					return;
				}
				// Reset to plain text each attempt; a prior run may have appended an Edit link.
				status.textContent = '';
				if ( json?.success ) {
					status.textContent = this.#format(
						this.#t(
							'userCreated',
							'Created user #%d. Now create its Application Password under Users → Profile.'
						),
						json.data.user_id
					);
					// Point the already-rendered App-Password snippets at the login just
					// created, so a config copied in this same session (no reload) names the
					// real account rather than the seed the server printed at page load.
					this.#retargetSnippetLogin( json.data.login ?? '' );
				} else {
					// On a duplicate username the server returns the existing user's edit URL;
					// show the friendly message plus a real "Edit user" link built via the DOM
					// (textContent + href only - never innerHTML), so nothing untrusted is parsed.
					status.textContent = json?.data?.message ?? this.#t( 'errorUnknown', 'unknown' );
					const editUrl = json?.data?.edit_url;
					if ( editUrl ) {
						const link = document.createElement( 'a' );
						link.href = editUrl;
						link.textContent = this.#t( 'editUser', 'Edit user' );
						status.append( ' ', link );
					}
				}
			} );
		}

		#bindTestConnection() {
			const btn = document.querySelector( '#aafm-test-connection' );
			if ( ! btn ) {
				return;
			}
			btn.addEventListener( 'click', async () => {
				const status = document.querySelector( '.aafm-test-status' );
				if ( status ) {
					status.textContent = this.#t( 'checking', 'Checking…' );
				}
				const json = await this.#post( 'aafm_test_connection' );
				if ( ! status ) {
					return;
				}
				if ( json?.success && json.data.reachable ) {
					status.textContent = this.#format(
						this.#t(
							'connectionOk',
							'Reachable (HTTP %1$s) - %2$s tool(s) in your admin view.'
						),
						json.data.http_code,
						json.data.admin_tool_count
					);
				} else if ( json?.success ) {
					status.textContent = this.#format(
						this.#t(
							'connectionNoTools',
							'Endpoint answered HTTP %s but did not return a tool list.'
						),
						json.data.http_code
					);
				} else {
					status.textContent = this.#format(
						this.#t( 'errorWithMessage', 'Error: %s' ),
						json?.data?.message ?? this.#t( 'errorUnknown', 'unknown' )
					);
				}
			} );
		}

		#bindClearLog() {
			const btn = document.querySelector( '#aafm-clear-log' );
			if ( ! btn ) {
				return;
			}
			btn.addEventListener( 'click', async () => {
				const status = document.querySelector( '.aafm-clear-status' );
				const json = await this.#post( 'aafm_clear_log' );
				if ( status ) {
					status.textContent = json?.success
						? this.#t( 'cleared', 'Cleared' )
						: this.#t( 'error', 'Error' );
				}
				if ( json?.success ) {
					// Empty the table, reset the count, and collapse the pager to page 1 of 1.
					this.#renderLogRows( [] );
					const num = document.querySelector( '.aafm-count-num' );
					if ( num ) {
						num.textContent = '0';
					}
					const wrap = document.querySelector( '#aafm-log-table-wrap' );
					if ( wrap ) {
						wrap.dataset.page = '1';
						wrap.dataset.totalPages = '1';
						this.#updatePager( 1, 1 );
					}
				}
			} );
		}

		/**
		 * Keep the "Export CSV" link's filter query arg in step with the currently selected
		 * status filter, so a click always exports what the operator is looking at rather than
		 * whatever filter happened to be active on first page load.
		 *
		 * @param {string} filter One of all|started|success|error|denied.
		 */
		#syncExportLinkFilter( filter ) {
			const link = document.querySelector( '#aafm-export-log' );
			if ( ! link ) {
				return;
			}
			const url = new URL( link.href, window.location.origin );
			url.searchParams.set( 'filter', filter );
			link.href = url.toString();
		}

		/**
		 * Wire the activity log's status filter (segmented buttons) and Prev/Next pager.
		 *
		 * Both are server-side: only one page of rows is ever in the DOM, so a filter or a
		 * page change re-queries the aafm_get_log_page action and re-renders the tbody. The
		 * table wrapper (#aafm-log-table-wrap) holds the current page/filter/total-pages as
		 * data-* state so this stays the single source of truth. Rows are built with the DOM
		 * (textContent only), never innerHTML, so the response is never an HTML sink.
		 */
		#bindLogPaginationAndFilters() {
			const wrap = document.querySelector( '#aafm-log-table-wrap' );
			if ( ! wrap ) {
				return;
			}
			const segButtons = document.querySelectorAll(
				'.aafm-activity .aafm-seg-btn[data-filter]'
			);
			const prev = document.querySelector( '.aafm-pager-prev' );
			const next = document.querySelector( '.aafm-pager-next' );

			// In-flight guard. Rapid Next/filter clicks fire overlapping requests whose
			// responses can arrive out of order; a stale page would then clobber the newest.
			// Each load() bumps a token and only the request holding the latest token is
			// allowed to render - older responses are dropped.
			let loadToken = 0;

			// Light the segmented button matching `filter` and dim the rest. Called only from
			// inside load() - once on success, and once on failure to snap the buttons back to
			// the last filter that actually loaded - so the active button can never disagree
			// with what the table and the export link are actually showing.
			const setActiveFilterButton = ( filter ) => {
				segButtons.forEach( ( b ) => {
					const on = b.dataset.filter === filter;
					b.classList.toggle( 'is-active', on );
					b.classList.toggle( 'on', on );
					b.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
				} );
			};

			const load = async ( page, filter ) => {
				const token = ++loadToken;
				const pagerStatus = document.querySelector( '.aafm-pager-status' );
				if ( pagerStatus ) {
					pagerStatus.textContent = this.#t( 'loadingPage', 'Loading…' );
				}
				const json = await this.#post( 'aafm_get_log_page', {
					page,
					filter,
				} );
				// A newer load() started while this one was in flight: discard this result.
				if ( token !== loadToken ) {
					return;
				}
				if ( ! json?.success ) {
					// The request failed: leave the rows and export link on the last filter that
					// actually loaded, and snap the segmented buttons back to agree with it.
					setActiveFilterButton( wrap.dataset.filter ?? 'all' );
					this.#updatePager(
						Number( wrap.dataset.page ) || 1,
						Number( wrap.dataset.totalPages ) || 1
					);
					return;
				}
				const data = json.data ?? {};
				this.#renderLogRows( Array.isArray( data.rows ) ? data.rows : [] );
				wrap.dataset.page = String( data.page ?? 1 );
				wrap.dataset.filter = String( data.filter ?? filter );
				wrap.dataset.totalPages = String( data.total_pages ?? 1 );
				setActiveFilterButton( wrap.dataset.filter );
				this.#updatePager(
					Number( data.page ) || 1,
					Number( data.total_pages ) || 1
				);
				const num = document.querySelector( '.aafm-count-num' );
				if ( num && typeof data.total === 'number' ) {
					num.textContent = new Intl.NumberFormat().format( data.total );
				}
				this.#syncExportLinkFilter( wrap.dataset.filter );
			};

			segButtons.forEach( ( btn ) => {
				btn.addEventListener( 'click', () => {
					// A filter change always restarts at page 1. The active button itself is set
					// by load() once the request resolves, not here - a failed request must never
					// leave the clicked filter looking active while the data underneath it is
					// still the old one.
					load( 1, btn.dataset.filter ?? 'all' );
				} );
			} );

			if ( prev ) {
				prev.addEventListener( 'click', () => {
					const page = ( Number( wrap.dataset.page ) || 1 ) - 1;
					if ( page >= 1 ) {
						load( page, wrap.dataset.filter ?? 'all' );
					}
				} );
			}
			if ( next ) {
				next.addEventListener( 'click', () => {
					const page = ( Number( wrap.dataset.page ) || 1 ) + 1;
					const totalPages = Number( wrap.dataset.totalPages ) || 1;
					if ( page <= totalPages ) {
						load( page, wrap.dataset.filter ?? 'all' );
					}
				} );
			}
		}

		/**
		 * Build the Detail column's <td>, linking the row's identifier when the server resolved
		 * one.
		 *
		 * `row.detail_link` (when present) carries the before/id/url/after parts already split
		 * server-side, so this never concatenates untrusted text into an HTML string: the anchor
		 * is a real DOM node, its href is assigned as a property (not parsed as markup), and every
		 * text fragment goes through textContent / createTextNode. No innerHTML anywhere in this
		 * path.
		 *
		 * @param {Object} row Row object from the aafm_get_log_page response.
		 * @return {HTMLTableCellElement}
		 */
		#buildDetailCell( row ) {
			const td = document.createElement( 'td' );
			const link = row.detail_link;
			if ( ! link || typeof link !== 'object' ) {
				td.textContent = row.detail ?? '';
				return td;
			}
			td.append( document.createTextNode( link.before ?? '' ) );
			const anchor = document.createElement( 'a' );
			anchor.href = link.url ?? '';
			anchor.textContent = `#${ link.id ?? '' }`;
			td.append( anchor );
			td.append( document.createTextNode( link.after ?? '' ) );
			return td;
		}

		/**
		 * Replace the activity table body with a page of rows, built cell-by-cell with
		 * textContent (never innerHTML). An empty set renders a single "no activity" row.
		 *
		 * @param {Array<Object>} rows Row objects from the aafm_get_log_page response.
		 */
		#renderLogRows( rows ) {
			const tbody = document.querySelector( '.aafm-log-table tbody' );
			if ( ! tbody ) {
				return;
			}
			tbody.replaceChildren();

			if ( ! rows.length ) {
				const tr = document.createElement( 'tr' );
				const td = document.createElement( 'td' );
				td.colSpan = 6;
				td.textContent = this.#t( 'noActivity', 'No activity recorded yet.' );
				tr.append( td );
				tbody.append( tr );
				return;
			}

			rows.forEach( ( row ) => {
				const tr = document.createElement( 'tr' );

				const cell = ( text ) => {
					const td = document.createElement( 'td' );
					td.textContent = text ?? '';
					return td;
				};

				tr.append( cell( row.time ) );
				tr.append( cell( row.principal ) );
				tr.append( cell( row.ability ) );
				tr.append( this.#buildDetailCell( row ) );

				const statusTd = document.createElement( 'td' );
				const pill = document.createElement( 'span' );
				const variant = String( row.variant ?? 'neutral' );
				const status = String( row.status ?? '' );
				pill.className = `aafm-pill aafm-pill-${ variant } aafm-status aafm-status-${ status }`;
				pill.textContent = status;
				statusTd.append( pill );
				tr.append( statusTd );

				tr.append( cell( row.arg_keys ) );
				tbody.append( tr );
			} );
		}

		/**
		 * Update the pager label and enable/disable Prev/Next for the current page.
		 *
		 * @param {number} page       Current 1-based page.
		 * @param {number} totalPages Total number of pages (at least 1).
		 */
		#updatePager( page, totalPages ) {
			const label = document.querySelector( '.aafm-pager-status' );
			if ( label ) {
				const fmt = new Intl.NumberFormat();
				label.textContent = this.#format(
					this.#t( 'pagerStatus', 'Page %1$s of %2$s' ),
					fmt.format( page ),
					fmt.format( totalPages )
				);
			}
			const prev = document.querySelector( '.aafm-pager-prev' );
			const next = document.querySelector( '.aafm-pager-next' );
			if ( prev ) {
				prev.disabled = page <= 1;
			}
			if ( next ) {
				next.disabled = page >= totalPages;
			}
		}

		/**
		 * Wire the OAuth management tables' Revoke buttons (Registered clients +
		 * Active grants). Clicks are delegated off the .aafm-oauth-manage container so
		 * a single listener covers both tables. Each revoke confirms first, then POSTs
		 * the nonce-checked AJAX action; on success the row is updated in place - the
		 * client's Status pill flips to Revoked and its button is removed, and a grant
		 * row is removed entirely. Every DOM change is textContent / class / attribute
		 * only, never innerHTML, so the response is never an HTML sink.
		 */
		#bindOauthRevoke() {
			const root = document.querySelector( '.aafm-oauth-manage' );
			if ( ! root ) {
				return;
			}
			root.addEventListener( 'click', async ( e ) => {
				const clientBtn = e.target.closest( '.aafm-revoke-client' );
				const grantBtn = e.target.closest( '.aafm-revoke-grant' );
				const btn = clientBtn ?? grantBtn;
				if ( ! btn || ! root.contains( btn ) ) {
					return;
				}

				const isGrant = Boolean( grantBtn );
				const confirmMsg = isGrant
					? this.#t(
							'revokeGrantConfirm',
							'Revoke this grant? The user will have to approve again to reconnect.'
					  )
					: this.#t(
							'revokeClientConfirm',
							'Revoke this client? It is turned off and its active sessions end right away.'
					  );
				if ( ! window.confirm( confirmMsg ) ) {
					return;
				}

				const clientId = btn.dataset.clientId ?? '';
				btn.disabled = true;

				let json;
				if ( isGrant ) {
					json = await this.#post( 'aafm_oauth_revoke_grant', {
						user_id: btn.dataset.userId ?? '',
						client_id: clientId,
					} );
				} else {
					json = await this.#post( 'aafm_oauth_revoke_client', {
						client_id: clientId,
					} );
				}

				if ( ! json?.success ) {
					btn.disabled = false;
					window.alert(
						json?.data?.message ??
							this.#t( 'revokeFailed', 'Could not revoke. Please try again.' )
					);
					return;
				}

				const row = btn.closest( 'tr' );
				if ( ! row ) {
					return;
				}
				const revoked = Number( json?.data?.revoked_tokens ) || 0;
				if ( isGrant ) {
					// The grant is gone: drop its row, and lower the owning client's active-token
					// count by the tokens we just revoked so it does not read stale.
					this.#adjustClientTokens( root, clientId, -revoked );
					row.remove();
				} else {
					// The client and all its tokens are revoked: its active-token count is now 0.
					const tokensCell = row.querySelector( '.aafm-client-tokens' );
					if ( tokensCell ) {
						tokensCell.textContent = '0';
					}
					// Flip the Status pill to Revoked and replace the button with plain text.
					const pill = row.querySelector( '.aafm-status-cell .aafm-pill' );
					if ( pill ) {
						pill.classList.remove( 'aafm-pill-success' );
						pill.classList.add( 'aafm-pill-neutral' );
						pill.textContent = this.#t( 'statusRevoked', 'Revoked' );
					}
					const cell = btn.parentElement;
					btn.remove();
					if ( cell ) {
						const note = document.createElement( 'span' );
						note.className = 'aafm-muted';
						note.textContent = this.#t( 'statusRevoked', 'Revoked' );
						cell.append( note );
					}
				}
			} );
		}

		/**
		 * Client-side pager for one OAuth management table (Registered clients or Active
		 * grants). Both queries already run with no LIMIT and every row is already in the
		 * DOM (aafm_oauth_list_clients()/aafm_oauth_list_grants()), so this only toggles
		 * `hidden` on a rows-per-page slice - no second query, no AJAX round trip.
		 *
		 * A MutationObserver on the <tbody> re-derives the page count whenever a row is
		 * removed, rather than the click handlers being the only thing that can trigger a
		 * recompute: #bindOauthRevoke() calls row.remove() for a revoked grant with no
		 * knowledge of pagination at all, and the observer means it does not need any. If
		 * removing a row empties the current page (the last row on the last page), the
		 * observer steps the page back and re-renders, so the pager never goes on showing a
		 * page number or a Prev/Next state the live row set no longer has.
		 *
		 * @param {HTMLTableElement} table   The table to paginate.
		 * @param {number}           perPage Rows per page.
		 */
		#paginateTable( table, perPage ) {
			const tbody = table.querySelector( 'tbody' );
			if ( ! tbody ) {
				return;
			}
			const anchor = table.closest( '.aafm-table-wrap' ) ?? table;

			const pager = document.createElement( 'div' );
			pager.className = 'aafm-pager';

			const count = document.createElement( 'span' );
			count.className = 'aafm-pager-status aafm-oauth-pager-count';

			const prev = document.createElement( 'button' );
			prev.type = 'button';
			prev.className = 'aafm-btn aafm-btn-secondary aafm-btn-sm';
			prev.textContent = this.#t( 'pagerPrevious', 'Previous' );

			const status = document.createElement( 'span' );
			status.className = 'aafm-pager-status';
			status.setAttribute( 'aria-live', 'polite' );

			const next = document.createElement( 'button' );
			next.type = 'button';
			next.className = 'aafm-btn aafm-btn-secondary aafm-btn-sm';
			next.textContent = this.#t( 'pagerNext', 'Next' );

			pager.append( count, prev, status, next );
			anchor.after( pager );

			const fmt = new Intl.NumberFormat();
			let page = 1;
			let totalPages = 1;

			const render = () => {
				const rows = Array.from( tbody.rows );
				const total = rows.length;
				totalPages = Math.max( 1, Math.ceil( total / perPage ) );
				if ( page > totalPages ) {
					page = totalPages;
				}

				// A table that already fits on one page gets no pager at all.
				pager.hidden = total <= perPage;

				rows.forEach( ( row, i ) => {
					row.hidden = Math.floor( i / perPage ) !== page - 1;
				} );

				const start = 0 === total ? 0 : ( page - 1 ) * perPage + 1;
				const end = Math.min( page * perPage, total );
				count.textContent = this.#format(
					this.#t( 'oauthPagerCount', 'Showing %1$s-%2$s of %3$s' ),
					fmt.format( start ),
					fmt.format( end ),
					fmt.format( total )
				);
				status.textContent = this.#format(
					this.#t( 'pagerStatus', 'Page %1$s of %2$s' ),
					fmt.format( page ),
					fmt.format( totalPages )
				);
				prev.disabled = page <= 1;
				next.disabled = page >= totalPages;
			};

			prev.addEventListener( 'click', () => {
				if ( page > 1 ) {
					page -= 1;
					render();
				}
			} );
			next.addEventListener( 'click', () => {
				if ( page < totalPages ) {
					page += 1;
					render();
				}
			} );

			new MutationObserver( render ).observe( tbody, { childList: true } );

			render();
		}

		/**
		 * Paginate the Connection tab's two OAuth tables, ten rows per page. Two
		 * independent pagers over two unrelated datasets - Registered clients never
		 * shares a page count with Active grants.
		 */
		#bindOauthPagination() {
			const PER_PAGE = 10;
			const clientsTable = document.querySelector( '.aafm-clients-table' );
			const grantsTable = document.querySelector( '.aafm-grants-table' );
			if ( clientsTable ) {
				this.#paginateTable( clientsTable, PER_PAGE );
			}
			if ( grantsTable ) {
				this.#paginateTable( grantsTable, PER_PAGE );
			}
		}

		/**
		 * Wire the Registered-clients table's per-row "Agent" toggle: on change, POST the
		 * nonce-checked AJAX action that flags/unflags that client as an agent identity. On
		 * failure the checkbox reverts to its prior state so the UI never shows a state the
		 * server did not actually persist.
		 */
		#bindClientAgentToggle() {
			const root = document.querySelector( '.aafm-oauth-manage' );
			if ( ! root ) {
				return;
			}
			root.addEventListener( 'change', async ( e ) => {
				const toggle = e.target.closest( '.aafm-client-agent-toggle' );
				if ( ! toggle || ! root.contains( toggle ) ) {
					return;
				}

				const clientId = toggle.dataset.clientId ?? '';
				const desired = toggle.checked;
				toggle.disabled = true;

				const json = await this.#post( 'aafm_set_client_agent_identity', {
					client_id: clientId,
					is_agent_identity: desired ? '1' : '0',
				} );

				toggle.disabled = false;

				if ( ! json?.success ) {
					toggle.checked = ! desired;
					window.alert(
						json?.data?.message ??
							this.#t( 'agentToggleFailed', 'Could not save. Please try again.' )
					);
				}
			} );
		}

		/**
		 * The abilities catalog the allowlist picker renders from, grouped by subject exactly the
		 * way aafm_allowlist_ability_catalog() (PHP) built it - the same source
		 * aafm_allowlist_sanitize_row() validates a save against, so a picker built from this can
		 * never offer a name the server would refuse.
		 *
		 * @return {Array<{subject: string, label: string, abilities: Array<{name: string, label: string}>}>}
		 */
		#allowlistCatalog() {
			return Array.isArray( aafmAdmin?.allowlistCatalog ) ? aafmAdmin.allowlistCatalog : [];
		}

		/**
		 * Build one allowlist row's ability picker: an "All abilities" checkbox, and - visible only
		 * while that checkbox is unchecked - a plain selected-count label, a warning for the
		 * zero-selected state, a search field, and the searchable/subject-grouped checkbox list
		 * itself. Built entirely with DOM APIs (createElement/textContent/value/checked), never
		 * innerHTML, matching this file's existing convention for every dynamically-created
		 * allowlist cell.
		 *
		 * The list used to sit behind a collapsed `<details>` an operator had to notice and click.
		 * Verified working (three ticks correctly produced "3 selected"), but the operator could not
		 * find it - a grey "Choose abilities 0 selected" under a bold "All abilities" label read as
		 * disabled helper text. A chevron would only have signposted the hidden thing; the fix is to
		 * not hide it: unchecking "All abilities" IS the request to narrow, so the list it would
		 * narrow just appears there, no click, no disclosure to discover.
		 *
		 * "All" and an individual selection are mutually exclusive in the STORED shape (the server
		 * accepts only the literal string "all" or an array), so checking "All" here only hides the
		 * rest of the picker - it does not clear any ticked box - and #serializeAllowlistRow() below
		 * reports "all" whenever the toggle is checked, ignoring whatever the individual boxes show
		 * underneath. Unchecking "All" again shows exactly the selection that was there before, with
		 * nothing to re-open.
		 *
		 * @param {'all'|Array<string>} allowed Initial state: "all", or the array of allowed names.
		 * @return {HTMLElement} The `.aafm-allowlist-picker` root, ready to append to a cell.
		 */
		#buildAllowlistPicker( allowed ) {
			const isAll = 'all' === allowed;
			const names = new Set( isAll ? [] : allowed );

			const picker = document.createElement( 'div' );
			picker.className = 'aafm-allowlist-picker';

			const allLabel = document.createElement( 'label' );
			allLabel.className = 'aafm-allowlist-all';
			const allToggle = document.createElement( 'input' );
			allToggle.type = 'checkbox';
			allToggle.className = 'aafm-allowlist-all-toggle';
			allToggle.checked = isAll;
			allLabel.append( allToggle, document.createTextNode( ' ' + this.#t( 'allowlistAll', 'All abilities (no narrowing)' ) ) );

			// Everything below is what "All abilities" narrows - hidden while there is genuinely
			// nothing to choose (All is checked), visible with no further click the moment it isn't.
			const bodyEl = document.createElement( 'div' );
			bodyEl.className = 'aafm-allowlist-picker-body';
			bodyEl.hidden = isAll;

			const count = document.createElement( 'p' );
			count.className = 'aafm-allowlist-picker-count aafm-muted';

			// aafm_ability_allowed_for_principal() (includes/allowlist.php) fails a role or client
			// closed against EVERY ability when its allowed set is a non-"all" empty array - correct,
			// fail-closed behaviour that must not change, but nothing in the UI used to say so before
			// a save. Warn plainly instead; this is advisory, never a block, since locking a scope out
			// entirely can be exactly what the operator wants.
			const warning = document.createElement( 'p' );
			warning.className = 'aafm-notice aafm-notice-warning aafm-notice-inline aafm-allowlist-warning';
			warning.textContent = this.#t(
				'allowlistZeroSelected',
				'No abilities selected. Saving now will block this scope from every ability.'
			);
			warning.hidden = true;

			const searchInput = document.createElement( 'input' );
			searchInput.type = 'search';
			searchInput.className = 'aafm-allowlist-picker-search aafm-integration-search';
			searchInput.placeholder = this.#t( 'allowlistSearch', 'Search abilities…' );
			searchInput.autocomplete = 'off';

			const groupsEl = document.createElement( 'div' );
			groupsEl.className = 'aafm-allowlist-picker-groups';

			const updateCount = () => {
				const checked = groupsEl.querySelectorAll( '.aafm-allowlist-ability:checked' ).length;
				count.textContent = this.#format( this.#t( 'allowlistSelectedCount', '%s selected' ), checked );
				warning.hidden = 0 !== checked;
			};

			this.#allowlistCatalog().forEach( ( group ) => {
				const fieldset = document.createElement( 'fieldset' );
				fieldset.className = 'aafm-allowlist-group';
				fieldset.dataset.subject = group.subject;
				const legend = document.createElement( 'legend' );
				legend.textContent = group.label;
				fieldset.append( legend );

				( group.abilities ?? [] ).forEach( ( ability ) => {
					const item = document.createElement( 'label' );
					item.className = 'aafm-allowlist-item';
					const box = document.createElement( 'input' );
					box.type = 'checkbox';
					box.className = 'aafm-allowlist-ability';
					box.value = ability.name;
					box.checked = names.has( ability.name );
					box.addEventListener( 'change', updateCount );
					item.append( box, document.createTextNode( ' ' + ability.label ) );
					fieldset.append( item );
				} );

				groupsEl.append( fieldset );
			} );

			// Per-picker search: filters this row's own list only, the same substring-of-textContent
			// match the Abilities tab search uses, hiding an emptied group's legend along with it.
			searchInput.addEventListener( 'input', () => {
				const query = searchInput.value.trim().toLowerCase();
				groupsEl.querySelectorAll( '.aafm-allowlist-group' ).forEach( ( fieldset ) => {
					let visible = 0;
					fieldset.querySelectorAll( '.aafm-allowlist-item' ).forEach( ( item ) => {
						const isMatch = '' === query || item.textContent.toLowerCase().includes( query );
						item.hidden = ! isMatch;
						if ( isMatch ) {
							visible += 1;
						}
					} );
					fieldset.hidden = 0 === visible;
				} );
			} );

			// Hiding the body is the whole mechanism now - a hidden checkbox is neither focusable
			// nor clickable, so there is no need to also disable it, and leaving it enabled keeps its
			// checked state intact for when "All" is unchecked again.
			allToggle.addEventListener( 'change', () => {
				bodyEl.hidden = allToggle.checked;
			} );

			bodyEl.append( count, warning, searchInput, groupsEl );
			picker.append( allLabel, bodyEl );
			updateCount();

			return picker;
		}

		/**
		 * Read one allowlist row's picker back into the shape the server expects: the literal
		 * string "all", or the array of checked ability names.
		 *
		 * @param {HTMLElement} row A `[data-allowlist-row]` element.
		 * @return {'all'|Array<string>}
		 */
		#serializeAllowlistRow( row ) {
			const allToggle = row.querySelector( '.aafm-allowlist-all-toggle' );
			if ( allToggle?.checked ) {
				return 'all';
			}
			return Array.from( row.querySelectorAll( '.aafm-allowlist-ability:checked' ) ).map(
				( box ) => box.value
			);
		}

		/**
		 * Hydrate one server-rendered "Allowed abilities" cell: read its `data-allowed` (the literal
		 * "all", or a JSON array of names - aafm_render_allowlist_section()'s data shell) and swap
		 * the no-JS text summary for the interactive picker built from the same state.
		 *
		 * @param {HTMLElement} cell A `.aafm-allowlist-allowed-cell`.
		 */
		#hydrateAllowlistCell( cell ) {
			let allowed = 'all';
			try {
				const parsed = JSON.parse( cell.dataset.allowed ?? '"all"' );
				allowed = 'all' === parsed || Array.isArray( parsed ) ? parsed : 'all';
			} catch {
				allowed = 'all';
			}
			cell.replaceChildren( this.#buildAllowlistPicker( allowed ) );
		}

		/**
		 * Wire the Connections tab's "Ability allowlist" card: add a scope row, remove a row,
		 * and save the whole set as one AJAX call. Every dynamically-created cell is built with
		 * DOM APIs (createElement/textContent/value), never innerHTML with interpolated input,
		 * so no separate escaping helper is needed for the values this card handles.
		 *
		 * The zero-row state renders a plain .aafm-empty-state paragraph instead of a table with
		 * an empty <tbody>, so #aafm-allowlist-table does not exist until the first row lands.
		 * Bind against the card itself (always rendered) rather than the table, delegate the
		 * remove click to the card so it still works on a table built after bind time, and build
		 * the table the first time "Add scope" needs somewhere to put a row.
		 */
		#bindAllowlist() {
			const card = document.querySelector( '.aafm-allowlist-card' );
			const saveBtn = document.getElementById( 'aafm-allowlist-save' );
			const addBtn = document.getElementById( 'aafm-allowlist-add-row' );
			if ( ! card || ! saveBtn || ! addBtn ) {
				return;
			}
			const status = document.getElementById( 'aafm-allowlist-status' );

			// Every row the server rendered starts as a data shell (a `data-allowed` JSON
			// attribute plus a plain-text summary) - swap each one for the interactive picker now.
			card.querySelectorAll( '.aafm-allowlist-allowed-cell' ).forEach( ( cell ) => {
				this.#hydrateAllowlistCell( cell );
			} );

			const allowlistBody = () => document.getElementById( 'aafm-allowlist-table' )?.querySelector( 'tbody' ) ?? null;

			// Get the <tbody> to append a new row to, building the table wrap first if this is
			// the first row added since page load.
			const ensureAllowlistBody = () => {
				const existing = allowlistBody();
				if ( existing ) {
					return existing;
				}

				document.getElementById( 'aafm-allowlist-empty' )?.remove();

				const wrap = document.createElement( 'div' );
				wrap.className = 'aafm-table-wrap';
				wrap.id = 'aafm-allowlist-table-wrap';

				const table = document.createElement( 'table' );
				table.className = 'widefat striped aafm-oauth-table aafm-allowlist-table';
				table.id = 'aafm-allowlist-table';

				const thead = document.createElement( 'thead' );
				const headRow = document.createElement( 'tr' );
				[
					this.#t( 'allowlistScope', 'Scope' ),
					this.#t( 'allowlistAllowedHeading', 'Allowed abilities' ),
					'',
				].forEach( ( text ) => {
					const th = document.createElement( 'th' );
					th.textContent = text;
					headRow.append( th );
				} );
				thead.append( headRow );

				const tbody = document.createElement( 'tbody' );
				table.append( thead, tbody );
				wrap.append( table );

				document.getElementById( 'aafm-allowlist-add' )?.before( wrap );

				return tbody;
			};

			addBtn.addEventListener( 'click', () => {
				const typeSelect = document.getElementById( 'aafm-allowlist-new-scope-type' );
				const idInput = document.getElementById( 'aafm-allowlist-new-scope-id' );
				const scopeId = idInput?.value.trim() ?? '';
				if ( ! scopeId ) {
					idInput?.focus();
					return;
				}

				const row = document.createElement( 'tr' );
				row.dataset.allowlistRow = '';
				row.dataset.scopeType = typeSelect?.value ?? 'role';
				row.dataset.scopeId = scopeId;

				const labelCell = document.createElement( 'td' );
				labelCell.textContent =
					( typeSelect?.value ?? 'role' ) === 'role' ? `Role: ${ scopeId }` : `Connection: ${ scopeId }`;

				const allowedCell = document.createElement( 'td' );
				allowedCell.className = 'aafm-allowlist-allowed-cell';
				// Unrestricted until the operator narrows it - never starts as "deny everything".
				allowedCell.append( this.#buildAllowlistPicker( 'all' ) );

				const removeCell = document.createElement( 'td' );
				const removeBtn = document.createElement( 'button' );
				removeBtn.type = 'button';
				removeBtn.className = 'aafm-btn aafm-btn-secondary aafm-allowlist-remove';
				removeBtn.textContent = this.#t( 'allowlistRemove', 'Remove' );
				removeCell.append( removeBtn );

				row.append( labelCell, allowedCell, removeCell );
				ensureAllowlistBody().append( row );
				if ( idInput ) {
					idInput.value = '';
				}
			} );

			// Delegated on the card (always present) rather than the table (not present until
			// the first row exists), so a remove button works whether its row came from the
			// server or from a later "Add scope" click.
			card.addEventListener( 'click', ( e ) => {
				const btn = e.target.closest( '.aafm-allowlist-remove' );
				if ( btn ) {
					btn.closest( 'tr' )?.remove();
				}
			} );

			saveBtn.addEventListener( 'click', async () => {
				const body = allowlistBody();
				const rows = Array.from( body?.querySelectorAll( '[data-allowlist-row]' ) ?? [] ).map( ( row ) => ( {
					scope_type: row.dataset.scopeType,
					scope_id: row.dataset.scopeId,
					allowed_abilities: this.#serializeAllowlistRow( row ),
				} ) );

				saveBtn.disabled = true;
				const json = await this.#post( 'aafm_save_allowlist', {
					allowlist_json: JSON.stringify( rows ),
				} );
				saveBtn.disabled = false;

				if ( json?.success ) {
					// A row naming an unknown role or OAuth client now rejects the WHOLE save
					// server-side (see aafm_ajax_save_allowlist()'s own comment), so a successful
					// response never carries a row like that - but two DOM rows can still share
					// the same scope (the operator added the same role or client twice), and the
					// server canonicalizes that down to whichever row was submitted LAST. Track
					// the last DOM row seen for each key so every earlier duplicate is removed,
					// matching what was actually stored.
					const keptKeys = new Set(
						( Array.isArray( json.data?.rows ) ? json.data.rows : [] ).map(
							( r ) => `${ r.scope_type }:${ r.scope_id }`
						)
					);
					const domRows = Array.from( body?.querySelectorAll( '[data-allowlist-row]' ) ?? [] );
					const lastRowForKey = new Map();
					domRows.forEach( ( row ) => {
						lastRowForKey.set( `${ row.dataset.scopeType }:${ row.dataset.scopeId }`, row );
					} );
					domRows.forEach( ( row ) => {
						const key = `${ row.dataset.scopeType }:${ row.dataset.scopeId }`;
						if ( ! keptKeys.has( key ) || lastRowForKey.get( key ) !== row ) {
							row.remove();
						}
					} );

					if ( status ) {
						status.textContent = this.#t( 'allowlistSaved', 'Saved.' );
					}
				} else if ( status ) {
					// A row naming an unknown role or OAuth client rejects the whole save with a
					// row-specific message (aafm_ajax_save_allowlist()) - surfaced here verbatim
					// rather than a generic "Saved" the operator would have to disbelieve.
					status.textContent =
						json?.data?.message ?? this.#t( 'allowlistSaveFailed', 'Could not save. Please try again.' );
				}
			} );
		}

		// Adjust a client's "Active tokens" cell in place after a revoke, so the count stays
		// truthful without a page reload. Matches the row by dataset (no selector injection).
		#adjustClientTokens( root, clientId, delta ) {
			if ( ! clientId || ! delta ) {
				return;
			}
			const clientRow = Array.from(
				root.querySelectorAll( '[data-client-row]' )
			).find( ( el ) => el.dataset.clientRow === clientId );
			const cell = clientRow?.querySelector( '.aafm-client-tokens' );
			if ( ! cell ) {
				return;
			}
			const current =
				parseInt( ( cell.textContent || '' ).replace( /[^0-9]/g, '' ), 10 ) || 0;
			cell.textContent = String( Math.max( 0, current + delta ) );
		}

		#bindResetPlugin() {
			const btn = document.querySelector( '#aafm-reset-plugin' );
			if ( ! btn ) {
				return;
			}
			const status = document.querySelector( '.aafm-reset-status' );
			btn.addEventListener( 'click', async () => {
				// Destructive + irreversible: require an explicit confirmation first.
				if ( ! window.confirm( this.#t( 'resetConfirm', 'Reset the plugin to defaults? This cannot be undone.' ) ) ) {
					return;
				}
				btn.disabled = true;
				if ( status ) {
					status.textContent = this.#t( 'resetWorking', 'Resetting…' );
				}
				const json = await this.#post( 'aafm_reset_plugin' );
				if ( json?.success ) {
					if ( status ) {
						status.textContent = this.#t( 'resetDone', 'Reset. Reloading…' );
					}
					// Reload so every tab reflects the wiped configuration.
					window.location.reload();
					return;
				}
				if ( status ) {
					status.textContent = json?.data?.message ?? this.#t( 'resetFailed', 'Reset failed.' );
				}
				btn.disabled = false;
			} );
		}

		/**
		 * Drive the first-run Quick Connect wizard: step progression, the OAuth toggle and its
		 * live/inert endpoint field, the app-password path, the write toggle, and finish/dismiss.
		 *
		 * The wizard markup is rendered server-side only when setup is due, so this returns early
		 * when the modal is absent. OAuth is written to the option solely on the explicit
		 * "Continue" click past the connection step (aafm_quickconnect_oauth), never on load.
		 */
		#bindQuickConnect() {
			const root = document.querySelector( '#aafm-qc' );
			if ( ! root ) {
				return;
			}

			const reduce = window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
			const state = { step: 1, oauth: true, write: false, method: 'oauth' };

			const modal = root.querySelector( '.aafm-qc-modal' );
			const qcTitle = root.querySelector( '#aafm-qc-title' );
			const { isOpen, landFocus } = this.#quickConnectFocus( root, modal );

			const jobs = {
				1: root.querySelector( '[data-qc-job="1"]' ),
				2: root.querySelector( '[data-qc-job="2"]' ),
				3: root.querySelector( '[data-qc-job="3"]' ),
			};
			const segs = Array.from( root.querySelectorAll( '[data-qc-seg]' ) );
			const stepNow = root.querySelector( '[data-qc-stepnow]' );
			const pct = root.querySelector( '[data-qc-pct]' );

			const setTag = ( job, kind, text ) => {
				const tag = job.querySelector( '[data-qc-tag]' );
				if ( tag ) {
					tag.className = 'aafm-qc-tag ' + kind;
					tag.textContent = text;
				}
			};

			const render = () => {
				[ 1, 2, 3 ].forEach( ( i ) => {
					const job = jobs[ i ];
					// A collapsed step is zero-height but still laid out, so without inert
					// its controls stay tabbable and in the accessibility tree: a keyboard
					// user tabbing through step 1 could reach - and fire - "Finish setup".
					// inert takes the whole subtree out of both. Browsers without it behave
					// as the wizard did before, so this can only improve matters.
					// Driven as an attribute, not the property, so the markup and the focus
					// trap's [inert] filter agree even on an engine that does not implement it.
					job.querySelector( '.aafm-qc-job-body' )?.toggleAttribute( 'inert', i !== state.step );
					job.classList.remove( 'is-current', 'is-done', 'is-todo' );
					if ( i < state.step ) {
						job.classList.add( 'is-done' );
						setTag( job, 'done', this.#t( 'saved', 'Saved' ) );
					} else if ( i === state.step ) {
						job.classList.add( 'is-current' );
						setTag( job, 'now', this.#t( 'qcInProgress', 'In progress' ) );
					} else {
						job.classList.add( 'is-todo' );
						setTag( job, 'todo', this.#t( 'qcNotStarted', 'Not started' ) );
					}
				} );
				segs.forEach( ( seg, idx ) => {
					seg.classList.remove( 'is-fill', 'is-current' );
					const n = idx + 1;
					if ( n < state.step ) {
						seg.classList.add( 'is-fill' );
					} else if ( n === state.step ) {
						seg.classList.add( 'is-current' );
					}
				} );
				if ( stepNow ) {
					stepNow.textContent = String( state.step );
				}
				if ( pct ) {
					pct.textContent = Math.round( ( ( state.step - 1 ) / 3 ) * 100 ) + '%';
				}
				const active = jobs[ state.step ];
				if ( active ) {
					active.scrollIntoView( { behavior: reduce ? 'auto' : 'smooth', block: 'nearest' } );
				}
			};

			// Advancing collapses the step the user was standing in, and the control they just
			// pressed goes inert with it, which would drop focus on <body> and strand the
			// keyboard outside the dialog. Move focus to the new step's title instead: it
			// names where they have landed, and there is nothing there to fire by accident.
			const focusStep = () => {
				const heading = jobs[ state.step ]?.querySelector( '.aafm-qc-job-titles .jt' );
				if ( heading ) {
					heading.setAttribute( 'tabindex', '-1' );
					heading.focus();
				}
			};

			const goStep = ( n ) => {
				state.step = Math.max( 1, Math.min( 3, n ) );
				render();
				focusStep();
			};

			// Re-open a completed step by clicking its header.
			[ 1, 2, 3 ].forEach( ( i ) => {
				jobs[ i ].querySelector( '.aafm-qc-job-head' ).addEventListener( 'click', () => {
					if ( jobs[ i ].classList.contains( 'is-done' ) ) {
						goStep( i );
					}
				} );
			} );

			// ---- Job 1: OAuth toggle + endpoint field ----
			const oauth = root.querySelector( '[data-qc-oauth]' );
			const urlField = root.querySelector( '[data-qc-urlfield]' );
			const urlCopy = urlField.querySelector( '.aafm-qc-ucopy' );
			const nextline = root.querySelector( '[data-qc-nextline]' );
			const hint = root.querySelector( '[data-qc-hint]' );

			const applyOauth = () => {
				state.oauth = oauth.checked;
				if ( oauth.checked ) {
					urlField.classList.add( 'is-live' );
					urlField.classList.remove( 'is-inert' );
					urlCopy.disabled = false;
					nextline.classList.remove( 'is-hidden' );
					state.method = 'oauth';
					if ( hint ) {
						hint.textContent = this.#t( 'qcOauthOn', 'OAuth is on. Copy the URL, then continue.' );
					}
				} else {
					urlField.classList.remove( 'is-live' );
					urlField.classList.add( 'is-inert' );
					urlCopy.disabled = true;
					nextline.classList.add( 'is-hidden' );
					state.method = 'apppw';
					if ( hint ) {
						hint.textContent = this.#t( 'qcOauthOff', 'OAuth is off. Use the application-password path below, or turn OAuth on.' );
					}
				}
			};
			oauth.addEventListener( 'change', applyOauth );

			// ---- Job 1: app-password disclosure ----
			const alt = root.querySelector( '[data-qc-altauth]' );
			const altTrigger = root.querySelector( '[data-qc-alttrigger]' );
			const altPanel = alt.querySelector( '.aafm-qc-altpanel' );
			altTrigger.addEventListener( 'click', () => {
				const open = alt.classList.toggle( 'is-open' );
				altTrigger.setAttribute( 'aria-expanded', String( open ) );
				// Same collapse trick as the steps, same fix: the closed panel's Create-user
				// button, profile link, and copy control must not be tabbable while the
				// trigger reports aria-expanded="false".
				altPanel?.toggleAttribute( 'inert', ! open );
			} );

			// Create the dedicated agent user (reuses the real aafm_create_agent_user action,
			// which stamps the plugin marker). Mirrors #bindCreateUser's DOM-only status output.
			const createUser = root.querySelector( '#aafm-qc-create-user' );
			const userStatus = root.querySelector( '[data-qc-userstatus]' );
			const substepA = root.querySelector( '[data-qc-substep="a"]' );
			createUser.addEventListener( 'click', async () => {
				userStatus.textContent = this.#t( 'creating', 'Creating…' );
				userStatus.classList.remove( 'is-ok' );
				const json = await this.#post( 'aafm_create_agent_user', { login: 'mcp-agent' } );
				userStatus.textContent = '';
				if ( json?.success ) {
					userStatus.classList.add( 'is-ok' );
					userStatus.textContent = this.#t( 'qcUserCreated', 'mcp-agent created (subscriber)' );
					substepA.classList.add( 'is-ok' );
					createUser.disabled = true;
					state.method = state.oauth ? 'oauth' : 'apppw';
				} else {
					userStatus.textContent = json?.data?.message ?? this.#t( 'errorUnknown', 'unknown' );
					const editUrl = json?.data?.edit_url;
					if ( editUrl ) {
						const link = document.createElement( 'a' );
						link.href = editUrl;
						link.textContent = this.#t( 'editUser', 'Edit user' );
						userStatus.append( ' ', link );
						substepA.classList.add( 'is-ok' );
					}
				}
			} );

			// ---- Job 1: Continue writes OAuth (the one explicit enable point) ----
			// Only advance when the write actually succeeded, so a denied, expired-nonce, or failed
			// call surfaces an error here instead of letting Finish later report a connection that is
			// not really on. Mirrors the finish handler's inline-error pattern.
			root.querySelector( '[data-qc-next="2"]' ).addEventListener( 'click', async () => {
				const json = await this.#post( 'aafm_quickconnect_oauth', { enabled: oauth.checked ? 1 : 0 } );
				if ( ! json?.success ) {
					if ( hint ) {
						hint.textContent = json?.data?.message ?? this.#t( 'requestFailed', 'Request failed.' );
					}
					return;
				}
				goStep( 2 );
			} );

			// ---- Job 2: write toggle ----
			const write = root.querySelector( '[data-qc-write]' );
			const writeWarn = root.querySelector( '[data-qc-writewarn]' );
			write.addEventListener( 'change', () => {
				state.write = write.checked;
				writeWarn.classList.toggle( 'is-open', write.checked );
			} );
			root.querySelector( '[data-qc-next="3"]' ).addEventListener( 'click', () => goStep( 3 ) );

			// ---- Back buttons ----
			root.querySelectorAll( '[data-qc-back]' ).forEach( ( btn ) => {
				btn.addEventListener( 'click', () => goStep( Number( btn.dataset.qcBack ) ) );
			} );

			// ---- Job 3: finish ----
			const finishBtn = root.querySelector( '#aafm-qc-finish' );
			finishBtn.addEventListener( 'click', async () => {
				finishBtn.disabled = true;
				const json = await this.#post( 'aafm_quickconnect_finish', { write: state.write ? 1 : 0 } );
				if ( ! json?.success ) {
					finishBtn.disabled = false;
					if ( hint ) {
						hint.textContent = json?.data?.message ?? this.#t( 'requestFailed', 'Request failed.' );
					}
					return;
				}
				this.#quickConnectSucceed( root, state, reduce );
			} );

			// ---- Permanent opt-out ----
			root.querySelector( '#aafm-qc-dismiss' ).addEventListener( 'click', async () => {
				const json = await this.#post( 'aafm_quickconnect_dismiss' );
				if ( ! json?.success ) {
					if ( hint ) {
						hint.textContent = json?.data?.message ?? this.#t( 'requestFailed', 'Request failed.' );
					}
					return;
				}
				root.classList.add( 'is-closed' );
				landFocus();
			} );

			// ---- Go to dashboard from the success screen ----
			const goDash = root.querySelector( '#aafm-qc-godash' );
			if ( goDash ) {
				goDash.addEventListener( 'click', () => {
					window.location.href = 'admin.php?page=agent-abilities-for-mcp';
				} );
			}

			// ---- Temporary close: X, scrim, Esc. Sets no flag, so it reopens next visit. ----
			const closeTemp = () => {
				root.classList.add( 'is-closed' );
				landFocus();
			};
			root.querySelectorAll( '[data-qc-close]' ).forEach( ( el ) => {
				el.addEventListener( 'click', closeTemp );
			} );
			document.addEventListener( 'keydown', ( e ) => {
				if ( 'Escape' === e.key && isOpen() ) {
					closeTemp();
				}
			} );

			render();
			applyOauth();

			// Opening focus goes to the dialog's own title, so a screen reader starts by
			// announcing what this thing is rather than reading from wherever the page
			// happened to leave focus.
			qcTitle?.focus();
		}

		/**
		 * Keep keyboard focus inside the Quick Connect dialog while it is open, and hand it
		 * somewhere sensible when it closes.
		 *
		 * The overlay is aria-modal="true", which tells assistive tech the rest of the page
		 * does not exist, so focus has to honour that claim. The trap is scoped to the modal
		 * rather than applying `inert` to the page behind it because the overlay is rendered
		 * inside .aafm-wrap: inerting an ancestor would inert the dialog with it.
		 *
		 * @param {HTMLElement} root  The wizard root (.aafm-qc-overlay).
		 * @param {HTMLElement} modal The dialog itself (.aafm-qc-modal).
		 * @return {{isOpen: () => boolean, landFocus: () => void}} Open test + close-time focus handoff.
		 */
		#quickConnectFocus( root, modal ) {
			const FOCUSABLE = [
				'a[href]',
				'button:not([disabled])',
				'input:not([disabled])',
				'select:not([disabled])',
				'textarea:not([disabled])',
				'[tabindex]:not([tabindex="-1"])',
			].join( ',' );

			const isOpen = () => ! root.classList.contains( 'is-closed' );

			// What can actually be tabbed to right now: rendered, and not sitting inside a
			// collapsed step or a closed disclosure (both of which are marked inert).
			const tabbables = () =>
				Array.from( modal.querySelectorAll( FOCUSABLE ) ).filter(
					( el ) => ! el.closest( '[inert]' ) && el.getClientRects().length > 0
				);

			modal.addEventListener( 'keydown', ( e ) => {
				if ( 'Tab' !== e.key ) {
					return;
				}
				const list = tabbables();
				if ( ! list.length ) {
					return;
				}
				const first = list[ 0 ];
				const last = list[ list.length - 1 ];
				const inside = modal.contains( document.activeElement );
				if ( e.shiftKey && ( ! inside || document.activeElement === first ) ) {
					e.preventDefault();
					last.focus();
				} else if ( ! e.shiftKey && ( ! inside || document.activeElement === last ) ) {
					e.preventDefault();
					first.focus();
				}
			} );

			// Tab is not the only way out: find-in-page, a browser extension, or a control
			// disappearing under focus can all land it on the page behind. Pull it back.
			document.addEventListener( 'focusin', ( e ) => {
				if ( ! isOpen() || modal.contains( e.target ) ) {
					return;
				}
				const list = tabbables();
				if ( list.length ) {
					list[ 0 ].focus();
				}
			} );

			// On close, focus the plugin page's own heading rather than dropping it on <body>,
			// which would restart tabbing from the top of the admin chrome.
			const landFocus = () => {
				// The h1 sits inside .aafm-page-head > .title-wrap, so a child combinator off
				// .aafm-wrap misses it. Descendant match, with the wrap itself as the fallback.
				const heading = document.querySelector( '.aafm-wrap .aafm-page-head h1' )
					|| document.querySelector( '.aafm-wrap h1' );
				if ( ! heading ) {
					return;
				}
				heading.setAttribute( 'tabindex', '-1' );
				heading.focus();
			};

			return { isOpen, landFocus };
		}

		/**
		 * Show the wizard's success receipt and (unless reduced motion) fire the confetti.
		 *
		 * @param {HTMLElement} root   The wizard root.
		 * @param {Object}      state  The wizard state (method + write).
		 * @param {boolean}     reduce Whether reduced motion is preferred.
		 */
		#quickConnectSucceed( root, state, reduce ) {
			const method = 'apppw' === state.method
				? this.#t( 'qcMethodAppPassword', 'Application password' )
				: this.#t( 'qcMethodOauth', 'OAuth' );
			root.querySelector( '[data-qc-rmethod]' ).textContent = method;
			const writeVal = root.querySelector( '[data-qc-rwrite]' );
			const writeIcon = root.querySelector( '[data-qc-rwriteicon]' );
			if ( state.write ) {
				writeVal.textContent = this.#t( 'qcOn', 'On' );
				writeIcon.classList.add( 'is-amber' );
			} else {
				writeVal.textContent = this.#t( 'qcOff', 'Off' );
				writeIcon.classList.remove( 'is-amber' );
			}
			root.querySelector( '[data-qc-body]' ).style.display = 'none';
			root.querySelector( '.aafm-qc-foot' ).style.display = 'none';
			const meter = root.querySelector( '.aafm-qc-meter' );
			if ( meter ) {
				meter.style.display = 'none';
			}
			root.querySelector( '[data-qc-success]' ).classList.add( 'is-shown' );
			// The button that was focused just went display:none with the step list, so put
			// focus on the receipt's only control instead of letting it fall to <body>.
			root.querySelector( '#aafm-qc-godash' )?.focus();
			if ( ! reduce ) {
				this.#quickConnectConfetti( root );
			}
		}

		/**
		 * A self-contained canvas confetti burst for the success moment. No external library or
		 * asset; a static inline SVG stands in under prefers-reduced-motion (handled in CSS).
		 *
		 * @param {HTMLElement} root The wizard root.
		 */
		#quickConnectConfetti( root ) {
			const cv = root.querySelector( '[data-qc-confetti]' );
			const modal = root.querySelector( '.aafm-qc-modal' );
			const success = root.querySelector( '[data-qc-success]' );
			if ( ! cv || ! modal ) {
				return;
			}
			const dpr = Math.min( window.devicePixelRatio || 1, 2 );
			const w = modal.clientWidth;
			const h = success.clientHeight || 340;
			cv.width = w * dpr;
			cv.height = h * dpr;
			cv.style.width = w + 'px';
			cv.style.height = h + 'px';
			const ctx = cv.getContext( '2d' );
			ctx.scale( dpr, dpr );

			const colors = [ '#2271b1', '#135e96', '#dba617', '#00a32a', '#8ec5e8' ];
			const parts = Array.from( { length: 130 }, () => {
				const fromLeft = Math.random() < 0.5;
				return {
					x: fromLeft ? w * 0.16 : w * 0.84,
					y: h * 0.3,
					vx: ( fromLeft ? 1 : -1 ) * ( 2 + Math.random() * 5 ),
					vy: -( 6 + Math.random() * 7 ),
					g: 0.24 + Math.random() * 0.12,
					s: 5 + Math.random() * 6,
					rot: Math.random() * Math.PI,
					vr: ( Math.random() - 0.5 ) * 0.32,
					c: colors[ ( Math.random() * colors.length ) | 0 ],
					shape: Math.random() < 0.35 ? 'c' : 'r',
					life: 1,
				};
			} );

			let raf;
			let t0 = performance.now();
			const tick = ( now ) => {
				const dt = Math.min( 2, ( now - t0 ) / 16.67 );
				t0 = now;
				ctx.clearRect( 0, 0, w, h );
				let alive = 0;
				parts.forEach( ( p ) => {
					p.vy += p.g * dt;
					p.x += p.vx * dt;
					p.y += p.vy * dt;
					p.rot += p.vr * dt;
					if ( p.y > h * 0.42 ) {
						p.life -= 0.012 * dt;
					}
					if ( p.life <= 0 ) {
						return;
					}
					alive++;
					ctx.save();
					ctx.globalAlpha = Math.max( 0, p.life );
					ctx.translate( p.x, p.y );
					ctx.rotate( p.rot );
					ctx.fillStyle = p.c;
					if ( 'c' === p.shape ) {
						ctx.beginPath();
						ctx.arc( 0, 0, p.s * 0.5, 0, 7 );
						ctx.fill();
					} else {
						ctx.fillRect( -p.s / 2, -p.s / 2, p.s, p.s * 0.62 );
					}
					ctx.restore();
				} );
				if ( alive > 0 ) {
					raf = requestAnimationFrame( tick );
				} else {
					ctx.clearRect( 0, 0, w, h );
				}
			};
			raf = requestAnimationFrame( tick );
			window.setTimeout( () => window.cancelAnimationFrame( raf ), 5000 );
		}
	}

	document.addEventListener( 'DOMContentLoaded', () => new AafmAdmin() );
} )();
