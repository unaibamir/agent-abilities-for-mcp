<?php
/**
 * Governed term-meta: default-deny allowlist (aafm_allowed_term_meta_keys), the hard-block
 * floor (reused from post-meta), scalar-only values, and per-object edit_term gating.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use WP_Error;

final class TermMetaTest extends TestCase {

	public function test_allowlist_defaults_empty_then_opts_in(): void {
		$this->assertSame( array(), aafm_allowed_term_meta_keys() );
		add_filter( 'aafm_allowed_term_meta_keys', static fn(): array => array( 'seo_title' ) );
		$this->assertSame( array( 'seo_title' ), aafm_allowed_term_meta_keys() );
		remove_all_filters( 'aafm_allowed_term_meta_keys' );
	}

	public function test_validate_key_rejects_unlisted_and_hard_blocked(): void {
		add_filter( 'aafm_allowed_term_meta_keys', static fn(): array => array( 'seo_title' ) );
		$this->assertSame( 'seo_title', aafm_validate_term_meta_key( 'seo_title' ) );
		$this->assertInstanceOf( WP_Error::class, aafm_validate_term_meta_key( 'unlisted' ) );
		// A `_`-prefixed protected key is hard-blocked even if someone tried to allowlist it.
		add_filter( 'aafm_allowed_term_meta_keys', static fn(): array => array( '_secret' ) );
		$this->assertInstanceOf( WP_Error::class, aafm_validate_term_meta_key( '_secret' ) );
		remove_all_filters( 'aafm_allowed_term_meta_keys' );
	}

	public function test_sanitize_value_refuses_non_scalar(): void {
		$this->assertInstanceOf( WP_Error::class, aafm_sanitize_term_meta_value( 'seo_title', array( 'x' => 1 ) ) );
		$this->assertSame( 'hello', aafm_sanitize_term_meta_value( 'seo_title', 'hello' ) );
	}
}
