<?php
/**
 * A tools/call result read the way the wire tests were written against adapter 0.6.1.
 *
 * Adapter 0.7.0 returns result records whose structured content is a decoded JSON value, with
 * objects as stdClass. The wire tests compare it to plain arrays, so this exposes the same
 * accessors with structured content converted to arrays and the content blocks left as records.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Support;

use WP\McpSchema\Record\CallToolResult;

final class McpToolCallOutcome {

	/**
	 * The adapter's result record.
	 *
	 * @var CallToolResult
	 */
	private CallToolResult $record;

	/**
	 * Wrap a result record.
	 *
	 * @param CallToolResult $record The record the adapter produced.
	 */
	public function __construct( CallToolResult $record ) {
		$this->record = $record;
	}

	/**
	 * The record itself.
	 *
	 * @return CallToolResult
	 */
	public function record(): CallToolResult {
		return $this->record;
	}

	/**
	 * Whether the tool reported an error.
	 *
	 * @return bool
	 */
	public function getIsError(): bool { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- mirrors the adapter's result accessor.
		return (bool) $this->record->getIsError();
	}

	/**
	 * The content blocks, each a record with getText().
	 *
	 * @return array<int, object>
	 */
	public function getContent(): array { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- mirrors the adapter's result accessor.
		return $this->record->getContent();
	}

	/**
	 * The structured content as arrays, or null when the result carries none.
	 *
	 * @return array<mixed>|null
	 */
	public function getStructuredContent(): ?array { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- mirrors the adapter's result accessor.
		$structured = $this->record->getStructuredContent();

		if ( null === $structured ) {
			return null;
		}

		return json_decode( (string) wp_json_encode( $structured ), true );
	}
}
