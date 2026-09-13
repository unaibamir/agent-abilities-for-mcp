<?php
/**
 * First-activation admin-menu pointer: the wp-pointer callout that greets a genuinely new install.
 *
 * On activation aafm_quickconnect_flag_menu_pointer() flags the install; on any admin screen other
 * than the plugin's own page a due, undismissed admin then gets a wp-pointer anchored to the plugin
 * menu item ("Connect an AI agent to your site. It takes about two minutes.") that leads into the
 * Quick Connect wizard. It is gated by a first-activation option plus the per-user core
 * dismissed-pointers meta, so it shows once and never nags after dismissal or after the operator
 * opens the plugin page. Dismissal rides core's own dismiss-wp-pointer AJAX action.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * The unique id of the first-activation admin-menu pointer.
 *
 * Stored per user in the core `dismissed_wp_pointers` meta once dismissed, so the callout never
 * nags after the operator dismisses it or opens the plugin page.
 *
 * @return string
 */
function aafm_quickconnect_pointer_id(): string {
	return 'aafm_quickconnect_pointer';
}

/**
 * Flag a genuinely new install so the first-activation menu pointer shows once.
 *
 * Runs on activation via add_option, so it seeds the flag exactly once and a later
 * deactivate/reactivate cycle does not re-arm the pointer for an install that already dismissed it.
 * Whether a given admin actually sees the pointer is then gated per user by the core
 * dismissed-pointers meta.
 *
 * Deliberately does NOT route through aafm_update_option_verified(): that helper's
 * update_option() call would overwrite an existing row, but add_option()'s no-op-if-present
 * behaviour is exactly what keeps a reactivate cycle from re-arming a pointer the operator
 * already dismissed. So this certifies the row's presence directly with the same lower-level
 * cache primitives instead (Codex round 9, R9-10), without disturbing that contract.
 *
 * @return bool True when a row for the option is confirmed present after this call, whether
 *              this call created it or an earlier activation already had.
 */
function aafm_quickconnect_flag_menu_pointer(): bool {
	// A rejected cache rewrite here (Codex round 10, R10-9) means the forget below may not have
	// actually cleared a stale entry, the same gap aafm_update_option_verified() guards against -
	// certifying against db_found alone, without checking this, could report the flag as set
	// while a stale cache still hides it from the next get_option() read.
	$caches_ok = aafm_forget_option_caches( 'aafm_menu_pointer_active' );
	add_option( 'aafm_menu_pointer_active', '1' );
	$caches_ok = aafm_forget_option_caches( 'aafm_menu_pointer_active' ) && $caches_ok;
	aafm_force_refresh_option_caches( 'aafm_menu_pointer_active' );

	if ( ! $caches_ok ) {
		return false;
	}

	return aafm_read_option_views( 'aafm_menu_pointer_active' )['db_found'];
}

/**
 * Activation-hook entry point for aafm_quickconnect_flag_menu_pointer().
 *
 * WordPress's register_activation_hook() expects a callable(bool): void - it passes the
 * network-wide activation flag in and does nothing with a return value.
 * aafm_quickconnect_flag_menu_pointer() itself has to return bool so it can certify the row it
 * just seeded (Codex round 9, R9-10), so that certifying function cannot be the activation
 * callback directly. This thin wrapper is: it satisfies the hook's void contract while still
 * running (and discarding the result of) the certified write.
 *
 * @param bool $network_wide Whether the plugin is being activated network-wide. Unused: the
 *                            pointer flag is a per-site option.
 * @return void
 */
function aafm_quickconnect_activate_menu_pointer( bool $network_wide = false ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- signature required by register_activation_hook()'s callable(bool): void contract.
	// The hook contract is void, so a failed flag cannot be reported back to the activation
	// caller directly; logging it is the only way this is ever visible rather than a silently
	// missing first-run pointer (Codex round 10, R10-9 - the same "never report a change that
	// did not take" rule as every other certified write in this plugin).
	//
	// F3 (1.7.5 deferred): this file is require_once'd at top level specifically so its
	// activation callback is defined before plugins_loaded (see the require_once above this
	// function's registration in agent-abilities-for-mcp.php), but
	// aafm_switch_not_persisted_message() lives in includes/helpers.php, which is only loaded
	// inside aafm_bootstrap() on plugins_loaded. Activating a previously inactive plugin runs
	// this hook in the same request WITHOUT plugins_loaded ever firing, so calling that helper
	// here was an undefined-function fatal instead of an audit entry. aafm_log_activity() itself
	// is safe: includes/audit/log.php is required at top level too. The message text is inlined
	// rather than moving the formatting helper's file into the top-level load order.
	if ( ! aafm_quickconnect_flag_menu_pointer() ) {
		aafm_log_activity(
			array(
				'ability'    => 'aafm/menu-pointer-not-flagged',
				'status'     => 'error',
				'event_type' => 'setting_changed',
				'detail'     => sprintf(
					/* translators: %s: the name of the setting, for example "Read-only mode". */
					__( '%s could not be changed: the site\'s persistent object cache is still returning the old value. Flush the object cache (Redis, Memcached, or your host\'s cache) and save again.', 'agent-abilities-for-mcp' ),
					__( 'The first-activation pointer', 'agent-abilities-for-mcp' )
				),
			)
		);
	}
}

/**
 * Whether the current user has already dismissed the menu pointer.
 *
 * @return bool
 */
function aafm_quickconnect_pointer_dismissed_for_user(): bool {
	$dismissed = (string) get_user_meta( get_current_user_id(), 'dismissed_wp_pointers', true );
	$list      = array_filter( array_map( 'trim', explode( ',', $dismissed ) ) );
	return in_array( aafm_quickconnect_pointer_id(), $list, true );
}

/**
 * Record the menu pointer as dismissed for the current user.
 *
 * Appends the pointer id to the core `dismissed_wp_pointers` meta - the same store core's
 * dismiss-wp-pointer AJAX writes to - so opening the plugin page dismisses the pointer server-side
 * without waiting for a click.
 *
 * @return void
 */
function aafm_quickconnect_mark_pointer_dismissed_for_user(): void {
	$user_id = get_current_user_id();
	if ( $user_id <= 0 ) {
		return;
	}
	$dismissed = (string) get_user_meta( $user_id, 'dismissed_wp_pointers', true );
	$list      = array_filter( array_map( 'trim', explode( ',', $dismissed ) ) );
	if ( in_array( aafm_quickconnect_pointer_id(), $list, true ) ) {
		return;
	}
	$list[] = aafm_quickconnect_pointer_id();
	update_user_meta( $user_id, 'dismissed_wp_pointers', implode( ',', $list ) );
}

/**
 * Whether the first-activation menu pointer should be enqueued for this admin request.
 *
 * True only when the install is flagged new, the user can manage options and has not dismissed the
 * pointer, and the current screen is NOT the plugin's own page (opening that page dismisses the
 * pointer instead - see aafm_maybe_enqueue_menu_pointer()).
 *
 * @param string $hook Current admin page hook suffix.
 * @return bool
 */
function aafm_quickconnect_pointer_should_show( string $hook ): bool {
	if ( '1' !== (string) get_option( 'aafm_menu_pointer_active', '' ) ) {
		return false;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return false;
	}
	if ( 'toplevel_page_agent-abilities-for-mcp' === $hook ) {
		return false;
	}
	return ! aafm_quickconnect_pointer_dismissed_for_user();
}

/**
 * Enqueue and configure the first-activation admin-menu pointer.
 *
 * Hooked on admin_enqueue_scripts for every admin screen. On the plugin's own page it silently marks
 * the pointer dismissed for this user (opening the page counts as "seen"). On any other admin screen,
 * when the pointer is still due, it enqueues core's wp-pointer script/style and hands the pointer
 * copy and target to a small inline script that anchors the callout to the plugin's menu item. The
 * pointer dismisses through core's own dismiss-wp-pointer AJAX, which stores the id in user meta.
 *
 * @param string $hook Current admin page hook suffix.
 * @return void
 */
function aafm_maybe_enqueue_menu_pointer( string $hook ): void {
	// Opening the plugin page dismisses the pointer for this user, so it never nags afterwards.
	if ( 'toplevel_page_agent-abilities-for-mcp' === $hook ) {
		if ( '1' === (string) get_option( 'aafm_menu_pointer_active', '' )
			&& current_user_can( 'manage_options' )
			&& ! aafm_quickconnect_pointer_dismissed_for_user()
		) {
			aafm_quickconnect_mark_pointer_dismissed_for_user();
		}
		return;
	}

	if ( ! aafm_quickconnect_pointer_should_show( $hook ) ) {
		return;
	}

	wp_enqueue_style( 'wp-pointer' );
	wp_enqueue_script( 'wp-pointer' );

	$page_url = admin_url( 'admin.php?page=agent-abilities-for-mcp' );

	$data = array(
		'id'      => aafm_quickconnect_pointer_id(),
		'target'  => '#toplevel_page_agent-abilities-for-mcp',
		'ajaxUrl' => admin_url( 'admin-ajax.php' ),
		'pageUrl' => $page_url,
		'heading' => __( 'Agent Abilities for MCP', 'agent-abilities-for-mcp' ),
		'body'    => __( 'Connect an AI agent to your site. It takes about two minutes.', 'agent-abilities-for-mcp' ),
		'start'   => __( 'Start setup', 'agent-abilities-for-mcp' ),
		'dismiss' => __( 'Dismiss', 'agent-abilities-for-mcp' ),
	);

	// Attach the config and behaviour to the core wp-pointer handle. The inline script builds the
	// pointer content with jQuery text nodes (no innerHTML), so nothing is parsed as raw markup.
	wp_add_inline_script(
		'wp-pointer',
		'window.aafmMenuPointer = ' . wp_json_encode( $data ) . ';' . aafm_quickconnect_pointer_inline_js()
	);
}

/**
 * The inline jQuery that opens the first-activation menu pointer.
 *
 * Kept as a small self-contained IIFE so it needs no separate asset file. Every string comes from
 * the localized aafmMenuPointer object and reaches the DOM through jQuery text()/attr only. The
 * "Start setup" button links to the plugin page; both buttons close the pointer, and closing posts
 * to core's dismiss-wp-pointer action so the id is recorded in user meta.
 *
 * @return string
 */
function aafm_quickconnect_pointer_inline_js(): string {
	return <<<'JS'
( function ( $ ) {
	$( function () {
		var cfg = window.aafmMenuPointer || {};
		var $target = $( cfg.target );
		if ( ! $target.length || ! $.fn.pointer ) {
			return;
		}
		var $content = $( '<div/>' );
		$( '<h3/>' ).text( cfg.heading ).appendTo( $content );
		$( '<p/>' ).text( cfg.body ).appendTo( $content );

		var dismiss = function () {
			$.post( cfg.ajaxUrl, { action: 'dismiss-wp-pointer', pointer: cfg.id } );
		};

		$target.pointer( {
			content: $content.html(),
			position: { edge: 'left', align: 'center' },
			pointerClass: 'wp-pointer aafm-menu-pointer',
			buttons: function ( event, t ) {
				var $box = $( '<span/>' );
				$( '<a class="button" href="#"/>' ).text( cfg.dismiss ).on( 'click', function ( e ) {
					e.preventDefault();
					t.element.pointer( 'close' );
				} ).appendTo( $box );
				$( '<a class="button button-primary" style="margin-left:8px;margin-right:8px"/>' )
					.text( cfg.start ).attr( 'href', cfg.pageUrl ).appendTo( $box );
				return $box;
			},
			close: dismiss
		} ).pointer( 'open' );
	} );
}( jQuery ) );
JS;
}
