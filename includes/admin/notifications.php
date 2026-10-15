<?php
/**
 * Notifications UI: the Settings card, the test email, the admin notice, its dismissal, and the
 * notice sent to the old address when the notification address changes.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Plain, translated name of a mode for the log row and the card.
 *
 * @param string $mode One of aafm_notify_modes().
 * @return string
 */
function aafm_notify_mode_label( string $mode ): string {
	$labels = array(
		'off'       => __( 'Off', 'agent-abilities-for-mcp' ),
		'notice'    => __( 'Admin notice only', 'agent-abilities-for-mcp' ),
		'immediate' => __( 'Email right away, plus an admin notice', 'agent-abilities-for-mcp' ),
		'digest'    => __( 'One email a day, plus an admin notice', 'agent-abilities-for-mcp' ),
	);
	return $labels[ $mode ] ?? $labels['off'];
}

/**
 * Render the Notifications card on the Settings tab.
 *
 * @return void
 */
function aafm_render_notifications_section(): void {
	$s = aafm_notifications_settings();

	ob_start();
	$select = '<select id="aafm-notify-mode" name="aafm_notify_mode" aria-label="' . esc_attr__( 'How to be told', 'agent-abilities-for-mcp' ) . '">';
	foreach ( aafm_notify_modes() as $mode ) {
		$select .= '<option value="' . esc_attr( $mode ) . '"' . selected( $s['mode'], $mode, false ) . '>' . esc_html( aafm_notify_mode_label( $mode ) ) . '</option>';
	}
	$select .= '</select>';
	aafm_render_set_row(
		array(
			'label'   => __( 'Tell me', 'agent-abilities-for-mcp' ),
			'control' => $select,
			'help'    => __( 'Off by default. Nothing is sent or shown until you choose a mode and tick at least one thing below.', 'agent-abilities-for-mcp' ),
		)
	);

	aafm_render_set_row(
		array(
			'label'   => __( 'Email address', 'agent-abilities-for-mcp' ),
			// get_bloginfo(), not get_option(): a new get_option() site would need a line in OptionReadSweepTest for a value that is only a placeholder.
			'control' => sprintf(
				'<input type="email" id="aafm-notify-email" name="aafm_notify_email" value="%1$s" placeholder="%2$s" aria-label="%3$s"> <button type="button" class="aafm-btn aafm-btn-secondary" id="aafm-notify-test">%4$s</button> <span class="aafm-notify-test-status" aria-live="polite"></span>',
				esc_attr( $s['email'] ),
				esc_attr( (string) get_bloginfo( 'admin_email' ) ),
				esc_attr__( 'Email address for alerts', 'agent-abilities-for-mcp' ),
				esc_html__( 'Send a test email', 'agent-abilities-for-mcp' )
			),
			'help'    => __( 'One address, needed for the two email modes. The grey example is your site admin address; it is not used unless you type it here. Emails say what happened and link to the Activity Log. They never include the content of a call, a user name or an IP address.', 'agent-abilities-for-mcp' ),
		)
	);

	foreach ( aafm_notify_triggers() as $slug => $label ) {
		aafm_render_set_row(
			array(
				'label'   => $label,
				'control' => sprintf(
					'<label class="aafm-switch"><input type="checkbox" name="aafm_notify_%1$s" value="1" aria-label="%2$s"%3$s><span class="aafm-switch-track"></span></label>',
					esc_attr( $slug ),
					esc_attr( $label ),
					checked( ! empty( $s['triggers'][ $slug ] ), true, false )
				),
				'help'    => 'new_app' === $slug
					? __( 'An alert for every app authorization, including an app that already had consent. An Application Password counts only when it is created for a user who can edit posts, or for the agent user.', 'agent-abilities-for-mcp' )
					: '',
			)
		);
	}

	echo '<p class="help">' . esc_html__( 'The daily email depends on WP-Cron, which only runs when your site gets visits and does not run at all if DISABLE_WP_CRON is set. On a quiet site, point a real cron job at wp-cron.php (or run "wp cron event run --due-now") for the daily email. Admin notice only and Email right away do not depend on cron. If email is unreliable on your host, the admin notice still shows every alert.', 'agent-abilities-for-mcp' ) . '</p>';
	$body = (string) ob_get_clean();

	aafm_render_section(
		array(
			'icon'  => 'audit',
			'title' => __( 'Notifications', 'agent-abilities-for-mcp' ),
			'body'  => $body,
		)
	);
}

/**
 * Record a notification settings change. The row names the mode, never the address.
 *
 * @param array<string,mixed> $before Settings before.
 * @param array<string,mixed> $after  Settings after.
 * @return void
 */
function aafm_log_notifications_change( array $before, array $after ): void {
	if ( $before === $after ) {
		return;
	}
	$user = wp_get_current_user();
	aafm_log_activity(
		array(
			'ability'           => 'aafm/notifications',
			'principal_user_id' => (int) $user->ID,
			'principal_login'   => $user->user_login ? (string) $user->user_login : '',
			'status'            => 'success',
			'event_type'        => 'setting_changed',
			/* translators: %s: the notification mode, for example "Off". */
			'detail'            => sprintf( __( 'Notifications set to: %s', 'agent-abilities-for-mcp' ), aafm_notify_mode_label( (string) $after['mode'] ) ),
		)
	);
}

/**
 * Warn the OLD address when a save redirects or weakens alerts: the address changed, email alerts were
 * switched off (mode Off or Admin notice only), or the read-only and high-risk switch alert was unticked.
 * A hijacked admin session would do exactly one of these before doing harm, so the previous recipient
 * hears about it. Reads the settings captured BEFORE the save; the new address is never named. One
 * message per kind, throttled like any other bucket, and only when the old mode could send email.
 *
 * @param array<string,mixed> $before Settings before.
 * @param array<string,mixed> $after  Settings after.
 * @return void
 */
function aafm_notify_settings_weakened( array $before, array $after ): void {
	try {
		$was_emailing = in_array( $before['mode'], array( 'immediate', 'digest' ), true ) && '' !== $before['email'];
		if ( ! $was_emailing ) {
			return;
		}
		$still_emailing = in_array( $after['mode'], array( 'immediate', 'digest' ), true );
		$kind           = '';
		if ( ! $still_emailing ) {
			$kind = 'settings:alerts_off';
		} elseif ( $before['email'] !== $after['email'] ) {
			$kind = 'recipient:changed';
		} elseif ( ! empty( $before['triggers']['switch_flip'] ) && empty( $after['triggers']['switch_flip'] ) ) {
			$kind = 'settings:switch_alerts_off';
		}
		// The throttle key carries a hash of the OLD address, so A to B and then B to C inside the window
		// still warns B: one address's warning never swallows another's.
		if ( '' === $kind || ! aafm_notify_take_slot( $kind . ':' . substr( md5( strtolower( (string) $before['email'] ) ), 0, 12 ) )['allowed'] ) {
			return;
		}
		list( $trigger, $subtype ) = explode( ':', $kind, 2 );
		$event                     = array(
			'trigger' => $trigger,
			'subtype' => $subtype,
			'time'    => time(),
		);
		aafm_notify_send_later(
			(string) $before['email'],
			array( $event ),
			false,
			__( 'If you did not make this change, review who can sign in as an administrator.', 'agent-abilities-for-mcp' )
		);
	} catch ( \Throwable $e ) {
		unset( $e );
	}
}

/**
 * Settle the digest queue after a save. A save that leaves digest mode, or points the digest at a new
 * address, hands the queued events (including this very save's read-only or high-risk flip, queued
 * moments earlier under the old settings) to the OLD address through the capped delivery path. The
 * queue is cleared by the end-of-request flush, and only once wp_mail() reports success; a full hourly
 * cap or a failing mailer keeps it and writes one bounded error row. A queue left behind a mode that
 * is no longer digest loses its ordinary events so they never mail weeks later when digest mode comes
 * back, but an undelivered switch flip stays.
 *
 * @param array<string,mixed> $before Settings before.
 * @param array<string,mixed> $after  Settings after.
 * @return void
 */
function aafm_notify_settle_queue( array $before, array $after ): void {
	try {
		$flush = 'digest' === $before['mode'] && '' !== $before['email']
			&& ( 'digest' !== $after['mode'] || $before['email'] !== $after['email'] );
		if ( 'digest' === $after['mode'] && ! $flush ) {
			return;
		}
		$queue = aafm_notify_queue_list();
		if ( array() === $queue ) {
			return;
		}
		if ( $flush ) {
			if ( ! aafm_notify_send_later( (string) $before['email'], $queue, true, '', 'global', $queue ) ) {
				aafm_notify_log_failure( __( 'The queued notifications could not be sent and were kept', 'agent-abilities-for-mcp' ) );
			}
			return;
		}
		$stale = array_values( wp_list_filter( $queue, array( 'trigger' => 'switch_flip' ), 'NOT' ) );
		if ( array() !== $stale && ! aafm_notify_queue_remove( $stale ) ) {
			aafm_notify_log_failure( __( 'The queued notifications could not be cleared', 'agent-abilities-for-mcp' ) );
		}
	} catch ( \Throwable $e ) {
		unset( $e );
	}
}

/**
 * AJAX: send one test email to the SAVED address. One per minute.
 *
 * @return void
 */
function aafm_ajax_notify_test(): void {
	check_ajax_referer( 'aafm_admin', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'agent-abilities-for-mcp' ) ), 403 );
	}
	$s = aafm_notifications_settings();
	if ( '' === $s['email'] ) {
		wp_send_json_error( array( 'message' => __( 'Save a valid email address first, then send the test.', 'agent-abilities-for-mcp' ) ) );
	}
	if ( (int) aafm_transient_count( 'aafm_ntf_test' ) > 0 ) {
		wp_send_json_error( array( 'message' => __( 'A test email was sent a moment ago. Wait a minute and try again.', 'agent-abilities-for-mcp' ) ) );
	}
	set_transient( 'aafm_ntf_test', 1, MINUTE_IN_SECONDS );
	$site = aafm_notify_site_name();
	/* translators: %s: the site name. */
	$subject = sprintf( __( '[%s] Agent Abilities test email', 'agent-abilities-for-mcp' ), $site );
	$sent    = wp_mail( $s['email'], $subject, __( 'This is a test. If you can read it, alerts from Agent Abilities for MCP will reach this address.', 'agent-abilities-for-mcp' ) );
	if ( $sent ) {
		wp_send_json_success( array( 'message' => __( 'Test email sent. Check the inbox, and the spam folder.', 'agent-abilities-for-mcp' ) ) );
	}
	wp_send_json_error( array( 'message' => __( 'WordPress could not send the email. Your host may block mail; the admin notice will still show every alert.', 'agent-abilities-for-mcp' ) ) );
}

/**
 * The admin notice: the three newest alerts with a link to each Activity Log entry, a count of any
 * further alerts (Dismiss clears them all), and a Dismiss link.
 *
 * @return void
 */
function aafm_notify_admin_notice(): void {
	if ( ! current_user_can( 'manage_options' ) || is_network_admin() ) {
		return;
	}
	if ( 'off' === aafm_notifications_settings()['mode'] ) {
		return;
	}
	$pending = aafm_notify_pending_list();
	if ( array() === $pending ) {
		return;
	}
	$dismiss = wp_nonce_url( admin_url( 'admin-post.php?action=aafm_notify_dismiss' ), 'aafm_notify_dismiss' );
	echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Agent Abilities for MCP', 'agent-abilities-for-mcp' ) . '</strong></p><ul>';
	foreach ( array_slice( $pending, 0, 3 ) as $event ) {
		echo '<li>' . esc_html( aafm_notify_event_label( $event ) ) . ' (' . esc_html( gmdate( 'Y-m-d H:i', (int) ( $event['time'] ?? 0 ) ) ) . ' UTC) <a href="' . esc_url( aafm_notify_event_link( $event ) ) . '">' . esc_html__( 'View in Activity Log', 'agent-abilities-for-mcp' ) . '</a></li>';
	}
	$more = count( $pending ) - 3;
	if ( $more > 0 ) {
		/* translators: %d: how many further alerts are waiting, which Dismiss would also clear. */
		$more_text = sprintf( _n( 'and %d more alert.', 'and %d more alerts.', $more, 'agent-abilities-for-mcp' ), $more );
		echo '<li>' . esc_html( $more_text ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=agent-abilities-for-mcp&tab=activity' ) ) . '">' . esc_html__( 'Open the Activity Log', 'agent-abilities-for-mcp' ) . '</a></li>';
	}
	echo '</ul><p><a href="' . esc_url( $dismiss ) . '">' . esc_html__( 'Dismiss', 'agent-abilities-for-mcp' ) . '</a></p></div>';
}

/**
 * Clear the admin-notice entries (admin-post). Nonce and capability gated.
 *
 * @return void
 */
function aafm_handle_notify_dismiss(): void {
	check_admin_referer( 'aafm_notify_dismiss' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to do this.', 'agent-abilities-for-mcp' ), '', array( 'response' => 403 ) );
	}
	aafm_notify_pending_clear();
	$back = wp_get_referer();
	wp_safe_redirect( $back ? $back : admin_url() );
	exit;
}
