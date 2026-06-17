<?php
/**
 * User read + write abilities. The reads are the most PII-sensitive in the catalog:
 * enumeration is gated behind list_users (the cap WP itself requires to view the user
 * list) and the output is reduced to a safe whitelist with no login or password hash.
 *
 * The writes (create/update/delete-user) are the most security-sensitive in the whole
 * catalog. create-user forces the site default role server-side — never a caller-chosen
 * role, so an agent can never mint an admin. update-user gates any role change behind
 * promote_users and refuses to demote the sole remaining administrator. delete-user
 * requires a reassign target and refuses to delete the current user or the last admin.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

add_filter( 'aafm_abilities_registry', 'aafm_register_users_definitions' );

/**
 * Contribute the user read definition to the registry.
 *
 * @param array<string,array<string,mixed>> $registry Registry.
 * @return array<string,array<string,mixed>>
 */
function aafm_register_users_definitions( array $registry ): array {
	$registry['aafm/get-users']   = array(
		'label'        => __( 'Get users', 'agent-abilities-for-mcp' ),
		'description'  => __( 'List users: id, display name, email, roles, and post count. Gated by the list-users capability. Never login or password.', 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'users',
		'args_builder' => 'aafm_args_get_users',
	);
	$registry['aafm/get-user']    = array(
		'label'        => __( 'Get user', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Read one user by id: id, display name, email, roles, post count, registration date, and bio. Never login or password.', 'agent-abilities-for-mcp' ),
		'group'        => 'reads',
		'risk'         => 'read',
		'subject'      => 'users',
		'args_builder' => 'aafm_args_get_user',
	);
	$registry['aafm/create-user'] = array(
		'label'        => __( 'Create user', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Create a new user with the site default role (never a caller-chosen role). Requires the create-users capability. Off by default.', 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'destructive',
		'subject'      => 'users',
		'args_builder' => 'aafm_args_create_user',
	);
	$registry['aafm/update-user'] = array(
		'label'        => __( 'Update user', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Edit a user\'s display name, name, or email by id. Changing a role needs the promote-users capability and never demotes the last administrator.', 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'write',
		'subject'      => 'users',
		'args_builder' => 'aafm_args_update_user',
	);
	$registry['aafm/delete-user'] = array(
		'label'        => __( 'Delete user', 'agent-abilities-for-mcp' ),
		'description'  => __( 'Permanently delete a user and reassign their content to another user. Never deletes you or the last administrator. Requires the delete-users capability. Off by default.', 'agent-abilities-for-mcp' ),
		'group'        => 'writes',
		'risk'         => 'destructive',
		'subject'      => 'users',
		'args_builder' => 'aafm_args_delete_user',
	);
	return $registry;
}

/**
 * Args for aafm/get-users.
 *
 * @return array<string,mixed>
 */
function aafm_args_get_users(): array {
	return array(
		'label'               => __( 'Get users', 'agent-abilities-for-mcp' ),
		'description'         => __( 'List users: id, display name, email, roles, and post count. Email is gated by the list-users capability. Never login or password.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'role'     => array(
					'type' => 'string',
				),
				'search'   => array(
					'type' => 'string',
				),
				'per_page' => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 50,
				),
				'page'     => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'users' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'object' ),
				),
			),
		),
		'execute_callback'    => 'aafm_exec_get_users',
		'permission_callback' => 'aafm_perm_list_users',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => true,
				'destructive' => false,
			),
		),
	);
}

/**
 * Args for aafm/get-user.
 *
 * @return array<string,mixed>
 */
function aafm_args_get_user(): array {
	return array(
		'label'               => __( 'Get user', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Read one user by id: id, display name, email, roles, post count, registration date, and bio. Never login or password.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-reads',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'user_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
			'required'             => array( 'user_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'user' => array( 'type' => 'object' ),
			),
		),
		'execute_callback'    => 'aafm_exec_get_user',
		'permission_callback' => 'aafm_perm_list_users',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => true,
				'destructive' => false,
			),
		),
	);
}

/**
 * Execute aafm/get-user.
 *
 * Returns the rich single-user shape (the redacted whitelist plus registration date
 * and bio) for one user by id. Email is exposed by the locked decision; login and the
 * password hash never are. An unknown id degrades to a generic error.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_get_user( array $input ) {
	$id   = isset( $input['user_id'] ) ? absint( $input['user_id'] ) : 0;
	$user = $id ? get_userdata( $id ) : false;
	if ( ! $user instanceof WP_User ) {
		return aafm_generic_error();
	}
	return array( 'user' => aafm_rich_user( $user ) );
}

/**
 * Permission for user enumeration: list_users.
 *
 * This is the capability WordPress itself gates the user-list screen behind. A
 * caller without it (subscriber, author, etc.) is denied, and the denial is
 * audited by the registration wrapper before any user record is read.
 *
 * @return bool
 */
function aafm_perm_list_users(): bool {
	return current_user_can( 'list_users' );
}

/**
 * Execute aafm/get-users.
 *
 * Lists users redacted to id, display name, email, roles, and post count. Login,
 * password hash, registration date, IP, capabilities, and meta are never
 * returned — only the safe whitelist produced by aafm_redact_user().
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>
 */
function aafm_exec_get_users( array $input ): array {
	$paging = aafm_paginate_args( $input, 50 );
	$args   = array(
		'number' => $paging['per_page'],
		'paged'  => $paging['page'],
		'fields' => 'all',
	);

	if ( ! empty( $input['role'] ) ) {
		$args['role'] = sanitize_key( (string) $input['role'] );
	}
	if ( ! empty( $input['search'] ) ) {
		$args['search'] = '*' . sanitize_text_field( (string) $input['search'] ) . '*';
	}

	$users = get_users( $args );

	// Resolve every user's post count in ONE batched query instead of a COUNT(*)
	// per row. count_many_users_posts() uses the same defaults as count_user_posts()
	// (post_type 'post', all statuses), so the numbers match the per-user path.
	$user_ids = array_map(
		static fn( $user ): int => $user instanceof WP_User ? (int) $user->ID : 0,
		$users
	);
	$counts   = count_many_users_posts( array_values( array_filter( $user_ids ) ) );

	$redacted = array();
	foreach ( $users as $user ) {
		if ( ! $user instanceof WP_User ) {
			continue;
		}
		$count      = isset( $counts[ $user->ID ] ) ? (int) $counts[ $user->ID ] : 0;
		$redacted[] = aafm_redact_user( $user, $count );
	}

	return array( 'users' => $redacted );
}

/**
 * Args for aafm/create-user.
 *
 * Closed schema: username + email required; display_name/first_name/last_name/password
 * optional. There is deliberately NO role field — the role is forced to the site default
 * server-side so an agent can never request an elevated role.
 *
 * @return array<string,mixed>
 */
function aafm_args_create_user(): array {
	return array(
		'label'               => __( 'Create user', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Create a new user with the site default role only — never a caller-chosen role. A password is generated if none is given. Requires the create-users capability.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'username'     => array(
					'type'      => 'string',
					'minLength' => 1,
				),
				'email'        => array(
					'type'   => 'string',
					'format' => 'email',
				),
				'display_name' => array(
					'type' => 'string',
				),
				'first_name'   => array(
					'type' => 'string',
				),
				'last_name'    => array(
					'type' => 'string',
				),
				'password'     => array(
					'type' => 'string',
				),
			),
			'required'             => array( 'username', 'email' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'user' => array( 'type' => 'object' ),
			),
		),
		'execute_callback'    => 'aafm_exec_create_user',
		'permission_callback' => 'aafm_perm_create_user',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => true,
			),
		),
	);
}

/**
 * Permission for aafm/create-user: the create_users capability.
 *
 * Object-independent (no per-object branch), so discovery can fall through to this
 * callback with empty input — it is the correct answer at both discovery and execute.
 *
 * @return bool
 */
function aafm_perm_create_user(): bool {
	return current_user_can( 'create_users' );
}

/**
 * Execute aafm/create-user.
 *
 * Creates a user with the SITE DEFAULT role only. The role is read from the
 * default_role option, never from caller input, so an agent can never mint an
 * administrator. A duplicate username or email is refused. A strong password is
 * generated when none is supplied. Uses core wp_insert_user().
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_create_user( array $input ) {
	$username = sanitize_user( (string) ( $input['username'] ?? '' ), true );
	$email    = sanitize_email( (string) ( $input['email'] ?? '' ) );
	if ( '' === $username || ! is_email( $email ) ) {
		return aafm_generic_error();
	}
	if ( username_exists( $username ) || email_exists( $email ) ) {
		return aafm_generic_error();
	}

	$password = isset( $input['password'] ) && '' !== (string) $input['password']
		? (string) $input['password']
		: wp_generate_password( 24, true );

	$userdata = array(
		'user_login'   => $username,
		'user_email'   => $email,
		'user_pass'    => $password,
		// Role is forced to the site default — never caller-chosen, so an agent can't mint an admin.
		'role'         => (string) get_option( 'default_role', 'subscriber' ),
		'display_name' => sanitize_text_field( (string) ( $input['display_name'] ?? $username ) ),
		'first_name'   => sanitize_text_field( (string) ( $input['first_name'] ?? '' ) ),
		'last_name'    => sanitize_text_field( (string) ( $input['last_name'] ?? '' ) ),
	);

	$result = wp_insert_user( $userdata );
	if ( is_wp_error( $result ) ) {
		return aafm_generic_error();
	}

	return array( 'user' => aafm_rich_user( get_userdata( (int) $result ) ) );
}

/**
 * Args for aafm/update-user.
 *
 * Closed schema: user_id required; display_name/first_name/last_name/email/role optional.
 * A role change is honored only for a caller who can promote_users, and never demotes the
 * last administrator (enforced in the execute callback).
 *
 * @return array<string,mixed>
 */
function aafm_args_update_user(): array {
	return array(
		'label'               => __( 'Update user', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Edit a user by id: display name, name, or email. A role change needs the promote-users capability and never demotes the last administrator.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'user_id'      => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'display_name' => array(
					'type' => 'string',
				),
				'first_name'   => array(
					'type' => 'string',
				),
				'last_name'    => array(
					'type' => 'string',
				),
				'email'        => array(
					'type'   => 'string',
					'format' => 'email',
				),
				'role'         => array(
					'type' => 'string',
				),
			),
			'required'             => array( 'user_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'user' => array( 'type' => 'object' ),
			),
		),
		'execute_callback'    => 'aafm_exec_update_user',
		'permission_callback' => 'aafm_perm_update_user',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => false,
			),
		),
	);
}

/**
 * Permission for aafm/update-user: per-object edit_user on the target id.
 *
 * Returns false with empty input (no id), so discovery uses the object-independent
 * edit_users floor in server.php; this per-object check still runs at execute time.
 *
 * @param array<string,mixed> $input Validated input.
 * @return bool
 */
function aafm_perm_update_user( array $input ): bool {
	$id = isset( $input['user_id'] ) ? absint( $input['user_id'] ) : 0;
	return $id > 0 && current_user_can( 'edit_user', $id );
}

/**
 * Execute aafm/update-user.
 *
 * Edits the safe profile fields by id. A role change is honored ONLY when the caller can
 * promote_users (the admin cap WP gates the role dropdown behind) and the target role is
 * a real role. Reviewer note M2: a role change that would demote the SOLE remaining
 * administrator is refused — a lockout is as damaging as deleting the last admin. Uses
 * core wp_update_user().
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_update_user( array $input ) {
	$id     = isset( $input['user_id'] ) ? absint( $input['user_id'] ) : 0;
	$target = $id ? get_userdata( $id ) : false;
	if ( ! $target instanceof WP_User ) {
		return aafm_generic_error();
	}

	$data = array( 'ID' => $id );
	foreach ( array( 'display_name', 'first_name', 'last_name' ) as $field ) {
		if ( isset( $input[ $field ] ) ) {
			$data[ $field ] = sanitize_text_field( (string) $input[ $field ] );
		}
	}
	if ( isset( $input['email'] ) ) {
		$email = sanitize_email( (string) $input['email'] );
		if ( ! is_email( $email ) ) {
			return aafm_generic_error();
		}
		$data['user_email'] = $email;
	}

	if ( isset( $input['role'] ) ) {
		// Role change is admin-only (promote_users) and must target a real role.
		$role = sanitize_key( (string) $input['role'] );
		if ( ! current_user_can( 'promote_users' ) || null === get_role( $role ) ) {
			return aafm_generic_error();
		}
		// M2: never demote the sole remaining administrator (a lockout guard mirroring
		// the delete-user last-admin floor). Only relevant when the new role is NOT admin
		// and the target currently IS an admin.
		if ( 'administrator' !== $role ) {
			if ( in_array( 'administrator', (array) $target->roles, true ) ) {
				$admins = get_users(
					array(
						'role'   => 'administrator',
						'fields' => 'ID',
						'number' => 2,
					)
				);
				if ( count( $admins ) <= 1 ) {
					return aafm_generic_error();
				}
			}
		}
		$data['role'] = $role;
	}

	$result = wp_update_user( $data );
	if ( is_wp_error( $result ) ) {
		return aafm_generic_error();
	}

	return array( 'user' => aafm_rich_user( get_userdata( $id ) ) );
}

/**
 * Args for aafm/delete-user.
 *
 * Closed schema: user_id required; reassign_to optional IN THE SCHEMA so the execute body
 * can refuse a missing target with the orphaned-content message rather than a bare schema
 * rejection. The reassign is mandatory in practice — the execute callback rejects when it
 * is absent.
 *
 * @return array<string,mixed>
 */
function aafm_args_delete_user(): array {
	return array(
		'label'               => __( 'Delete user', 'agent-abilities-for-mcp' ),
		'description'         => __( 'Permanently delete a user and reassign their content to another user (required). Never deletes you or the last administrator. Requires the delete-users capability.', 'agent-abilities-for-mcp' ),
		'category'            => 'aafm-writes',
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'user_id'     => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'reassign_to' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
			'required'             => array( 'user_id' ),
			'additionalProperties' => false,
		),
		'output_schema'       => array(
			'type'       => 'object',
			'properties' => array(
				'deleted' => array( 'type' => 'boolean' ),
			),
		),
		'execute_callback'    => 'aafm_exec_delete_user',
		'permission_callback' => 'aafm_perm_delete_user',
		'meta'                => array(
			'annotations' => array(
				'readonly'    => false,
				'destructive' => true,
			),
		),
	);
}

/**
 * Permission for aafm/delete-user: delete_users AND per-object delete_user on the id.
 *
 * Returns false with empty input (no id), so discovery uses the object-independent
 * delete_users floor in server.php; this per-object check still runs at execute time.
 *
 * @param array<string,mixed> $input Validated input.
 * @return bool
 */
function aafm_perm_delete_user( array $input ): bool {
	$id = isset( $input['user_id'] ) ? absint( $input['user_id'] ) : 0;
	return $id > 0 && current_user_can( 'delete_users' ) && current_user_can( 'delete_user', $id );
}

/**
 * Execute aafm/delete-user.
 *
 * Permanently removes a user via core wp_delete_user(), reassigning their content to
 * another existing user. Three guards: a reassign target is mandatory and must exist and
 * differ from the victim; the current user can never delete themselves; the last
 * administrator can never be deleted (a lockout guard). Uses wp_delete_user() — never raw
 * SQL — which lives in wp-admin/includes/user.php.
 *
 * @param array<string,mixed> $input Validated input.
 * @return array<string,mixed>|WP_Error
 */
function aafm_exec_delete_user( array $input ) {
	$id       = isset( $input['user_id'] ) ? absint( $input['user_id'] ) : 0;
	$reassign = isset( $input['reassign_to'] ) ? absint( $input['reassign_to'] ) : 0;
	$victim   = $id ? get_userdata( $id ) : false;
	if ( ! $victim instanceof WP_User ) {
		return aafm_generic_error();
	}

	// Never delete the current user.
	if ( get_current_user_id() === $id ) {
		return aafm_generic_error();
	}

	// The reassign target is mandatory, must exist, and must not be the victim.
	if ( ! $reassign || $reassign === $id || ! get_userdata( $reassign ) instanceof WP_User ) {
		return aafm_generic_error();
	}

	// Never delete the last administrator.
	if ( in_array( 'administrator', (array) $victim->roles, true ) ) {
		$admins = get_users(
			array(
				'role'   => 'administrator',
				'fields' => 'ID',
				'number' => 2,
			)
		);
		if ( count( $admins ) <= 1 ) {
			return aafm_generic_error();
		}
	}

	require_once ABSPATH . 'wp-admin/includes/user.php';
	$ok = wp_delete_user( $id, $reassign );
	if ( ! $ok ) {
		return aafm_generic_error();
	}

	return array( 'deleted' => true );
}
