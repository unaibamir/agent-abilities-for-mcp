<?php
/**
 * Slice C meta-key sanitizers: deny post-meta, exposed user-meta, deny user-meta.
 *
 * All three split on newlines, trim, sanitize_text_field (never sanitize_key, which would
 * strip the `*` wildcard and mangle legit keys), KEEP the `*` sentinel, drop empties, and
 * de-dupe. The DENY variants do NOT strip hard-blocked keys (denying a blocked key is a
 * harmless no-op and the deny list must be able to name anything an admin wants refused).
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\TestCase;

final class MetaKeySanitizerTest extends TestCase {

	/**
	 * Deny post-meta - keeps `*`, keeps a hard-blocked key (no strip), trims, de-dupes.
	 */
	public function test_denied_meta_keys_sanitizer_keeps_star_and_blocked(): void {
		$out = aafm_sanitize_denied_meta_keys_input(
			array( 'aafm_deny_meta_keys' => "*\n secret \n\nsecret\nwp_capabilities" )
		);
		$this->assertSame( array( '*', 'secret', 'wp_capabilities' ), $out );
	}

	/**
	 * Exposed user-meta - keeps `*`, splits/trims/de-dupes, drops empties.
	 */
	public function test_exposed_user_meta_keys_sanitizer_keeps_star(): void {
		$out = aafm_sanitize_exposed_user_meta_keys_input(
			array( 'aafm_exposed_user_meta_keys' => "*\n profile_color \n\nprofile_color\nbio" )
		);
		$this->assertSame( array( '*', 'profile_color', 'bio' ), $out );
	}

	/**
	 * Deny user-meta - keeps `*`, keeps a hard-blocked auth key (no strip), trims, de-dupes.
	 */
	public function test_denied_user_meta_keys_sanitizer_keeps_star_and_blocked(): void {
		$out = aafm_sanitize_denied_user_meta_keys_input(
			array( 'aafm_denied_user_meta_keys' => "*\n session_tokens \n\nsession_tokens\nprivate_note" )
		);
		$this->assertSame( array( '*', 'session_tokens', 'private_note' ), $out );
	}

	/**
	 * Exposed term-meta - keeps `*`, drops a hard-blocked key, trims, de-dupes.
	 */
	public function test_exposed_term_meta_keys_sanitizer_keeps_star_drops_blocked(): void {
		$out = aafm_sanitize_exposed_term_meta_keys_input(
			array( 'aafm_exposed_term_meta_keys' => "*\n seo_title \n\nseo_title\n_edit_lock" )
		);
		$this->assertSame( array( '*', 'seo_title' ), $out );
	}

	/**
	 * Deny term-meta - keeps `*`, keeps a hard-blocked key (no strip), trims, de-dupes.
	 */
	public function test_denied_term_meta_keys_sanitizer_keeps_star_and_blocked(): void {
		$out = aafm_sanitize_denied_term_meta_keys_input(
			array( 'aafm_denied_term_meta_keys' => "*\n secret \n\nsecret\nsession_tokens" )
		);
		$this->assertSame( array( '*', 'secret', 'session_tokens' ), $out );
	}

	/**
	 * Empty / missing payload yields an empty list, never a notice.
	 */
	public function test_sanitizers_handle_missing_payload(): void {
		$this->assertSame( array(), aafm_sanitize_denied_meta_keys_input( array() ) );
		$this->assertSame( array(), aafm_sanitize_exposed_user_meta_keys_input( array() ) );
		$this->assertSame( array(), aafm_sanitize_denied_user_meta_keys_input( array() ) );
		$this->assertSame( array(), aafm_sanitize_exposed_term_meta_keys_input( array() ) );
		$this->assertSame( array(), aafm_sanitize_denied_term_meta_keys_input( array() ) );
	}
}
