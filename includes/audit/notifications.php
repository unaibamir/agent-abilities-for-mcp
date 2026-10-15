<?php
/**
 * Owner notifications: opt-in email, daily digest and admin notice for four kinds of event.
 *
 * Off by default (the option row is absent). Nothing here carries the content of a call: events are
 * identifiers (a trigger, a tool name, an app id and host, a count, a row id). Detection is by direct,
 * guarded calls from the places that already know the facts; every entry point swallows its own
 * failures, so a notification bug can never change an agent call or an admin save.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'AAFM_NOTIFY_WINDOW' ) ) {
	define( 'AAFM_NOTIFY_WINDOW', 15 * MINUTE_IN_SECONDS );
}
if ( ! defined( 'AAFM_NOTIFY_BURST_COUNT' ) ) {
	define( 'AAFM_NOTIFY_BURST_COUNT', 10 );
}
if ( ! defined( 'AAFM_NOTIFY_BURST_WINDOW' ) ) {
	define( 'AAFM_NOTIFY_BURST_WINDOW', 5 * MINUTE_IN_SECONDS );
}
if ( ! defined( 'AAFM_NOTIFY_GLOBAL_MAX' ) ) {
	define( 'AAFM_NOTIFY_GLOBAL_MAX', 6 );
}
if ( ! defined( 'AAFM_NOTIFY_QUEUE_MAX' ) ) {
	define( 'AAFM_NOTIFY_QUEUE_MAX', 200 );
}
if ( ! defined( 'AAFM_NOTIFY_PENDING_MAX' ) ) {
	define( 'AAFM_NOTIFY_PENDING_MAX', 10 );
}

/**
 * The delivery modes, in the order the Settings select shows them.
 *
 * @return list<string>
 */
function aafm_notify_modes(): array {
	return array( 'off', 'notice', 'immediate', 'digest' );
}

/**
 * The four trigger switches: slug => label.
 *
 * @return array<string,string>
 */
function aafm_notify_triggers(): array {
	return array(
		'new_app'      => __( 'A new app is approved to connect, or an Application Password is created', 'agent-abilities-for-mcp' ),
		'risky_run'    => __( 'A high-risk tool, or one that deletes permanently, runs', 'agent-abilities-for-mcp' ),
		'denial_burst' => __( 'A burst of denied calls, or a connection hits the rate limit', 'agent-abilities-for-mcp' ),
		'switch_flip'  => __( 'Read-only mode or the high-risk lock is switched', 'agent-abilities-for-mcp' ),
	);
}

/**
 * Coerce any stored value to the settings shape. A bad address becomes '' (never repaired).
 *
 * @param mixed $stored Whatever the option held.
 * @return array{mode:string,email:string,triggers:array<string,bool>}
 */
function aafm_notifications_normalize( $stored ): array {
	$out = array(
		'mode'     => 'off',
		'email'    => '',
		'triggers' => array_fill_keys( array_keys( aafm_notify_triggers() ), false ),
	);
	if ( ! is_array( $stored ) ) {
		return $out;
	}
	if ( isset( $stored['mode'] ) && is_string( $stored['mode'] ) && in_array( $stored['mode'], aafm_notify_modes(), true ) ) {
		$out['mode'] = $stored['mode'];
	}
	if ( isset( $stored['email'] ) && is_string( $stored['email'] ) ) {
		// is_email() on the value exactly as stored, never sanitize_email() first: that function strips bad
		// characters from each part instead of failing, so "a@b.co, c@d.co" would become the one valid-looking
		// address "a@b.cocd.co", a different recipient from anything the owner typed.
		$email        = trim( $stored['email'] );
		$out['email'] = is_email( $email ) ? $email : '';
	}
	$triggers = isset( $stored['triggers'] ) && is_array( $stored['triggers'] ) ? $stored['triggers'] : array();
	foreach ( array_keys( $out['triggers'] ) as $slug ) {
		$out['triggers'][ $slug ] = ! empty( $triggers[ $slug ] );
	}
	return $out;
}

/**
 * The stored notification settings. Absent row means off.
 *
 * @return array{mode:string,email:string,triggers:array<string,bool>}
 */
function aafm_notifications_settings(): array {
	return aafm_notifications_normalize( get_option( 'aafm_notifications', array() ) );
}

/**
 * Settings from the posted Settings form (flat field names), in the stored shape.
 *
 * @param array<string,mixed> $posted Unslashed form fields.
 * @return array{mode:string,email:string,triggers:array<string,bool>}
 */
function aafm_notifications_sanitize( array $posted ): array {
	$mode = isset( $posted['aafm_notify_mode'] ) && is_string( $posted['aafm_notify_mode'] ) && in_array( $posted['aafm_notify_mode'], aafm_notify_modes(), true )
		? $posted['aafm_notify_mode']
		: 'off';
	// The address exactly as typed, trimmed. Never sanitize_email(): see aafm_notifications_normalize(). A value is stored only if is_email() approves it unchanged.
	$email = isset( $posted['aafm_notify_email'] ) && is_string( $posted['aafm_notify_email'] ) ? trim( $posted['aafm_notify_email'] ) : '';
	$flags = array();
	foreach ( array_keys( aafm_notify_triggers() ) as $slug ) {
		$flags[ $slug ] = ! empty( $posted[ 'aafm_notify_' . $slug ] );
	}
	return array(
		'mode'     => $mode,
		'email'    => is_email( $email ) ? $email : '',
		'triggers' => $flags,
	);
}

/**
 * The reason a settings change cannot be saved, or null. Runs before any write, so a rejected save
 * changes nothing.
 *
 * @param array{mode:string,email:string,triggers:array<string,bool>} $clean     Result of aafm_notifications_sanitize().
 * @param string                                                      $raw_email What the owner typed, trimmed.
 * @return string|null
 */
function aafm_notifications_validate( array $clean, string $raw_email ): ?string {
	if ( 'off' === $clean['mode'] ) {
		return null;
	}
	if ( '' !== $raw_email && '' === $clean['email'] ) {
		return __( 'That email address does not look valid. Use one address, for example name@example.com.', 'agent-abilities-for-mcp' );
	}
	if ( in_array( $clean['mode'], array( 'immediate', 'digest' ), true ) && '' === $clean['email'] ) {
		return __( 'Add a valid email address, or choose "Admin notice only".', 'agent-abilities-for-mcp' );
	}
	if ( ! in_array( true, $clean['triggers'], true ) ) {
		return __( 'Pick at least one thing to be told about, or choose Off.', 'agent-abilities-for-mcp' );
	}
	return null;
}

/**
 * The settings when notifications are on for this trigger and deliverable, else null.
 *
 * @param string $trigger A key of aafm_notify_triggers().
 * @return array{mode:string,email:string,triggers:array<string,bool>}|null
 */
function aafm_notify_settings_for( string $trigger ): ?array {
	$s = aafm_notifications_settings();
	if ( 'off' === $s['mode'] || empty( $s['triggers'][ $trigger ] ) ) {
		return null;
	}
	if ( 'notice' !== $s['mode'] && '' === $s['email'] ) {
		return null;
	}
	return $s;
}

/**
 * Claim the one send allowed per bucket per window. An event inside the window only increments a
 * counter that the next send reports. An unreadable counter counts as "inside the window".
 *
 * @param string $bucket Trigger and subtype, for example "switch_flip:read_only_off".
 * @return array{allowed:bool,suppressed:int}
 */
function aafm_notify_take_slot( string $bucket ): array {
	$key   = 'aafm_ntf_' . substr( md5( $bucket ), 0, 20 );
	$count = aafm_transient_count( $key );
	if ( null === $count || $count > 0 ) {
		$swallowed = (int) aafm_transient_count( $key . '_n' );
		set_transient( $key . '_n', $swallowed + 1, DAY_IN_SECONDS );
		return array(
			'allowed'    => false,
			'suppressed' => 0,
		);
	}
	set_transient( $key, 1, AAFM_NOTIFY_WINDOW );
	$suppressed = (int) aafm_transient_count( $key . '_n' );
	delete_transient( $key . '_n' );
	return array(
		'allowed'    => true,
		'suppressed' => $suppressed,
	);
}

/**
 * Claim one of the hourly email slots shared by every bucket.
 *
 * @return bool
 */
function aafm_notify_global_slot(): bool {
	$count = aafm_transient_count( 'aafm_ntf_global' );
	if ( null === $count || $count >= AAFM_NOTIFY_GLOBAL_MAX ) {
		return false;
	}
	// ponytail: the hour slides with each send. A fixed window would need a stored start time.
	set_transient( 'aafm_ntf_global', $count + 1, HOUR_IN_SECONDS );
	return true;
}

/**
 * A short identifier: letters, digits and the few characters tool names and ids use.
 *
 * @param string $value Raw value.
 * @param int    $max   Maximum length.
 * @return string
 */
function aafm_notify_clean_identifier( string $value, int $max = 120 ): string {
	return substr( (string) preg_replace( '/[^A-Za-z0-9_\/.\-]/', '', $value ), 0, $max );
}

/**
 * An app name as it goes into a message: letters, digits, space and a few marks, at most 40
 * characters. The name is chosen by whoever registered the app, so it is bounded and never used in a
 * subject.
 *
 * @param string $value Raw name.
 * @return string
 */
function aafm_notify_clean_app_name( string $value ): string {
	$clean = preg_replace( '/\s+/u', ' ', aafm_sanitize_plain_text( $value ) );
	$clean = preg_replace( '/[^\p{L}\p{N} .,_()\-]/u', '', null === $clean ? '' : $clean );
	return mb_substr( trim( null === $clean ? '' : $clean ), 0, 40 );
}

/**
 * The site name as it goes into a subject: control characters stripped, 60 characters at most.
 *
 * @return string
 */
function aafm_notify_site_name(): string {
	$site = (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) );
	return mb_substr( trim( $site ), 0, 60 );
}

/**
 * Record an event: throttle, write the admin notice entry, then mail or queue. Never throws.
 *
 * @param string              $trigger A key of aafm_notify_triggers().
 * @param string              $subtype Throttle sub-bucket, for example "read_only_off".
 * @param array<string,mixed> $facts   Optional: row_id, ability, client_id, host, app_name, count, user_id.
 * @return void
 */
function aafm_notify_event( string $trigger, string $subtype, array $facts = array() ): void {
	try {
		$settings = aafm_notify_settings_for( $trigger );
		if ( null === $settings ) {
			return;
		}
		$slot = aafm_notify_take_slot( $trigger . ':' . $subtype );
		if ( ! $slot['allowed'] ) {
			return;
		}
		$event = array(
			'trigger'    => $trigger,
			'subtype'    => $subtype,
			'time'       => time(),
			'row_id'     => isset( $facts['row_id'] ) ? max( 0, (int) $facts['row_id'] ) : 0,
			'ability'    => isset( $facts['ability'] ) ? aafm_notify_clean_identifier( (string) $facts['ability'] ) : '',
			'client_id'  => isset( $facts['client_id'] ) ? aafm_notify_clean_identifier( (string) $facts['client_id'], 191 ) : '',
			'host'       => isset( $facts['host'] ) ? aafm_notify_clean_identifier( (string) $facts['host'], 120 ) : '',
			'app_name'   => isset( $facts['app_name'] ) ? aafm_notify_clean_app_name( (string) $facts['app_name'] ) : '',
			'count'      => isset( $facts['count'] ) ? max( 0, (int) $facts['count'] ) : 0,
			'user_id'    => isset( $facts['user_id'] ) ? max( 0, (int) $facts['user_id'] ) : 0,
			'suppressed' => $slot['suppressed'],
		);
		aafm_notify_pending_push( $event );
		if ( 'digest' === $settings['mode'] ) {
			aafm_notify_queue_push( $event );
		} elseif ( 'immediate' === $settings['mode'] && aafm_notify_global_slot() ) {
			aafm_notify_deliver( $settings['email'], array( $event ), false );
		}
	} catch ( \Throwable $e ) {
		unset( $e ); // A notification problem must never reach the call or the save that raised it.
	}
}

/**
 * The fixed, translated sentence for an event. Never contains anything from a call.
 *
 * @param array<string,mixed> $event An event record.
 * @return string
 */
function aafm_notify_event_label( array $event ): string {
	$key    = (string) ( $event['trigger'] ?? '' ) . ':' . (string) ( $event['subtype'] ?? '' );
	$labels = array(
		'new_app:oauth_grant'            => __( 'A new app was approved to connect', 'agent-abilities-for-mcp' ),
		'new_app:app_password'           => __( 'An Application Password was created', 'agent-abilities-for-mcp' ),
		'risky_run:high_risk'            => __( 'A high-risk tool ran', 'agent-abilities-for-mcp' ),
		'risky_run:permanent_delete'     => __( 'A tool that deletes permanently ran', 'agent-abilities-for-mcp' ),
		'risky_run:bridged_destructive'  => __( 'A destructive tool from another plugin ran', 'agent-abilities-for-mcp' ),
		'denial_burst:burst'             => __( 'Many calls were denied', 'agent-abilities-for-mcp' ),
		'denial_burst:rate_limit'        => __( 'A connection hit the rate limit', 'agent-abilities-for-mcp' ),
		'switch_flip:read_only_on'       => __( 'Read-only mode was turned on', 'agent-abilities-for-mcp' ),
		'switch_flip:read_only_off'      => __( 'Read-only mode was turned off', 'agent-abilities-for-mcp' ),
		'switch_flip:high_risk_unlocked' => __( 'The high-risk lock was opened', 'agent-abilities-for-mcp' ),
		'switch_flip:high_risk_locked'   => __( 'The high-risk lock was closed', 'agent-abilities-for-mcp' ),
		'recipient:changed'              => __( 'The notification address was changed', 'agent-abilities-for-mcp' ),
		'settings:alerts_off'            => __( 'Email alerts were turned off', 'agent-abilities-for-mcp' ),
		'settings:switch_alerts_off'     => __( 'Alerts for read-only and high-risk switch changes were turned off', 'agent-abilities-for-mcp' ),
	);
	return $labels[ $key ] ?? __( 'Something needs a look', 'agent-abilities-for-mcp' );
}

/**
 * The Activity Log link for an event: its row, or the denied view for a burst, or the tab.
 *
 * @param array<string,mixed> $event An event record.
 * @return string
 */
function aafm_notify_event_link( array $event ): string {
	$base = 'admin.php?page=agent-abilities-for-mcp&tab=activity';
	if ( ! empty( $event['row_id'] ) ) {
		return admin_url( $base . '&row=' . (int) $event['row_id'] );
	}
	if ( 'denial_burst' === ( $event['trigger'] ?? '' ) ) {
		return admin_url( $base . '&filter=denied' );
	}
	return admin_url( $base );
}

/**
 * Build the subject and plain-text body. Identifiers only, cleaned again at render time (a queued
 * event is stored data); the subject is a fixed label plus the site name with control characters
 * stripped and 60 characters at most.
 *
 * @param array<int,array<string,mixed>> $events Events (one for an alert, many for a digest).
 * @param bool                           $digest Whether this is the daily summary.
 * @return array{subject:string,body:string}
 */
function aafm_notify_build_message( array $events, bool $digest ): array {
	$site = aafm_notify_site_name();

	if ( $digest ) {
		/* translators: %s: the site name. */
		$subject = sprintf( __( '[%s] Agent Abilities daily summary', 'agent-abilities-for-mcp' ), $site );
		$lines   = array( __( 'What happened since the last summary:', 'agent-abilities-for-mcp' ), '' );
		$counts  = array();
		foreach ( $events as $event ) {
			$label            = aafm_notify_event_label( $event );
			$counts[ $label ] = ( $counts[ $label ] ?? 0 ) + 1;
		}
		foreach ( $counts as $label => $n ) {
			$lines[] = $label . ': ' . $n;
		}
		$lines[] = '';
		foreach ( array_slice( $events, -10 ) as $event ) {
			$lines[] = aafm_notify_event_label( $event ) . ' (' . gmdate( 'Y-m-d H:i:s', (int) ( $event['time'] ?? 0 ) ) . ' UTC): ' . aafm_notify_event_link( $event );
		}
	} else {
		$event = (array) ( $events[0] ?? array() );
		/* translators: 1: the site name. 2: what happened, for example "Read-only mode was turned off". */
		$subject = sprintf( __( '[%1$s] Agent Abilities alert: %2$s', 'agent-abilities-for-mcp' ), $site, aafm_notify_event_label( $event ) );
		$lines   = array( aafm_notify_event_label( $event ), '' );
		/* translators: %s: a UTC date and time. */
		$lines[] = sprintf( __( 'When: %s UTC', 'agent-abilities-for-mcp' ), gmdate( 'Y-m-d H:i:s', (int) ( $event['time'] ?? 0 ) ) );
		if ( ! empty( $event['ability'] ) ) {
			/* translators: %s: a tool name such as aafm/delete-post. */
			$lines[] = sprintf( __( 'Tool: %s', 'agent-abilities-for-mcp' ), aafm_notify_clean_identifier( (string) $event['ability'] ) );
		}
		if ( ! empty( $event['client_id'] ) ) {
			/* translators: %s: an OAuth client id. */
			$lines[] = sprintf( __( 'App id: %s', 'agent-abilities-for-mcp' ), aafm_notify_clean_identifier( (string) $event['client_id'], 191 ) );
		}
		if ( ! empty( $event['host'] ) ) {
			/* translators: %s: a host name the app redirects to. */
			$lines[] = sprintf( __( 'Redirects to: %s', 'agent-abilities-for-mcp' ), aafm_notify_clean_identifier( (string) $event['host'], 120 ) );
		}
		if ( ! empty( $event['app_name'] ) ) {
			/* translators: %s: the name an app gave itself when it registered. */
			$lines[] = sprintf( __( 'The name the app gave itself: %s', 'agent-abilities-for-mcp' ), aafm_notify_clean_app_name( (string) $event['app_name'] ) );
		}
		if ( ! empty( $event['user_id'] ) ) {
			/* translators: %d: a WordPress user id. */
			$lines[] = sprintf( __( 'For user account #%d', 'agent-abilities-for-mcp' ), (int) $event['user_id'] );
		}
		if ( ! empty( $event['count'] ) ) {
			/* translators: %d: how many calls were denied. */
			$lines[] = sprintf( __( 'Denied calls counted: %d', 'agent-abilities-for-mcp' ), (int) $event['count'] );
		}
		if ( ! empty( $event['suppressed'] ) ) {
			/* translators: %d: how many similar alerts were held back. */
			$lines[] = sprintf( __( 'Held back since the last message: plus %d more', 'agent-abilities-for-mcp' ), (int) $event['suppressed'] );
		}
		$lines[] = '';
		$lines[] = __( 'Open the Activity Log entry:', 'agent-abilities-for-mcp' );
		$lines[] = aafm_notify_event_link( $event );
	}
	$lines[] = '';
	$lines[] = __( 'You get this because notifications are switched on under Settings in Agent Abilities for MCP. It never includes the content of a call.', 'agent-abilities-for-mcp' );

	return array(
		'subject' => $subject,
		'body'    => implode( "\n", $lines ),
	);
}

/**
 * Send a message to the owner's address. A failed send writes one bounded error row; the admin
 * notice entry already exists by then.
 *
 * @param string                         $to     The validated address.
 * @param array<int,array<string,mixed>> $events Events to describe.
 * @param bool                           $digest Whether this is the daily summary.
 * @return bool Whether wp_mail() reported success.
 */
function aafm_notify_deliver( string $to, array $events, bool $digest ): bool {
	$message = aafm_notify_build_message( $events, $digest );
	$sent    = (bool) wp_mail( $to, $message['subject'], $message['body'] );
	if ( ! $sent && aafm_denial_log_within_cap( 'ntf' ) ) {
		aafm_log_activity(
			array(
				'ability'    => 'aafm/notifications',
				'status'     => 'error',
				'event_type' => 'setting_changed',
				'detail'     => __( 'A notification email could not be sent', 'agent-abilities-for-mcp' ),
			)
		);
	}
	return $sent;
}

/**
 * A stored list option as a list, or an empty list when it holds anything else.
 *
 * @param mixed $stored The option value.
 * @return array<int,array<string,mixed>>
 */
function aafm_notify_as_list( $stored ): array {
	return is_array( $stored ) ? array_values( array_filter( $stored, 'is_array' ) ) : array();
}

/**
 * The admin-notice entries, newest first.
 *
 * @return array<int,array<string,mixed>>
 */
function aafm_notify_pending_list(): array {
	return aafm_notify_as_list( get_option( 'aafm_notify_pending', array() ) );
}

/**
 * Add one admin-notice entry, newest first, capped.
 *
 * @param array<string,mixed> $event The event.
 * @return void
 */
function aafm_notify_pending_push( array $event ): void {
	$list = aafm_notify_pending_list();
	array_unshift( $list, $event );
	aafm_update_option_verified( 'aafm_notify_pending', array_slice( $list, 0, AAFM_NOTIFY_PENDING_MAX ), false );
}

/**
 * Clear the admin-notice entries.
 *
 * @return bool
 */
function aafm_notify_pending_clear(): bool {
	return aafm_delete_option_cache_safe( 'aafm_notify_pending' );
}

/**
 * The digest queue, oldest first.
 *
 * @return array<int,array<string,mixed>>
 */
function aafm_notify_queue_list(): array {
	return aafm_notify_as_list( get_option( 'aafm_notify_queue', array() ) );
}

/**
 * Append one event to the digest queue, dropping the oldest past the cap.
 *
 * @param array<string,mixed> $event The event.
 * @return void
 */
function aafm_notify_queue_push( array $event ): void {
	$queue   = aafm_notify_queue_list();
	$queue[] = $event;
	aafm_update_option_verified( 'aafm_notify_queue', array_slice( $queue, -AAFM_NOTIFY_QUEUE_MAX ), false );
}

/**
 * Clear the digest queue.
 *
 * @return bool
 */
function aafm_notify_queue_clear(): bool {
	return aafm_delete_option_cache_safe( 'aafm_notify_queue' );
}

/**
 * Cron: send the daily summary and clear the queue only when the mail went out.
 *
 * @return void
 */
function aafm_notify_digest_run(): void {
	try {
		$s = aafm_notifications_settings();
		if ( 'digest' !== $s['mode'] || '' === $s['email'] ) {
			return;
		}
		$queue = aafm_notify_queue_list();
		if ( array() === $queue ) {
			return;
		}
		if ( aafm_notify_deliver( $s['email'], $queue, true ) ) {
			aafm_notify_queue_clear();
		}
	} catch ( \Throwable $e ) {
		unset( $e );
	}
}
add_action( 'aafm_notifications_digest', 'aafm_notify_digest_run' );

/**
 * Schedule the daily digest when the mode needs it and clear it when not. Idempotent, so it runs
 * after a settings save and self-heals on admin_init like the plugin's other events.
 *
 * @return void
 */
function aafm_notify_sync_schedule(): void {
	$s    = aafm_notifications_settings();
	$want = 'digest' === $s['mode'] && '' !== $s['email'] && in_array( true, $s['triggers'], true );
	$next = wp_next_scheduled( 'aafm_notifications_digest' );
	if ( $want && ! $next ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'aafm_notifications_digest' );
	} elseif ( ! $want && $next ) {
		wp_clear_scheduled_hook( 'aafm_notifications_digest' );
	}
}
add_action( 'admin_init', 'aafm_notify_sync_schedule' );

/**
 * Clear the digest event on deactivation.
 *
 * @return void
 */
function aafm_notify_unschedule(): void {
	wp_clear_scheduled_hook( 'aafm_notifications_digest' );
}
register_deactivation_hook( AAFM_PLUGIN_FILE, 'aafm_notify_unschedule' );

/**
 * How alarming an ability is: high_risk, permanent_delete, bridged_destructive, or null for the rest.
 * Recoverable trash and ordinary writes are deliberately not alarming.
 *
 * @param string $name Ability name.
 * @return string|null
 */
function aafm_notify_ability_risk( string $name ): ?string {
	if ( aafm_ability_is_high_risk( $name ) ) {
		return 'high_risk';
	}
	if ( in_array( $name, aafm_permanent_delete_abilities(), true ) ) {
		return 'permanent_delete';
	}
	if ( 0 === strpos( $name, 'aafm-bridge/' ) && function_exists( 'wp_get_ability' ) ) {
		$ability = wp_get_ability( $name );
		// One rule for "destructive": the helper the operator's own "can delete data" confirm uses. The
		// wrapper's annotations are already that helper's normalized output (bridge.php:708-722), so this
		// agrees with it for an undeclared foreign ability (destructive) and for a contradictory
		// readonly:true plus destructive:true declaration (destructive), which a local rule would miss.
		if ( $ability && ! empty( aafm_bridge_risk( $ability )['destructive'] ) ) {
			return 'bridged_destructive';
		}
	}
	return null;
}

/**
 * Called from the execute closure once a call has resolved: a successful high-risk or destructive run
 * is an event. Anything else returns at once.
 *
 * @param string   $ability Ability name.
 * @param string   $status  The call's final status.
 * @param int|null $row_id  The activity row, when it landed.
 * @return void
 */
function aafm_notify_ability_finished( string $ability, string $status, ?int $row_id ): void {
	if ( 'success' !== $status ) {
		return;
	}
	try {
		if ( null === aafm_notify_settings_for( 'risky_run' ) ) {
			return;
		}
		$kind = aafm_notify_ability_risk( $ability );
		if ( null !== $kind ) {
			aafm_notify_event(
				'risky_run',
				$kind,
				array(
					'row_id'  => (int) $row_id,
					'ability' => $ability,
				)
			);
		}
	} catch ( \Throwable $e ) {
		unset( $e );
	}
}

/**
 * Count denied calls; the tenth within five minutes fires one alert and restarts the count.
 *
 * The count is a plain integer transient read through aafm_transient_count(), the same reader the
 * throttle uses: a failed or unreadable read never restarts the count at 1 (an unreadable count skips
 * this call), so a flaky cache cannot quietly lower the alerts. The window slides: each denial restarts
 * the five-minute clock, the same bound aafm_denial_log_within_cap() uses.
 *
 * @param array<string,mixed> $record The aafm_ability_resolved payload.
 * @return void
 */
function aafm_notify_on_resolved( array $record ): void {
	if ( 'denied' !== ( $record['status'] ?? '' ) ) {
		return;
	}
	try {
		if ( null === aafm_notify_settings_for( 'denial_burst' ) ) {
			return;
		}
		$count = aafm_transient_count( 'aafm_ntf_denials' );
		if ( null === $count ) {
			return;
		}
		++$count;
		if ( $count >= AAFM_NOTIFY_BURST_COUNT ) {
			delete_transient( 'aafm_ntf_denials' );
			aafm_notify_event(
				'denial_burst',
				'burst',
				array(
					'count'  => $count,
					'row_id' => isset( $record['row_id'] ) ? (int) $record['row_id'] : 0,
				)
			);
			return;
		}
		// ponytail: sliding window. A slow trickle of denials under five minutes apart can still reach ten.
		// A fixed window needs a stored start time; add it if that ever matters.
		set_transient( 'aafm_ntf_denials', $count, AAFM_NOTIFY_BURST_WINDOW );
	} catch ( \Throwable $e ) {
		unset( $e );
	}
}
add_action( 'aafm_ability_resolved', 'aafm_notify_on_resolved' );

/**
 * An approved OAuth grant. Resolves the app's name only when the trigger is on.
 *
 * @param array<string,mixed> $ctx    The aafm_oauth_log_event() context.
 * @param int                 $row_id The lifecycle row just written.
 * @return void
 */
function aafm_notify_oauth_grant( array $ctx, int $row_id ): void {
	try {
		if ( null === aafm_notify_settings_for( 'new_app' ) ) {
			return;
		}
		$client_id = isset( $ctx['client_id'] ) ? (string) $ctx['client_id'] : '';
		$client    = '' !== $client_id ? aafm_oauth_get_client( $client_id ) : null;
		aafm_notify_event(
			'new_app',
			'oauth_grant',
			array(
				'row_id'    => $row_id,
				'client_id' => $client_id,
				'host'      => isset( $ctx['redirect_host'] ) ? (string) $ctx['redirect_host'] : '',
				'app_name'  => null !== $client ? $client['client_name'] : '',
			)
		);
	} catch ( \Throwable $e ) {
		unset( $e );
	}
}

/**
 * An Application Password was created for a user. Only the user id is read; never the password or its name.
 *
 * Core lets every role create one, so on a membership or LMS site a signed-up member would keep the
 * owner's alerts busy. Only a user who can edit posts, or the plugin's own agent user, is worth an
 * alert (spec D25). That is a chosen line, not a proven floor: a lower-privileged user's calls are
 * still logged in the Activity Log.
 *
 * @param mixed $user_id The user the password belongs to.
 * @return void
 */
function aafm_notify_on_app_password( $user_id ): void {
	try {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 || ( ! user_can( $user_id, 'edit_posts' ) && ! aafm_principal_is_agent_identity( $user_id, null ) ) ) {
			return;
		}
		aafm_notify_event( 'new_app', 'app_password', array( 'user_id' => $user_id ) );
	} catch ( \Throwable $e ) {
		unset( $e );
	}
}
add_action( 'wp_create_application_password', 'aafm_notify_on_app_password', 10, 1 );

/**
 * A governance switch changed and the change persisted.
 *
 * @param string $subtype read_only_on, read_only_off, high_risk_unlocked or high_risk_locked.
 * @param int    $row_id  The log row for the change.
 * @return void
 */
function aafm_notify_switch_flip( string $subtype, int $row_id ): void {
	aafm_notify_event( 'switch_flip', $subtype, array( 'row_id' => $row_id ) );
}
