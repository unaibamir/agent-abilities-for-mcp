<?php
/**
 * MCP server registration and tool-name helpers.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Mirror the adapter's McpNameSanitizer for display purposes (connect wizard, diagnostics).
 *
 * CONFIRMED against the vendored 0.5.0 source (Phase 0.5.2): the adapter converts '/' -> '-'
 * and keeps hyphens, producing names in the charset ^[A-Za-z0-9_.-]+$. So `aafm/get-posts`
 * becomes `aafm-get-posts`. Removing the slash is the hard blocker we care about; the few
 * client surfaces that also dislike hyphens (some ChatGPT Apps) are a v1.x follow-up — Claude,
 * Cursor, and Windsurf (our v1 targets) accept hyphenated tool names.
 *
 * @param string $ability_name Ability name, e.g. "aafm/get-posts".
 * @return string Sanitized MCP tool name, e.g. "aafm-get-posts".
 */
function aafm_mcp_tool_name( string $ability_name ): string {
	return str_replace( '/', '-', trim( $ability_name ) );
}

/**
 * Build the registration-time $tools catalog: every enabled ability that exists.
 *
 * IMPORTANT (corrected on the live path in Phase 2.4): create_server() runs inside
 * mcp_adapter_init at rest_api_init priority 15, and on the adapter's streamable-HTTP
 * transport the Application Password user is NOT resolved yet at that point — the request
 * is still anonymous. So this list can only decide WHICH abilities exist, not which the
 * connection may call. Per-connection capability filtering happens later, at request time,
 * in aafm_filter_mcp_tools_list() on the adapter's `mcp_adapter_tools_list` hook, where the
 * agent user IS resolved. The hard gate remains each ability's own permission_callback at
 * execute time. (See ROADMAP "Carried issues" for the timing correction to Phase 0.5 #2.)
 *
 * @param array<int,string> $enabled Enabled ability names.
 * @return list<string>
 */
function aafm_build_server_tools( array $enabled ): array {
	$tools = array();
	foreach ( $enabled as $name ) {
		$ability = wp_get_ability( $name );
		if ( ! $ability instanceof WP_Ability ) {
			continue;
		}
		// If a user is already resolved (e.g. unit tests, or a transport that resolves auth
		// before rest_api_init), drop abilities this user cannot call. On the live HTTP path
		// the user is anonymous here, so this is a no-op and the request-time filter does the
		// real work — belt and suspenders, never advertising more than the catalog.
		if ( is_user_logged_in() ) {
			if ( ! aafm_user_can_discover_ability( $name ) ) {
				continue;
			}
		}
		$tools[] = $name;
	}
	return $tools;
}

/**
 * Whether the current user passes an ability's UNDECORATED permission callback.
 *
 * Uses the raw callback stashed at registration (aafm_remember_raw_permission) so a
 * list-time visibility check never writes a denied audit row. Unknown abilities (no
 * stashed callback) are treated as not-callable — fail closed.
 *
 * @param string              $ability_name Ability name, e.g. "aafm/trash-post".
 * @param array<string,mixed> $input        Input to pass to the permission callback.
 * @return bool
 */
function aafm_user_can_call_ability( string $ability_name, array $input = array() ): bool {
	$permission = aafm_remember_raw_permission( $ability_name );
	if ( ! is_callable( $permission ) ) {
		return false;
	}
	return true === $permission( $input );
}

/**
 * An object-INDEPENDENT discovery predicate for abilities whose execute-time
 * permission_callback needs a specific object id from the input.
 *
 * The tools/list visibility check runs with empty input, but several abilities gate
 * on a per-object capability (e.g. edit_post( $id )) that is always false when no id is
 * present. With empty input those tools would be hidden even from a fully capable user,
 * so they become undiscoverable and the agent can never call them. This map returns a
 * coarse, id-free "can this user use this kind of tool at all" check used ONLY for
 * discovery; the per-object permission_callback is left untouched and still runs as the
 * hard EXECUTE-time gate (and still denies + audits on objects the user can't act on).
 *
 * Returns null for abilities that have no per-object branch — those fall back to the
 * normal empty-input callable check, which is already correct for them.
 *
 * Page caps are derived from the 'page' post-type object so the mapping stays correct
 * if the page caps are ever remapped, rather than hardcoding 'edit_pages'/'delete_pages'.
 *
 * @param string $name Ability name, e.g. "aafm/update-post".
 * @return callable():bool|null Discovery predicate, or null when no override is needed.
 */
function aafm_ability_list_permission( string $name ): ?callable {
	switch ( $name ) {
		// Single-item reads: as discoverable as their list siblings get-posts/get-pages,
		// which gate on the generic 'read' capability.
		case 'aafm/get-post':
		case 'aafm/get-page':
			return static fn(): bool => current_user_can( 'read' );

		// Post writes: the floor cap that the per-object edit_post()/delete_post() refine.
		case 'aafm/update-post':
		case 'aafm/set-featured-image':
			return static fn(): bool => current_user_can( 'edit_posts' );
		case 'aafm/trash-post':
			return static fn(): bool => current_user_can( 'delete_posts' );

		// Governed post-meta (get/update/delete): all gate on per-object edit_post (reads
		// included — meta can hold private data), so discovery uses the same edit_posts floor
		// as update-post, refined per-object at execute time.
		case 'aafm/get-post-meta':
		case 'aafm/update-post-meta':
		case 'aafm/delete-post-meta':
			return static fn(): bool => current_user_can( 'edit_posts' );

		// Page writes: derive edit_pages/delete_pages from the page post-type object.
		case 'aafm/update-page':
			return static function (): bool {
				$pto = get_post_type_object( 'page' );
				return $pto instanceof WP_Post_Type && current_user_can( $pto->cap->edit_posts );
			};
		case 'aafm/trash-page':
			return static function (): bool {
				$pto = get_post_type_object( 'page' );
				return $pto instanceof WP_Post_Type && current_user_can( $pto->cap->delete_posts );
			};

		// Comment moderation: the site-wide floor cap the per-object edit_comment() refines.
		case 'aafm/moderate-comment':
			return static fn(): bool => current_user_can( 'moderate_comments' );

		// Revisions: list/get/restore all gate per-object on edit_post on the parent — reads
		// included, since a revision can hold content from when the post was private. Discovery
		// uses the same edit_posts floor as update-post, refined per-object at execute.
		case 'aafm/list-revisions':
		case 'aafm/get-revision':
		case 'aafm/restore-revision':
			return static fn(): bool => current_user_can( 'edit_posts' );

		default:
			return null;
	}
}

/**
 * Whether the current user may DISCOVER (see in tools/list) a given ability.
 *
 * Discovery is deliberately decoupled from per-object EXECUTE authorization. For abilities
 * with a per-object permission branch this uses the coarse, id-free predicate from
 * aafm_ability_list_permission() so a capable user can actually see the tool. For every
 * other ability it falls back to the real callback with empty input, which is the correct
 * object-independent check for the general-cap abilities (create-post, get-posts, …).
 *
 * Discovery never grants execution: each ability's permission_callback still runs at
 * execute time and still denies (and audits) on any specific object the user can't touch.
 *
 * @param string $ability_name Ability name, e.g. "aafm/update-post".
 * @return bool
 */
function aafm_user_can_discover_ability( string $ability_name ): bool {
	$list_permission = aafm_ability_list_permission( $ability_name );
	if ( null !== $list_permission ) {
		return true === $list_permission();
	}
	return aafm_user_can_call_ability( $ability_name, array() );
}

/**
 * Per-connection capability gate for tools/list, applied at request time.
 *
 * The adapter does NOT permission-filter tools/list itself (Phase 0.5.2); it exposes the
 * `mcp_adapter_tools_list` filter (since 0.5.0) which fires while the JSON-RPC method is
 * dispatched — by then the Application Password user IS resolved. We drop any Tool DTO whose
 * backing ability the current user cannot DISCOVER (an object-independent check), so a
 * connection only sees tools it could plausibly use, while the per-object permission_callback
 * still re-checks the specific object at execute time. Non-AAFM tools (no matching enabled
 * ability) are left untouched.
 *
 * @param mixed $tools  Array of Tool DTOs from the adapter.
 * @param mixed $server Adapter server instance (unused).
 * @return mixed Filtered Tool DTOs.
 */
function aafm_filter_mcp_tools_list( $tools, $server = null ) {
	unset( $server );
	if ( ! is_array( $tools ) ) {
		return $tools;
	}

	// Map our enabled abilities to their sanitized MCP tool names once.
	$enabled_by_tool_name = array();
	foreach ( aafm_get_enabled_abilities() as $ability_name ) {
		$enabled_by_tool_name[ aafm_mcp_tool_name( $ability_name ) ] = $ability_name;
	}

	$visible = array();
	foreach ( $tools as $tool ) {
		$tool_name = is_object( $tool ) && method_exists( $tool, 'getName' ) ? (string) $tool->getName() : '';

		// Only gate tools that belong to one of our enabled abilities. Discovery is
		// decoupled from per-object execute authorization (see aafm_user_can_discover_ability):
		// a capable user must SEE per-object tools (update-post, trash-post, …) even though the
		// real permission_callback still re-checks the specific object at execute time.
		if ( isset( $enabled_by_tool_name[ $tool_name ] ) ) {
			if ( ! aafm_user_can_discover_ability( $enabled_by_tool_name[ $tool_name ] ) ) {
				continue;
			}
		}
		$visible[] = $tool;
	}

	return $visible;
}

/**
 * Transport-level gate: require an authenticated user. Per-ability caps do the real work.
 * Named (not inline) so it is unit-testable and PHPStan-visible.
 *
 * @param \WP_REST_Request<array<string,mixed>> $request Incoming request (unused; auth already resolved).
 * @return bool|WP_Error
 */
function aafm_transport_permission_callback( $request ) {
	unset( $request );
	return is_user_logged_in()
		? true
		: new WP_Error( 'aafm_unauthenticated', __( 'Authentication required.', 'agent-abilities-for-mcp' ), array( 'status' => 401 ) );
}

/**
 * Register the single governed MCP server inside mcp_adapter_init.
 *
 * Phase 0.5.1 confirmed the 13-argument create_server() signature and corrected the
 * transport + error-handler FQCNs against the vendored 0.5.0 source.
 *
 * @param \WP\MCP\Core\McpAdapter $adapter Adapter instance.
 * @return void
 */
function aafm_register_mcp_server( $adapter ): void {
	// Idempotent: the adapter keeps one server per ID and emits an incorrect-usage notice
	// if asked to create a duplicate. Bail if ours already exists so a re-entrant init
	// (or a diagnostics route lookup that re-fires rest_api_init) never trips that notice.
	if ( null !== $adapter->get_server( 'aafm-server' ) ) {
		return;
	}

	$tools = aafm_build_server_tools( aafm_get_enabled_abilities() );

	// Per-connection capability gate at request time (the user is anonymous here; see
	// aafm_build_server_tools()). Priority 5 so it runs before any consumer reordering.
	add_filter( 'mcp_adapter_tools_list', 'aafm_filter_mcp_tools_list', 5, 2 );

	$adapter->create_server(
		'aafm-server',
		'agent-abilities-for-mcp',
		'mcp',
		__( 'Agent Abilities for MCP', 'agent-abilities-for-mcp' ),
		__( 'Curated, governed WordPress abilities for AI agents.', 'agent-abilities-for-mcp' ),
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
