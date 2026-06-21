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
			this.#bindSectionToggles();
			this.#bindIntegrationToggles();
			this.#bindIntegrationFilters();
			this.#bindSaveAbilities();
			this.#bindSaveIntegrations();
			this.#bindSavePostTypes();
			this.#bindSaveMetaKeys();
			this.#bindSaveDeniedMetaKeys();
			this.#bindSaveUserMetaKeys();
			this.#bindSaveSettings();
			this.#bindMetaChips();
			this.#bindCreateUser();
			this.#bindTestConnection();
			this.#bindClearLog();
			this.#bindLogPaginationAndFilters();
			this.#bindResetPlugin();
			this.#bindQuickstarts();
			this.#bindOauthRevoke();
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
		 * is via the `hidden` attribute only — no markup is built from data, so there is no
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
				// the persisted option — no off-tab state is trusted from the client.
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
				if ( status ) {
					status.textContent = json?.success
						? this.#t( 'saved', 'Saved' )
						: this.#t( 'errorSaving', 'Error saving' );
				}
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

		#bindSaveDeniedMetaKeys() {
			const btn = document.querySelector( '#aafm-meta-keys-save' );
			const root = document.querySelector( '#aafm-meta-keys-form' );
			if ( ! btn || ! root ) {
				return;
			}
			// Exposed and Deny share one Save button. #bindSaveMetaKeys sends the Exposed list
			// and owns the status text; this sends the Deny list (by its own name) on the same
			// click. The chip helper writes only into the Exposed textarea, so its name selector
			// stays Exposed-scoped.
			btn.addEventListener( 'click', async () => {
				const deny = root.querySelector( 'textarea[name="aafm_deny_meta_keys"]' );
				const body = new URLSearchParams();
				body.append( 'action', 'aafm_save_denied_meta_keys' );
				body.append( 'nonce', this.#nonce );
				body.append( 'aafm_deny_meta_keys', deny?.value ?? '' );
				try {
					await fetch( this.#ajaxUrl, {
						method: 'POST',
						body,
						credentials: 'same-origin',
					} );
				} catch {
					// The shared status text is owned by #bindSaveMetaKeys.
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
				const body = new URLSearchParams();
				body.append( 'action', 'aafm_save_user_meta_keys' );
				body.append( 'nonce', this.#nonce );
				body.append( 'aafm_exposed_user_meta_keys', exposed?.value ?? '' );
				body.append( 'aafm_denied_user_meta_keys', deny?.value ?? '' );
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
				const retention = form.querySelector(
					'input[name="aafm_log_retention_days"]'
				);
				const draft = form.querySelector( 'input[name="aafm_force_draft"]' );
				const allowlist = form.querySelector( 'textarea[name="aafm_ip_allowlist"]' );

				const body = new URLSearchParams();
				body.append( 'action', 'aafm_save_settings' );
				body.append( 'nonce', this.#nonce );
				body.append( 'aafm_rate_limit_per_min', rate?.value ?? '0' );
				body.append( 'aafm_max_title_len', title?.value ?? '0' );
				body.append( 'aafm_log_retention_days', retention?.value ?? '30' );
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
				} else {
					// On a duplicate username the server returns the existing user's edit URL;
					// show the friendly message plus a real "Edit user" link built via the DOM
					// (textContent + href only — never innerHTML), so nothing untrusted is parsed.
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

			const load = async ( page, filter ) => {
				const pagerStatus = document.querySelector( '.aafm-pager-status' );
				if ( pagerStatus ) {
					pagerStatus.textContent = this.#t( 'loadingPage', 'Loading…' );
				}
				const json = await this.#post( 'aafm_get_log_page', {
					page,
					filter,
				} );
				if ( ! json?.success ) {
					// Leave the current view in place and restore the pager label.
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
				this.#updatePager(
					Number( data.page ) || 1,
					Number( data.total_pages ) || 1
				);
				const num = document.querySelector( '.aafm-count-num' );
				if ( num && typeof data.total === 'number' ) {
					num.textContent = new Intl.NumberFormat().format( data.total );
				}
			};

			segButtons.forEach( ( btn ) => {
				btn.addEventListener( 'click', () => {
					const filter = btn.dataset.filter ?? 'all';
					segButtons.forEach( ( b ) => {
						const on = b === btn;
						b.classList.toggle( 'is-active', on );
						b.classList.toggle( 'on', on );
						b.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
					} );
					// A filter change always restarts at page 1.
					load( 1, filter );
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
				td.colSpan = 5;
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
		 * the nonce-checked AJAX action; on success the row is updated in place — the
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
				if ( isGrant ) {
					// The grant no longer exists: drop the row.
					row.remove();
				} else {
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
