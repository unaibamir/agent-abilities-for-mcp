<?php
/**
 * Dashboard card: "Why is my tool missing?"
 *
 * Lists every ability the owner switched on that agents cannot see right now, with the reason:
 * the tool no longer exists, its host plugin is inactive or too old, read-only mode or the
 * high-risk lock holds it, or the server left it out at registration (the omitted-abilities
 * option). Bridged abilities are not covered; their state already shows on the Other plugins tab.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * The card's data: one row per enabled native ability that agents cannot see, first reason wins.
 *
 * @return array{id:string,title:string,rule:string,rows:list<array{name:string,label:string,reason:string,reason_text:string}>}
 */
function aafm_missing_tools_data(): array {
	$full    = aafm_get_abilities_registry_full();
	$live    = aafm_get_abilities_registry();
	$omitted = get_option( 'aafm_omitted_abilities', array() ); // AAFM_OMITTED_ABILITIES_OPTION; the constant is invisible to PHPStan outside server.php.
	$omitted = is_array( $omitted ) ? $omitted : array();
	$rows    = array();
	foreach ( aafm_get_stored_enabled_abilities_raw() as $name ) {
		$lock = isset( $live[ $name ] ) ? aafm_ability_lock_reason( $name ) : null;
		if ( ! isset( $full[ $name ] ) ) {
			$reason = 'unknown';
			$text   = __( 'This tool no longer exists in this version of the plugin.', 'agent-abilities-for-mcp' );
		} elseif ( ! isset( $live[ $name ] ) ) {
			$reason = 'host_inactive';
			$text   = __( 'Its plugin is not active, or is older than this tool needs.', 'agent-abilities-for-mcp' );
		} elseif ( null !== $lock ) {
			$reason = $lock;
			$text   = aafm_ability_lock_note( $lock );
		} elseif ( isset( $omitted[ $name ] ) ) {
			$reason = 'omitted';
			$text   = ucfirst( aafm_omitted_reason_label( (string) $omitted[ $name ] ) ) . '.';
		} else {
			continue;
		}
		$rows[] = array(
			'name'        => $name,
			'label'       => (string) ( $full[ $name ]['label'] ?? $name ),
			'reason'      => $reason,
			'reason_text' => $text,
		);
	}
	return array(
		'id'    => 'missing-tools',
		'title' => __( 'Why is my tool missing?', 'agent-abilities-for-mcp' ),
		'rule'  => __( 'These tools are switched on, but agents cannot see them right now. Each line says why.', 'agent-abilities-for-mcp' ),
		'rows'  => $rows,
	);
}

/**
 * Print the card. Prints nothing when no enabled tool is missing.
 *
 * @param array{id:string,title:string,rule:string,rows:list<array{name:string,label:string,reason:string,reason_text:string}>} $data From aafm_missing_tools_data().
 * @return void
 */
function aafm_render_missing_tools_card( array $data ): void {
	if ( array() === $data['rows'] ) {
		return;
	}
	$items = '';
	foreach ( $data['rows'] as $row ) {
		$items .= sprintf(
			'<li><strong>%1$s</strong> <code>%2$s</code><br>%3$s</li>',
			esc_html( $row['label'] ),
			esc_html( $row['name'] ),
			esc_html( $row['reason_text'] )
		);
	}
	aafm_render_section(
		array(
			'id'          => 'aafm-' . $data['id'],
			'title'       => $data['title'],
			'icon'        => 'warning',
			'description' => $data['rule'],
			'body'        => '<ul class="aafm-missing-tools-list">' . $items . '</ul>',
		)
	);
}
