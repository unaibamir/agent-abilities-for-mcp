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
	 * Field keys whose record() refuses to store (returns false), modelling an update_field()
	 * failure on ONE specific field while its siblings persist. Lets a test prove that a single
	 * non-persisting field does not abort the writes queued after it.
	 *
	 * @var string[]
	 */
	public static array $fail_keys = array();

	/**
	 * When true, container fields (repeater/group/flexible_content) model real ACF Pro storage:
	 * a value written keyed by sub-field NAME is stored (and read back RAW) keyed by sub-field KEY,
	 * while the FORMATTED read re-keys back to names. The default (false) keeps the plain verbatim
	 * KV behaviour every other ACF test relies on. This flag is what lets a test reproduce the
	 * container re-keying that only real ACF Pro performs, which the verbatim stub hid.
	 *
	 * @var bool
	 */
	public static bool $model_container_rekeying = false;

	/**
	 * Clear all state.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$groups                   = array();
		self::$field_defs               = array();
		self::$seed_values              = array();
		self::$written                  = array();
		self::$update_should_fail       = false;
		self::$fail_keys                = array();
		self::$model_container_rekeying = false;
	}

	/**
	 * The container field types (mirrors aafm_acf_container_field_types, including clone - a clone
	 * def carries `sub_fields` and a flat sub-field-map value exactly like a group).
	 *
	 * @var string[]
	 */
	private const CONTAINER_TYPES = array( 'repeater', 'group', 'flexible_content', 'clone' );

	/**
	 * A container definition's sub-field defs at one level: its own `sub_fields`, plus a
	 * flexible-content layout's `sub_fields` selected by the row's layout name (all layouts when
	 * no name is given - a malformed row without its acf_fc_layout marker).
	 *
	 * @param array<string,mixed> $def    Field definition.
	 * @param string              $layout The row's acf_fc_layout layout name, when known.
	 * @return array<int,array<string,mixed>> Sub-field definitions.
	 */
	private static function sub_defs( array $def, string $layout ): array {
		$subs = isset( $def['sub_fields'] ) && is_array( $def['sub_fields'] ) ? $def['sub_fields'] : array();
		if ( isset( $def['layouts'] ) && is_array( $def['layouts'] ) ) {
			foreach ( $def['layouts'] as $candidate ) {
				if ( ! is_array( $candidate ) || ! isset( $candidate['sub_fields'] ) || ! is_array( $candidate['sub_fields'] ) ) {
					continue;
				}
				if ( '' !== $layout && (string) ( $candidate['name'] ?? '' ) !== $layout ) {
					continue;
				}
				$subs = array_merge( $subs, $candidate['sub_fields'] );
			}
		}
		return $subs;
	}

	/**
	 * Rewrite one container value between sub-field NAMES and KEYS, the way real ACF Pro does at
	 * EVERY depth: a nested container (repeater in a flex layout, group in a repeater, ...) re-keys
	 * its own rows through its own embedded definition, because real ACF loads each nested value
	 * through the nested field's load_value. Groups/clones are one flat map; repeaters/flex are a
	 * list of rows, and a flex row's acf_fc_layout marker (kept as-is) selects which layout's
	 * sub_fields apply to that row.
	 *
	 * @param mixed               $value  The container value.
	 * @param array<string,mixed> $def    The container field definition.
	 * @param bool                $to_key True rewrites name=>key (store); false key=>name (format).
	 * @return mixed
	 */
	private static function rekey_container( $value, array $def, bool $to_key ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( in_array( (string) ( $def['type'] ?? '' ), array( 'group', 'clone' ), true ) ) {
			return self::rekey_row( $value, $def, '', $to_key );
		}
		$out = array();
		foreach ( $value as $i => $row ) {
			$layout    = is_array( $row ) && isset( $row['acf_fc_layout'] ) ? (string) $row['acf_fc_layout'] : '';
			$out[ $i ] = is_array( $row ) ? self::rekey_row( $row, $def, $layout, $to_key ) : $row;
		}
		return $out;
	}

	/**
	 * Re-key one row (or flat group/clone map), recursing into nested container sub-fields.
	 *
	 * @param array<int|string,mixed> $row    The row map.
	 * @param array<string,mixed>     $def    The parent container definition.
	 * @param string                  $layout The row's acf_fc_layout name ('' outside flex).
	 * @param bool                    $to_key True rewrites name=>key; false key=>name.
	 * @return array<int|string,mixed>
	 */
	private static function rekey_row( array $row, array $def, string $layout, bool $to_key ): array {
		$by_name = array();
		$by_key  = array();
		foreach ( self::sub_defs( $def, $layout ) as $sub ) {
			if ( is_array( $sub ) && ! empty( $sub['key'] ) && isset( $sub['name'] ) && '' !== (string) $sub['name'] ) {
				$by_name[ (string) $sub['name'] ] = $sub;
				$by_key[ (string) $sub['key'] ]   = $sub;
			}
		}
		$out = array();
		foreach ( $row as $sub_key => $sub_val ) {
			$sub_key = (string) $sub_key;
			if ( 'acf_fc_layout' === $sub_key ) {
				$out[ $sub_key ] = $sub_val;
				continue;
			}
			$sub = $by_name[ $sub_key ] ?? ( $by_key[ $sub_key ] ?? null );
			if ( null === $sub ) {
				$out[ $sub_key ] = $sub_val;
				continue;
			}
			if ( in_array( (string) ( $sub['type'] ?? '' ), self::CONTAINER_TYPES, true ) ) {
				$sub_val = self::rekey_container( $sub_val, $sub, $to_key );
			}
			$out[ $to_key ? (string) $sub['key'] : (string) $sub['name'] ] = $sub_val;
		}
		return $out;
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
	 * Coerce a value to the form real ACF reads it back as, so the stub models storage typing rather
	 * than echoing the written value verbatim.
	 *
	 * ACF writes through update_metadata(), which persists every scalar into a text column: an integer
	 * or float reads back a numeric string, boolean true reads back '1' and false reads back ''
	 * (ACF 6.8.6). Modelling that here is what lets a test prove the write-verify must respect ACF's
	 * stored typing - without it, a numeric/boolean write echoes back its original PHP type and the
	 * JSON-exact bug is invisible (exactly why it shipped). Arrays recurse so structured field values
	 * round-trip leaf by leaf; a same-value no-op therefore still reads back equal.
	 *
	 * @param mixed $value Written value.
	 * @return mixed The value as ACF's metadata storage reads it back.
	 */
	private static function coerce_stored( $value ) {
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $key => $sub ) {
				$out[ $key ] = self::coerce_stored( $sub );
			}
			return $out;
		}
		if ( is_bool( $value ) ) {
			return $value ? '1' : '';
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return (string) $value;
		}
		return $value;
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
		if ( self::$update_should_fail || in_array( (string) $field_key, self::$fail_keys, true ) ) {
			return false; // Model an update_field() failure: nothing is stored.
		}
		// Real ACF persists through update_metadata()/update_option(), both of which wp_unslash()
		// the value before storing (acf-meta-functions.php acf_update_metadata). Model that here so
		// an unslashed backslash loses a level exactly like production - the trap the write path
		// must compensate for by slashing before update_field().
		$value  = wp_unslash( $value );
		$bucket = self::bucket( $selector );
		if ( ! isset( self::$written[ $bucket ] ) ) {
			self::$written[ $bucket ] = array();
		}
		// Store the value AS ACF's metadata storage reads it back (numeric/boolean scalars become
		// strings), not the raw PHP type written, so the verify path sees real ACF typing.
		$stored = self::coerce_stored( $value );

		// Optionally model real ACF Pro's container re-keying: a value written by sub-field NAME is
		// stored (and read back RAW) by sub-field KEY, at every nesting depth. Only when a test
		// opts in via the flag.
		if ( self::$model_container_rekeying ) {
			$def = self::$field_defs[ (string) $field_key ] ?? null;
			if ( is_array( $def ) && in_array( (string) ( $def['type'] ?? '' ), self::CONTAINER_TYPES, true ) ) {
				$stored = self::rekey_container( $stored, $def, true );
			}
		}

		self::$written[ $bucket ][ (string) $field_key ] = $stored;
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

		// Real ACF returns a container FORMATTED value keyed by sub-field NAME, even though the raw
		// value is stored by key. When modelling that, re-key the raw value back to names here.
		if ( self::$model_container_rekeying && in_array( $type, self::CONTAINER_TYPES, true ) ) {
			$def = self::$field_defs[ (string) $field_key ] ?? null;
			if ( is_array( $def ) ) {
				return self::rekey_container( $raw, $def, false );
			}
		}

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
