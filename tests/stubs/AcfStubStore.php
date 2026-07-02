<?php
/**
 * Process-wide backing store for the ACF host stubs (Wave 4 integration tests).
 *
 * Lives in its own file so the IntegrationStubs trait file holds a single object structure (the
 * trait), satisfying Generic.Files.OneObjectStructurePerFile. Required directly from the test
 * bootstrap, never shipped.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests;

/**
 * Process-wide backing store for the ACF host stubs.
 *
 * ACF's get_field/update_field/get_fields/acf_get_* are global functions defined once per
 * process; this static store holds the field-group structure, the per-object recorded writes,
 * and the seeded "current values" so a write is visible to a following read inside one test.
 * stub_acf() reset()s + seeds it each test, and reset_integration_stubs() clears it.
 */
class AcfStubStore {

	/**
	 * Field groups as configured (each with its own 'fields' list).
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public static array $groups = array();

	/**
	 * Field definitions keyed by field key: key => {key,label,type,...}.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	public static array $field_defs = array();

	/**
	 * The seeded current-object values: field key => value.
	 *
	 * NOTE: seed values are GLOBAL, not per-object - every object selector reads the same seed (only
	 * recorded WRITES are bucketed per selector). Reads are therefore not object-isolated in this
	 * stub; tests that need to prove a write landed under a specific selector assert on value().
	 *
	 * @var array<string,mixed>
	 */
	public static array $seed_values = array();

	/**
	 * Recorded writes, indexed by "selector" then field key: selector => (key => value).
	 *
	 * @var array<string,array<string,mixed>>
	 */
	public static array $written = array();

	/**
	 * When true, record() refuses to store and returns false - modelling an update_field()
	 * failure so the write-failure path is exercisable.
	 *
	 * @var bool
	 */
	public static bool $update_should_fail = false;

	/**
	 * Clear all state.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$groups             = array();
		self::$field_defs         = array();
		self::$seed_values        = array();
		self::$written            = array();
		self::$update_should_fail = false;
	}

	/**
	 * The groups with their 'fields' stripped - the shape acf_get_field_groups() returns.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function groups_without_fields(): array {
		$out = array();
		foreach ( self::$groups as $group ) {
			$copy = $group;
			unset( $copy['fields'] );
			$out[] = $copy;
		}
		return $out;
	}

	/**
	 * The fields belonging to a given group (matched by its 'key').
	 *
	 * @param mixed $group Group array passed by acf_get_fields().
	 * @return array<int,array<string,mixed>>
	 */
	public static function fields_for_group( $group ): array {
		$group_key = is_array( $group ) ? (string) ( $group['key'] ?? '' ) : (string) $group;
		foreach ( self::$groups as $candidate ) {
			if ( (string) ( $candidate['key'] ?? '' ) === $group_key ) {
				return isset( $candidate['fields'] ) && is_array( $candidate['fields'] ) ? $candidate['fields'] : array();
			}
		}
		return array();
	}

	/**
	 * The definition for one field key (or false), the shape acf_get_field() returns.
	 *
	 * @param mixed $key Field key.
	 * @return array<string,mixed>|false
	 */
	public static function field_def( $key ) {
		return self::$field_defs[ (string) $key ] ?? false;
	}

	/**
	 * Normalise an ACF selector (post id, "term_{id}", "user_{id}", '' / false) to a string bucket.
	 *
	 * @param mixed $selector Selector.
	 * @return string
	 */
	private static function bucket( $selector ): string {
		if ( false === $selector || null === $selector || '' === $selector ) {
			return '__current__';
		}
		return (string) $selector;
	}

	/**
	 * Record a single field write under its object selector.
	 *
	 * @param mixed $field_key Field key.
	 * @param mixed $value     Value.
	 * @param mixed $selector  Object selector.
	 * @return bool
	 */
	public static function record( $field_key, $value, $selector ): bool {
		if ( self::$update_should_fail ) {
			return false; // Model an update_field() failure: nothing is stored.
		}
		$bucket = self::bucket( $selector );
		if ( ! isset( self::$written[ $bucket ] ) ) {
			self::$written[ $bucket ] = array();
		}
		self::$written[ $bucket ][ (string) $field_key ] = $value;
		return true;
	}

	/**
	 * Read one field value for an object: a recorded write wins, else the seed.
	 *
	 * @param mixed $field_key Field key.
	 * @param mixed $selector  Object selector.
	 * @return mixed
	 */
	public static function value( $field_key, $selector ) {
		$bucket = self::bucket( $selector );
		$key    = (string) $field_key;
		if ( isset( self::$written[ $bucket ] ) && array_key_exists( $key, self::$written[ $bucket ] ) ) {
			return self::$written[ $bucket ][ $key ];
		}
		return self::$seed_values[ $key ] ?? null;
	}

	/**
	 * Read one field value FORMATTED, the way real ACF returns it when get_field()'s $format arg
	 * is true. For several field types ACF stores one shape and returns another: image/file fields
	 * store an attachment ID but return an array (or URL); date fields store Ymd but return a
	 * display-formatted string. Modelling that divergence here is what lets a test prove the
	 * write-verify must compare the RAW value, not this formatted one.
	 *
	 * @param mixed $field_key Field key.
	 * @param mixed $selector  Object selector.
	 * @return mixed
	 */
	public static function value_formatted( $field_key, $selector ) {
		$raw  = self::value( $field_key, $selector );
		$type = (string) ( self::$field_defs[ (string) $field_key ]['type'] ?? '' );

		switch ( $type ) {
			case 'image':
			case 'file':
				// Stored as an attachment ID; ACF returns the attachment as an array by default.
				if ( is_int( $raw ) || ( is_string( $raw ) && ctype_digit( $raw ) ) ) {
					return array(
						'ID'  => (int) $raw,
						'url' => 'https://example.test/wp-content/uploads/' . (int) $raw . '.png',
					);
				}
				return $raw;
			case 'date_picker':
				// Stored as Ymd; ACF returns a display-formatted string (d/m/Y by default).
				if ( is_string( $raw ) && 1 === preg_match( '/^\d{8}$/', $raw ) ) {
					return substr( $raw, 6, 2 ) . '/' . substr( $raw, 4, 2 ) . '/' . substr( $raw, 0, 4 );
				}
				return $raw;
			default:
				return $raw;
		}
	}

	/**
	 * All hydrated values for an object, keyed by field key (the get_fields() shape): the seed
	 * merged with any recorded writes for that object.
	 *
	 * @param mixed $selector Object selector.
	 * @return array<string,mixed>
	 */
	public static function all_values( $selector ): array {
		$bucket = self::bucket( $selector );
		$values = self::$seed_values;
		if ( isset( self::$written[ $bucket ] ) ) {
			foreach ( self::$written[ $bucket ] as $key => $val ) {
				$values[ $key ] = $val;
			}
		}
		return $values;
	}
}
