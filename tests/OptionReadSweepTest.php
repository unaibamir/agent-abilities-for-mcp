<?php
/**
 * Sweeps (step 14, U-S-T3, U-R-T8, W-3): every get_option(), get_transient(),
 * aafm_read_option_views() and aafm_option_row_if_cache_agrees() call in includes/ and
 * agent-abilities-for-mcp.php, keyed path|function|ordinal (the enclosing named function; a closure
 * counts as the function it sits in). A call is a name token followed by "(", not preceded by
 * function, ->, ?->, :: or new. Every key must match exactly once, so a new call fails this test
 * until it is classified here.
 *
 * Classes for get_option(): G a policy read that also asks aafm_policy_row_if_stale() about the
 * same first-argument expression; L a policy value read only for a log row's "before" field; N not
 * a policy read.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests;

final class OptionReadSweepTest extends TestCase {

	/**
	 * Every get_option() call, key => class.
	 *
	 * @var array<string,string>
	 */
	private const GET_OPTION = array(
		'includes/abilities/settings.php|aafm_exec_get_site_settings|1' => 'N',
		'includes/abilities/settings.php|aafm_exec_update_site_settings|1' => 'N',
		'includes/abilities/settings.php|aafm_exec_update_site_settings|2' => 'N',
		'includes/abilities/users.php|aafm_exec_create_user|1' => 'G',
		'includes/adapter-loader.php|aafm_maybe_disable_standalone_adapter_autoload|1' => 'N',
		'includes/admin/connection.php|aafm_endpoint_url|1' => 'N',
		'includes/admin/connection.php|aafm_backfill_agent_user_marker|1' => 'N',
		'includes/admin/connection.php|aafm_format_admin_datetime|1' => 'N',
		'includes/admin/connection.php|aafm_format_admin_datetime|2' => 'N',
		'includes/admin/dashboard.php|aafm_render_dashboard_tab|1' => 'N',
		'includes/admin/onboarding-pointer.php|aafm_quickconnect_pointer_should_show|1' => 'N',
		'includes/admin/onboarding-pointer.php|aafm_maybe_enqueue_menu_pointer|1' => 'N',
		'includes/admin/page.php|aafm_get_stored_enabled_abilities_raw|1' => 'G',
		'includes/admin/quickconnect.php|aafm_quickconnect_is_finished|1' => 'N',
		'includes/admin/quickconnect.php|aafm_quickconnect_is_dismissed|1' => 'N',
		'includes/admin/quickconnect.php|aafm_quickconnect_site_looks_configured|1' => 'N',
		'includes/admin/quickconnect.php|aafm_quickconnect_apply_abilities|1' => 'L',
		'includes/admin/review-request.php|aafm_review_request_state|1' => 'N',
		'includes/admin/settings.php|aafm_ajax_save_settings|1' => 'L',
		'includes/admin/settings.php|aafm_ajax_save_settings|2' => 'L',
		'includes/admin/settings.php|aafm_render_settings_tab|1' => 'G',
		'includes/admin/settings.php|aafm_render_settings_tab|2' => 'G',
		'includes/audit/high-risk.php|aafm_high_risk_unlocked|1' => 'G',
		'includes/audit/read-only.php|aafm_read_only_mode_stored|1' => 'G',
		'includes/block-guard.php|aafm_block_guard_is_strict|1' => 'G',
		'includes/bridge.php|aafm_get_stored_bridged_abilities_raw|1' => 'G',
		'includes/helpers.php|aafm_allowed_post_types|1'   => 'G',
		'includes/helpers.php|aafm_scoped_allowed_meta_keys|1' => 'G',
		'includes/helpers.php|aafm_scoped_deny_option_raw|1' => 'G',
		'includes/helpers.php|aafm_scoped_meta_has_star|1' => 'G',
		'includes/oauth/discovery.php|aafm_oauth_option_is_on|1' => 'G',
		'includes/oauth/tokens.php|aafm_oauth_mint_tokens|1' => 'G',
		'includes/option-cache.php|aafm_persist_operator_switch|1' => 'N',
		'includes/option-cache.php|aafm_update_option_verified|1' => 'N',
		'includes/registry.php|aafm_get_enabled_abilities|1' => 'G',
		'includes/safety.php|aafm_rate_limit_per_min|1'    => 'G',
		'includes/safety.php|aafm_ip_allowlist|1'          => 'G',
		'includes/safety.php|aafm_force_draft|1'           => 'G',
		'includes/safety.php|aafm_max_title_len|1'         => 'G',
		'includes/safety.php|aafm_log_retention_days|1'    => 'G',
		'includes/server.php|aafm_reconcile_omitted_abilities|1' => 'N',
		'includes/server.php|aafm_notice_omitted_abilities|1' => 'N',
	);

	/**
	 * The options each G key's first-argument expression can hold.
	 *
	 * @var array<string,list<string>>
	 */
	private const G_OPTIONS = array(
		'includes/abilities/users.php|aafm_exec_create_user|1' => array( 'default_role' ),
		'includes/admin/page.php|aafm_get_stored_enabled_abilities_raw|1' => array( 'aafm_enabled_abilities' ),
		'includes/admin/settings.php|aafm_render_settings_tab|1' => array( 'aafm_high_risk_abilities_unlocked' ),
		'includes/admin/settings.php|aafm_render_settings_tab|2' => array( 'aafm_delete_data_on_uninstall' ),
		'includes/audit/high-risk.php|aafm_high_risk_unlocked|1' => array( 'aafm_high_risk_abilities_unlocked' ),
		'includes/audit/read-only.php|aafm_read_only_mode_stored|1' => array( 'aafm_read_only_mode' ),
		'includes/block-guard.php|aafm_block_guard_is_strict|1' => array( 'aafm_block_guard_strict' ),
		'includes/bridge.php|aafm_get_stored_bridged_abilities_raw|1' => array( 'aafm_enabled_bridged_abilities' ),
		'includes/helpers.php|aafm_allowed_post_types|1'   => array( 'aafm_allowed_post_types' ),
		'includes/helpers.php|aafm_scoped_allowed_meta_keys|1' => array( 'aafm_allowed_meta_keys', 'aafm_exposed_term_meta_keys', 'aafm_exposed_user_meta_keys' ),
		'includes/helpers.php|aafm_scoped_deny_option_raw|1' => array( 'aafm_denied_meta_keys', 'aafm_denied_term_meta_keys', 'aafm_denied_user_meta_keys' ),
		'includes/helpers.php|aafm_scoped_meta_has_star|1' => array( 'aafm_allowed_meta_keys', 'aafm_exposed_term_meta_keys', 'aafm_exposed_user_meta_keys' ),
		'includes/oauth/discovery.php|aafm_oauth_option_is_on|1' => array( 'aafm_oauth_enabled', 'aafm_oauth_dcr_enabled' ),
		'includes/oauth/tokens.php|aafm_oauth_mint_tokens|1' => array( 'aafm_oauth_access_ttl', 'aafm_oauth_refresh_ttl' ),
		'includes/registry.php|aafm_get_enabled_abilities|1' => array( 'aafm_enabled_abilities' ),
		'includes/safety.php|aafm_rate_limit_per_min|1'    => array( 'aafm_rate_limit_per_min' ),
		'includes/safety.php|aafm_ip_allowlist|1'          => array( 'aafm_ip_allowlist' ),
		'includes/safety.php|aafm_force_draft|1'           => array( 'aafm_force_draft' ),
		'includes/safety.php|aafm_max_title_len|1'         => array( 'aafm_max_title_len' ),
		'includes/safety.php|aafm_log_retention_days|1'    => array( 'aafm_log_retention_days' ),
	);

	/**
	 * Every get_transient() call. The two rate counters read through aafm_transient_count().
	 *
	 * @var list<string>
	 */
	private const GET_TRANSIENT = array(
		'includes/admin/page.php|aafm_detected_meta_keys|1',
		'includes/admin/review-request.php|aafm_review_request_display_count|1',
		'includes/audit/log.php|aafm_activity_log_schema_admin_notice|1',
		'includes/audit/log.php|aafm_denial_log_within_cap|1',
		'includes/helpers.php|aafm_transient_count|1',
		'includes/oauth/schema.php|aafm_oauth_engine_admin_notice|1',
		'includes/oauth/schema.php|aafm_oauth_schema_admin_notice|1',
		'includes/server.php|aafm_preflight_bound_server_tools_cached|1',
	);

	/**
	 * Every aafm_read_option_views() call. None decides from the cache view except the certifier.
	 *
	 * @var list<string>
	 */
	private const READ_OPTION_VIEWS = array(
		'includes/abilities/woocommerce/gateways.php|aafm_exec_wc_update_payment_gateway|1',
		'includes/admin/onboarding-pointer.php|aafm_quickconnect_flag_menu_pointer|1',
		'includes/admin/page.php|aafm_paired_meta_write_three_stage|1',
		'includes/admin/page.php|aafm_stored_option_list|1',
		'includes/allowlist.php|aafm_allowlist_overrides|1',
		'includes/allowlist.php|aafm_allowlist_overrides_for_display|1',
		'includes/audit/log.php|aafm_maybe_upgrade_activity_log|1',
		'includes/oauth/discovery.php|aafm_oauth_preserve_toggle_on_upgrade|1',
		'includes/oauth/discovery.php|aafm_oauth_preserve_toggle_on_upgrade|2',
		'includes/oauth/discovery.php|aafm_oauth_dcr_adopt_on_by_default|1',
		'includes/oauth/discovery.php|aafm_oauth_dcr_adopt_on_by_default|2',
		'includes/oauth/discovery.php|aafm_oauth_dcr_adopt_on_by_default|3',
		'includes/oauth/schema.php|aafm_maybe_upgrade_oauth_tables|1',
		'includes/option-cache.php|aafm_option_write_certified|1',
		'includes/write-contract.php|aafm_option_write|1',
		'includes/write-contract.php|aafm_wc_write|1',
	);

	/**
	 * Every aafm_option_row_if_cache_agrees() call.
	 *
	 * @var list<string>
	 */
	private const CACHE_AGREES = array(
		'includes/abilities/settings.php|aafm_exec_update_site_settings|1',
		'includes/abilities/woocommerce/gateways.php|aafm_exec_wc_update_payment_gateway|1',
	);

	/**
	 * Calls to $callees in $source: key => first-argument expression (whitespace removed).
	 *
	 * @param string   $source  Full file contents.
	 * @param string   $path    Path the source is keyed under.
	 * @param string[] $callees Function names to find.
	 * @return array<string,array<string,string>> callee => ( key => first argument ).
	 */
	private function calls( string $source, string $path, array $callees ): array {
		$tokens   = token_get_all( $source );
		$nullsafe = defined( 'T_NULLSAFE_OBJECT_OPERATOR' ) ? constant( 'T_NULLSAFE_OBJECT_OPERATOR' ) : -1;
		$names    = array( T_STRING, defined( 'T_NAME_FULLY_QUALIFIED' ) ? constant( 'T_NAME_FULLY_QUALIFIED' ) : T_STRING );
		$skip     = array( T_FUNCTION, T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NEW, $nullsafe );
		$stack    = array(); // [ name, depth at which its body opened ].
		$pending  = null;
		$depth    = 0;
		$ordinals = array();
		$calls    = array();
		$total    = count( $tokens );

		for ( $i = 0; $i < $total; $i++ ) {
			$token = $tokens[ $i ];
			if ( is_array( $token ) && T_FUNCTION === $token[0] ) {
				$next = $this->next_significant( $tokens, $i + 1, 1 );
				if ( null !== $next && is_array( $tokens[ $next ] ) && T_STRING === $tokens[ $next ][0] ) {
					$pending = $tokens[ $next ][1];
				}
				continue;
			}
			if ( '{' === $token || ( is_array( $token ) && in_array( $token[0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) ) {
				++$depth;
				if ( null !== $pending && '{' === $token ) {
					$stack[] = array( $pending, $depth );
					$pending = null;
				}
				continue;
			}
			if ( ';' === $token ) {
				$pending = null;
				continue;
			}
			if ( '}' === $token ) {
				if ( array() !== $stack && end( $stack )[1] === $depth ) {
					array_pop( $stack );
				}
				--$depth;
				continue;
			}
			// PHP 8 reads `\get_option` as one name token; PHP 7.4 reads a separator, then the name.
			if ( ! is_array( $token ) || ! in_array( $token[0], $names, true ) || ! in_array( ltrim( $token[1], '\\' ), $callees, true ) ) {
				continue;
			}
			$name = ltrim( $token[1], '\\' );
			$open = $this->next_significant( $tokens, $i + 1, 1 );
			if ( null === $open || '(' !== $tokens[ $open ] ) {
				continue;
			}
			$before = $this->next_significant( $tokens, $i - 1, -1 );
			if ( null !== $before && is_array( $tokens[ $before ] ) && T_NS_SEPARATOR === $tokens[ $before ][0] ) {
				$before = $this->next_significant( $tokens, $before - 1, -1 );
			}
			if ( null !== $before && is_array( $tokens[ $before ] ) && in_array( $tokens[ $before ][0], $skip, true ) ) {
				continue;
			}

			$argument = '';
			$nesting  = 0;
			for ( $j = $open + 1; $j < $total; $j++ ) {
				$text = is_array( $tokens[ $j ] ) ? $tokens[ $j ][1] : $tokens[ $j ];
				if ( in_array( $text, array( '(', '[', '{' ), true ) ) {
					++$nesting;
				} elseif ( in_array( $text, array( ')', ']', '}' ), true ) ) {
					if ( 0 === $nesting ) {
						break;
					}
					--$nesting;
				} elseif ( ',' === $text && 0 === $nesting ) {
					break;
				}
				if ( ! is_array( $tokens[ $j ] ) || T_WHITESPACE !== $tokens[ $j ][0] ) {
					$argument .= $text;
				}
			}

			$base                            = $path . '|' . ( array() === $stack ? '{main}' : end( $stack )[0] );
			$ordinals[ $base . '|' . $name ] = ( $ordinals[ $base . '|' . $name ] ?? 0 ) + 1;
			$calls[ $name ][ $base . '|' . $ordinals[ $base . '|' . $name ] ] = $argument;
		}

		return $calls;
	}

	/**
	 * Index of the nearest token in $step's direction that is not whitespace or a comment.
	 *
	 * @param array<int,array{0:int,1:string,2:int}|string> $tokens token_get_all() output.
	 * @param int                                           $index  Index to start from.
	 * @param int                                           $step   1 forward, -1 back.
	 */
	private function next_significant( array $tokens, int $index, int $step ): ?int {
		$total = count( $tokens );
		for ( ; $index >= 0 && $index < $total; $index += $step ) {
			if ( is_array( $tokens[ $index ] ) && in_array( $tokens[ $index ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			return $index;
		}
		return null;
	}

	/**
	 * Every swept call in the plugin, callee => ( key => first argument ).
	 *
	 * @return array<string,array<string,string>>
	 */
	private function plugin_calls(): array {
		$root  = AAFM_PLUGIN_DIR;
		$paths = array( 'agent-abilities-for-mcp.php' );
		$files = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root . 'includes', \FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $files as $file ) {
			if ( 'php' === $file->getExtension() ) {
				$paths[] = 'includes/' . ltrim( str_replace( $root . 'includes', '', $file->getPathname() ), '/' );
			}
		}

		$callees = array( 'get_option', 'get_transient', 'aafm_read_option_views', 'aafm_option_row_if_cache_agrees', 'aafm_policy_row_if_stale' );
		$found   = array_fill_keys( $callees, array() );
		foreach ( $paths as $path ) {
			$source = (string) file_get_contents( $root . $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading this plugin's own local source to scan it, not a remote URL.
			$this->assertNotSame( '', $source, "The sweep must read {$path}." );
			foreach ( $this->calls( $source, $path, $callees ) as $callee => $keys ) {
				$found[ $callee ] = array_merge( $found[ $callee ], $keys );
			}
		}
		return $found;
	}

	/**
	 * The keys found for $callee, sorted.
	 *
	 * @param array<string,array<string,string>> $found  plugin_calls() output.
	 * @param string                             $callee Function name.
	 * @return list<string>
	 */
	private function keys_of( array $found, string $callee ): array {
		$keys = array_keys( $found[ $callee ] );
		sort( $keys );
		return $keys;
	}

	/**
	 * Sorted copy.
	 *
	 * @param array<int,string> $keys Keys.
	 * @return array<int,string>
	 */
	private function sorted( array $keys ): array {
		sort( $keys );
		return $keys;
	}

	public function test_the_scanner_keys_calls_and_their_first_argument(): void {
		$source = "<?php\nfunction f( \$k ) {\n\t\$a = get_option( 'x', 1 );\n\t\$b = static function () use ( \$k ) {\n\t\treturn get_option( \$k );\n\t};\n\t\$o->get_option( 'y' );\n\tWP::get_option( 'z' );\n\t\\get_option( 'y' );\n}\nfunction get_option() {}\n";

		$this->assertSame(
			array(
				'get_option' => array(
					'x.php|f|1' => "'x'",
					'x.php|f|2' => '$k',
					'x.php|f|3' => "'y'",
				),
			),
			$this->calls( $source, 'x.php', array( 'get_option' ) )
		);
	}

	public function test_every_get_option_call_is_classified_exactly_once(): void {
		$found = $this->plugin_calls();

		$this->assertSame( $this->sorted( array_keys( self::GET_OPTION ) ), $this->keys_of( $found, 'get_option' ), 'A get_option() call was added, moved or removed; classify it in GET_OPTION.' );
		$this->assertSame(
			array(
				'G' => 20,
				'L' => 3,
				'N' => 19,
			),
			array(
				'G' => count( array_keys( self::GET_OPTION, 'G', true ) ),
				'L' => count( array_keys( self::GET_OPTION, 'L', true ) ),
				'N' => count( array_keys( self::GET_OPTION, 'N', true ) ),
			)
		);
	}

	public function test_every_policy_read_also_asks_whether_its_cache_copy_is_stale(): void {
		$found = $this->plugin_calls();
		$stale = array();
		foreach ( $found['aafm_policy_row_if_stale'] as $key => $argument ) {
			$stale[ implode( '|', array_slice( explode( '|', $key ), 0, 2 ) ) ][] = $argument;
		}

		$this->assertSame( $this->sorted( array_keys( array_filter( self::GET_OPTION, static fn( string $kind ): bool => 'G' === $kind ) ) ), $this->sorted( array_keys( self::G_OPTIONS ) ) );
		foreach ( array_keys( self::G_OPTIONS ) as $key ) {
			$function = implode( '|', array_slice( explode( '|', $key ), 0, 2 ) );
			$this->assertContains( $found['get_option'][ $key ], $stale[ $function ] ?? array(), "{$key} must ask aafm_policy_row_if_stale() about the same option expression." );
		}
	}

	public function test_the_policy_reads_cover_exactly_the_batched_options(): void {
		$options = array( 'aafm_ability_allowlist_overrides' );
		foreach ( self::G_OPTIONS as $list ) {
			$options = array_merge( $options, $list );
		}

		$this->assertCount( 24, aafm_policy_options() );
		$this->assertSame( $this->sorted( aafm_policy_options() ), $this->sorted( array_values( array_unique( $options ) ) ) );
	}

	public function test_every_get_transient_call_is_listed_exactly_once(): void {
		$found = $this->plugin_calls();

		$this->assertCount( 8, $found['get_transient'] );
		$this->assertSame( $this->sorted( self::GET_TRANSIENT ), $this->keys_of( $found, 'get_transient' ) );
	}

	public function test_every_option_view_and_cache_agreement_call_is_listed_exactly_once(): void {
		$found = $this->plugin_calls();

		$this->assertSame( $this->sorted( self::READ_OPTION_VIEWS ), $this->keys_of( $found, 'aafm_read_option_views' ) );
		$this->assertSame( $this->sorted( self::CACHE_AGREES ), $this->keys_of( $found, 'aafm_option_row_if_cache_agrees' ) );
	}
}
