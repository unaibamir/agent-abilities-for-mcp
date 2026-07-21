<?php
/**
 * Quick Connect content-access mapping: which abilities the wizard's Read and Write rows enable,
 * and the load-bearing guarantee that the write bundle can never include a destructive ability.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Admin;

use AAFM\Tests\TestCase;

final class QuickConnectAbilitiesTest extends TestCase {

	/**
	 * The Read bundle is core + content reads only: it carries the safe read-only abilities and
	 * never a write, never a personal-data subject (users/comments), and never an integration.
	 */
	public function test_read_bundle_is_safe_reads_only(): void {
		$reads    = aafm_quickconnect_read_abilities();
		$registry = aafm_get_abilities_registry();

		$this->assertContains( 'aafm/get-posts', $reads );
		$this->assertContains( 'aafm/get-pages', $reads );
		$this->assertContains( 'aafm/get-media', $reads );
		$this->assertContains( 'aafm/get-terms', $reads );
		$this->assertContains( 'aafm/get-site-info', $reads );

		// No write ever slips into the read bundle.
		$this->assertNotContains( 'aafm/create-post', $reads );
		$this->assertNotContains( 'aafm/update-post', $reads );

		// Personal-data subjects stay out of the wizard's first-run read bundle.
		$this->assertNotContains( 'aafm/get-users', $reads );
		$this->assertNotContains( 'aafm/get-comments', $reads );

		// Every name in the bundle is a real, registered read in an allowed subject.
		$allowed = aafm_quickconnect_read_subjects();
		foreach ( $reads as $name ) {
			$this->assertArrayHasKey( $name, $registry, "Read ability {$name} must exist in the registry." );
			$this->assertSame( 'reads', $registry[ $name ]['group'] ?? '', "Read ability {$name} must be in the reads group." );
			$this->assertContains( $registry[ $name ]['subject'] ?? '', $allowed, "Read ability {$name} must be in an allowed subject." );
		}
	}

	/**
	 * The Write bundle is content create/edit only. This is the security-critical assertion: no
	 * matter how the catalog grows, the wizard's write row must never enable a destructive ability.
	 */
	public function test_write_bundle_is_content_writes_and_never_destructive(): void {
		$writes   = aafm_quickconnect_write_abilities();
		$registry = aafm_get_abilities_registry();

		$this->assertContains( 'aafm/create-post', $writes );
		$this->assertContains( 'aafm/update-post', $writes );
		$this->assertContains( 'aafm/create-page', $writes );
		$this->assertContains( 'aafm/update-page', $writes );

		// The destructive content abilities must NEVER be in the wizard's write bundle.
		$this->assertNotContains( 'aafm/trash-post', $writes );
		$this->assertNotContains( 'aafm/delete-post', $writes );
		$this->assertNotContains( 'aafm/trash-page', $writes );
		$this->assertNotContains( 'aafm/delete-page', $writes );

		// Reads never appear in the write bundle.
		$this->assertNotContains( 'aafm/get-posts', $writes );

		// The hard guarantee, checked against the registry: every write is a content write and
		// carries a non-destructive risk. A future 'destructive' content write cannot leak in.
		foreach ( $writes as $name ) {
			$this->assertArrayHasKey( $name, $registry, "Write ability {$name} must exist in the registry." );
			$this->assertSame( 'content', $registry[ $name ]['subject'] ?? '', "Write ability {$name} must be a content ability." );
			$this->assertSame( 'writes', $registry[ $name ]['group'] ?? '', "Write ability {$name} must be in the writes group." );
			$this->assertNotSame( 'destructive', $registry[ $name ]['risk'] ?? '', "Write ability {$name} must not be destructive." );
		}
	}

	/**
	 * Defence in depth against the first-run write set: the wizard's write filter is
	 * risk !== 'destructive', so a content write whose author forgot the destructive risk tag would
	 * silently slip into the first-run bundle. Independently of that filter, cross-check the registry:
	 * every content write whose ability name signals a delete/trash operation MUST be tagged
	 * risk='destructive' (and is therefore excluded from the wizard's write row). A future
	 * aafm/delete-* or aafm/trash-* content write that forgets its risk tag fails here rather than
	 * quietly entering the first-run write set.
	 */
	public function test_destructive_content_writes_are_risk_tagged_and_excluded(): void {
		$registry = aafm_get_abilities_registry();
		$writes   = aafm_quickconnect_write_abilities();
		$checked  = 0;

		foreach ( $registry as $name => $def ) {
			$subject = $def['subject'] ?? '';
			$group   = $def['group'] ?? '';
			if ( 'content' !== $subject || 'writes' !== $group ) {
				continue;
			}

			// The local part after the aafm/ namespace, e.g. 'delete-post', 'trash-page'.
			$local = str_contains( (string) $name, '/' ) ? substr( (string) $name, strpos( (string) $name, '/' ) + 1 ) : (string) $name;
			if ( ! str_starts_with( $local, 'delete-' ) && ! str_starts_with( $local, 'trash-' ) ) {
				continue;
			}

			++$checked;
			$this->assertSame(
				'destructive',
				$def['risk'] ?? '',
				"Content write {$name} deletes/trashes, so it must be tagged risk='destructive'."
			);
			// And, tag being correct, it stays out of the wizard's first-run write row.
			$this->assertNotContains( $name, $writes, "Destructive content write {$name} must never enter the first-run write bundle." );
		}

		// The registry always ships delete-post and trash-post, so a zero here means the scan stopped
		// seeing content writes - a broken guard, not a clean catalog.
		$this->assertGreaterThan( 0, $checked, 'No delete/trash content writes were checked, so the guard is not scanning the registry.' );
	}

	/**
	 * The Read and Write bundles are disjoint, so a name never gets double-classified.
	 */
	public function test_read_and_write_bundles_are_disjoint(): void {
		$overlap = array_intersect( aafm_quickconnect_read_abilities(), aafm_quickconnect_write_abilities() );
		$this->assertSame( array(), $overlap );
	}

	/**
	 * Applying with write off enables exactly the read bundle; a destructive ability is never on.
	 */
	public function test_apply_without_write_enables_reads_only(): void {
		aafm_quickconnect_apply_abilities( false );
		$enabled = aafm_get_enabled_abilities();

		$this->assertContains( 'aafm/get-posts', $enabled );
		$this->assertNotContains( 'aafm/create-post', $enabled );
		$this->assertNotContains( 'aafm/delete-post', $enabled );
		$this->assertNotContains( 'aafm/trash-post', $enabled );
	}

	/**
	 * Applying with write on adds the content write bundle on top of the reads, still with no
	 * destructive ability anywhere in the result.
	 */
	public function test_apply_with_write_adds_content_writes_but_no_deletes(): void {
		aafm_quickconnect_apply_abilities( true );
		$enabled = aafm_get_enabled_abilities();

		$this->assertContains( 'aafm/get-posts', $enabled );
		$this->assertContains( 'aafm/create-post', $enabled );
		$this->assertContains( 'aafm/update-post', $enabled );
		$this->assertNotContains( 'aafm/delete-post', $enabled );
		$this->assertNotContains( 'aafm/trash-post', $enabled );
		$this->assertNotContains( 'aafm/delete-page', $enabled );
	}

	/**
	 * The wizard owns its two rows: re-running it with write off removes the write bundle it
	 * previously turned on, while preserving an unrelated ability the operator enabled elsewhere.
	 */
	public function test_apply_toggling_write_off_removes_writes_but_keeps_unrelated(): void {
		// Simulate a prior full-Abilities-tab choice that is outside the wizard's two rows.
		update_option( 'aafm_enabled_abilities', array( 'aafm/get-users' ) );

		aafm_quickconnect_apply_abilities( true );
		$this->assertContains( 'aafm/create-post', aafm_get_enabled_abilities() );

		aafm_quickconnect_apply_abilities( false );
		$enabled = aafm_get_enabled_abilities();
		$this->assertNotContains( 'aafm/create-post', $enabled, 'Unticking write must remove the write bundle.' );
		$this->assertContains( 'aafm/get-users', $enabled, 'An ability enabled outside the wizard must survive.' );
		$this->assertContains( 'aafm/get-posts', $enabled, 'The read bundle stays on.' );
	}
}
