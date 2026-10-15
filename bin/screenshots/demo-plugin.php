<?php
/**
 * A stand-in plugin that registers abilities, for the Other plugins screenshot. Disposable site only.
 */
add_action(
	'wp_abilities_api_categories_init',
	static function (): void {
		wp_register_ability_category(
			'demo-forms',
			array(
				'label'       => 'Demo Forms',
				'description' => 'Demo abilities used for screenshots.',
			)
		);
	}
);
add_action(
	'wp_abilities_api_init',
	static function (): void {
		$common = array(
			'category'            => 'demo-forms',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => new stdClass(),
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => static function () {
				return array();
			},
			'permission_callback' => static function (): bool {
				return current_user_can( 'edit_posts' );
			},
		);
		wp_register_ability(
			'demo-forms/list-forms',
			array_merge(
				$common,
				array(
					'label'       => 'List forms',
					'description' => 'List the forms on the site.',
					'meta'        => array( 'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ) ),
				)
			)
		);
		wp_register_ability(
			'demo-forms/get-entries',
			array_merge(
				$common,
				array(
					'label'       => 'Get form entries',
					'description' => 'Read the entries a form has collected.',
					'meta'        => array( 'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ) ),
				)
			)
		);
		wp_register_ability(
			'demo-forms/delete-entry',
			array_merge(
				$common,
				array(
					'label'       => 'Delete a form entry',
					'description' => 'Remove one form entry.',
					'meta'        => array( 'annotations' => array( 'readonly' => false, 'destructive' => true, 'idempotent' => true ) ),
				)
			)
		);
	}
);
