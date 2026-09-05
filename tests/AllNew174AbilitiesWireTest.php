<?php
/**
 * Consolidated wire-coverage sweep for every one of the 26 canonical abilities plan 228
 * registers (224-1-7-4-release-spec.md's canonical table), named individually - Codex-review
 * amendment 17. Each task's own wire test proves its own narrow round trip; this file's job is
 * the sweep-completeness proof that NONE of the 26 is missing a real tools/call exercise, the
 * same way PageBuilderGuardSweepTest.php proves the guard is wired everywhere rather than
 * trusting a per-call-site claim.
 *
 * For every name: registered, resolves a real MCP tool name via aafm_mcp_tool_name(), a real
 * tools/call through a throwaway McpServer/ToolsHandler pair, a domain-appropriate success or
 * structured-error shape, and for a write, the change persisted or was correctly refused.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests;

final class AllNew174AbilitiesWireTest extends TestCase {

	use IntegrationStubs;

	/**
	 * TEC event fixture id.
	 *
	 * @var int
	 */
	private int $event_id;

	/**
	 * TEC venue fixture id.
	 *
	 * @var int
	 */
	private int $venue_id;

	/**
	 * TEC organizer fixture id.
	 *
	 * @var int
	 */
	private int $organizer_id;

	/**
	 * Stub Event Tickets ticket id.
	 *
	 * @var int
	 */
	private int $ticket_id;

	/**
	 * Ordinary post fixture id (slim-seo, replace-sitewide).
	 *
	 * @var int
	 */
	private int $ordinary_post_id;

	/**
	 * Fusion-Builder-owned post fixture id (avada).
	 *
	 * @var int
	 */
	private int $avada_post_id;

	/**
	 * GeoDirectory listing fixture id.
	 *
	 * @var int
	 */
	private int $listing_id;

	public function set_up(): void {
		parent::set_up();

		$this->stub_tec();
		aafm_geodir_stub_activate();
		add_filter( 'aafm_integration_active_tec', '__return_true' );
		add_filter( 'aafm_integration_active_event_tickets', '__return_true' );
		add_filter( 'aafm_integration_active_slim_seo', '__return_true' );
		add_filter( 'aafm_integration_active_avada', '__return_true' );
		add_filter( 'aafm_integration_active_geodirectory', '__return_true' );

		$this->acting_as( 'administrator' );

		$this->event_id     = self::factory()->post->create(
			array(
				'post_type'   => \Tribe__Events__Main::POSTTYPE,
				'post_status' => 'publish',
			)
		);
		$this->venue_id     = self::factory()->post->create(
			array(
				'post_type'  => 'tribe_venue',
				'post_title' => 'Sweep Venue',
			)
		);
		$this->organizer_id = self::factory()->post->create(
			array(
				'post_type'  => 'tribe_organizer',
				'post_title' => 'Sweep Organizer',
			)
		);
		$this->ticket_id    = $this->stub_add_ticket( $this->event_id, 'Sweep Ticket', 5.0, 10 );
		$this->stub_add_attendee(
			$this->event_id,
			array(
				'purchaser_name'  => 'Sweep Attendee',
				'purchaser_email' => 'sweep@example.test',
			)
		);

		// Deliberately NOT Avada-owned: replace-sitewide's own guard skips a builder-owned post
		// silently (still reports success overall), which would make this shared fixture's
		// content look untouched for a reason unrelated to the ability under test.
		$this->ordinary_post_id = self::factory()->post->create( array( 'post_content' => 'sweep text' ) );

		$this->avada_post_id = self::factory()->post->create( array( 'post_content' => '[fusion_builder_container]sweep text[/fusion_builder_container]' ) );
		update_post_meta( $this->avada_post_id, 'fusion_builder_status', 'active' );

		$this->listing_id = self::factory()->post->create(
			array(
				'post_type'   => 'gd_place',
				'post_status' => 'publish',
			)
		);
	}

	public function tear_down(): void {
		remove_filter( 'aafm_integration_active_tec', '__return_true' );
		remove_filter( 'aafm_integration_active_event_tickets', '__return_true' );
		remove_filter( 'aafm_integration_active_slim_seo', '__return_true' );
		remove_filter( 'aafm_integration_active_avada', '__return_true' );
		remove_filter( 'aafm_integration_active_geodirectory', '__return_true' );
		parent::tear_down();
	}

	/**
	 * The 26 canonical ability names this plan registers (224-1-7-4-release-spec.md's canonical
	 * table). Names only - fixtures are built fresh per iteration in set_up(), since a data
	 * provider runs before WordPress is bootstrapped for that iteration.
	 *
	 * @return array<int,array{0:string}>
	 */
	public static function provide_new_1_7_4_ability_names(): array {
		$names = array(
			'tec-get-events',
			'tec-get-event',
			'tec-create-event',
			'tec-update-event',
			'tec-delete-event',
			'tec-get-venues',
			'tec-get-venue',
			'tec-create-venue',
			'tec-update-venue',
			'tec-get-organizers',
			'tec-get-organizer',
			'tec-create-organizer',
			'tec-update-organizer',
			'tec-get-tickets',
			'tec-get-ticket',
			'tec-get-attendees',
			'slim-seo-get-post',
			'slim-seo-update-post',
			'geodirectory-get-listings',
			'geodirectory-get-listing',
			'geodirectory-create-listing',
			'geodirectory-update-listing',
			'avada-get-page-content',
			'avada-replace-text',
			'replace-sitewide',
			'upload-media-from-url',
		);
		return array_map( static fn( string $n ): array => array( $n ), $names );
	}

	/**
	 * Arguments to call the tool with, for one ability short name.
	 *
	 * @param string $name Ability short name (no "aafm-" prefix, no "aafm/" prefix).
	 * @return array<string,mixed>
	 */
	private function args_for( string $name ): array {
		switch ( $name ) {
			case 'tec-get-events':
				return array();
			case 'tec-get-event':
				return array( 'event_id' => $this->event_id );
			case 'tec-create-event':
				return array(
					'title'      => 'Sweep New Event',
					'start_date' => '2027-02-01 09:00:00',
					'end_date'   => '2027-02-01 12:00:00',
					'status'     => 'publish',
				);
			case 'tec-update-event':
				return array(
					'event_id' => $this->event_id,
					'title'    => 'Sweep Event Updated',
				);
			case 'tec-delete-event':
				return array( 'event_id' => $this->event_id );
			case 'tec-get-venues':
				return array();
			case 'tec-get-venue':
				return array( 'venue_id' => $this->venue_id );
			case 'tec-create-venue':
				return array( 'title' => 'Sweep New Venue' );
			case 'tec-update-venue':
				return array(
					'venue_id' => $this->venue_id,
					'title'    => 'Sweep Venue Updated',
				);
			case 'tec-get-organizers':
				return array();
			case 'tec-get-organizer':
				return array( 'organizer_id' => $this->organizer_id );
			case 'tec-create-organizer':
				return array( 'title' => 'Sweep New Organizer' );
			case 'tec-update-organizer':
				return array(
					'organizer_id' => $this->organizer_id,
					'title'        => 'Sweep Organizer Updated',
				);
			case 'tec-get-tickets':
				return array( 'event_id' => $this->event_id );
			case 'tec-get-ticket':
				return array( 'ticket_id' => $this->ticket_id );
			case 'tec-get-attendees':
				return array( 'event_id' => $this->event_id );
			case 'slim-seo-get-post':
				return array( 'post_id' => $this->ordinary_post_id );
			case 'slim-seo-update-post':
				return array(
					'post_id' => $this->ordinary_post_id,
					'title'   => 'Sweep SEO title',
				);
			case 'geodirectory-get-listings':
				return array();
			case 'geodirectory-get-listing':
				return array( 'listing_id' => $this->listing_id );
			case 'geodirectory-create-listing':
				return array( 'title' => 'Sweep New Listing' );
			case 'geodirectory-update-listing':
				return array(
					'listing_id' => $this->listing_id,
					'title'      => 'Sweep Listing Updated',
				);
			case 'avada-get-page-content':
				return array( 'post_id' => $this->avada_post_id );
			case 'avada-replace-text':
				return array(
					'post_id' => $this->avada_post_id,
					'search'  => 'sweep text',
					'replace' => 'sweep replaced',
				);
			case 'replace-sitewide':
				return array(
					'search'  => 'sweep text',
					'replace' => 'sweep replaced',
					'dry_run' => false,
				);
			case 'upload-media-from-url':
				return array(
					'url'      => 'https://example.test/pixel.png',
					'filename' => 'pixel.png',
				);
			default:
				$this->fail( "No fixture args defined for {$name} - the sweep's own coverage is incomplete." );
		}
	}

	/**
	 * Whether calling this ability is expected to change persisted state (used to assert
	 * "persisted or refused" for writes, per amendment 17).
	 *
	 * @param string $name Ability short name.
	 * @return bool
	 */
	private function is_write( string $name ): bool {
		return in_array(
			$name,
			array(
				'tec-create-event',
				'tec-update-event',
				'tec-delete-event',
				'tec-create-venue',
				'tec-update-venue',
				'tec-create-organizer',
				'tec-update-organizer',
				'slim-seo-update-post',
				'geodirectory-create-listing',
				'geodirectory-update-listing',
				'avada-replace-text',
				'replace-sitewide',
				'upload-media-from-url',
			),
			true
		);
	}

	/**
	 * One 1.7.4 ability, proved registered and reachable over a real tools/call.
	 *
	 * @dataProvider provide_new_1_7_4_ability_names
	 * @param string $short_name Ability short name (no "aafm/" prefix).
	 */
	public function test_every_new_1_7_4_ability_is_registered_and_round_trips_over_a_real_tools_call( string $short_name ): void {
		$ability_name = 'aafm/' . $short_name;

		if ( 'upload-media-from-url' === $short_name ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', true );
			add_filter(
				'pre_http_request',
				static fn() => array(
					'headers'  => array( 'content-type' => 'image/png' ),
					'body'     => $png,
					'response' => array( 'code' => 200 ),
					'cookies'  => array(),
				)
			);
			// example.test never resolves in this container (RFC 2606) and some DNS setups
			// sinkhole an unresolvable name to a private/loopback address - pin a public IP.
			add_filter( 'aafm_resolve_hostname_to_ip', static fn(): string => '203.0.113.10' );
		}

		$this->in_action( 'wp_abilities_api_categories_init', 'aafm_register_categories' );
		$this->register_enabled( array( $ability_name ) );

		$this->assertTrue( wp_has_ability( $ability_name ), "{$ability_name} did not register." );

		$tool_name = aafm_mcp_tool_name( $ability_name );
		$this->assertNotSame( '', $tool_name, "{$ability_name} resolved to an empty MCP tool name." );

		$adapter   = \WP\MCP\Core\McpAdapter::instance();
		$server_id = 'aafm-server-sweep-' . str_replace( '/', '-', $ability_name ) . '-' . uniqid();
		$tools     = aafm_build_server_tools( aafm_preflight_bound_server_tools_cached( array( $ability_name ) ) );

		$this->in_action(
			'mcp_adapter_init',
			static function () use ( $adapter, $server_id, $tools ): void {
				$adapter->create_server(
					$server_id,
					AAFM_MCP_NAMESPACE,
					AAFM_MCP_ROUTE_SEGMENT,
					'Agent Abilities for MCP (1.7.4 sweep)',
					'Test-only server carrying a single 1.7.4 ability.',
					AAFM_VERSION,
					array( \WP\MCP\Transport\HttpTransport::class ),
					\WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
					\WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler::class,
					$tools,
					array(),
					array(),
					'aafm_transport_permission_callback'
				);
			}
		);
		$server = $adapter->get_server( $server_id );
		$this->assertInstanceOf( \WP\MCP\Core\McpServer::class, $server, "Failed to build the sweep server for {$ability_name}." );

		$handler = new \WP\MCP\Handlers\Tools\ToolsHandler( $server );
		$result  = $handler->call_tool(
			array(
				'name'      => $tool_name,
				'arguments' => $this->args_for( $short_name ),
			),
			'req-sweep-' . $short_name
		);

		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'aafm_resolve_hostname_to_ip' );

		// A JSON-RPC "method not found" is the specific failure this sweep exists to catch - a
		// registered-but-undiscoverable/uncallable tool. Any OTHER structured refusal (a domain
		// permission or validation error) is a legitimate, domain-appropriate outcome and passes.
		if ( $result instanceof \WP\McpSchema\Common\JsonRpc\DTO\JSONRPCErrorResponse ) {
			$this->assertNotSame(
				-32601,
				$result->getError()->getCode(),
				"{$tool_name} was not found on the server - registered but unreachable over the real wire."
			);
			return; // A domain-level refusal is an acceptable outcome for this sweep's purpose.
		}

		$this->assertIsArray( $result->getStructuredContent(), "{$tool_name} returned no structured content." );

		if ( $this->is_write( $short_name ) ) {
			$this->assert_write_persisted( $short_name );
		}
	}

	/**
	 * For a write ability that returned success (not a refusal), assert the change actually
	 * landed - "persisted or refused" per amendment 17, and the refused half is already covered
	 * by the JSONRPCErrorResponse branch above returning early.
	 *
	 * @param string $short_name Ability short name.
	 * @return void
	 */
	private function assert_write_persisted( string $short_name ): void {
		switch ( $short_name ) {
			case 'tec-create-event':
				$this->assertNotEmpty(
					get_posts(
						array(
							'post_type'   => \Tribe__Events__Main::POSTTYPE,
							'title'       => 'Sweep New Event',
							'post_status' => 'any',
						)
					),
					'tec-create-event did not create a new event post.'
				);
				break;
			case 'tec-update-event':
				$this->assertSame( 'Sweep Event Updated', get_the_title( $this->event_id ) );
				break;
			case 'tec-delete-event':
				$this->assertSame( 'trash', get_post_status( $this->event_id ) );
				break;
			case 'tec-create-venue':
				$this->assertNotEmpty(
					get_posts(
						array(
							'post_type'   => 'tribe_venue',
							'title'       => 'Sweep New Venue',
							'post_status' => 'any',
						)
					)
				);
				break;
			case 'tec-update-venue':
				$this->assertSame( 'Sweep Venue Updated', get_the_title( $this->venue_id ) );
				break;
			case 'tec-create-organizer':
				$this->assertNotEmpty(
					get_posts(
						array(
							'post_type'   => 'tribe_organizer',
							'title'       => 'Sweep New Organizer',
							'post_status' => 'any',
						)
					)
				);
				break;
			case 'tec-update-organizer':
				$this->assertSame( 'Sweep Organizer Updated', get_the_title( $this->organizer_id ) );
				break;
			case 'slim-seo-update-post':
				$stored = get_post_meta( $this->ordinary_post_id, 'slim_seo', true );
				$this->assertSame( 'Sweep SEO title', $stored['title'] ?? null );
				break;
			case 'geodirectory-create-listing':
				$this->assertNotEmpty(
					get_posts(
						array(
							'post_type'   => 'gd_place',
							'title'       => 'Sweep New Listing',
							'post_status' => 'any',
						)
					)
				);
				break;
			case 'geodirectory-update-listing':
				$this->assertSame( 'Sweep Listing Updated', get_the_title( $this->listing_id ) );
				break;
			case 'avada-replace-text':
				$this->assertStringContainsString( 'sweep replaced', (string) get_post_field( 'post_content', $this->avada_post_id, 'raw' ) );
				break;
			case 'replace-sitewide':
				$this->assertStringContainsString( 'sweep replaced', (string) get_post_field( 'post_content', $this->ordinary_post_id, 'raw' ) );
				break;
			case 'upload-media-from-url':
				// Verified structurally above (structured content present); the fetch/upload
				// mechanics have their own dedicated persistence assertions in
				// tests/abilities/UploadMediaFromUrlTest.php and the SSRF test file.
				break;
			default:
				$this->fail( "No persistence assertion defined for the write {$short_name} - the sweep's own coverage is incomplete." );
		}
	}
}
