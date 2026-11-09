<?php
/**
 * The Changes tab: what agents changed in the last 30 days, and the owner's Revert.
 *
 * The tab shows field names, never values. Every action here is an owner action: a nonce, manage_options
 * and the per-object capability that aafm_undo_apply() checks at click time.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Rows the Changes tab shows per page. A replace-sitewide group counts as one.
 */
const AAFM_CHANGES_PER_PAGE = 20;

/**
 * The words for a change's status, as a pill variant and a label.
 *
 * @param array<string,mixed> $state aafm_undo_row_state() answer.
 * @param string              $status The row's stored status.
 * @return array{variant: string, label: string}
 */
function aafm_changes_status_pill( array $state, string $status ): array {
	if ( ! empty( $state['undoable'] ) ) {
		return array(
			'variant' => 'success',
			'label'   => __( 'Can revert', 'agent-abilities-for-mcp' ),
		);
	}
	if ( 'reverted' === $status ) {
		return array(
			'variant' => 'neutral',
			'label'   => __( 'Reverted', 'agent-abilities-for-mcp' ),
		);
	}
	$reason = (string) ( $state['reason'] ?? '' );
	if ( 'expired' === $reason ) {
		return array(
			'variant' => 'neutral',
			'label'   => __( 'Expired', 'agent-abilities-for-mcp' ),
		);
	}
	return array(
		'variant' => 'warn',
		'label'   => __( 'Cannot revert', 'agent-abilities-for-mcp' ),
	);
}

/**
 * The Item cell: a link to the edit screen by id, never a title.
 *
 * @param array<string,mixed> $row A change row.
 * @return string HTML, escaped.
 */
function aafm_changes_item_html( array $row ): string {
	$id    = (int) $row['object_id'];
	$label = sprintf(
		/* translators: 1: "Post" or "Term", 2: the numeric id. */
		__( '%1$s %2$d', 'agent-abilities-for-mcp' ),
		'term' === $row['object_type'] ? __( 'Term', 'agent-abilities-for-mcp' ) : __( 'Post', 'agent-abilities-for-mcp' ),
		$id
	);
	if ( 'term' === $row['object_type'] ) {
		$perm = (array) json_decode( (string) ( $row['perm_input'] ?? '{}' ), true );
		$link = add_query_arg(
			array(
				'taxonomy' => sanitize_key( (string) ( $perm['taxonomy'] ?? '' ) ),
				'tag_ID'   => $id,
			),
			admin_url( 'term.php' )
		);
	} else {
		$link = (string) get_edit_post_link( $id, 'raw' );
	}
	return '' === $link ? esc_html( $label ) : sprintf( '<a href="%s">%s</a>', esc_url( $link ), esc_html( $label ) );
}

/**
 * Whether a post change has revisions to compare, for the Compare revisions link.
 *
 * @param array<string,mixed> $row A change row.
 * @return string URL or ''.
 */
function aafm_changes_compare_url( array $row ): string {
	if ( 'post' !== $row['object_type'] || ! in_array( 'post_content', aafm_undo_field_names( $row ), true ) ) {
		return '';
	}
	$post = aafm_exact_object( 'post', (int) $row['object_id'] );
	if ( ! $post instanceof WP_Post || ! post_type_supports( $post->post_type, 'revisions' ) ) {
		return '';
	}
	$revisions = wp_get_post_revisions( $post->ID, array( 'numberposts' => 1 ) );
	return array() === $revisions ? '' : admin_url( 'revision.php?revision=' . (int) array_key_first( $revisions ) );
}

/**
 * One table row for one change.
 *
 * @param array<string,mixed> $row A change row.
 * @return string HTML, escaped.
 */
function aafm_changes_row_html( array $row ): string {
	$state  = aafm_undo_row_state( $row, 'owner' );
	$pill   = aafm_changes_status_pill( $state, (string) $row['status'] );
	$when   = strtotime( (string) $row['created_at'] . ' UTC' );
	$user   = (int) $row['principal_user_id'] > 0 ? aafm_exact_object( 'user', (int) $row['principal_user_id'] ) : false;
	$by     = $user instanceof WP_User ? (string) $user->display_name : __( 'Deleted user', 'agent-abilities-for-mcp' );
	$who    = 'owner' === $row['source'] ? __( 'you', 'agent-abilities-for-mcp' ) : __( 'agent', 'agent-abilities-for-mcp' );
	$tool   = (string) preg_replace( '#^aafm/#', '', (string) $row['ability'] );
	$fields = implode( ', ', aafm_undo_field_names( $row ) );
	$reason = empty( $state['undoable'] ) && ! empty( $state['reason'] ) ? aafm_undo_reason_message( (string) $state['reason'] ) : '';

	$action = '';
	if ( ! empty( $state['undoable'] ) ) {
		$action .= sprintf(
			'<button type="button" class="aafm-btn aafm-btn-secondary aafm-btn-sm" data-undo-revert="%1$d" data-undo-fields="%2$s">%3$s</button>',
			(int) $row['id'],
			esc_attr( $fields ),
			esc_html__( 'Revert', 'agent-abilities-for-mcp' )
		);
	}
	$compare = aafm_changes_compare_url( $row );
	if ( '' !== $compare ) {
		$action .= sprintf( ' <a href="%s">%s</a>', esc_url( $compare ), esc_html__( 'Compare revisions', 'agent-abilities-for-mcp' ) );
	}

	return sprintf(
		'<tr data-change-id="%1$d"><td title="%2$s">%3$s</td><td title="%4$s">%4$s</td><td>%5$s</td><td title="%6$s">%6$s</td><td title="%7$s">%8$s</td><td><span class="aafm-pill aafm-pill-%9$s">%10$s</span>%11$s</td><td class="aafm-changes-action">%12$s<span class="aafm-changes-status" aria-live="polite"></span></td></tr>',
		(int) $row['id'],
		esc_attr( gmdate( 'Y-m-d H:i:s', (int) $when ) . ' UTC' ),
		esc_html( sprintf( /* translators: %s: a time span such as "3 hours". */ __( '%s ago', 'agent-abilities-for-mcp' ), human_time_diff( (int) $when ) ) ),
		esc_html( $tool ),
		aafm_changes_item_html( $row ),
		esc_attr( $fields ),
		esc_attr( $by . ' (' . $who . ')' ),
		esc_html( $by . ' (' . $who . ')' ),
		esc_attr( $pill['variant'] ),
		esc_html( $pill['label'] ),
		'' === $reason ? '' : '<div class="aafm-changes-reason">' . esc_html( $reason ) . '</div>',
		$action
	);
}

/**
 * The group row for the changes one replace-sitewide call made: one line, Revert all, and each post listed.
 *
 * @param array<string,mixed>       $lead    The newest row of the group.
 * @param list<array<string,mixed>> $members Every visible row of the group, newest first.
 * @return string HTML, escaped.
 */
function aafm_changes_group_html( array $lead, array $members ): string {
	$when  = strtotime( (string) $lead['created_at'] . ' UTC' );
	$user  = (int) $lead['principal_user_id'] > 0 ? aafm_exact_object( 'user', (int) $lead['principal_user_id'] ) : false;
	$by    = $user instanceof WP_User ? (string) $user->display_name : __( 'Deleted user', 'agent-abilities-for-mcp' );
	$items = '';
	$any   = false;
	foreach ( $members as $member ) {
		$state  = aafm_undo_row_state( $member, 'owner' );
		$any    = $any || ! empty( $state['undoable'] );
		$pill   = aafm_changes_status_pill( $state, (string) $member['status'] );
		$items .= sprintf( '<li>%1$s <span class="aafm-pill aafm-pill-%2$s">%3$s</span></li>', aafm_changes_item_html( $member ), esc_attr( $pill['variant'] ), esc_html( $pill['label'] ) );
	}
	$action = $any ? sprintf(
		'<button type="button" class="aafm-btn aafm-btn-secondary aafm-btn-sm" data-undo-revert-group="%1$s" data-undo-fields="%2$s">%3$s</button>',
		esc_attr( (string) $lead['group_id'] ),
		esc_attr( __( 'post content, on every post listed', 'agent-abilities-for-mcp' ) ),
		esc_html__( 'Revert all', 'agent-abilities-for-mcp' )
	) : '';

	return sprintf(
		'<tr data-change-group="%1$s"><td title="%2$s">%3$s</td><td title="replace-sitewide">replace-sitewide</td><td colspan="2"><details><summary>%4$s</summary><ul class="aafm-changes-group-list">%5$s</ul></details></td><td title="%6$s">%7$s</td><td></td><td class="aafm-changes-action">%8$s<span class="aafm-changes-status" aria-live="polite"></span></td></tr>',
		esc_attr( (string) $lead['group_id'] ),
		esc_attr( gmdate( 'Y-m-d H:i:s', (int) $when ) . ' UTC' ),
		esc_html( sprintf( /* translators: %s: a time span such as "3 hours". */ __( '%s ago', 'agent-abilities-for-mcp' ), human_time_diff( (int) $when ) ) ),
		esc_html(
			sprintf(
				/* translators: %d: how many posts one find and replace changed. */
				_n( '%d post changed', '%d posts changed', count( $members ), 'agent-abilities-for-mcp' ),
				count( $members )
			)
		),
		$items,
		esc_attr( $by . ' (' . __( 'agent', 'agent-abilities-for-mcp' ) . ')' ),
		esc_html( $by . ' (' . __( 'agent', 'agent-abilities-for-mcp' ) . ')' ),
		$action
	);
}

/**
 * Render the Changes tab.
 *
 * @return void
 */
function aafm_render_changes_tab(): void {
	aafm_undo_age_batch();

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only paging from the URL; nothing is written.
	$page    = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
	$listing = aafm_undo_list_for_tab( $page, AAFM_CHANGES_PER_PAGE );

	$body = '';
	if ( ! $listing['ok'] ) {
		$body = aafm_get_notice_html( 'error', __( 'The change history could not be read. Try again in a moment.', 'agent-abilities-for-mcp' ) );
	} elseif ( array() === $listing['rows'] ) {
		$body = '<div class="aafm-empty-state">' . esc_html__( 'No changes recorded yet. When an agent edits a post, a term or a custom field through one of the tools listed above, the change shows up here.', 'agent-abilities-for-mcp' ) . '</div>';
	} else {
		$groups = array();
		foreach ( $listing['rows'] as $row ) {
			if ( 'aafm/replace-sitewide' === $row['ability'] && '' !== (string) $row['group_id'] ) {
				$groups[ (string) $row['group_id'] ][] = $row;
			}
		}
		$rows_html = '';
		$done      = array();
		foreach ( $listing['rows'] as $row ) {
			$key = (string) $row['group_id'];
			if ( 'aafm/replace-sitewide' === $row['ability'] && '' !== $key ) {
				if ( ! isset( $done[ $key ] ) ) {
					$done[ $key ] = true;
					$rows_html   .= aafm_changes_group_html( $groups[ $key ][0], $groups[ $key ] );
				}
				continue;
			}
			$rows_html .= aafm_changes_row_html( $row );
		}
		$headers = array(
			__( 'When', 'agent-abilities-for-mcp' ),
			__( 'Tool', 'agent-abilities-for-mcp' ),
			__( 'Item', 'agent-abilities-for-mcp' ),
			__( 'Fields', 'agent-abilities-for-mcp' ),
			__( 'By', 'agent-abilities-for-mcp' ),
			__( 'Status', 'agent-abilities-for-mcp' ),
			__( 'Action', 'agent-abilities-for-mcp' ),
		);
		$head    = '';
		foreach ( $headers as $label ) {
			$head .= '<th scope="col">' . esc_html( $label ) . '</th>';
		}
		$pages = max( 1, (int) ceil( $listing['total'] / AAFM_CHANGES_PER_PAGE ) );
		$base  = admin_url( 'admin.php?page=agent-abilities-for-mcp&tab=changes' );
		$prev  = $page > 1 ? sprintf( '<a class="aafm-btn aafm-btn-secondary" href="%s">%s</a>', esc_url( add_query_arg( 'paged', $page - 1, $base ) ), esc_html__( 'Previous', 'agent-abilities-for-mcp' ) ) : '<button type="button" class="aafm-btn aafm-btn-secondary" disabled>' . esc_html__( 'Previous', 'agent-abilities-for-mcp' ) . '</button>';
		$next  = $page < $pages ? sprintf( '<a class="aafm-btn aafm-btn-secondary" href="%s">%s</a>', esc_url( add_query_arg( 'paged', $page + 1, $base ) ), esc_html__( 'Next', 'agent-abilities-for-mcp' ) ) : '<button type="button" class="aafm-btn aafm-btn-secondary" disabled>' . esc_html__( 'Next', 'agent-abilities-for-mcp' ) . '</button>';
		$body  = sprintf(
			'<div class="aafm-table-wrap"><table class="widefat striped aafm-changes-table"><thead><tr>%1$s</tr></thead><tbody>%2$s</tbody></table></div><div class="aafm-pager">%3$s<span class="aafm-pager-status" aria-live="polite">%4$s</span>%5$s</div>',
			$head,
			$rows_html,
			$prev,
			esc_html(
				sprintf(
					/* translators: 1: current page number, 2: total number of pages. */
					__( 'Page %1$s of %2$s', 'agent-abilities-for-mcp' ),
					number_format_i18n( $page ),
					number_format_i18n( $pages )
				)
			),
			$next
		);
	}

	$body .= sprintf(
		'<p class="aafm-changes-clear"><button type="button" class="aafm-btn aafm-btn-secondary" id="aafm-changes-clear">%s</button><span class="aafm-changes-status" id="aafm-changes-clear-status" aria-live="polite"></span></p>',
		esc_html__( 'Clear history', 'agent-abilities-for-mcp' )
	);

	echo '<div class="aafm-changes">';
	aafm_render_section(
		array(
			'id'          => 'aafm-changes-card',
			'title'       => __( 'Changes', 'agent-abilities-for-mcp' ),
			'icon'        => 'changes',
			'description' => __( 'Changes agents made with the post, page, term, custom field and SEO tools are kept for 30 days while the plugin is active. Revert puts a change back if the item has not been edited since. Field names are shown, never their contents. Notifications, webhooks and anything already read are not recalled.', 'agent-abilities-for-mcp' ),
			'body'        => $body,
		)
	);
	echo '</div>';
}

/**
 * The common start of every Changes AJAX action: nonce, then capability, then an opened log row.
 *
 * Answers the request itself on a refusal. A bad nonce or a missing capability is logged inside the
 * per-IP denial cap and answered 403; a log row that cannot be opened refuses with not_logged (409)
 * and nothing is written.
 *
 * @param string $ability The ability label of the log row.
 * @return int The opened activity row id.
 */
function aafm_undo_ajax_begin( string $ability ): int {
	$nonce_ok = false !== check_ajax_referer( 'aafm_admin', 'nonce', false );
	if ( ! $nonce_ok || ! current_user_can( 'manage_options' ) ) {
		if ( aafm_denial_log_within_cap( 'ud' ) ) {
			aafm_log_activity(
				array_merge(
					aafm_undo_log_principal(),
					array(
						'ability'    => $ability,
						'status'     => $nonce_ok ? 'denied' : 'error',
						'event_type' => 'undo_action',
						'detail'     => $nonce_ok ? 'Refused: not allowed' : 'Refused: bad or missing nonce',
					)
				)
			);
		}
		wp_send_json_error(
			array( 'message' => $nonce_ok ? __( 'You are not allowed to do this.', 'agent-abilities-for-mcp' ) : __( 'Your session expired. Reload the page and try again.', 'agent-abilities-for-mcp' ) ),
			403
		);
	}

	aafm_undo_age_batch();
	$row_id = aafm_log_activity(
		array_merge(
			aafm_undo_log_principal(),
			array(
				'ability'    => $ability,
				'status'     => 'started',
				'event_type' => 'undo_action',
			)
		)
	);
	if ( $row_id < 1 ) {
		wp_send_json_error( array( 'message' => aafm_undo_reason_message( 'not_logged' ) ), 409 );
	}
	return $row_id;
}

/**
 * Close the log row of a Changes AJAX action and answer.
 *
 * @param int                 $row_id  The opened activity row.
 * @param string              $status  success, error or denied.
 * @param string              $detail  Ids and reason codes only.
 * @param array<string,mixed> $payload The answer body.
 * @param bool                $ok      Whether to answer success or error.
 * @return never
 */
function aafm_undo_ajax_finish( int $row_id, string $status, string $detail, array $payload, bool $ok ): void {
	$closed = aafm_update_activity_status( $row_id, $status, null, $detail );
	if ( ! $closed ) {
		// The change itself already happened, so the answer keeps the real outcome and adds the note.
		$payload['note'] = __( 'The activity log row for this action could not be finished.', 'agent-abilities-for-mcp' );
	}
	if ( $ok ) {
		wp_send_json_success( $payload );
	}
	wp_send_json_error( $payload, 'denied' === $status ? 403 : 409 );
}

/**
 * AJAX: revert one change.
 *
 * @return void
 */
function aafm_ajax_undo_revert(): void {
	$row_id = aafm_undo_ajax_begin( 'aafm/owner-revert' );
	// The nonce was checked just above; this only reads the id.
	$id     = isset( $_POST['change_id'] ) ? absint( wp_unslash( $_POST['change_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	$result = aafm_undo_apply( $id, 'owner' );

	if ( is_wp_error( $result ) ) {
		$code   = str_replace( 'aafm_undo_', '', (string) $result->get_error_code() );
		$denied = 'not_allowed' === $code;
		/* translators: 1: change id, 2: reason code. */
		$detail = sprintf( __( 'Revert refused for change %1$d: %2$s', 'agent-abilities-for-mcp' ), $id, $code );
		aafm_undo_ajax_finish( $row_id, $denied ? 'denied' : 'error', $detail, array( 'message' => $result->get_error_message() ), false );
	}

	$target = aafm_undo_get_view( $id )['row'];
	$detail = sprintf(
		/* translators: 1: outcome code, 2: change id, 3: object id. */
		__( '%1$s: change %2$d on object %3$d', 'agent-abilities-for-mcp' ),
		(string) $result['outcome'],
		$id,
		(int) ( $target['object_id'] ?? 0 )
	);
	$payload = array(
		'outcome'       => $result['outcome'],
		'new_change_id' => $result['new_change_id'],
	);
	if ( isset( $result['differences'] ) ) {
		$payload['differences'] = $result['differences'];
	}
	aafm_undo_ajax_finish( $row_id, 'success', $detail, $payload, true );
}

/**
 * AJAX: revert every change one replace-sitewide call made, newest first.
 *
 * @return void
 */
function aafm_ajax_undo_revert_group(): void {
	$row_id = aafm_undo_ajax_begin( 'aafm/owner-revert-group' );
	// The nonce was checked just above; this only reads the group id.
	$group   = isset( $_POST['group_id'] ) ? sanitize_text_field( wp_unslash( $_POST['group_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	$members = 1 === preg_match( '/^[A-Za-z0-9]{32}$/', $group ) ? aafm_undo_group_members( $group ) : array(
		'ok'   => false,
		'rows' => array(),
	);
	$bad     = ! $members['ok'] || array() === $members['rows'];
	foreach ( $members['rows'] as $member ) {
		$bad = $bad || 'aafm/replace-sitewide' !== $member['ability'];
	}
	if ( $bad ) {
		aafm_undo_ajax_finish( $row_id, 'error', 'Revert all refused: not a replace-sitewide group', array( 'message' => __( 'That group of changes cannot be reverted together.', 'agent-abilities-for-mcp' ) ), false );
	}

	$results = array();
	$landed  = array();
	foreach ( $members['rows'] as $member ) {
		$id     = (int) $member['id'];
		$result = aafm_undo_apply( $id, 'owner' );
		if ( is_wp_error( $result ) ) {
			$results[] = array(
				'change_id' => $id,
				'outcome'   => 'refused',
				'reason'    => str_replace( 'aafm_undo_', '', (string) $result->get_error_code() ),
			);
			continue;
		}
		$landed[]  = $id;
		$results[] = array(
			'change_id' => $id,
			'outcome'   => $result['outcome'],
		);
	}
	$detail = sprintf(
		/* translators: 1: how many changes were reverted, 2: how many were in the group, 3: comma-separated change ids. */
		__( 'Reverted %1$d of %2$d changes in a group: %3$s', 'agent-abilities-for-mcp' ),
		count( $landed ),
		count( $results ),
		implode( ',', array_map( static fn( array $r ): int => (int) $r['change_id'], $results ) )
	);
	aafm_undo_ajax_finish( $row_id, array() === $landed ? 'error' : 'success', $detail, array( 'results' => $results ), true );
}

/**
 * AJAX: empty the change history.
 *
 * @return void
 */
function aafm_ajax_undo_clear_history(): void {
	$row_id = aafm_undo_ajax_begin( 'aafm/owner-clear-history' );
	if ( ! aafm_undo_clear() ) {
		aafm_undo_ajax_finish( $row_id, 'error', 'Clear history failed', array( 'message' => __( 'The change history could not be cleared. Try again in a moment.', 'agent-abilities-for-mcp' ) ), false );
	}
	aafm_undo_ajax_finish( $row_id, 'success', 'Change history cleared', array( 'cleared' => true ), true );
}

/**
 * The upgrade notice: history records from this update. Shown on this plugin's own admin pages, while
 * capture is on and the notice has not been dismissed.
 *
 * @return void
 */
function aafm_render_undo_notice(): void {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! current_user_can( 'manage_options' ) || null === $screen || false === strpos( (string) $screen->id, 'agent-abilities-for-mcp' ) ) {
		return;
	}
	if ( aafm_undo_capture_off() || get_option( 'aafm_undo_notice_dismissed', false ) ) {
		return;
	}
	echo '<div class="aafm-undo-notice-wrap" id="aafm-undo-notice">';
	aafm_render_notice(
		'info',
		sprintf(
			/* translators: %s: link to the Settings tab, wrapped around the words "turn it off". */
			__( 'Change history is now on. From this update, the changes agents make with the post, term, custom field and SEO tools are kept for 30 days so you can revert them from the Changes tab. You can %s in Settings.', 'agent-abilities-for-mcp' ),
			'<a href="' . esc_url( admin_url( 'admin.php?page=agent-abilities-for-mcp&tab=settings#aafm-undo-capture' ) ) . '">' . esc_html__( 'turn it off', 'agent-abilities-for-mcp' ) . '</a>'
		) . ' <button type="button" class="aafm-btn aafm-btn-secondary aafm-btn-sm" id="aafm-undo-notice-dismiss">' . esc_html__( 'Dismiss', 'agent-abilities-for-mcp' ) . '</button>',
		array( 'html' => true )
	);
	echo '</div>';
}

/**
 * AJAX: dismiss the upgrade notice for good.
 *
 * @return void
 */
function aafm_ajax_undo_dismiss_notice(): void {
	check_ajax_referer( 'aafm_admin', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'agent-abilities-for-mcp' ) ), 403 );
	}
	if ( ! aafm_update_option_verified( 'aafm_undo_notice_dismissed', '1' ) ) {
		wp_send_json_error( array( 'message' => __( 'That could not be saved. Try again.', 'agent-abilities-for-mcp' ) ), 409 );
	}
	wp_send_json_success( array( 'dismissed' => true ) );
}
