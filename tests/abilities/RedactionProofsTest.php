<?php
/**
 * Negative redaction proofs: assert the DANGEROUS fields are ABSENT from every
 * agent-facing shape, not merely that the safe fields are present.
 *
 * The competitor CVEs this plugin exists to avoid were leaks — emails, logins,
 * password hashes, IPs, absolute paths, post passwords, WP/PHP versions, the
 * admin email. Each redactor here is asserted to omit those keys and to not
 * carry their values anywhere in the serialized payload.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use WP_Comment;
use WP_Post;
use WP_Term;
use WP_User;

final class RedactionProofsTest extends TestCase {

	/**
	 * Assert none of the given substrings appear anywhere in the JSON payload.
	 *
	 * @param array<int,string>   $needles Forbidden substrings.
	 * @param array<string,mixed> $shape   The redacted shape.
	 */
	private function assertNoneLeak( array $needles, array $shape ): void {
		$json = (string) wp_json_encode( $shape );
		foreach ( $needles as $needle ) {
			if ( '' === $needle ) {
				continue;
			}
			$this->assertStringNotContainsString(
				$needle,
				$json,
				sprintf( 'Redacted shape leaked "%s": %s', $needle, $json )
			);
		}
	}

	public function test_redact_post_omits_password_and_raw_content(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_status'   => 'publish',
				'post_password' => 'TopSecretPass123',
				'post_content'  => 'Body with <script>alert(1)</script> and SECRETMARKER.',
			)
		);
		$shape   = aafm_redact_post( get_post( $post_id ) );

		$this->assertArrayNotHasKey( 'post_password', $shape );
		$this->assertArrayNotHasKey( 'content', $shape );
		$this->assertArrayNotHasKey( 'comment_count', $shape );
		$this->assertArrayNotHasKey( 'ping_status', $shape );
		// The post_password value must never appear anywhere in the payload.
		$this->assertNoneLeak( array( 'TopSecretPass123' ), $shape );
		// Only the whitelisted keys are present.
		$this->assertSame(
			array( 'id', 'title', 'status', 'type', 'slug', 'excerpt', 'link', 'author_id', 'date_gmt', 'modified_gmt' ),
			array_keys( $shape )
		);
	}

	public function test_redact_user_omits_email_login_and_pass(): void {
		$user_id = self::factory()->user->create(
			array(
				'role'          => 'author',
				'user_login'    => 'secretlogin',
				'user_email'    => 'leak@example.com',
				'user_pass'     => 'hunter2hunter2',
				'user_nicename' => 'secretnice',
				// display_name is deliberately unrelated to the login so the
				// "login value absent" assertion below is meaningful, not an
				// artifact of display_name happening to echo the login.
				'display_name'  => 'Public Display Name',
			)
		);
		$user    = new WP_User( $user_id );
		$shape   = aafm_redact_user( $user );

		foreach ( array( 'user_email', 'user_login', 'email', 'login', 'user_pass', 'pass', 'user_registered', 'user_url', 'allcaps' ) as $forbidden ) {
			$this->assertArrayNotHasKey( $forbidden, $shape );
		}
		$this->assertNoneLeak(
			array( 'leak@example.com', 'secretlogin', 'hunter2hunter2' ),
			$shape
		);
		$this->assertSame( array( 'id', 'display_name', 'roles', 'post_count' ), array_keys( $shape ) );
	}

	public function test_redact_user_on_non_user_returns_empty(): void {
		$this->assertSame( array(), aafm_redact_user( false ) );
	}

	public function test_redact_comment_omits_email_ip_and_agent(): void {
		$post_id    = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$comment_id = self::factory()->comment->create(
			array(
				'comment_post_ID'      => $post_id,
				'comment_author'       => 'Jane Public',
				'comment_author_email' => 'jane@private.example',
				'comment_author_IP'    => '203.0.113.42',
				'comment_author_url'   => 'http://spam.example',
				'comment_agent'        => 'EvilBot/9000',
				'comment_content'      => 'Hello there',
			)
		);
		$shape      = aafm_redact_comment( get_comment( $comment_id ) );

		foreach ( array( 'comment_author_email', 'comment_author_IP', 'comment_author_url', 'comment_agent', 'author_email', 'author_ip', 'author_url' ) as $forbidden ) {
			$this->assertArrayNotHasKey( $forbidden, $shape );
		}
		$this->assertNoneLeak(
			array( 'jane@private.example', '203.0.113.42', 'EvilBot/9000', 'http://spam.example' ),
			$shape
		);
		$this->assertSame(
			array( 'id', 'post_id', 'author_name', 'content', 'status', 'date_gmt', 'parent' ),
			array_keys( $shape )
		);
	}

	public function test_redact_comment_on_non_comment_returns_empty(): void {
		$this->assertSame( array(), aafm_redact_comment( null ) );
	}

	public function test_redact_term_exposes_only_safe_fields(): void {
		$term_id = self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'Safe Term',
			)
		);
		$shape   = aafm_redact_term( get_term( $term_id, 'category' ) );
		$this->assertArrayNotHasKey( 'term_taxonomy_id', $shape );
		$this->assertArrayNotHasKey( 'filter', $shape );
		$this->assertSame(
			array( 'id', 'name', 'slug', 'taxonomy', 'parent', 'count', 'description' ),
			array_keys( $shape )
		);
	}

	public function test_redact_media_omits_absolute_path(): void {
		$att   = self::factory()->attachment->create_upload_object(
			DIR_TESTDATA . '/images/canola.jpg'
		);
		$file  = (string) get_attached_file( $att );
		$shape = aafm_redact_media( get_post( $att ) );

		$this->assertArrayNotHasKey( '_wp_attached_file', $shape );
		$this->assertArrayNotHasKey( 'path', $shape );
		$this->assertArrayNotHasKey( 'file', $shape );
		// The absolute server path must never appear in the inventory shape.
		if ( '' !== $file ) {
			$this->assertNoneLeak( array( $file ), $shape );
		}
		$this->assertSame(
			array( 'id', 'title', 'mime_type', 'url', 'alt', 'width', 'height' ),
			array_keys( $shape )
		);

		if ( '' !== $file && file_exists( $file ) ) {
			wp_delete_file( $file );
		}
	}

	public function test_redact_media_null_dimensions_for_non_image(): void {
		// An attachment with no metadata yields null width/height, not a fatal.
		$att   = self::factory()->post->create(
			array(
				'post_type'      => 'attachment',
				'post_mime_type' => 'application/pdf',
				'post_status'    => 'inherit',
			)
		);
		$shape = aafm_redact_media( get_post( $att ) );
		$this->assertNull( $shape['width'] );
		$this->assertNull( $shape['height'] );
	}

	public function test_site_info_hides_environment_and_admin_email(): void {
		// Drive the execute callback directly; assert the leaked-by-competitors fields
		// are absent from the descriptor.
		$shape = aafm_exec_get_site_info();
		$this->assertArrayHasKey( 'site', $shape );
		$site = $shape['site'];

		foreach ( array( 'version', 'php_version', 'admin_email', 'debug', 'wp_version', 'server', 'plugins', 'theme', 'path', 'abspath' ) as $forbidden ) {
			$this->assertArrayNotHasKey( $forbidden, $site );
		}
		$this->assertSame( array( 'name', 'tagline', 'url', 'language' ), array_keys( $site ) );

		$admin_email = (string) get_option( 'admin_email' );
		$wp_version  = (string) get_bloginfo( 'version' );
		$this->assertNoneLeak(
			array_filter( array( $admin_email, $wp_version, (string) PHP_VERSION, (string) ABSPATH ) ),
			$site
		);
	}
}
