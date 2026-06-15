/* global aafmAdmin */
/**
 * Admin UI for Agent Abilities for MCP.
 *
 * Every value that comes back from an AJAX response is treated as untrusted and reaches
 * the DOM through textContent only — this file never assigns innerHTML, so there is no
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
			return template.replace( /%(\d+\$)?[sd]/g, ( match, pos ) => {
				const index = pos ? Number( pos.slice( 0, -1 ) ) - 1 : auto++;
				return String( args[ index ] ?? '' );
			} );
		}

		constructor() {
			this.#bindCopy();
			this.#bindOsTabs();
			this.#bindClientPicker();
			this.#bindSubjectTabs();
			this.#bindSaveAbilities();
			this.#bindSavePostTypes();
			this.#bindSaveMetaKeys();
			this.#bindSaveSettings();
			this.#bindMetaChips();
			this.#bindCreateUser();
			this.#bindTestConnection();
			this.#bindClearLog();
			this.#bindResetPlugin();
			this.#bindQuickstarts();
		}

		#bindQuickstarts() {
			const toggle = document.querySelector( '.aafm-quickstart-toggle' );
			const grid = document.querySelector( '#aafm-quickstart-grid' );
			if ( ! toggle || ! grid ) {
				return;
			}
			toggle.addEventListener( 'click', () => {
				const open = grid.hidden;
				grid.hidden = ! open;
				toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
				const i18n = aafmAdmin?.i18n;
				toggle.textContent = open
					? i18n?.quickstartsHide ?? 'Hide client configs'
					: i18n?.quickstartsShow ?? 'Show config for a specific client';
			} );
		}

		/**
		 * Wire the client picker (the .aafm-client cards in #aafm-clients).
		 *
		 * Clicking a card marks it .on (clearing its siblings) and swaps the primary
		 * config blocks to that client's snippet. The per-client unix payload already
		 * lives on the matching .aafm-quickstart-card's data-config, so the unix block
		 * is taken verbatim from there. Clients differ only by the JSON root key
		 * (VS Code uses "servers"; everyone else uses "mcpServers"), so the windows
		 * block — which has no per-client payload in the markup — is reconciled by
		 * rewriting just that root key. Both updates touch textContent / data-copy
		 * only, never innerHTML.
		 *
		 * @param {string} text   A rendered snippet (JSON text).
		 * @param {string} client The selected client slug.
		 * @return {string} The snippet with its root key matched to the client.
		 */
		#applyRootKey( text, client ) {
			const wanted = 'vscode' === client ? 'servers' : 'mcpServers';
			// The root key is the only "servers"/"mcpServers" token in the snippet
			// (it never appears in any value), so swap the first one we find.
			return text.replace( /"(?:mcp)?[sS]ervers"/, `"${ wanted }"` );
		}

		#bindClientPicker() {
			const cards = document.querySelectorAll( '#aafm-clients .aafm-client' );
			if ( ! cards.length ) {
				return;
			}
			cards.forEach( ( card ) => {
				card.addEventListener( 'click', () => {
					cards.forEach( ( c ) => c.classList.toggle( 'on', c === card ) );

					const client = card.dataset.client ?? '';
					// The matching quickstart card carries the ready-made unix snippet.
					const source = document.querySelector(
						`.aafm-quickstart-card[data-client="${ client }"]`
					);
					const unixCfg = source?.dataset.config ?? '';

					document
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

		#bindSubjectTabs() {
			const tabs = document.querySelectorAll( '.aafm-subject-tab' );
			if ( ! tabs.length ) {
				return;
			}
			tabs.forEach( ( tab ) => {
				tab.addEventListener( 'click', () => {
					const subject = tab.dataset.subject;
					tabs.forEach( ( t ) => {
						const active = t === tab;
						t.classList.toggle( 'is-active', active );
						t.setAttribute( 'aria-selected', active ? 'true' : 'false' );
					} );
					document
						.querySelectorAll( '.aafm-subject-panel[data-subject]' )
						.forEach( ( panel ) => {
							panel.hidden = panel.dataset.subject !== subject;
						} );
				} );
			} );
		}

		#bindOsTabs() {
			const tabs = document.querySelectorAll( '.aafm-os-tab' );
			if ( ! tabs.length ) {
				return;
			}
			tabs.forEach( ( tab ) => {
				tab.addEventListener( 'click', () => {
					const os = tab.dataset.os;
					tabs.forEach( ( t ) => {
						const active = t === tab;
						t.classList.toggle( 'is-active', active );
						t.setAttribute( 'aria-selected', active ? 'true' : 'false' );
					} );
					document
						.querySelectorAll( '.aafm-snippet[data-os]' )
						.forEach( ( box ) => {
							box.hidden = box.dataset.os !== os;
						} );
				} );
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
			btn.addEventListener( 'click', async () => {
				const status = root.querySelector( '.aafm-meta-keys-status' );
				const textarea = root.querySelector( 'textarea[name="aafm_meta_keys"]' );
				const body = new URLSearchParams();
				body.append( 'action', 'aafm_save_meta_keys' );
				body.append( 'nonce', this.#nonce );
				body.append( 'aafm_meta_keys', textarea?.value ?? '' );
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
				const draft = form.querySelector( 'input[name="aafm_force_draft"]' );
				const allowlist = form.querySelector( 'textarea[name="aafm_ip_allowlist"]' );

				const body = new URLSearchParams();
				body.append( 'action', 'aafm_save_settings' );
				body.append( 'nonce', this.#nonce );
				body.append( 'aafm_rate_limit_per_min', rate?.value ?? '0' );
				body.append( 'aafm_max_title_len', title?.value ?? '0' );
				if ( draft?.checked ) {
					body.append( 'aafm_force_draft', '1' );
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
						// A failed save never wrote anything — say so plainly.
						status.textContent = this.#t(
							'settingsNotSaved',
							'Could not save — your previous settings are still in effect.'
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
									'Saved. Dropped %d line(s) that were not a valid IP or range — check the allowlist.'
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
				if ( json?.success ) {
					status.textContent = this.#format(
						this.#t(
							'userCreated',
							'Created user #%d. Now create its Application Password under Users → Profile.'
						),
						json.data.user_id
					);
				} else {
					status.textContent = this.#format(
						this.#t( 'errorWithMessage', 'Error: %s' ),
						json?.data?.message ?? this.#t( 'errorUnknown', 'unknown' )
					);
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
							'Reachable (HTTP %1$s) — %2$s tool(s) in your admin view.'
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
					document
						.querySelectorAll( '.aafm-log-table tbody tr' )
						.forEach( ( r ) => r.remove() );
				}
			} );
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
	}

	document.addEventListener( 'DOMContentLoaded', () => new AafmAdmin() );
} )();
