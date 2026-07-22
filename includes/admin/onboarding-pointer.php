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
 * @return void
 */
function aafm_quickconnect_flag_menu_pointer(): void {
	add_option( 'aafm_menu_pointer_active', '1' );
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
