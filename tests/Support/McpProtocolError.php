<?php
/**
 * A JSON-RPC protocol error returned by the adapter's tools handler.
 *
 * The 0.7.0 handler returns protocol errors as plain arrays, where 0.6.1 returned an error response
 * object. This stands in for that object so a test can tell a protocol error from a tool result and
 * read its code and message.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Support;

final class McpProtocolError {

	/**
	 * The handler's error envelope.
	 *
	 * @var array<string, mixed>
	 */
	private array $envelope;

	/**
	 * Wrap an error envelope.
	 *
	 * @param array<string, mixed> $envelope JSON-RPC error response array.
	 */
	public function __construct( array $envelope ) {
		$this->envelope = $envelope;
	}

	/**
	 * The error object, with getCode() and getMessage().
	 *
	 * @return object
	 */
	public function getError(): object { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- mirrors the adapter's error response accessor.
		$error = (array) ( $this->envelope['error'] ?? array() );

		return new class( (int) ( $error['code'] ?? 0 ), (string) ( $error['message'] ?? '' ) ) {
			/**
			 * The error code.
			 *
			 * @var int
			 */
			private int $code;

			/**
			 * The error message.
			 *
			 * @var string
			 */
			private string $message;

			/**
			 * Hold the error fields.
			 *
			 * @param int    $code    Error code.
			 * @param string $message Error message.
			 */
			public function __construct( int $code, string $message ) {
				$this->code    = $code;
				$this->message = $message;
			}

			public function getCode(): int { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- mirrors the adapter's error accessor.
				return $this->code;
			}

			public function getMessage(): string { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- mirrors the adapter's error accessor.
				return $this->message;
			}
		};
	}
}
