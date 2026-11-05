<?php
/**
 * Register throwaway abilities from another plugin, with chosen annotations.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Support;

trait ForeignAbilities {

	/**
	 * Slugs this test registered.
	 *
	 * @var list<string>
	 */
	private array $foreign_slugs = array();

	/**
	 * Register demo abilities in a namespace.
	 *
	 * @param string                            $ns      Ability namespace.
	 * @param array<string,array<string,mixed>> $tools   Tool name after the slash => annotations (array() for none).
	 * @param callable|null                     $execute Execute callback; returns array() when null.
	 * @return list<string> The slugs registered.
	 */
	protected function register_foreign_abilities( string $ns, array $tools, ?callable $execute = null ): array {
		$this->in_action(
			'wp_abilities_api_categories_init',
			static function (): void {
				if ( ! wp_has_ability_category( 'demo-things' ) ) {
					wp_register_ability_category(
						'demo-things',
						array(
							'label'       => 'Demo things',
							'description' => 'Demo fixture category.',
						)
					);
				}
			}
		);

		$execute = $execute ?? static fn() => array();
		$slugs   = array();
		foreach ( $tools as $tool => $annotations ) {
			$slug = $ns . '/' . $tool;
			$this->in_action(
				'wp_abilities_api_init',
				static function () use ( $slug, $annotations, $execute ): void {
					wp_register_ability(
						$slug,
						array(
							'label'               => $slug,
							'description'         => 'Foreign fixture.',
							'category'            => 'demo-things',
							'input_schema'        => array(
								'type'       => 'object',
								'properties' => array(),
							),
							'execute_callback'    => $execute,
							'permission_callback' => '__return_true',
							'meta'                => array( 'annotations' => $annotations ),
						)
					);
				}
			);
			$slugs[]               = $slug;
			$this->foreign_slugs[] = $slug;
		}
		return $slugs;
	}

	/**
	 * Unregister one fixture ability, leaving the others in place.
	 *
	 * @param string $slug Slug to remove.
	 * @return void
	 */
	protected function unregister_foreign_ability( string $slug ): void {
		if ( wp_has_ability( $slug ) ) {
			wp_unregister_ability( $slug );
		}
	}

	/**
	 * Unregister everything this test registered. Call from tear_down.
	 *
	 * @return void
	 */
	protected function unregister_foreign_abilities(): void {
		foreach ( $this->foreign_slugs as $slug ) {
			$this->unregister_foreign_ability( $slug );
		}
		$this->foreign_slugs = array();
	}
}
