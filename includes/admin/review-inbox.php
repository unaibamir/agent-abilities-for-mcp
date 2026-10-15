<?php
/**
 * Needs review: drafts and pending posts an agent wrote, with Preview, Open in editor, Publish and Discard.
 *
 * Pairs with "Force draft on create": the agent writes, the owner approves. Every action checks
 * the owner's capabilities again at click time, re-reads the post's marker and status (the page may
 * be stale), and writes one activity-log row whether it succeeds, fails or is refused.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * How many waiting posts the card lists; a count at this number shows as "20+".
 */
const AAFM_REVIEW_INBOX_LIMIT = 20;

/**
 * Ids of agent-written posts still waiting for the owner, most recently changed first.
 *
 * @param int $limit Maximum rows.
 * @return list<int>
 */
function aafm_review_inbox_ids( int $limit = AAFM_REVIEW_INBOX_LIMIT ): array {
	$ids = get_posts(
		array(
			'post_type'      => array_values( get_post_types( array( 'show_ui' => true ), 'names' ) ),
			'post_status'    => array( 'draft', 'pending' ),
			'posts_per_page' => max( 1, $limit ),
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'fields'         => 'ids',
			'no_found_rows'  => true,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- narrows to agent-written posts by our own marker; capped by posts_per_page.
			'meta_key'       => AAFM_ORIGIN_TOOL_META,
			'meta_compare'   => 'EXISTS',
		)
	);

	return array_values( array_map( 'intval', $ids ) );
}

/**
 * Display data for the inbox.
 *
 * Each row's post loads through aafm_exact_object_chain() and its capabilities through
 * aafm_user_can_checked(), so a failed metadata load hides the button instead of granting it.
 *
 * @param int $limit Maximum rows.
 * @return list<array<string,mixed>>
 */
function aafm_review_inbox_rows( int $limit = AAFM_REVIEW_INBOX_LIMIT ): array {
	$rows = array();
	foreach ( aafm_review_inbox_ids( $limit ) as $post_id ) {
		$post = aafm_exact_object_chain( 'post', $post_id );
		if ( ! $post instanceof WP_Post ) {
			continue;
		}
		$type      = get_post_type_object( $post->post_type );
		$user      = aafm_exact_object( 'user', (int) aafm_meta_get( 'post', $post_id, AAFM_ORIGIN_USER_META, true ) );
		$client_id = (string) aafm_meta_get( 'post', $post_id, AAFM_ORIGIN_CLIENT_META, true );
		$client    = '' !== $client_id ? aafm_oauth_get_client( $client_id ) : null;
		$viewable  = null !== $type && is_post_type_viewable( $type );

		$rows[] = array(
			'id'          => $post_id,
			'title'       => '' !== trim( $post->post_title ) ? $post->post_title : __( '(no title)', 'agent-abilities-for-mcp' ),
			'type'        => null !== $type ? (string) $type->labels->singular_name : $post->post_type,
			'status'      => $post->post_status,
			'agent'       => $user instanceof WP_User ? $user->user_login : __( 'unknown user', 'agent-abilities-for-mcp' ),
			'client'      => is_array( $client ) ? $client['client_name'] : '',
			'tool'        => (string) aafm_meta_get( 'post', $post_id, AAFM_ORIGIN_TOOL_META, true ),
			'when'        => (string) mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $post->post_modified ),
			'preview'     => $viewable ? (string) get_preview_post_link( $post ) : '',
			'edit'        => (string) get_edit_post_link( $post_id, 'raw' ),
			'can_publish' => aafm_user_can_checked( 'edit_post', $post_id ) && aafm_user_can_checked( 'publish_post', $post_id ),
			'can_discard' => aafm_user_can_checked( 'delete_post', $post_id ) && aafm_trash_is_enabled(),
		);
	}

	return $rows;
}

/**
 * Publish or discard one waiting post after re-checking everything.
 *
 * The post's own capabilities are checked here, per post, at click time: edit_post and publish_post
 * to publish, delete_post to discard. A site-wide manage_options check is not a substitute.
 *
 * @param string $action  'publish' or 'discard'.
 * @param int    $post_id Post id.
 * @return true|WP_Error
 */
function aafm_review_apply( string $action, int $post_id ) {
	// A zero id is gone before any lookup: core's get_post( 0 ) would fall back to the global post.
	$post = $post_id > 0 ? aafm_exact_object_chain( 'post', $post_id ) : null;
	if ( ! $post instanceof WP_Post
		|| '' === (string) aafm_meta_get( 'post', $post_id, AAFM_ORIGIN_TOOL_META, true )
		|| ! in_array( $post->post_status, array( 'draft', 'pending' ), true ) ) {
		return new WP_Error( 'aafm_review_gone', __( 'This item is no longer waiting for review. Reload the page.', 'agent-abilities-for-mcp' ) );
	}

	if ( 'publish' === $action ) {
		$allowed = aafm_user_can_checked( 'edit_post', $post_id ) && aafm_user_can_checked( 'publish_post', $post_id );
	} else {
		$allowed = aafm_user_can_checked( 'delete_post', $post_id );
	}
	if ( ! $allowed ) {
		return new WP_Error( 'aafm_review_forbidden', __( 'You are not allowed to do this.', 'agent-abilities-for-mcp' ) );
	}

	if ( 'publish' === $action ) {
		$updated = wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			),
			true
		);
		$after   = is_wp_error( $updated ) ? null : aafm_exact_object( 'post', $post_id );
		if ( ! $after instanceof WP_Post || ! in_array( $after->post_status, array( 'publish', 'future' ), true ) ) {
			return new WP_Error( 'aafm_review_failed', __( 'WordPress would not publish this item. Open it in the editor to see why.', 'agent-abilities-for-mcp' ) );
		}
		return true;
	}

	if ( ! aafm_trash_is_enabled() ) {
		return new WP_Error( 'aafm_review_no_trash', __( 'The Trash is turned off on this site, so this would delete the item for good. Open it in the editor to remove it yourself.', 'agent-abilities-for-mcp' ) );
	}
	// A pre_trash_post callback can hand back the unchanged post, so success is the status, not the return value.
	wp_trash_post( $post_id );
	$after = aafm_exact_object( 'post', $post_id );
	if ( ! $after instanceof WP_Post || 'trash' !== $after->post_status ) {
		return new WP_Error( 'aafm_review_failed', __( 'WordPress would not move this item to the Trash.', 'agent-abilities-for-mcp' ) );
	}

	return true;
}

/**
 * Write the audit row for a Publish or Discard attempt. Ids and the tool name only, never a title.
 *
 * @param string $action  'publish' or 'discard'.
 * @param int    $post_id Post id.
 * @param string $status  success|error|denied.
 * @param string $tool    The ability that wrote the post, '' when unknown.
 * @return void
 */
function aafm_log_review_action( string $action, int $post_id, string $status, string $tool ): void {
	$user = wp_get_current_user();
	if ( 'success' === $status ) {
		$detail = 'publish' === $action
			/* translators: 1: post id, 2: the ability that wrote the post. */
			? sprintf( __( 'Published post %1$d written by %2$s', 'agent-abilities-for-mcp' ), $post_id, $tool )
			/* translators: 1: post id, 2: the ability that wrote the post. */
			: sprintf( __( 'Discarded post %1$d written by %2$s', 'agent-abilities-for-mcp' ), $post_id, $tool );
	} else {
		$detail = 'publish' === $action
			/* translators: %d: post id. */
			? sprintf( __( 'Publish refused for post %d', 'agent-abilities-for-mcp' ), $post_id )
			/* translators: %d: post id. */
			: sprintf( __( 'Discard refused for post %d', 'agent-abilities-for-mcp' ), $post_id );
	}

	aafm_log_activity(
		array(
			'ability'           => 'aafm/review-' . $action,
			'principal_user_id' => (int) $user->ID,
			'principal_login'   => $user->user_login ? (string) $user->user_login : '',
			'status'            => $status,
			'event_type'        => 'review_action',
			'detail'            => $detail,
		)
	);
}

/**
 * Shared AJAX body for Publish and Discard.
 *
 * @param string $action 'publish' or 'discard'.
 * @return void
 */
function aafm_review_handle( string $action ): void {
	// A bad or missing nonce is refused and still logged, so a forged request leaves a trace.
	$nonce_ok = false !== check_ajax_referer( 'aafm_admin', 'nonce', false );
	$post_id  = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- only an id for the audit row; no action is taken before the nonce check below.
	$tool     = $post_id > 0 ? (string) aafm_meta_get( 'post', $post_id, AAFM_ORIGIN_TOOL_META, true ) : '';

	// The two refusals below are open to any logged-in user, so their rows share one per-IP cap.
	if ( ! $nonce_ok ) {
		if ( aafm_denial_log_within_cap( 'rv' ) ) {
			aafm_log_review_action( $action, $post_id, 'error', $tool );
		}
		wp_send_json_error( array( 'message' => __( 'Your session expired. Reload the page and try again.', 'agent-abilities-for-mcp' ) ), 403 );
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		if ( aafm_denial_log_within_cap( 'rv' ) ) {
			aafm_log_review_action( $action, $post_id, 'denied', $tool );
		}
		wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'agent-abilities-for-mcp' ) ), 403 );
	}

	$result = aafm_review_apply( $action, $post_id );
	if ( is_wp_error( $result ) ) {
		$denied = 'aafm_review_forbidden' === $result->get_error_code();
		aafm_log_review_action( $action, $post_id, $denied ? 'denied' : 'error', $tool );
		wp_send_json_error( array( 'message' => $result->get_error_message() ), $denied ? 403 : 409 );
	}

	aafm_log_review_action( $action, $post_id, 'success', $tool );
	wp_send_json_success(
		array(
			'post_id' => $post_id,
			'action'  => $action,
		)
	);
}

/**
 * AJAX: publish a waiting post.
 *
 * @return void
 */
function aafm_ajax_review_publish(): void {
	aafm_review_handle( 'publish' );
}

/**
 * AJAX: move a waiting post to the Trash.
 *
 * @return void
 */
function aafm_ajax_review_discard(): void {
	aafm_review_handle( 'discard' );
}

/**
 * Render the Needs review card on the Dashboard.
 *
 * @return void
 */
function aafm_render_review_inbox_card(): void {
	$rows = aafm_review_inbox_rows();

	echo '<section class="aafm-card aafm-review-inbox" id="aafm-review-inbox">';
	echo '<div class="aafm-card-head"><span class="icon">';
	echo wp_kses( aafm_icon( 'check' ), aafm_svg_allowed_html() );
	echo '</span><h2>' . esc_html__( 'Needs review', 'agent-abilities-for-mcp' ) . '</h2>';
	if ( array() !== $rows ) {
		printf( '<span class="aafm-pill aafm-pill-warn aafm-review-count">%s</span>', esc_html( count( $rows ) >= AAFM_REVIEW_INBOX_LIMIT ? number_format_i18n( AAFM_REVIEW_INBOX_LIMIT ) . '+' : number_format_i18n( count( $rows ) ) ) );
	}
	echo '</div><div class="aafm-card-pad">';

	if ( array() === $rows ) {
		echo '<p class="description">' . esc_html__( 'Nothing is waiting. Drafts and pending posts an agent writes show up here so you can look them over before they go live.', 'agent-abilities-for-mcp' ) . '</p>';
	} else {
		echo '<ul class="aafm-review-list">';
		foreach ( $rows as $row ) {
			if ( '' !== $row['client'] ) {
				$meta = sprintf(
					/* translators: 1: agent user, 2: OAuth client name, 3: date and time, 4: tool name. */
					__( 'By %1$s through %2$s, %3$s. Tool: %4$s.', 'agent-abilities-for-mcp' ),
					$row['agent'],
					$row['client'],
					$row['when'],
					$row['tool']
				);
			} else {
				$meta = sprintf(
					/* translators: 1: agent user, 2: date and time, 3: tool name. */
					__( 'By %1$s, %2$s. Tool: %3$s.', 'agent-abilities-for-mcp' ),
					$row['agent'],
					$row['when'],
					$row['tool']
				);
			}
			printf(
				'<li class="aafm-review-row" data-post-id="%1$d"><div class="aafm-review-main"><strong class="aafm-review-title">%2$s</strong> <span class="aafm-pill aafm-pill-neutral">%3$s</span> <span class="aafm-pill aafm-pill-info">%4$s</span><div class="aafm-review-meta">%5$s</div><span class="aafm-review-status" aria-live="polite"></span></div><div class="aafm-review-actions">',
				(int) $row['id'],
				esc_html( (string) $row['title'] ),
				esc_html( (string) $row['type'] ),
				esc_html( (string) $row['status'] ),
				esc_html( $meta )
			);
			if ( '' !== $row['preview'] ) {
				printf( '<a class="aafm-btn aafm-btn-secondary aafm-btn-sm" href="%s" target="_blank" rel="noopener noreferrer">%s</a>', esc_url( (string) $row['preview'] ), esc_html__( 'Preview', 'agent-abilities-for-mcp' ) );
			}
			if ( '' !== $row['edit'] ) {
				printf( '<a class="aafm-btn aafm-btn-secondary aafm-btn-sm" href="%s" target="_blank" rel="noopener noreferrer">%s</a>', esc_url( (string) $row['edit'] ), esc_html__( 'Open in editor', 'agent-abilities-for-mcp' ) );
			}
			if ( $row['can_publish'] ) {
				printf( '<button type="button" class="aafm-btn aafm-btn-primary aafm-btn-sm" data-review-action="publish">%s</button>', esc_html__( 'Publish', 'agent-abilities-for-mcp' ) );
			}
			if ( $row['can_discard'] ) {
				printf( '<button type="button" class="aafm-btn aafm-btn-secondary aafm-btn-sm" data-review-action="discard">%s</button>', esc_html__( 'Discard', 'agent-abilities-for-mcp' ) );
			}
			echo '</div></li>';
		}
		echo '</ul>';
	}

	echo '</div></section>';
}
