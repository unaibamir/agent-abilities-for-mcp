<?php
/**
 * What the owner and the agent are told about another plugin's tools: which plugin they come from.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * The namespace of a foreign ability slug: the text before the first slash, "core" when there is none.
 *
 * @param string $slug Foreign ability slug.
 * @return string
 */
function aafm_bridge_namespace_of( string $slug ): string {
	$pos = strpos( $slug, '/' );
	return false === $pos ? 'core' : substr( $slug, 0, $pos );
}

/**
 * Make a plugin's Name header safe to repeat: another plugin wrote it, so it is untrusted text.
 *
 * Tags are stripped, control and invisible formatting characters and runs of whitespace collapse to
 * one space, and the result is capped at 60 characters. HTML output still escapes it.
 *
 * @param string $name Raw header value.
 * @return string Plain text, '' when nothing is left.
 */
function aafm_bridge_clean_plugin_name( string $name ): string {
	$name = wp_strip_all_tags( wp_check_invalid_utf8( $name, true ) );
	$name = trim( (string) preg_replace( '/[\s\p{Cc}\p{Cf}\p{Zl}\p{Zp}]+/u', ' ', $name ) );
	return trim( mb_substr( $name, 0, 60 ) );
}

/**
 * The Name header of the active plugin whose folder, main-file name or text domain is this namespace.
 *
 * Only for the heading, the source line and the error path: get_plugins() parses every plugin file
 * when the object cache is cold, so the Activity Log's query path must never reach this.
 *
 * @param string $ns Ability namespace.
 * @return string Cleaned name, '' when no active plugin matches.
 */
function aafm_bridge_active_plugin_name( string $ns ): string {
	static $built_for = null;
	static $names     = array();

	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	$active = array_filter( (array) get_option( 'active_plugins', array() ), 'is_string' );
	if ( is_multisite() ) {
		$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
	}

	$signature = implode( '|', $active );
	if ( $signature !== $built_for ) {
		$built_for = $signature;
		$names     = array();
		$plugins   = get_plugins();
		foreach ( $active as $file ) {
			$file = (string) $file;
			if ( ! isset( $plugins[ $file ] ) ) {
				continue;
			}
			$name = aafm_bridge_clean_plugin_name( (string) ( $plugins[ $file ]['Name'] ?? '' ) );
			if ( '' === $name ) {
				continue;
			}
			$candidates = array(
				false === strpos( $file, '/' ) ? '' : dirname( $file ),
				basename( $file, '.php' ),
				(string) ( $plugins[ $file ]['TextDomain'] ?? '' ),
			);
			foreach ( $candidates as $candidate ) {
				if ( '' !== $candidate && ! isset( $names[ strtolower( $candidate ) ] ) ) {
					$names[ strtolower( $candidate ) ] = $name;
				}
			}
		}
	}

	return $names[ $ns ] ?? '';
}

/**
 * The plugin name we can stand behind for a namespace: the known map, our integration cards, then an
 * active plugin's header. A Title Case guess is never a plugin name, so this returns '' without a match.
 *
 * @param string $ns Ability namespace.
 * @return string
 */
function aafm_bridge_provider_label( string $ns ): string {
	$known = aafm_bridge_known_plugin_labels();
	if ( isset( $known[ $ns ] ) ) {
		return $known[ $ns ];
	}

	$cards = aafm_integration_cards();
	if ( isset( $cards[ $ns ]['label'] ) ) {
		return (string) $cards[ $ns ]['label'];
	}

	return aafm_bridge_active_plugin_name( $ns );
}

/**
 * A translated risk word for a bridged tool, and the reason when we inferred it from silence.
 *
 * @param array<string,mixed> $risk Result of aafm_bridge_risk().
 * @return array{label:string,note:string}
 */
function aafm_bridge_risk_label( array $risk ): array {
	$labels = array(
		'read'        => __( 'Read', 'agent-abilities-for-mcp' ),
		'write'       => __( 'Write', 'agent-abilities-for-mcp' ),
		'destructive' => __( 'Destructive', 'agent-abilities-for-mcp' ),
	);
	$key    = (string) ( $risk['risk'] ?? 'destructive' );

	return array(
		'label' => $labels[ $key ] ?? $labels['destructive'],
		'note'  => empty( $risk['declared'] ) ? __( 'The plugin did not label this tool, so it is treated as destructive.', 'agent-abilities-for-mcp' ) : '',
	);
}

/**
 * Whether a bridged tool may return personal data, and of what kind.
 *
 * An exact-slug map of tools known to carry data, then a namespace map a filter can add to, then
 * whole-word tokens in the tool name. A false positive only costs a notice, so this leans toward
 * flagging, and the filter is a union: it cannot take a built-in away.
 *
 * @param string $slug Foreign ability slug.
 * @return string '', 'forms', 'lms', 'membership', 'orders', 'payments' or 'users'.
 */
function aafm_bridge_personal_data_kind( string $slug ): string {
	$exact = array(
		'woocommerce/orders-query'                       => 'orders',
		'woocommerce/order-add-note'                     => 'orders',
		'woocommerce/order-update-status'                => 'orders',
		'woocommerce-gateway-stripe/get-charges'         => 'payments',
		'woocommerce-gateway-stripe/get-charge'          => 'payments',
		'woocommerce-gateway-stripe/get-payment-intent'  => 'payments',
		'woocommerce-gateway-stripe/get-disputes'        => 'payments',
		'woocommerce-gateway-stripe/get-dispute'         => 'payments',
		'woocommerce-paypal-payments/get-paypal-order'   => 'payments',
		'woocommerce-paypal-payments/get-order-tracking' => 'payments',
		'simple-history/get-user-activity'               => 'users',
		'simple-history/get-failed-logins'               => 'users',
		'simple-history/get-recent-events'               => 'users',
		'simple-history/search-events'                   => 'users',
		'simple-history/get-event'                       => 'users',
	);
	if ( isset( $exact[ $slug ] ) ) {
		return $exact[ $slug ];
	}

	$pos = strpos( $slug, '/' );
	if ( false === $pos ) {
		return '';
	}

	$tokens = array(
		'forms'      => array( 'entries', 'entry', 'submissions', 'submission' ),
		'lms'        => array( 'learner', 'learners', 'student', 'students', 'enrol', 'enrollment', 'enrolment', 'course-progress' ),
		'membership' => array( 'member', 'members', 'membership', 'subscriber', 'subscribers' ),
		'orders'     => array( 'order', 'orders', 'customer', 'customers', 'billing', 'refund', 'refunds' ),
		'payments'   => array( 'charge', 'charges', 'payment-intent', 'dispute', 'disputes', 'payout', 'payouts' ),
		'users'      => array( 'user-activity', 'failed-logins', 'login-attempts' ),
	);

	$namespaces = array(
		'wpforms'    => 'forms',
		'fluentform' => 'forms',
		'ninjaforms' => 'forms',
		'sureforms'  => 'forms',
	);
	/**
	 * Add namespaces whose tools may return personal data.
	 *
	 * A union over the built-in map: a filter can add a namespace, never remove or change one.
	 *
	 * @param array<string,string> $namespaces Namespace => 'forms', 'lms', 'membership', 'orders', 'payments' or 'users'.
	 */
	$added = apply_filters( 'aafm_bridge_personal_data_namespaces', $namespaces );
	$ns    = substr( $slug, 0, $pos );
	if ( isset( $namespaces[ $ns ] ) ) {
		return $namespaces[ $ns ];
	}
	if ( is_array( $added ) && isset( $added[ $ns ], $tokens[ $added[ $ns ] ] ) ) {
		return (string) $added[ $ns ];
	}

	$tool = '-' . substr( $slug, $pos + 1 ) . '-';
	foreach ( $tokens as $kind => $words ) {
		foreach ( $words as $word ) {
			if ( false !== strpos( $tool, '-' . $word . '-' ) ) {
				return $kind;
			}
		}
	}

	return '';
}

/**
 * The notice for a tool that may return personal data. An indicator, never a determination.
 *
 * @param string $kind Result of aafm_bridge_personal_data_kind().
 * @return string '' when the tool is not flagged.
 */
function aafm_bridge_personal_data_notice( string $kind ): string {
	if ( '' === $kind ) {
		return '';
	}

	return __( 'This tool may return personal data, such as names, email addresses or order details. This notice is based on the plugin and tool name and is not a full check, so treat any tool from another plugin as able to return personal data.', 'agent-abilities-for-mcp' );
}
