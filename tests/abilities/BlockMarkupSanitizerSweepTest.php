<?php
/**
 * Every block-markup write goes through aafm_kses_block_markup(), and none bypasses it.
 *
 * The sweep reads every wp_kses_post( call in includes/abilities/ by tokens (comments and strings
 * are never counted) and matches each one by file plus its exact argument expression, never by
 * line number. A call is allowed only when it is on the exclusion list below, each with the reason
 * it is not block markup. The nine routed write sites must call the helper instead, so a routed
 * site that went back to wp_kses_post() fails the sweep as an unlisted call.
 *
 * Not in the sweep, because it calls no kses: restore-revision (revisions.php) restores content
 * already stored and takes no agent markup (plan 302 D20).
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\IntegrationStubs;
use AAFM\Tests\TestCase;

final class BlockMarkupSanitizerSweepTest extends TestCase {

	use IntegrationStubs;

	/**
	 * Markup carrying one of each sanitizer warning: a <style> element and invalid delimiter JSON.
	 */
	private const CONTENT = '<!-- wp:html --><style>.mcp-t td{padding:12px}</style><p>x</p><!-- /wp:html --><!-- wp:heading {"level":2,} --><h2 class="wp-block-heading">Hi</h2><!-- /wp:heading -->';

	/**
	 * The wp_kses_post( calls that stay, by file and argument expression, each with its reason.
	 *
	 * @var array<string,array<string,string>>
	 */
	private const EXCLUDED = array(
		'acf-integration.php'        => array( '$as_string' => 'An ACF wysiwyg field value, not post content.' ),
		'avada.php'                  => array( '$replace' => 'Inserted text, text-only by design.' ),
		'comments.php'               => array( "(string) \$input['content']" => 'Comment content (create and update).' ),
		'media.php'                  => array(
			'$sideloaded_content'             => 'Attachment description.',
			"(string) \$input['description']" => 'Attachment description.',
		),
		'posts.php'                  => array( '$replace' => 'replace-in-post and replace-sitewide inserted text, text-only by design.' ),
		'terms.php'                  => array( "(string) \$input['description']" => 'Term description (create and update).' ),
		'woocommerce/products.php'   => array( "(string) \$input['short_description']" => 'Stored as post_excerpt, not block markup.' ),
		'woocommerce/variations.php' => array( "(string) \$input['description']" => 'Stored in variation meta, not block markup.' ),
	);

	/**
	 * How many calls each excluded expression accounts for in its file.
	 *
	 * @var array<string,int>
	 */
	private const EXCLUDED_COUNTS = array(
		"comments.php|(string) \$input['content']"  => 2,
		'posts.php|$replace'                        => 2,
		"terms.php|(string) \$input['description']" => 2,
	);

	/**
	 * The nine routed write sites, by file.
	 *
	 * @var array<string,int>
	 */
	private const ROUTED = array(
		'blocks.php'               => 2,
		'geodirectory.php'         => 2,
		'posts.php'                => 2,
		'tec/events.php'           => 1,
		'themes.php'               => 1,
		'woocommerce/products.php' => 1,
	);

	/**
	 * Every call to a function in includes/abilities/, as "relative/file.php|normalized argument".
	 *
	 * Handles both tokenizations of a name: PHP 7.4 (T_NS_SEPARATOR + T_STRING) and PHP 8
	 * (T_NAME_FULLY_QUALIFIED for a leading backslash).
	 *
	 * @param string $callee Lowercase function name.
	 * @return list<string>
	 */
	private static function calls_to( string $callee ): array {
		$root  = dirname( __DIR__, 2 ) . '/includes/abilities/';
		$calls = array();
		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );
		$skip  = array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION );
		if ( defined( 'T_NULLSAFE_OBJECT_OPERATOR' ) ) {
			$skip[] = constant( 'T_NULLSAFE_OBJECT_OPERATOR' );
		}
		foreach ( $files as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			$relative = substr( $file->getPathname(), strlen( $root ) );
			$tokens   = token_get_all( (string) file_get_contents( $file->getPathname() ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local source file.
			$count    = count( $tokens );
			for ( $i = 0; $i < $count; $i++ ) {
				$token = $tokens[ $i ];
				if ( ! is_array( $token ) ) {
					continue;
				}
				$name = strtolower( ltrim( $token[1], '\\' ) );
				$kind = $token[0];
				if ( $callee !== $name || ! ( T_STRING === $kind || ( defined( 'T_NAME_FULLY_QUALIFIED' ) && constant( 'T_NAME_FULLY_QUALIFIED' ) === $kind ) ) ) {
					continue;
				}
				$prev = self::significant( $tokens, $i, -1 );
				$next = self::significant( $tokens, $i, 1 );
				if ( ( null !== $prev && is_array( $tokens[ $prev ] ) && in_array( $tokens[ $prev ][0], $skip, true ) ) || null === $next || '(' !== $tokens[ $next ] ) {
					continue;
				}
				$depth = 0;
				$arg   = '';
				for ( $j = $next; $j < $count; $j++ ) {
					$text = is_array( $tokens[ $j ] ) ? $tokens[ $j ][1] : $tokens[ $j ];
					if ( '(' === $text ) {
						++$depth;
						if ( 1 === $depth ) {
							continue;
						}
					} elseif ( ')' === $text ) {
						--$depth;
						if ( 0 === $depth ) {
							break;
						}
					}
					$arg .= ( is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) ? ' ' : $text;
				}
				$calls[] = $relative . '|' . trim( (string) preg_replace( '/\s+/', ' ', $arg ) );
			}
		}
		sort( $calls );
		return $calls;
	}

	/**
	 * Index of the nearest non-whitespace, non-comment token from $i in direction $step.
	 *
	 * @param array<int,mixed> $tokens Tokens.
	 * @param int              $i      Start index.
	 * @param int              $step   1 or -1.
	 * @return int|null
	 */
	private static function significant( array $tokens, int $i, int $step ): ?int {
		for ( $k = $i + $step; isset( $tokens[ $k ] ); $k += $step ) {
			if ( ! is_array( $tokens[ $k ] ) || ! in_array( $tokens[ $k ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				return $k;
			}
		}
		return null;
	}

	public function test_every_wp_kses_post_call_in_abilities_is_routed_or_excluded(): void {
		$expected = array();
		foreach ( self::EXCLUDED as $file => $args ) {
			foreach ( array_keys( $args ) as $arg ) {
				$key      = $file . '|' . $arg;
				$expected = array_merge( $expected, array_fill( 0, self::EXCLUDED_COUNTS[ $key ] ?? 1, $key ) );
			}
		}
		sort( $expected );

		$this->assertCount( 12, $expected, 'Fixture: twelve excluded calls.' );
		$this->assertSame( $expected, self::calls_to( 'wp_kses_post' ), 'A wp_kses_post( call in includes/abilities/ is neither routed through aafm_kses_block_markup() nor on the exclusion list.' );
	}

	public function test_the_nine_routed_sites_call_the_helper(): void {
		$per_file = array();
		foreach ( self::calls_to( 'aafm_kses_block_markup' ) as $call ) {
			$file              = explode( '|', $call )[0];
			$per_file[ $file ] = ( $per_file[ $file ] ?? 0 ) + 1;
		}
		ksort( $per_file );

		$this->assertSame( self::ROUTED, $per_file );
		$this->assertSame( 9, array_sum( $per_file ) );
	}

	/**
	 * The scanner itself sees a call, so an empty result above is never a blind scanner.
	 */
	public function test_the_scanner_finds_a_known_call(): void {
		$this->assertContains( 'avada.php|$replace', self::calls_to( 'wp_kses_post' ) );
	}

	/**
	 * Every routed ability, plus WooCommerce, whose description is routed with its warnings dropped.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function data_routed_abilities(): array {
		$out = array();
		foreach ( array( 'create-post', 'update-post', 'create-block', 'update-block', 'update-template', 'geodirectory-create-listing', 'geodirectory-update-listing', 'tec-create-event', 'tec-update-event', 'wc-update-product' ) as $name ) {
			$out[ $name ] = array( $name );
		}
		return $out;
	}

	/**
	 * A <style> element and invalid delimiter JSON come back as warnings from every routed write.
	 *
	 * @dataProvider data_routed_abilities
	 *
	 * @param string $ability Ability name without the aafm/ prefix.
	 */
	public function test_every_routed_write_returns_sanitizer_warnings( string $ability ): void {
		$this->acting_as( 'administrator' );
		list( $res, $stored ) = $this->write_through( $ability );

		$this->assertIsArray( $res, is_wp_error( $res ) ? $res->get_error_message() : 'not an array' );
		$this->assertStringNotContainsString( 'padding:12px', $stored, 'No CSS text is stored.' );
		if ( 'wc-update-product' === $ability ) {
			$this->assertArrayNotHasKey( 'content_warnings', $res, 'A WooCommerce response carries no content_warnings key.' );
			return;
		}
		$codes = wp_list_pluck( $res['content_warnings'] ?? array(), 'code' );
		$this->assertContains( 'removed_element', $codes );
		$this->assertContains( 'invalid_block_attributes', $codes );
	}

	/**
	 * Run one routed write with CONTENT and return its response and the stored content.
	 *
	 * @param string $ability Ability name without the aafm/ prefix.
	 * @return array{0:mixed,1:string}
	 */
	private function write_through( string $ability ): array {
		$stored = static fn( int $id ): string => (string) get_post( $id )->post_content;
		switch ( $ability ) {
			case 'create-post':
				$res = aafm_exec_create_post(
					array(
						'title'   => 'Routed',
						'content' => self::CONTENT,
					)
				);
				return array( $res, is_array( $res ) ? $stored( (int) $res['post']['id'] ) : '' );
			case 'update-post':
				$id = self::factory()->post->create();
				return array(
					aafm_exec_update_post(
						array(
							'post_id' => $id,
							'content' => self::CONTENT,
						)
					),
					$stored( $id ),
				);
			case 'create-block':
				$res = aafm_exec_create_block(
					array(
						'title'   => 'Routed',
						'content' => self::CONTENT,
					)
				);
				return array( $res, is_array( $res ) ? $stored( (int) $res['id'] ) : '' );
			case 'update-block':
				$id = self::factory()->post->create( array( 'post_type' => 'wp_block' ) );
				return array(
					aafm_exec_update_block(
						array(
							'block_id' => $id,
							'content'  => self::CONTENT,
						)
					),
					$stored( $id ),
				);
			case 'update-template':
				$previous = get_stylesheet();
				switch_theme( 'twentytwentyfive' );
				$id = (int) self::factory()->post->create(
					array(
						'post_type'   => 'wp_template',
						'post_status' => 'publish',
						'post_name'   => 'aafm-routed-tpl',
					)
				);
				wp_set_object_terms( $id, get_stylesheet(), 'wp_theme' );
				$res = aafm_exec_update_template(
					array(
						'template_id' => get_stylesheet() . '//aafm-routed-tpl',
						'content'     => self::CONTENT,
					)
				);
				$out = array( $res, $stored( $id ) );
				switch_theme( $previous );
				return $out;
			case 'geodirectory-create-listing':
			case 'geodirectory-update-listing':
				aafm_geodir_stub_activate();
				add_filter( 'aafm_integration_active_geodirectory', '__return_true' );
				if ( 'geodirectory-create-listing' === $ability ) {
					$res = aafm_exec_geodirectory_create_listing(
						array(
							'title'   => 'Routed',
							'content' => self::CONTENT,
						)
					);
					return array( $res, is_array( $res ) ? $stored( (int) $res['listing_id'] ) : '' );
				}
				$created = aafm_exec_geodirectory_create_listing( array( 'title' => 'Routed' ) );
				$id      = (int) $created['listing_id'];
				return array(
					aafm_exec_geodirectory_update_listing(
						array(
							'listing_id' => $id,
							'content'    => self::CONTENT,
						)
					),
					$stored( $id ),
				);
			case 'tec-create-event':
			case 'tec-update-event':
				$this->stub_tec();
				add_filter( 'aafm_integration_active_tec', '__return_true' );
				$dates = array(
					'start_date' => '2027-01-01 09:00:00',
					'end_date'   => '2027-01-01 12:00:00',
				);
				if ( 'tec-create-event' === $ability ) {
					$res = aafm_exec_tec_create_event(
						array(
							'title'   => 'Routed',
							'content' => self::CONTENT,
						) + $dates
					);
					return array( $res, is_array( $res ) ? $stored( (int) $res['event']['id'] ) : '' );
				}
				$id = self::factory()->post->create(
					array(
						'post_type'   => \Tribe__Events__Main::POSTTYPE,
						'post_status' => 'publish',
					)
				);
				return array(
					aafm_exec_tec_update_event(
						array(
							'event_id' => $id,
							'content'  => self::CONTENT,
						)
					),
					$stored( $id ),
				);
			default:
				$this->force_integration( 'woocommerce' );
				$this->stub_woocommerce();
				$id = self::factory()->post->create( array( 'post_type' => 'product' ) );
				\AAFM\Tests\WcStubStore::seed(
					$id,
					array(
						'id'     => $id,
						'name'   => 'Routed',
						'status' => 'publish',
					)
				);
				$res = aafm_exec_wc_update_product(
					array(
						'product_id'  => $id,
						'description' => self::CONTENT,
					)
				);
				return array( $res, is_array( $res ) ? (string) $res['description'] : '' );
		}
	}

	public function tear_down(): void {
		remove_filter( 'aafm_integration_active_geodirectory', '__return_true' );
		remove_filter( 'aafm_integration_active_tec', '__return_true' );
		$this->reset_integration_stubs();
		parent::tear_down();
	}
}
