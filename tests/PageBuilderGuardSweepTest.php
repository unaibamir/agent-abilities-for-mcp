<?php
/**
 * Sweep: every content-write execute callback refuses a builder-owned post.
 *
 * Update-cpt-item and update-page both delegate wholesale to aafm_exec_update_post() (confirmed
 * by reading posts.php/pages.php before wiring the guard), so wiring the guard once into
 * aafm_exec_update_post() covers all three - this sweep proves that delegation actually carries
 * the guard through, rather than trusting the delegation claim.
 *
 * Codex final round 8 HIGH: tec-update-event and geodirectory-update-listing both wrote
 * post_content with no ownership check at all, and this file's own hand-written list of write
 * callbacks never enumerated either one - the sweep's coverage was itself the gap, not just the
 * two missing checks. test_every_post_content_write_site_is_guarded_or_explicitly_exempt() below
 * is the structural fix for THAT: it scans every includes/abilities/**\/*.php file for a
 * post_content write and asserts the enclosing function either calls the ownership check itself
 * or is named in an explicit, reasoned exemption list, so a FUTURE write path that skips the
 * guard fails this test by construction rather than needing a human to remember to add a row
 * above.
 *
 * Codex hunt H1 (2026-09-06, accepted hardening follow-up logged in
 * 231-1-7-4-build-record-2026-09-05.md): the scan's own signals were narrower than the write
 * shapes a future ability could actually use. Broadened to also catch wp_insert_post() called
 * with an 'ID' (WordPress core treats that as an update, not a create - the same commit-an-
 * existing-post verb wp_update_post() is), a direct update_post_meta()/add_post_meta() write to
 * one of the page builders' own rendering-source meta keys (aafm_page_builder_markers()), and a
 * raw $wpdb write to the posts or postmeta table. Each addition was checked against the current
 * source tree for new false positives before landing (none found) - see the per-signal comments
 * below for what each one is scoped to and why.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests;

final class PageBuilderGuardSweepTest extends TestCase {

	use \AAFM\Tests\IntegrationStubs;

	public function set_up(): void {
		parent::set_up();
		// Needed only by the TEC/GeoDirectory rows in provide_write_execute_callbacks() below -
		// harmless for the plain 'post'/'page' rows, which never touch either post type.
		$this->stub_tec();
		aafm_geodir_stub_activate();
		add_filter( 'aafm_integration_active_tec', '__return_true' );
		add_filter( 'aafm_integration_active_geodirectory', '__return_true' );
	}

	public function tear_down(): void {
		remove_filter( 'aafm_integration_active_tec', '__return_true' );
		remove_filter( 'aafm_integration_active_geodirectory', '__return_true' );
		parent::tear_down();
	}

	/**
	 * Every function, anywhere under includes/abilities/, that assigns a 'post_content' array
	 * key, calls wp_update_post(), calls a repository ->save() (outside woocommerce/ - see
	 * find_ability_php_files()'s own scoping), or calls a WC_Product content setter
	 * (set_description()/set_short_description(), which WooCommerce persists as
	 * post_content/post_excerpt - Codex round 9 R9-1) must either call
	 * aafm_post_has_foreign_builder_ownership() in its own body, or be listed here with a reason.
	 * This is the mechanical half of the sweep: it does not run any PHP, it only reads source
	 * text, so it catches a future write path the moment it's written, before any test author has
	 * to remember to add a data-provider row for it.
	 *
	 * @return array<string,string> Function name => reason it does not need the ownership check.
	 */
	private function exempt_post_content_writers(): array {
		return array(
			// Creates a brand-new post/block/listing; there is no PRE-EXISTING content a
			// foreign builder could already own, which is the entire threat this guard exists
			// to stop.
			'aafm_insert_post'                      => 'Creates a brand-new post (shared by create-post and friends) - nothing pre-existing to protect.',
			'aafm_exec_create_block'                => 'Creates a brand-new wp_block - nothing pre-existing to protect.',
			'aafm_exec_geodirectory_create_listing' => 'Creates a brand-new gd_place listing - nothing pre-existing to protect.',
			'aafm_finish_media_upload'              => 'Rewrites post_content on an attachment THIS SAME CALL just sideloaded a moment earlier - nothing pre-existing to protect.',
			// Post types no classic page builder (Elementor, Divi, Beaver Builder, Avada - the
			// ones this guard's marker map covers) ever attaches ownership to.
			'aafm_exec_update_media'                => 'Attachment post type - never a front-end page a classic page builder renders or owns.',
			'aafm_exec_update_template'             => 'wp_template/wp_template_part - a block-theme Site Editor mechanism, mutually exclusive with the classic page builders this guard covers.',
			'aafm_exec_update_block'                => 'wp_block (reusable block) - a Gutenberg-internal mechanism, mutually exclusive with the classic page builders this guard covers.',
			// A pure args-builder helper, not itself a write site - both its callers
			// (aafm_exec_tec_create_event, which needs no guard, and aafm_exec_tec_update_event,
			// which now has one) are checked at their own chokepoint.
			'aafm_tec_event_orm_args'               => 'Builds an args array only; the actual write (and its own ownership check) happens in the calling create/update function.',
			// Codex round 9 R9-1: shared setter helper, not itself a write site - it is called by
			// BOTH aafm_exec_wc_create_product() (a brand-new product, nothing pre-existing to
			// protect) and aafm_exec_wc_update_product() (which now runs the ownership check on
			// the existing product BEFORE calling this), so the check belongs at the caller, the
			// same split aafm_tec_event_orm_args() uses above.
			'aafm_wc_apply_product_input'           => 'Shared setter helper for both create and update; the update caller (aafm_exec_wc_update_product) now runs the ownership check itself before calling this, and create has no prior owner to protect.',
			// Structured contact-info entities, not rendered page content: neither accepts a
			// content field at all (confirmed: neither venues.php nor organizers.php contains the
			// literal 'post_content' anywhere), so there is nothing here a page builder could ever
			// own or corrupt.
			'aafm_exec_tec_update_venue'            => 'Venues store only structured address/contact fields via ->save() - no content field exists to protect.',
			'aafm_exec_tec_update_organizer'        => 'Organizers store only structured contact fields via ->save() - no content field exists to protect.',
			// nav_menu_item: an internal menu-structure record, never a front-end page a classic
			// page builder renders or owns - even though wp_update_post() is genuinely called here
			// (to restore menu order after core resets it) and menu-item-description does map to
			// post_content.
			'aafm_exec_update_menu_item'            => 'nav_menu_item post type - an internal menu-structure record, not a page a classic page builder ever owns.',
			// Known false positives from the mechanical scan matching TEXT, not code: a
			// translatable description string and a comment, not an actual write call.
			// Codex final round 4 MEDIUM: the scan is now comment-blind (see strip_comments()
			// below), which resolves two of this list's three former "matches only in prose"
			// entries on its own - aafm_exec_moderate_comment and aafm_exec_aioseo_update_post
			// both matched only inside a // comment, never in real code, and are no longer in
			// this list at all. aafm_args_replace_sitewide stays: its false-positive text lives
			// inside a translatable __() description string, which is real, executed code, not a
			// comment - comment-stripping does not and should not touch it.
			'aafm_args_replace_sitewide'            => 'Args/schema builder only, no write - matches only because its own output_schema description mentions "wp_update_post()" in prose.',
		);
	}

	/**
	 * Extract every top-level `function aafm_...(` body from a file's source, keyed by name.
	 * A plain brace-depth counter, not a real parser - correct for this codebase's consistent
	 * style (array() literals, no nested top-level functions, no short array syntax), which is
	 * all a completeness sweep needs.
	 *
	 * @param string $source Full file contents.
	 * @return array<string,string> Function name => full source text of its body.
	 */
	private function extract_function_bodies( string $source ): array {
		$functions = array();
		$name      = null;
		$buffer    = '';
		$depth     = 0;
		foreach ( explode( "\n", $source ) as $line ) {
			if ( null === $name ) {
				if ( preg_match( '/^function\s+(aafm_[A-Za-z0-9_]+)\s*\(/', $line, $matches ) ) {
					$name   = $matches[1];
					$buffer = '';
					$depth  = 0;
				} else {
					continue;
				}
			}
			$buffer .= $line . "\n";
			$depth  += substr_count( $line, '{' ) - substr_count( $line, '}' );
			if ( $depth <= 0 && str_contains( $buffer, '{' ) ) {
				$functions[ $name ] = $buffer;
				$name               = null;
			}
		}
		return $functions;
	}

	/**
	 * Strip every // and /* comment (docblocks included) out of a function body before the
	 * signal regexes run against it.
	 *
	 * Codex final round 4 MEDIUM: the broadened Signal B ('wp_insert_post(' as a substring) also
	 * matches that literal text sitting inside a comment explaining unrelated behavior - a real
	 * false positive this file used to paper over with a blanket function-level exemption, which
	 * also hid any FUTURE real unguarded write in that same function. Tokenizing with
	 * token_get_all() and dropping T_COMMENT/T_DOC_COMMENT is the actual fix: it makes the scanner
	 * blind to prose while staying fully sensitive to real code, comments and all, everywhere else.
	 * A string literal (e.g. a translatable description built with __()) is a real, executed
	 * token - not a comment - and is deliberately left untouched.
	 *
	 * @param string $body Full source text of one function, as extracted by extract_function_bodies().
	 * @return string The same body with every comment token's text removed.
	 */
	private function strip_comments( string $body ): string {
		$tokens   = token_get_all( "<?php\n" . $body );
		$stripped = '';
		foreach ( $tokens as $token ) {
			if ( is_array( $token ) ) {
				if ( T_COMMENT === $token[0] || T_DOC_COMMENT === $token[0] ) {
					continue;
				}
				$stripped .= $token[1];
			} else {
				$stripped .= $token;
			}
		}
		return $stripped;
	}

	/**
	 * Every .php file under $dir, at any depth - PHP's glob() does not actually recurse on `**`,
	 * so this uses a real recursive directory walk instead of a glob pattern that would silently
	 * only ever scan the top level.
	 *
	 * @param string $dir Root directory to scan.
	 * @return list<string> Absolute file paths.
	 */
	private function find_ability_php_files( string $dir ): array {
		$files    = array();
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file_info ) {
			if ( $file_info->isFile() && 'php' === $file_info->getExtension() ) {
				$files[] = $file_info->getPathname();
			}
		}
		return $files;
	}

	/**
	 * The mechanical sweep itself: no PHP execution, just source scanning.
	 */
	public function test_every_post_content_write_site_is_guarded_or_explicitly_exempt(): void {
		$exempt = $this->exempt_post_content_writers();
		foreach ( $exempt as $function_name => $reason ) {
			$this->assertNotSame( '', trim( $reason ), "The exemption for $function_name must state a reason." );
		}

		$files = $this->find_ability_php_files( AAFM_PLUGIN_DIR . 'includes/abilities' );
		$this->assertNotEmpty( $files, 'The recursive scan must actually find ability files - an empty list would make this test pass by finding nothing.' );

		$unguarded = array();
		$seen_any  = false;
		foreach ( $files as $file ) {
			// Signal B (wp_update_post()/repository ->save()) is scoped OUT of woocommerce/:
			// most ->save() calls there are a WC_Order/WC_Coupon/etc CRUD-object save with no
			// content field at all. Codex round 9 R9-1: this used to exclude EVERY WooCommerce
			// ->save(), including WC_Product's, on that same assumption - wrong for
			// description/short_description, which WooCommerce persists as the product post's
			// post_content/post_excerpt. Signal E below catches that write shape specifically
			// (the setter call, not the generic ->save()), so the blanket Signal B/D exclusion
			// can stay narrow instead of widening it to a signal that would false-positive on
			// every non-content WC_Product/WC_Order/WC_Coupon save. Signal A ('post_content'
			// literal) still applies everywhere; it simply never matches inside woocommerce/ in
			// practice, since WooCommerce writes through its own setters, not a raw array key.
			$is_woocommerce = false !== strpos( $file, DIRECTORY_SEPARATOR . 'woocommerce' . DIRECTORY_SEPARATOR );

			$source    = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading this plugin's own local source files to scan them, not a remote URL.
			$functions = $this->extract_function_bodies( $source );
			foreach ( $functions as $function_name => $body ) {
				// Codex final round 4 MEDIUM: match against a comment-blind copy of the body, not
				// the raw source, so prose in a // or /* comment can never trip a signal or hide
				// a real one behind an exemption. See strip_comments() for why a string literal
				// (a translatable __() description, say) is deliberately left untouched.
				$body = $this->strip_comments( $body );
				// Signal A: a direct 'post_content' key assignment in this function's own body.
				$writes_content_directly = (bool) preg_match( "/'post_content'\\s*=>/", $body );
				// Signal B: commits an update to an EXISTING post. wp_update_post() always
				// requires an ID (whether called with an array or a WP_Post/stdClass argument -
				// this is a substring match, so either form matches); wp_insert_post() is the
				// create verb, EXCEPT that WordPress core itself treats a postarr carrying an
				// 'ID' as an update too (wp-includes/post.php delegates to wp_update_post()
				// internally) - a future write path could exploit exactly that to commit an
				// existing-post update while dodging a wp_update_post()-only scan, so both
				// functions are treated as the same signal here; a repository ->save() call, in
				// this codebase's ORM usage, is exclusively the update verb - ->create() is the
				// create verb. Catches a write whose content came from a shared args-builder
				// helper called earlier, rather than assigned inline (e.g.
				// aafm_exec_tec_update_event()).
				$commits_an_existing_post_update = ! $is_woocommerce
					&& (
						false !== strpos( $body, 'wp_update_post(' )
						|| false !== strpos( $body, 'wp_insert_post(' )
						|| false !== strpos( $body, '->save(' )
					);
				// Signal C: writes one of the page builders' OWN rendering-source meta keys
				// directly (aafm_page_builder_markers(), includes/page-builder-guard.php) rather
				// than through post_content/post_excerpt - the exact data a foreign builder
				// actually renders from, so a write here is just as capable of silently
				// corrupting or being ignored by builder-owned content as a post_content write
				// is. Scoped to the known marker key names specifically (not every
				// update_post_meta()/add_post_meta() call) so an ordinary SEO- or
				// allowlist-gated meta write - already covered by its own hard-block chokepoint,
				// aafm_hard_blocked_meta_key() - does not become a false positive here.
				$writes_a_builder_marker_key = false;
				foreach ( array_keys( aafm_page_builder_markers() ) as $marker_key ) {
					if (
						( false !== strpos( $body, 'update_post_meta(' ) || false !== strpos( $body, 'add_post_meta(' ) )
						&& false !== strpos( $body, "'" . $marker_key . "'" )
					) {
						$writes_a_builder_marker_key = true;
						break;
					}
				}
				// Signal D: a direct $wpdb write to the posts or postmeta table, bypassing every
				// WordPress post API (and so every signal above) entirely. No current write site
				// does this - GeoDirectory, this release's one integration with no post-field
				// write API of its own, uses core wp_insert_post()/wp_update_post() specifically
				// to stay on this signal's radar (see the dossier's TEC/GeoDirectory standing
				// invariant) - but a future integration could still reach for $wpdb directly.
				$writes_posts_table_directly = ! $is_woocommerce
					&& ( false !== strpos( $body, '$wpdb->posts' ) || false !== strpos( $body, '$wpdb->postmeta' ) )
					&& (
						false !== strpos( $body, '$wpdb->update(' )
						|| false !== strpos( $body, '$wpdb->insert(' )
						|| false !== strpos( $body, '$wpdb->query(' )
					);
				// Signal E: a WC_Product content setter, on the $product variable specifically.
				// Codex round 9 R9-1: WooCommerce persists description/short_description as the
				// product post's post_content/post_excerpt, so this is the WooCommerce-specific
				// write shape Signal B's blanket exclusion above cannot see. Scoped to the
				// literal `$product->set_description(`/`$product->set_short_description(` call
				// shape products.php actually uses (not a bare `->set_description(` wildcard),
				// so an ordinary non-content WC_Product save (price, stock, status) stays outside
				// the scan, and so do WC_Product_Variation::set_description()
				// (variations.php - a product_variation post, never individually rendered or
				// owned by a page builder, the same reasoning the nav_menu_item exemption above
				// already uses) and WC_Coupon::set_description() (coupons.php - free-form admin
				// text through aafm_sanitize_multiline_text(), not page content a builder could
				// ever own). Both are pre-existing, unfixed gaps outside this round's scope, not
				// new false positives this signal introduces - see the round 9 findings write-up.
				$writes_wc_product_content = false !== strpos( $body, '$product->set_description(' )
					|| false !== strpos( $body, '$product->set_short_description(' );

				if ( ! $writes_content_directly && ! $commits_an_existing_post_update && ! $writes_a_builder_marker_key && ! $writes_posts_table_directly && ! $writes_wc_product_content ) {
					continue;
				}
				$seen_any = true;
				if ( isset( $exempt[ $function_name ] ) ) {
					continue;
				}
				if ( false === strpos( $body, 'aafm_post_has_foreign_builder_ownership(' ) ) {
					$unguarded[] = $function_name . ' (' . basename( $file ) . ')';
				}
			}
		}

		$this->assertTrue( $seen_any, 'The sweep found zero content or builder-marker writers at all - it is almost certainly broken, not proving the codebase is clean.' );
		$this->assertSame(
			array(),
			$unguarded,
			'Every function that writes post_content, commits an existing-post update, writes a page builder\'s own marker meta, or writes the posts/postmeta table directly must call aafm_post_has_foreign_builder_ownership() itself, or be added to exempt_post_content_writers() with a reason: ' . implode( ', ', $unguarded )
		);
	}

	/**
	 * Every wired write execute callback refuses a post the guard flags as builder-owned.
	 *
	 * @dataProvider provide_write_execute_callbacks
	 *
	 * @param string              $exec_function Function name under test.
	 * @param array<string,mixed> $extra_input   Extra input merged with the builder-owned post's id.
	 * @param string              $id_key        The input key the callback expects the post id under.
	 * @param string              $post_type     Post type the fixture must be created as.
	 */
	public function test_every_content_write_refuses_a_builder_owned_post( string $exec_function, array $extra_input, string $id_key, string $post_type ): void {
		$post = self::factory()->post->create( array( 'post_type' => $post_type ) );
		update_post_meta( $post, '_elementor_data', '[]' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$result = $exec_function( array_merge( array( $id_key => $post ), $extra_input ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'aafm_page_builder_owned', $result->get_error_code() );
	}

	/**
	 * Data provider: one row per write execute callback under test.
	 *
	 * @return array<string,array{0:string,1:array<string,mixed>,2:string,3:string}>
	 */
	public function provide_write_execute_callbacks(): array {
		return array(
			'update-post'                 => array( 'aafm_exec_update_post', array( 'title' => 'x' ), 'post_id', 'post' ),
			'replace-in-post'             => array(
				'aafm_exec_replace_in_post',
				array(
					'search'  => 'x',
					'replace' => 'y',
				),
				'post_id',
				'post',
			),
			'update-page'                 => array( 'aafm_exec_update_page', array( 'title' => 'x' ), 'page_id', 'page' ),
			'update-cpt-item'             => array( 'aafm_exec_update_cpt_item', array( 'title' => 'x' ), 'post_id', 'post' ),
			// Codex final round 8 HIGH: both added after the earlier rows above were the ONLY
			// ones this sweep enumerated, which is exactly why they were missed the first time.
			// Literal 'tribe_events', not Tribe__Events__Main::POSTTYPE - that stub class is only
			// declared inside stub_tec() (set_up()), which PHPUnit runs AFTER this data provider.
			'tec-update-event'            => array( 'aafm_exec_tec_update_event', array( 'title' => 'x' ), 'event_id', 'tribe_events' ),
			'geodirectory-update-listing' => array( 'aafm_exec_geodirectory_update_listing', array( 'title' => 'x' ), 'listing_id', 'gd_place' ),
		);
	}

	public function test_replace_sitewide_skips_and_counts_a_builder_owned_candidate_instead_of_aborting(): void {
		$owned = self::factory()->post->create(
			array(
				'post_content' => 'find me here',
				'post_status'  => 'publish',
			)
		);
		update_post_meta( $owned, '_elementor_data', '[]' );
		$plain = self::factory()->post->create(
			array(
				'post_content' => 'find me here',
				'post_status'  => 'publish',
			)
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$result = aafm_exec_replace_sitewide(
			array(
				'search'    => 'find me',
				'replace'   => 'found',
				'post_type' => 'post',
				'status'    => 'publish',
				'dry_run'   => false,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 1, $result['skipped_builder_owned'] );
		$this->assertSame( 1, $result['updated_posts'] );

		$owned_post = get_post( $owned );
		$this->assertSame( 'find me here', $owned_post->post_content, 'The builder-owned post must be left byte-for-byte untouched.' );
		$plain_post = get_post( $plain );
		$this->assertSame( 'found here', $plain_post->post_content );
	}
}
