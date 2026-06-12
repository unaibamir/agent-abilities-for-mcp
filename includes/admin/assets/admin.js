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

		constructor() {
			this.#bindCopy();
			this.#bindOsTabs();
			this.#bindSubjectTabs();
			this.#bindSaveAbilities();
			this.#bindSavePostTypes();
			this.#bindCreateUser();
			this.#bindTestConnection();
			this.#bindClearLog();
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
				return { success: false, data: { message: 'Request failed.' } };
			}
		}

		#bindCopy() {
			document.querySelectorAll( '.aafm-copy' ).forEach( ( btn ) => {
				btn.addEventListener( 'click', async () => {
					try {
						await navigator.clipboard.writeText( btn.dataset.copy ?? '' );
						btn.textContent = 'Copied';
					} catch {
						btn.textContent = 'Press Ctrl+C';
					}
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
					status.textContent = 'Saving…';
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
					status.textContent = json?.success ? 'Saved' : 'Error saving';
				}
			} );
		}

		#bindSavePostTypes() {
			const form = document.querySelector( '#aafm-post-types-form' );
			if ( ! form ) {
				return;
			}
			form.addEventListener( 'submit', async ( e ) => {
				e.preventDefault();
				const status = form.querySelector( '.aafm-post-types-status' );
				const types = [
					...form.querySelectorAll( 'input[name="aafm_post_types[]"]:checked' ),
				].map( ( i ) => i.value );

				const body = new URLSearchParams();
				body.append( 'action', 'aafm_save_post_types' );
				body.append( 'nonce', this.#nonce );
				types.forEach( ( v ) => body.append( 'aafm_post_types[]', v ) );

				if ( status ) {
					status.textContent = 'Saving…';
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
					status.textContent = json?.success ? 'Saved' : 'Error saving';
				}
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
					status.textContent = 'Creating…';
				}
				const json = await this.#post( 'aafm_create_agent_user', { login } );
				if ( ! status ) {
					return;
				}
				if ( json?.success ) {
					status.textContent = `Created user #${ json.data.user_id }. Now create its Application Password under Users → Profile.`;
				} else {
					status.textContent = `Error: ${ json?.data?.message ?? 'unknown' }`;
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
					status.textContent = 'Checking…';
				}
				const json = await this.#post( 'aafm_test_connection' );
				if ( ! status ) {
					return;
				}
				if ( json?.success && json.data.reachable ) {
					status.textContent = `Reachable (HTTP ${ json.data.http_code }) — ${ json.data.admin_tool_count } tool(s) in your admin view.`;
				} else if ( json?.success ) {
					status.textContent = `Endpoint answered HTTP ${ json.data.http_code } but did not return a tool list.`;
				} else {
					status.textContent = `Error: ${ json?.data?.message ?? 'unknown' }`;
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
					status.textContent = json?.success ? 'Cleared' : 'Error';
				}
				if ( json?.success ) {
					document
						.querySelectorAll( '.aafm-log-table tbody tr' )
						.forEach( ( r ) => r.remove() );
				}
			} );
		}
	}

	document.addEventListener( 'DOMContentLoaded', () => new AafmAdmin() );
} )();
