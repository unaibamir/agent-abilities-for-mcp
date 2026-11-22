<?php
/**
 * Site Ops: the four page cache abilities.
 *
 * The site-cache-status tool reads. The three purge tools write: each validates its input here, checks
 * the site-wide purge budget, then asks every supported cache plugin that is serving cached
 * pages to purge through that plugin's own function, and reports each plugin separately. A
 * result says purged only on positive evidence that a cached copy existed and that no copy from
 * before the call is left. Nothing here flushes the object cache or changes another plugin's
 * settings, and none of the purge tools runs on a multisite network.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

add_filter( 'aafm_abilities_registry', 'aafm_register_site_cache_definitions' );
add_filter( 'aafm_abilities_registry_integrations', 'aafm_register_site_cache_full_definitions' );

/**
 * Contribute the cache definitions when a supported cache plugin is detected.
 *
 * @param array<string,array<string,mixed>> $registry Registry.
 * @return array<string,array<string,mixed>>
 */
function aafm_register_site_cache_definitions( array $registry ): array {
	if ( ! aafm_integration_active( 'site_cache' ) ) {
		return $registry;
	}
	return array_merge( $registry, aafm_site_cache_registry_definitions() );
}

/**
 * Contribute the cache definitions unconditionally, for the full catalog.
 *
 * @param array<string,array<string,mixed>> $registry Registry.
 * @return array<string,array<string,mixed>>
 */
function aafm_register_site_cache_full_definitions( array $registry ): array {
	return array_merge( $registry, aafm_site_cache_registry_definitions() );
}

/**
 * The four registry rows.
 *
 * @return array<string,array<string,mixed>>
 */
function aafm_site_cache_registry_definitions(): array {
	return array(
		'aafm/site-cache-status'     => array(
			'label'        => __( 'Cache status', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Lists the supported page cache plugins that are active on this site, whether each one is serving cached pages right now (for Proxy Cache Purge and Nginx Helper, which cannot tell whether a proxy caches, this means purging is available), which purges it supports, whether a purge can be confirmed at best or only requested, whether it also removes pages under a URL, and whether it sends requests of its own when it purges. Names cache plugins it found that these tools do not cover, and caches in front of the site such as Cloudflare, Varnish or Fastly that it can see in the headers of this request; these tools do not purge a CDN or a cache run by the host themselves, though a cache plugin that manages one can pass the purge on to it. Also says whether a persistent object cache is in use and how much of the site-wide purge budget is left. Reads only: it calls no purge and makes no request. Never returns settings, hosts or file paths. Requires the manage-options capability.', 'agent-abilities-for-mcp' ),
			'group'        => 'reads',
			'risk'         => 'read',
			'subject'      => 'site_cache',
			'args_builder' => 'aafm_args_site_cache_status',
		),
		'aafm/site-purge-post-cache' => array(
			'label'        => __( 'Purge a post from the page cache', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Removes one published post or page from the page cache of every supported cache plugin that is serving cached pages on this site, and reports each plugin separately: purged (the standard cached copy was found where the plugin keeps it and no copy from before the purge is left; when the reason is recached the plugin has already cached the page again, which SiteGround Speed Optimizer does straight away when its preheat option is on), requested (the purge was sent and removal could not be proven, which includes the case where no cached copy was found), failed, or skipped with a reason. The page URL is worked out from the post id; it cannot be supplied. Some plugins also clear the home page, archives or pages under the same address, some send their own requests to the site or to a proxy when they purge, and some request or queue the page again afterwards; site-cache-status shows which plugins remove pages under a URL and which send requests of their own. A plugin that fetches the page again while it purges can make this call take several seconds. Counts against a site-wide purge budget shared with the other purge tools. Does not touch the object cache. It does not itself purge a CDN, Cloudflare, Varnish or a cache run by the host in front of the site, though Proxy Cache Purge, Nginx Helper, SiteGround Speed Optimizer, W3 Total Cache and WP Fastest Cache can pass the purge on to one they are set up for; site-cache-status names the layers it can see. Refused on multisite. Requires the manage-options capability.', 'agent-abilities-for-mcp' ),
			'group'        => 'writes',
			'risk'         => 'write',
			'subject'      => 'site_cache',
			'args_builder' => 'aafm_args_site_purge_post_cache',
		),
		'aafm/site-purge-url-cache'  => array(
			'label'        => __( 'Purge a URL from the page cache', 'agent-abilities-for-mcp' ),
			'description'  => __( "Removes one URL of this site from the page cache of every supported cache plugin that is serving cached pages, and reports each plugin separately as purged, requested, failed or skipped, with the same meanings as site-purge-post-cache. The URL must be on this site's own address, with no query string and no fragment; wildcards, relative steps and addresses on other sites are refused before any cache plugin is called. The address is put into the form this site serves (with or without a final slash) and the result shows the address used. Some plugins also remove pages under that URL; site-cache-status says which. A plugin for which the home URL means the whole site is skipped here; use site-purge-site-cache for that. Some plugins send their own requests when they purge, and one that fetches the page again while it purges can make this call take several seconds. Counts against the site-wide purge budget. It does not itself purge a CDN, Cloudflare, Varnish or a cache run by the host in front of the site, though Proxy Cache Purge, Nginx Helper, SiteGround Speed Optimizer and W3 Total Cache can pass the purge on to one they are set up for; site-cache-status names the layers it can see. Refused on multisite. Requires the manage-options capability.", 'agent-abilities-for-mcp' ),
			'group'        => 'writes',
			'risk'         => 'write',
			'subject'      => 'site_cache',
			'args_builder' => 'aafm_args_site_purge_url_cache',
		),
		'aafm/site-purge-site-cache' => array(
			'label'        => __( 'Purge the whole page cache', 'agent-abilities-for-mcp' ),
			'description'  => __( 'Removes every cached page of this site from each supported cache plugin that is serving cached pages, and reports each plugin separately as purged, requested, failed or skipped, with the same meanings as site-purge-post-cache. It does not flush the object cache. It does not itself purge a CDN, Cloudflare, Varnish or a cache run by the host in front of the site, though Proxy Cache Purge, Nginx Helper, SiteGround Speed Optimizer, W3 Total Cache and WP Fastest Cache can pass the purge on to one they are set up for; site-cache-status names the layers it can see. Some plugins remove other generated files with their page cache (SpeedyCache also removes its critical CSS files) and some restart their preload or send their own requests. The site is slower until pages are cached again. Allowed once every 15 minutes for the whole site, and it uses a large share of the site-wide purge budget. Refused on multisite. Requires the manage-options capability.', 'agent-abilities-for-mcp' ),
			'group'        => 'writes',
			'risk'         => 'destructive',
			'subject'      => 'site_cache',
			'args_builder' => 'aafm_args_site_purge_site_cache',
		),
	);
}

/**
 * The schema of one adapter's row in a purge result. Shared by the three purge tools.
 *
 * @return array<string,mixed>
 */
function aafm_site_cache_result_schema(): array {
	return array(
		'type'       => 'object',
		'properties' => array(
			'provider'   => array( 'type' => 'string' ),
			'label'      => array( 'type' => 'string' ),
			'state'      => array(
				'type'        => 'string',
				'enum'        => array( 'purged', 'requested', 'failed', 'skipped' ),
				'description' => __( 'purged: a cached copy was found before the call and none from before the call is left. requested: the purge was sent and removal is not proven. failed and skipped: see reason.', 'agent-abilities-for-mcp' ),
			),
			'reason'     => array(
				'type'        => 'string',
				'enum'        => array( '', 'recached', 'no_acknowledgement', 'not_found_in_cache', 'proxy_answered', 'deferred', 'stale_window', 'variants_unchecked', 'still_cached', 'variant_still_cached', 'vendor_error', 'proxy_error', 'nothing_sent', 'not_caching', 'unsupported_operation', 'unsupported_version', 'over_budget' ),
				'description' => __( 'recached, with purged only: the old copy is gone and the plugin has already cached the page again. variant_still_cached: the plugin removed the copy stored under one spelling of the address and left another; calling the same purge again will not help, and site-purge-site-cache clears the surviving copy.', 'agent-abilities-for-mcp' ),
			),
			'was_cached' => array( 'type' => array( 'boolean', 'null' ) ),
		),
	);
}

/**
 * The output properties every purge tool shares.
 *
 * @return array<string,mixed>
 */
function aafm_site_cache_purge_output_properties(): array {
	return array(
		'results'          => array(
			'type'  => 'array',
			'items' => aafm_site_cache_result_schema(),
		),
		'summary'          => array(
			'type' => 'string',
			'enum' => array( 'purged', 'requested', 'partial', 'failed', 'nothing_to_do' ),
		),
		'budget_remaining' => array( 'type' => 'integer' ),
	);
}

/**
 * The parts the three purge tools share.
 *
 * @param string              $name        Ability name.
 * @param array<string,mixed> $input       Input schema.
 * @param array<string,mixed> $properties  Output properties in front of the shared ones.
 * @param string              $execute     Execute callback.
 * @param bool                $destructive Whether the tool is destructive.
 * @return array<string,mixed>
 */
function aafm_site_cache_purge_args( string $name, array $input, array $properties, string $execute, bool $destructive ): array {
	return array(
		'label'               => aafm_ability_label( $name ),
		'description'         => aafm_ability_description( $name ),
		'category'            => 'aafm-writes',
		'input_schema'        => $input,
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array_merge( $properties, aafm_site_cache_purge_output_properties() ),
		),
		'execute_callback'    => $execute,
		'permission_callback' => 'aafm_perm_manage_options',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => $destructive,
				'idempotent'  => true,
			),
		),
	);
}

/**
 * Args for aafm/site-cache-status.
 *
 * @return array<string,mixed>
 */
function aafm_args_site_cache_status(): array {
	return array(
		'label'               => aafm_ability_label( 'aafm/site-cache-status' ),
		'description'         => aafm_ability_description( 'aafm/site-cache-status' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'providers'            => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'provider'        => array( 'type' => 'string' ),
							'label'           => array( 'type' => 'string' ),
							'status'          => array(
								'type' => 'string',
								'enum' => array( 'serving', 'not_caching', 'unsupported_version', 'error' ),
							),
							'operations'      => array(
								'type'  => 'array',
								'items' => array(
									'type' => 'string',
									'enum' => array( 'post', 'url', 'all' ),
								),
							),
							'can_confirm'     => array(
								'type'        => 'boolean',
								'description' => __( 'Best case. A single purge can still come back as requested.', 'agent-abilities-for-mcp' ),
							),
							'purges_children' => array( 'type' => 'boolean' ),
							'vendor_requests' => array( 'type' => 'boolean' ),
						),
					),
				),
				'status_only'          => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'provider' => array( 'type' => 'string' ),
							'label'    => array( 'type' => 'string' ),
							'kind'     => array(
								'type'        => 'string',
								'enum'        => array( 'asset_cache', 'object_cache', 'page_cache_not_covered', 'vendor_mcp_server' ),
								'description' => __( 'page_cache_not_covered: a page cache plugin that is active and that these tools do not purge in this version. Purge results do not cover it.', 'agent-abilities-for-mcp' ),
							),
						),
					),
				),
				'object_cache'         => array(
					'type'       => 'object',
					'properties' => array(
						'persistent' => array( 'type' => 'boolean' ),
						'drop_in'    => array( 'type' => 'string' ),
					),
				),
				'budget'               => array(
					'type'       => 'object',
					'properties' => array(
						'window_seconds'          => array( 'type' => 'integer' ),
						'limit'                   => array( 'type' => 'integer' ),
						'used'                    => array( 'type' => 'integer' ),
						'remaining'               => array( 'type' => 'integer' ),
						'site_purge_available_in' => array( 'type' => 'integer' ),
					),
				),
				'multisite'            => array( 'type' => 'boolean' ),
				'request_host_differs' => array(
					'type'        => 'boolean',
					'description' => __( 'This request reached the site under another host name than its address. Plugins that name their cache by host may report nothing found.', 'agent-abilities-for-mcp' ),
				),
				'edge_layers'          => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => __( "Caches in front of this site that this request's headers show, such as Cloudflare or Varnish. It is a guess from the headers; these tools do not purge them themselves, though a cache plugin set up for one can pass its purge on to it.", 'agent-abilities-for-mcp' ),
				),
				'purge_supported'      => array( 'type' => 'boolean' ),
			),
		),
		'execute_callback'    => 'aafm_exec_site_cache_status',
		'permission_callback' => 'aafm_perm_manage_options',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
		),
	);
}

/**
 * Args for aafm/site-purge-post-cache.
 *
 * @return array<string,mixed>
 */
function aafm_args_site_purge_post_cache(): array {
	return aafm_site_cache_purge_args(
		'aafm/site-purge-post-cache',
		array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'ID of a published post or page of a public type. Drafts, private posts and missing ids are refused before any cache plugin is called.', 'agent-abilities-for-mcp' ),
				),
			),
			'required'             => array( 'post_id' ),
			'additionalProperties' => false,
		),
		array(
			'post_id' => array( 'type' => 'integer' ),
			'url'     => array( 'type' => 'string' ),
		),
		'aafm_exec_site_purge_post_cache',
		false
	);
}

/**
 * Args for aafm/site-purge-url-cache.
 *
 * @return array<string,mixed>
 */
function aafm_args_site_purge_url_cache(): array {
	return aafm_site_cache_purge_args(
		'aafm/site-purge-url-cache',
		array(
			'type'                 => 'object',
			'properties'           => array(
				'url' => array(
					'type'        => 'string',
					'minLength'   => 1,
					'maxLength'   => 2000,
					'description' => __( 'Full URL or path on this site, for example https://example.com/about/ or /about/. No query string, no fragment, no wildcard.', 'agent-abilities-for-mcp' ),
				),
			),
			'required'             => array( 'url' ),
			'additionalProperties' => false,
		),
		array(
			'url' => array( 'type' => 'string' ),
		),
		'aafm_exec_site_purge_url_cache',
		false
	);
}

/**
 * Args for aafm/site-purge-site-cache.
 *
 * @return array<string,mixed>
 */
function aafm_args_site_purge_site_cache(): array {
	return aafm_site_cache_purge_args(
		'aafm/site-purge-site-cache',
		array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		),
		array(),
		'aafm_exec_site_purge_site_cache',
		true
	);
}

/**
 * The refusal of a tool that does not run on a multisite network in this version.
 *
 * @return WP_Error
 */
function aafm_site_multisite_error(): WP_Error {
	return new WP_Error( 'aafm_site_multisite', __( 'This tool is not supported on a multisite network in this version.', 'agent-abilities-for-mcp' ) );
}

/**
 * One word for a whole purge result.
 *
 * @param array<int,array<string,mixed>> $rows Rows of aafm_site_cache_fan_out().
 * @return string nothing_to_do | failed | partial | purged | requested.
 */
function aafm_site_cache_summary( array $rows ): string {
	$states      = array();
	$over_budget = false;
	foreach ( $rows as $row ) {
		$state    = isset( $row['state'] ) ? (string) $row['state'] : '';
		$states[] = $state;
		if ( 'skipped' === $state && isset( $row['reason'] ) && 'over_budget' === $row['reason'] ) {
			$over_budget = true;
		}
	}

	$ran = array_values( array_diff( $states, array( 'skipped' ) ) );
	if ( array() === $ran ) {
		return 'nothing_to_do';
	}
	if ( array( 'failed' ) === array_values( array_unique( $ran ) ) ) {
		return 'failed';
	}
	if ( in_array( 'failed', $ran, true ) || $over_budget ) {
		return 'partial';
	}
	if ( in_array( 'purged', $ran, true ) && ! in_array( 'requested', $ran, true ) ) {
		return 'purged';
	}
	return 'requested';
}

/**
 * Steps 4 to 7 of every purge tool: is anything serving, the budget, the fan-out, the output.
 *
 * A call refused here resolves as an error and spends no budget.
 *
 * @param string              $op   'post' | 'url' | 'all'.
 * @param array<string,mixed> $args Arguments for aafm_site_cache_fan_out().
 * @return array{results:array<int,array<string,mixed>>,summary:string,budget_remaining:int}|WP_Error
 */
function aafm_site_cache_run_purge( string $op, array $args ) {
	// Called once per tool call and passed on; never kept for the next call.
	$map = aafm_site_cache_serving_map();
	if ( ! in_array( true, $map, true ) && ! in_array( null, $map, true ) ) {
		return new WP_Error( 'aafm_site_no_provider', __( 'No supported cache plugin is serving cached pages on this site.', 'agent-abilities-for-mcp' ) );
	}

	$budget = aafm_site_purge_budget_check( $op );
	if ( is_wp_error( $budget ) ) {
		return $budget;
	}

	aafm_siteops_http_guard_begin();
	try {
		$rows = aafm_site_cache_fan_out( $op, $args, $map );
	} finally {
		aafm_siteops_http_guard_end();
	}

	$state = aafm_site_purge_budget_state();

	return array(
		'results'          => $rows,
		'summary'          => aafm_site_cache_summary( $rows ),
		'budget_remaining' => $state['remaining'],
	);
}

/**
 * Execute aafm/site-cache-status.
 *
 * @return array<string,mixed>
 */
function aafm_exec_site_cache_status(): array {
	aafm_siteops_load();
	require_once AAFM_PLUGIN_DIR . 'includes/siteops/cache/status-only.php';

	// Read before any adapter call. It decides nothing; it explains a not_found_in_cache.
	$request_host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) ) : '';
	$site_host    = aafm_siteops_canonical_host();
	$default_port = 'https' === wp_parse_url( home_url( '/' ), PHP_URL_SCHEME ) ? '443' : '80';
	$host_differs = '' !== $request_host && $request_host !== $site_host && $request_host !== $site_host . ':' . $default_port;
	$edge_layers  = aafm_site_cache_edge_layers();

	$map       = aafm_site_cache_serving_map();
	$is_cli    = aafm_siteops_is_cli();
	$providers = array();
	foreach ( aafm_site_cache_adapters() as $slug => $entry ) {
		$slug     = (string) $slug;
		$detected = aafm_site_cache_detected( $entry );
		if ( ! $detected && ! aafm_siteops_plugin_active( isset( $entry['plugins'] ) && is_array( $entry['plugins'] ) ? $entry['plugins'] : array() ) ) {
			continue;
		}

		$ops = isset( $entry['ops'] ) && is_array( $entry['ops'] ) ? array_values( array_intersect( array( 'post', 'url', 'all' ), $entry['ops'] ) ) : array();
		if ( ! $detected || ! array_key_exists( $slug, $map ) ) {
			// Active with the entry points this version was tested against missing, or its adapter did not load.
			$status = 'unsupported_version';
			$ops    = array();
		} elseif ( null === $map[ $slug ] ) {
			$status = 'error';
		} else {
			$status = true === $map[ $slug ] ? 'serving' : 'not_caching';
		}
		if ( $is_cli && empty( $entry['cli'] ) ) {
			$ops = array();
		}

		$providers[] = array(
			'provider'        => $slug,
			'label'           => isset( $entry['label'] ) ? (string) $entry['label'] : $slug,
			'status'          => $status,
			'operations'      => $ops,
			'can_confirm'     => isset( $entry['confirm'] ) && 'file' === $entry['confirm'],
			'purges_children' => ! empty( $entry['recursive_url'] ),
			'vendor_requests' => ! empty( $entry['vendor_http'] ),
		);
	}

	$settings = aafm_site_purge_budget_settings();
	$state    = aafm_site_purge_budget_state();

	return array(
		'providers'            => $providers,
		'status_only'          => aafm_site_cache_status_only(),
		'object_cache'         => array(
			'persistent' => (bool) wp_using_ext_object_cache(),
			'drop_in'    => aafm_site_cache_drop_in_name(),
		),
		'budget'               => array(
			'window_seconds'          => $settings['window'],
			'limit'                   => $settings['limit'],
			'used'                    => $state['used'],
			'remaining'               => $state['remaining'],
			'site_purge_available_in' => $state['site_purge_available_in'],
		),
		'multisite'            => is_multisite(),
		'request_host_differs' => $host_differs,
		'edge_layers'          => $edge_layers,
		'purge_supported'      => ! is_multisite(),
	);
}

/**
 * Caches in front of this site, guessed from the headers they add to the request that reached it.
 * None of the cache tools purges them. A layer that adds none of these headers is not seen.
 *
 * @return list<string> Labels, in a fixed order.
 */
function aafm_site_cache_edge_layers(): array {
	$layers = array(
		'Cloudflare'      => array( 'HTTP_CF_RAY', 'HTTP_CF_CONNECTING_IP' ),
		'Sucuri firewall' => array( 'HTTP_X_SUCURI_CLIENTIP' ),
		'Varnish'         => array( 'HTTP_X_VARNISH' ),
		'Fastly'          => array( 'HTTP_FASTLY_CLIENT_IP' ),
		'bunny.net'       => array( 'HTTP_CDN_PULLZONEID' ),
	);
	$seen   = array();
	foreach ( $layers as $label => $keys ) {
		foreach ( $keys as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$seen[] = $label;
				break;
			}
		}
	}

	return $seen;
}

/**
 * Execute aafm/site-purge-post-cache.
 *
 * @param array<string,mixed> $input Tool input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_site_purge_post_cache( array $input ) {
	if ( is_multisite() ) {
		return aafm_site_multisite_error();
	}
	aafm_siteops_load();

	$post_id = absint( $input['post_id'] ?? 0 );
	$url     = aafm_siteops_post_url( $post_id );
	if ( is_wp_error( $url ) ) {
		return $url;
	}

	$run = aafm_site_cache_run_purge(
		'post',
		array(
			'post_id' => $post_id,
			'url'     => $url,
		)
	);
	if ( is_wp_error( $run ) ) {
		return $run;
	}

	return array_merge(
		array(
			'post_id' => $post_id,
			'url'     => $url['url'],
		),
		$run
	);
}

/**
 * Execute aafm/site-purge-url-cache.
 *
 * This tool never purges a whole site: an adapter for which the home URL means everything
 * skips it and the agent is pointed at purge-site-cache, which has the cooldown.
 *
 * @param array<string,mixed> $input Tool input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_site_purge_url_cache( array $input ) {
	if ( is_multisite() ) {
		return aafm_site_multisite_error();
	}
	aafm_siteops_load();

	$url = aafm_siteops_canonical_url( isset( $input['url'] ) && is_string( $input['url'] ) ? $input['url'] : '', true );
	if ( is_wp_error( $url ) ) {
		return $url;
	}

	$run = aafm_site_cache_run_purge( 'url', array( 'url' => $url ) );
	if ( is_wp_error( $run ) ) {
		return $run;
	}

	return array_merge( array( 'url' => $url['url'] ), $run );
}

/**
 * Execute aafm/site-purge-site-cache.
 *
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_site_purge_site_cache() {
	if ( is_multisite() ) {
		return aafm_site_multisite_error();
	}
	aafm_siteops_load();

	return aafm_site_cache_run_purge( 'all', array() );
}
