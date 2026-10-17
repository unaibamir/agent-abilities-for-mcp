<?php
/**
 * Demo data for the wordpress.org screenshots. Refuses to run anywhere but a disposable site.
 * Never touches a user's password. Runs on 1.8.0 and on 1.9.0 code: the 1.9.0 parts are feature-detected,
 * so the same script serves Part 0 and Part C.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$aafm_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
$aafm_ok   = 0 === strpos( DB_NAME, 'aafm_smoke' ) || 'localhost' === $aafm_host;
// The DDEV site's docroot is /var/www/html/wp; the smoke site lives under /tmp. Never seed the former.
if ( ! $aafm_ok || 0 === strpos( ABSPATH, '/var/www/html' ) ) {
	WP_CLI::error( 'Refusing to seed: this does not look like a disposable site.' );
}
global $wpdb;
mt_srand( 1909 );

update_option( 'blogname', 'Agent Abilities Demo' );
update_option( 'blogdescription', 'A demo site' );
wp_update_user( array( 'ID' => 1, 'display_name' => 'Demo Owner' ) );

// Keep WooCommerce's own onboarding out of the frames. Option names are from WooCommerce, not from this
// plugin, and were not verified at planning time: the capture script's page-text scan is the proof.
if ( function_exists( 'WC' ) ) {
	delete_transient( '_wc_activation_redirect' );
	update_option( 'woocommerce_task_list_hidden', 'yes' );
	update_option( 'woocommerce_show_marketplace_suggestions', 'no' );
	update_option( 'woocommerce_coming_soon', 'no' );
}

// Abilities: every core read, two writes, and (when WooCommerce is active) its abilities.
$registry       = aafm_get_abilities_registry_full();
$integrations   = aafm_integration_ability_order();
$in_integration = array();
foreach ( $integrations as $rows ) {
	foreach ( $rows as $row ) {
		$in_integration[ $row['name'] ] = true;
	}
}
$enabled = array();
foreach ( $registry as $name => $row ) {
	if ( 'read' === ( $row['risk'] ?? '' ) && ! isset( $in_integration[ $name ] ) ) {
		$enabled[] = $name;
	}
}
foreach ( array( 'aafm/create-post', 'aafm/update-post' ) as $name ) {
	if ( isset( $registry[ $name ] ) ) {
		$enabled[] = $name;
	}
}
if ( function_exists( 'WC' ) && isset( $integrations['woocommerce'] ) ) {
	foreach ( $integrations['woocommerce'] as $row ) {
		$enabled[] = $row['name'];
	}
}
update_option( 'aafm_enabled_abilities', array_values( array_unique( $enabled ) ) );

// OAuth and two apps.
aafm_install_oauth_tables();
update_option( 'aafm_oauth_enabled', '1' );
$claude    = aafm_oauth_register_client( array( 'redirect_uris' => array( 'https://claude.ai/api/mcp/auth_callback' ), 'client_name' => 'Claude' ) );
$gpt       = aafm_oauth_register_client( array( 'redirect_uris' => array( 'https://chatgpt.com/connector_platform_oauth_redirect' ), 'client_name' => 'ChatGPT' ) );
$claude_id = is_array( $claude ) ? $claude['client_id'] : '';
$gpt_id    = is_array( $gpt ) ? $gpt['client_id'] : '';

// Grants and an agent user's Application Password (1.10.0): one active and recently used, one paused,
// one expired, each with its own access preset. Demo users only; 203.0.113.0/24 is a documentation range.
if ( function_exists( 'aafm_oauth_grant_update' ) ) {
	$editor = wp_insert_user( array( 'user_login' => 'demo-editor', 'user_pass' => wp_generate_password(), 'role' => 'editor', 'user_email' => 'editor@example.com' ) );
	$editor = is_wp_error( $editor ) ? (int) username_exists( 'demo-editor' ) : $editor;
	$grants = array(
		array( 1, $claude_id, array( 'preset' => 'content_editor' ) ),
		array( 1, $gpt_id, array( 'preset' => 'read_only', 'paused' => true ) ),
		array( $editor, $claude_id, array( 'preset' => 'shop_manager', 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ), 'rate' => 20 ) ),
	);
	foreach ( $grants as $g ) {
		aafm_oauth_record_consent( $g[0], $g[1] );
		aafm_oauth_grant_update( $g[0], $g[1], $g[2] );
	}
	$wpdb->update( $wpdb->prefix . 'aafm_oauth_consents', array( 'last_used_at' => gmdate( 'Y-m-d H:i:s', time() - 25 * MINUTE_IN_SECONDS ), 'last_used_ip' => '203.0.113.24' ), array( 'wp_user_id' => 1, 'client_id' => $claude_id ) );

	$agent = wp_insert_user( array( 'user_login' => 'ai-agent', 'user_pass' => wp_generate_password(), 'role' => 'subscriber', 'user_email' => 'agent@example.com' ) );
	if ( ! is_wp_error( $agent ) ) {
		update_user_meta( $agent, aafm_agent_user_marker_meta_key(), 1 );
		$created = WP_Application_Passwords::create_new_application_password( $agent, array( 'name' => 'Claude Desktop' ) );
		if ( ! is_wp_error( $created ) ) {
			$items = WP_Application_Passwords::get_user_application_passwords( $agent );
			foreach ( $items as &$item ) {
				$item['created']   = time() - 3 * DAY_IN_SECONDS;
				$item['last_used'] = time() - 2 * HOUR_IN_SECONDS;
				$item['last_ip']   = '203.0.113.31';
			}
			unset( $item );
			update_user_meta( $agent, WP_Application_Passwords::USERMETA_KEY_APPLICATION_PASSWORDS, $items );
		}
	}
}

// Site Brief (1.8.0).
update_option( 'aafm_site_brief', "Use British spelling.\nNever change the pricing page.\nDrafts only on Fridays." );

// Notifications (1.9.0 only). Three triggers ticked; the address is on a reserved example domain and the
// option is written directly, so no event fires and no mail is attempted.
if ( function_exists( 'aafm_notifications_settings' ) ) {
	update_option( 'aafm_notifications', array( 'mode' => 'immediate', 'email' => 'owner@example.com', 'triggers' => array( 'new_app' => true, 'risky_run' => false, 'denial_burst' => true, 'switch_flip' => true ) ), true );
}

// Keep one-time prompts out of the frames.
update_option( 'aafm_review_request', array( 'status' => 'dismissed' ) );
update_option( 'aafm_quickconnect_finished', '1' );
delete_option( 'aafm_menu_pointer_active' );

// Agent drafts for the Needs review card (1.8.0).
foreach ( array( 'Spring offer landing page', 'Newsletter: October roundup' ) as $title ) {
	$id = wp_insert_post( array( 'post_title' => $title, 'post_status' => 'draft', 'post_author' => 1, 'post_content' => 'Draft written by an agent.' ) );
	update_post_meta( $id, '_aafm_agent_tool', 'aafm/create-post' );
	update_post_meta( $id, '_aafm_agent_user', 1 );
	update_post_meta( $id, '_aafm_agent_client', $claude_id );
}

// Sixty-odd activity rows over seven days. 203.0.113.0/24 is a documentation range.
$v19          = function_exists( 'aafm_compose_denied_detail' );
$has_duration = aafm_activity_log_has_column( aafm_activity_log_table(), 'duration_ms' );
// The first admin page load runs a one-time backfill that logs a write-outcome row. Run it now, so the
// row is cleared with the rest instead of heading the Activity Log in the frame.
if ( function_exists( 'aafm_backfill_agent_user_marker' ) ) {
	aafm_backfill_agent_user_marker();
}
aafm_clear_activity_log();
$table   = aafm_activity_log_table();
$rows    = array();
$add     = static function ( string $ability, string $status, string $client, ?string $detail, ?int $duration ) use ( &$rows ): void {
	$rows[] = array( $ability, $status, $client, $detail, $duration );
};
$refused = static function ( string $base, string $code ) use ( $v19 ): string {
	return $v19 ? $base . '. Refused: ' . $code : $base;
};
$plan = array( 'aafm/get-posts' => 22, 'aafm/get-post' => 14, 'aafm/get-pages' => 8, 'aafm/create-post' => 5, 'aafm/update-post' => 4, 'aafm/get-terms' => 3 );
foreach ( $plan as $ability => $n ) {
	for ( $i = 0; $i < $n; $i++ ) {
		$client = array( $claude_id, $gpt_id, '' )[ mt_rand( 0, 2 ) ];
		$add( $ability, 'success', $client, null, mt_rand( 12, 900 ) );
	}
}
$add( 'aafm/get-posts', 'error', $claude_id, 'aafm_error', 1400 );
$add( 'aafm/update-post', 'error', $gpt_id, 'aafm_error', 240 );
for ( $i = 0; $i < 3; $i++ ) {
	$add( 'aafm/update-post-meta', 'denied', $claude_id, $refused( 'Attempted: Updated meta key `price_note` on post #12', 'not_allowlisted' ), null );
}
for ( $i = 0; $i < 3; $i++ ) {
	$add( 'aafm/trash-post', 'denied', $gpt_id, $refused( 'Attempted: Trashed post #8', 'scope_list' ), null );
}
$add( 'aafm/get-posts', 'denied', $gpt_id, $v19 ? 'Refused: rate_limit' : null, null );
$add( 'aafm/delete-post', 'denied', $claude_id, $refused( 'Attempted: Deleted post #9', 'capability' ), null );
foreach ( $rows as $r ) {
	$data = array(
		'ability'           => $r[0],
		'principal_user_id' => 1,
		'principal_login'   => 'admin',
		'status'            => $r[1],
		'arg_keys'          => 'post_id',
		'source_ip'         => '203.0.113.' . mt_rand( 10, 40 ),
		'client_id'         => $r[2],
		'event_type'        => 'ability_call',
		'detail'            => $r[3],
		'created_at'        => gmdate( 'Y-m-d H:i:s', time() - mt_rand( 60, 7 * DAY_IN_SECONDS - 3600 ) ),
	);
	if ( $has_duration ) {
		$data['duration_ms'] = $r[4];
	}
	$wpdb->insert( $table, $data );
}
if ( function_exists( 'aafm_activity_summary_flush' ) ) {
	aafm_activity_summary_flush();
}
WP_CLI::success( 'Demo data seeded.' );
