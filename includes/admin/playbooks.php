<?php
/**
 * The Playbooks tab: the owner's library, the editor and the starter playbooks, with one AJAX save
 * per playbook.
 *
 * Stored text reaches the page only through esc_attr() data attributes and esc_html() cells. The
 * editor textarea is always rendered empty; admin.js fills it with .value from the row's
 * attributes, so there is no esc_textarea() path to forget.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * How many abilities a playbook names and how many of those are off for the current admin.
 *
 * @param array<string,mixed> $playbook Stored playbook.
 * @return array{uses:int,unavailable:int}
 */
function aafm_playbook_admin_counts( array $playbook ): array {
	$uses = aafm_playbook_named_abilities( (string) $playbook['body'] );
	return array(
		'uses'        => count( $uses ),
		'unavailable' => count( aafm_playbook_unavailable_abilities( $uses ) ),
	);
}

/**
 * The "Uses N abilities, M are off here" text.
 *
 * @param int $uses        Abilities named.
 * @param int $unavailable Of those, how many are off.
 * @return string
 */
function aafm_playbook_uses_text( int $uses, int $unavailable ): string {
	/* translators: %d: how many abilities the playbook names. */
	$text = sprintf( _n( 'Uses %d ability', 'Uses %d abilities', $uses, 'agent-abilities-for-mcp' ), $uses );
	if ( $unavailable > 0 ) {
		/* translators: 1: "Uses N abilities". 2: how many of them are off on this site. */
		$text = sprintf( _n( '%1$s, %2$d is off here', '%1$s, %2$d are off here', $unavailable, 'agent-abilities-for-mcp' ), $text, $unavailable );
	}
	return $text;
}

/**
 * One row of the "Your playbooks" table.
 *
 * @param array<string,mixed> $playbook Stored playbook.
 * @return string
 */
function aafm_playbook_row_html( array $playbook ): string {
	$counts  = aafm_playbook_admin_counts( $playbook );
	$updated = '' !== $playbook['updated_gmt'] ? substr( (string) $playbook['updated_gmt'], 0, 16 ) . ' UTC' : '';
	$active  = (bool) $playbook['active'];
	$uses    = aafm_playbook_uses_text( $counts['uses'], $counts['unavailable'] );

	return sprintf(
		'<tr data-playbook-slug="%1$s" data-playbook-title="%2$s" data-playbook-description="%3$s" data-playbook-body="%4$s" data-playbook-active="%5$s">'
		. '<td class="aafm-playbook-title" title="%2$s">%6$s</td>'
		. '<td><span class="aafm-pill %7$s">%8$s</span></td>'
		. '<td class="aafm-playbook-uses" title="%9$s">%9$s</td>'
		. '<td>%10$s</td>'
		. '<td class="aafm-playbook-actions"><button type="button" class="aafm-btn aafm-btn-secondary aafm-btn-sm aafm-playbook-edit">%11$s</button> <button type="button" class="aafm-btn aafm-btn-secondary aafm-btn-sm aafm-playbook-delete">%12$s</button></td>'
		. '</tr>',
		esc_attr( (string) $playbook['slug'] ),
		esc_attr( (string) $playbook['title'] ),
		esc_attr( (string) $playbook['description'] ),
		esc_attr( (string) $playbook['body'] ),
		$active ? '1' : '0',
		esc_html( (string) $playbook['title'] ),
		$active ? 'aafm-pill-success' : 'aafm-pill-neutral',
		$active ? esc_html__( 'Active', 'agent-abilities-for-mcp' ) : esc_html__( 'Off', 'agent-abilities-for-mcp' ),
		esc_html( $uses ),
		esc_html( $updated ),
		esc_html__( 'Edit', 'agent-abilities-for-mcp' ),
		esc_html__( 'Delete', 'agent-abilities-for-mcp' )
	);
}

/**
 * Render the Playbooks tab.
 *
 * @return void
 */
function aafm_render_playbooks_tab(): void {
	$playbooks = aafm_playbooks();
	$enabled   = aafm_get_enabled_abilities();
	$both_on   = in_array( 'aafm/list-playbooks', $enabled, true ) && in_array( 'aafm/get-playbook', $enabled, true );
	$any_on    = array() !== array_filter( array_column( $playbooks, 'active' ) );

	echo '<div class="aafm-playbooks">';

	// Card 1: the library.
	ob_start();
	$abilities_url = add_query_arg(
		array(
			'page' => 'agent-abilities-for-mcp',
			'tab'  => 'abilities',
		),
		admin_url( 'admin.php' )
	);
	if ( $any_on && ! $both_on ) {
		aafm_render_notice(
			'info',
			sprintf(
			/* translators: %s: link to the Abilities tab. */
				__( 'Agents cannot see your Active playbooks yet. Turn on List playbooks and Get playbook in the Site section of the %s.', 'agent-abilities-for-mcp' ),
				'<a href="' . esc_url( $abilities_url ) . '">' . esc_html__( 'Abilities tab', 'agent-abilities-for-mcp' ) . '</a>'
			),
			array(
				'inline' => true,
				'html'   => true,
			)
		);
	}
	echo '<p class="aafm-empty-state aafm-playbooks-empty"' . ( array() === $playbooks ? '' : ' hidden' ) . '>' . esc_html__( 'No playbooks yet. Write one below or add a starter.', 'agent-abilities-for-mcp' ) . '</p>';
	echo '<div class="aafm-table-wrap"' . ( array() === $playbooks ? ' hidden' : '' ) . '><table class="widefat striped aafm-playbooks-table">';
	echo '<thead><tr><th scope="col">' . esc_html__( 'Title', 'agent-abilities-for-mcp' ) . '</th><th scope="col">' . esc_html__( 'Status', 'agent-abilities-for-mcp' ) . '</th><th scope="col">' . esc_html__( 'Uses', 'agent-abilities-for-mcp' ) . '</th><th scope="col">' . esc_html__( 'Updated', 'agent-abilities-for-mcp' ) . '</th><th scope="col">' . esc_html__( 'Actions', 'agent-abilities-for-mcp' ) . '</th></tr></thead><tbody>';
	foreach ( $playbooks as $playbook ) {
		echo aafm_playbook_row_html( $playbook ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every value escaped in aafm_playbook_row_html().
	}
	echo '</tbody></table></div>';
	echo '<p class="aafm-save-status aafm-playbooks-list-status" role="status" aria-live="polite"></p>';
	aafm_render_section(
		array(
			'id'          => 'aafm-playbooks-list',
			'icon'        => 'playbooks',
			'title'       => __( 'Your playbooks', 'agent-abilities-for-mcp' ),
			'description' => __( 'A playbook is a set of steps you write for agents, such as how to check a draft or build a weekly report. Agents can list and load your Active playbooks once List playbooks and Get playbook are on and the connection\'s role and access rules allow them.', 'agent-abilities-for-mcp' ),
			'body'        => (string) ob_get_clean(),
		)
	);

	// Card 2: the editor.
	ob_start();
	echo '<form id="aafm-playbook-form" novalidate>';
	echo '<input type="hidden" id="aafm-playbook-slug" name="slug" value="">';
	aafm_render_set_row(
		array(
			'label'   => __( 'Title', 'agent-abilities-for-mcp' ),
			'control' => sprintf( '<input type="text" id="aafm-playbook-title" name="title" class="regular-text" aria-label="%s">', esc_attr__( 'Playbook title', 'agent-abilities-for-mcp' ) ),
		)
	);
	aafm_render_set_row(
		array(
			'label'   => __( 'Description', 'agent-abilities-for-mcp' ),
			'opt'     => __( 'Optional', 'agent-abilities-for-mcp' ),
			'control' => sprintf( '<input type="text" id="aafm-playbook-description" name="description" class="large-text" aria-label="%s">', esc_attr__( 'Playbook description: one line agents see in the list', 'agent-abilities-for-mcp' ) ),
		)
	);
	aafm_render_set_row(
		array(
			'label'   => __( 'Steps', 'agent-abilities-for-mcp' ),
			'control' => sprintf( '<textarea id="aafm-playbook-body" name="body" rows="14" class="large-text" aria-label="%s"></textarea>', esc_attr__( 'Playbook steps', 'agent-abilities-for-mcp' ) ),
			'help'    => sprintf(
				/* translators: 1: longest title, 2: longest description, 3: longest body, in characters. */
				__( 'Plain text. Title up to %1$d characters, description up to %2$d, steps up to %3$s. Name abilities as aafm/get-post or aafm-get-post and agents are told which ones they cannot use. Keep passwords and private details out of it: agents read it word for word. Site administrators can change it; agents cannot.', 'agent-abilities-for-mcp' ),
				AAFM_PLAYBOOK_TITLE_MAX,
				AAFM_PLAYBOOK_DESC_MAX,
				number_format_i18n( AAFM_PLAYBOOK_BODY_MAX )
			),
		)
	);
	aafm_render_set_row(
		array(
			'label'   => __( 'Active', 'agent-abilities-for-mcp' ),
			'control' => '<label class="aafm-switch"><input type="checkbox" id="aafm-playbook-active" name="active" value="1" aria-label="' . esc_attr__( 'Active: agents can list and load this playbook', 'agent-abilities-for-mcp' ) . '"><span class="aafm-switch-track"></span></label>',
			'help'    => __( 'Only Active playbooks reach agents.', 'agent-abilities-for-mcp' ),
		)
	);
	echo '<div class="aafm-savebar"><button type="submit" class="aafm-btn aafm-btn-primary">' . esc_html__( 'Save playbook', 'agent-abilities-for-mcp' ) . '</button> <button type="button" class="aafm-btn aafm-btn-ghost aafm-playbook-new">' . esc_html__( 'New playbook', 'agent-abilities-for-mcp' ) . '</button> <span class="aafm-save-status aafm-playbook-status" role="status" aria-live="polite"></span></div>';
	echo '</form>';
	aafm_render_section(
		array(
			'id'    => 'aafm-playbook-editor',
			'icon'  => 'terminal',
			'title' => __( 'Write a playbook', 'agent-abilities-for-mcp' ),
			'body'  => (string) ob_get_clean(),
		)
	);

	// Card 3: starters.
	ob_start();
	$taken = array_column( $playbooks, 'slug' );
	echo '<ul class="aafm-playbook-starters">';
	foreach ( aafm_playbook_starters() as $id => $starter ) {
		$needs_wc = in_array( 'woocommerce', $starter['requires'], true ) && ! aafm_woocommerce_active();
		$added    = in_array( $id, $taken, true );
		printf(
			'<li><div class="aafm-playbook-starter-text"><strong>%1$s</strong>%2$s<span>%3$s</span></div><button type="button" class="aafm-btn aafm-btn-secondary aafm-btn-sm aafm-playbook-starter-add" data-starter="%4$s"%5$s>%6$s</button></li>',
			esc_html( $starter['title'] ),
			$needs_wc ? ' <span class="aafm-pill aafm-pill-neutral">' . esc_html__( 'Needs WooCommerce', 'agent-abilities-for-mcp' ) . '</span>' : '',
			esc_html( $starter['description'] ),
			esc_attr( (string) $id ),
			$added ? ' disabled title="' . esc_attr__( 'Already in your playbooks.', 'agent-abilities-for-mcp' ) . '"' : '',
			esc_html__( 'Add to my playbooks', 'agent-abilities-for-mcp' )
		);
	}
	echo '</ul>';
	aafm_render_section(
		array(
			'id'          => 'aafm-playbook-starters',
			'icon'        => 'star',
			'title'       => __( 'Starter playbooks', 'agent-abilities-for-mcp' ),
			'description' => __( 'Ready-made playbooks to start from. An added starter arrives switched Off, so you can read and edit it before agents see it.', 'agent-abilities-for-mcp' ),
			'body'        => (string) ob_get_clean(),
		)
	);

	echo '</div>';
}

/**
 * Check the nonce and capability for a Playbooks AJAX action, ending the request when either fails.
 *
 * @return void
 */
function aafm_playbook_ajax_guard(): void {
	check_ajax_referer( 'aafm_admin', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'agent-abilities-for-mcp' ) ), 403 );
	}
}

/**
 * Send a saved playbook back to the tab, or the refusal.
 *
 * @param array<string,mixed>|WP_Error $result Stored entry or error.
 * @return void
 */
function aafm_playbook_ajax_send( $result ): void {
	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ) );
	}
	$counts = aafm_playbook_admin_counts( $result );
	wp_send_json_success(
		array(
			'playbook'          => $result,
			'uses_count'        => $counts['uses'],
			'unavailable_count' => $counts['unavailable'],
		)
	);
}

/**
 * AJAX: create or update a playbook.
 *
 * @return void
 */
function aafm_ajax_save_playbook(): void {
	aafm_playbook_ajax_guard();
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified in aafm_playbook_ajax_guard().
	$post = wp_unslash( $_POST );
	// phpcs:enable WordPress.Security.NonceVerification.Missing
	$slug = isset( $post['slug'] ) && is_string( $post['slug'] ) ? sanitize_title( $post['slug'] ) : '';
	aafm_playbook_ajax_send(
		aafm_save_playbook(
			array(
				'title'       => $post['title'] ?? '',
				'description' => $post['description'] ?? '',
				'body'        => $post['body'] ?? '',
				'active'      => isset( $post['active'] ) && '1' === $post['active'],
			),
			$slug
		)
	);
}

/**
 * AJAX: delete a playbook.
 *
 * @return void
 */
function aafm_ajax_delete_playbook(): void {
	aafm_playbook_ajax_guard();
	$slug   = isset( $_POST['slug'] ) ? sanitize_title( wp_unslash( (string) $_POST['slug'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in aafm_playbook_ajax_guard().
	$result = aafm_delete_playbook( $slug );
	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ) );
	}
	wp_send_json_success( array( 'slug' => $slug ) );
}

/**
 * AJAX: add a starter playbook.
 *
 * @return void
 */
function aafm_ajax_add_starter_playbook(): void {
	aafm_playbook_ajax_guard();
	$starter = isset( $_POST['starter'] ) ? sanitize_key( wp_unslash( (string) $_POST['starter'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in aafm_playbook_ajax_guard().
	aafm_playbook_ajax_send( aafm_add_starter_playbook( $starter ) );
}
