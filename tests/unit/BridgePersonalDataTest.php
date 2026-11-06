<?php
/**
 * Which bridged tools the screen warns may return personal data.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Unit;

use AAFM\Tests\TestCase;

final class BridgePersonalDataTest extends TestCase {

	public function test_the_form_namespaces_the_matrix_verified_are_flagged_forms(): void {
		foreach ( array( 'wpforms', 'fluentform', 'ninjaforms', 'sureforms' ) as $ns ) {
			$this->assertSame( 'forms', aafm_bridge_personal_data_kind( $ns . '/get-settings' ), $ns );
		}
	}

	public function test_a_token_in_the_slug_flags_an_unlisted_plugin(): void {
		$this->assertSame( 'forms', aafm_bridge_personal_data_kind( 'acme/list-entries' ) );
		$this->assertSame( 'lms', aafm_bridge_personal_data_kind( 'acme/get-learner-progress' ) );
		$this->assertSame( 'lms', aafm_bridge_personal_data_kind( 'acme/course-progress' ) );
		$this->assertSame( 'membership', aafm_bridge_personal_data_kind( 'acme/list-members' ) );
		$this->assertSame( 'orders', aafm_bridge_personal_data_kind( 'acme/list-refunds' ) );
		$this->assertSame( 'payments', aafm_bridge_personal_data_kind( 'acme/get-payment-intent' ) );
		$this->assertSame( 'users', aafm_bridge_personal_data_kind( 'acme/login-attempts' ) );
	}

	public function test_a_token_matches_whole_words_after_the_slash(): void {
		$this->assertSame( '', aafm_bridge_personal_data_kind( 'acme/reorder-items' ), '"order" inside a longer word.' );
		$this->assertSame( '', aafm_bridge_personal_data_kind( 'orders/get-stats' ), 'The namespace is not scanned for tokens.' );
		$this->assertSame( '', aafm_bridge_personal_data_kind( 'no-slash-entries' ) );
	}

	public function test_a_plain_tool_is_not_flagged(): void {
		$this->assertSame( '', aafm_bridge_personal_data_kind( 'akismet/get-stats' ) );
		$this->assertSame( '', aafm_bridge_personal_data_kind( 'jetpack/get-modules' ) );
		$this->assertSame( '', aafm_bridge_personal_data_kind( 'woocommerce/products-query' ) );
	}

	public function test_a_known_data_bearing_slug_the_generic_tokens_miss_is_flagged(): void {
		$this->assertSame( 'users', aafm_bridge_personal_data_kind( 'simple-history/get-recent-events' ), 'No token matches this one.' );
		$this->assertSame( 'users', aafm_bridge_personal_data_kind( 'simple-history/get-failed-logins' ) );
		$this->assertSame( 'orders', aafm_bridge_personal_data_kind( 'woocommerce/orders-query' ) );
		$this->assertSame( 'orders', aafm_bridge_personal_data_kind( 'woocommerce/order-add-note' ) );
		$this->assertSame( 'payments', aafm_bridge_personal_data_kind( 'woocommerce-gateway-stripe/get-charge' ) );
		$this->assertSame( 'payments', aafm_bridge_personal_data_kind( 'woocommerce-paypal-payments/get-order-tracking' ) );
	}

	public function test_the_notice_calls_itself_an_indicator(): void {
		$this->assertSame( '', aafm_bridge_personal_data_notice( '' ) );
		foreach ( array( 'forms', 'lms', 'membership', 'orders', 'payments', 'users' ) as $kind ) {
			$notice = aafm_bridge_personal_data_notice( $kind );
			$this->assertStringContainsString( 'may return personal data', $notice, $kind );
			$this->assertStringContainsString( 'based on the plugin and tool name and is not a full check', $notice, $kind );
			$this->assertStringContainsString( 'treat any tool from another plugin as able to return personal data', $notice, $kind );
		}
	}

	public function test_generic_person_words_in_a_tool_name_carry_the_indicator(): void {
		foreach ( array( 'acme/list-users', 'acme/get-user', 'core/get-user-info', 'acme/get-contacts', 'acme/export-leads' ) as $slug ) {
			$this->assertSame( 'users', aafm_bridge_personal_data_kind( $slug ), $slug );
		}
		$this->assertSame( '', aafm_bridge_personal_data_kind( 'acme/list-usergroups' ), 'Whole words only.' );
	}

	public function test_a_malformed_filter_value_is_ignored_and_does_not_fatal(): void {
		add_filter(
			'aafm_bridge_personal_data_namespaces',
			static fn( $map ) => array_merge(
				$map,
				array(
					'acme-array' => array( 'users' ),
					'acme-int'   => 7,
				)
			)
		);

		$this->assertSame( '', aafm_bridge_personal_data_kind( 'acme-array/get-settings' ) );
		$this->assertSame( '', aafm_bridge_personal_data_kind( 'acme-int/get-settings' ) );
	}

	public function test_the_filter_can_add_a_namespace(): void {
		add_filter(
			'aafm_bridge_personal_data_namespaces',
			static fn( $map ) => array_merge( $map, array( 'acme-crm' => 'users' ) )
		);
		$this->assertSame( 'users', aafm_bridge_personal_data_kind( 'acme-crm/get-settings' ) );
	}

	public function test_the_filter_cannot_remove_a_builtin(): void {
		add_filter( 'aafm_bridge_personal_data_namespaces', '__return_empty_array' );
		$this->assertSame( 'forms', aafm_bridge_personal_data_kind( 'wpforms/get-settings' ) );

		add_filter(
			'aafm_bridge_personal_data_namespaces',
			static fn() => array(
				'wpforms' => '',
				'acme'    => 'not-a-kind',
			)
		);
		$this->assertSame( 'forms', aafm_bridge_personal_data_kind( 'wpforms/get-settings' ), 'Nor blank one out.' );
		$this->assertSame( '', aafm_bridge_personal_data_kind( 'acme/get-settings' ), 'An unknown kind is ignored.' );
	}
}
