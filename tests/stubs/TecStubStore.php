<?php
/**
 * Process-wide host stubs for The Events Calendar / Event Tickets (1.7.4 integration tests).
 *
 * Required once from the test bootstrap (never per-test), so the classes below are declared a
 * single time for the whole process - no eval()/class_exists() guard needed, unlike the
 * IntegrationStubs trait's per-test stub methods.
 *
 * The fake repository is backed by REAL WordPress post CRUD (wp_insert_post/wp_update_post/
 * wp_delete_post/WP_Query) against real, for-real-registered custom post types (capability_type
 * and map_meta_cap copied verbatim from the installed plugin's own source), so a test exercises
 * this plugin's real current_user_can()/map_meta_cap() behavior and real post persistence, not a
 * second, hand-rolled permission model that could silently drift from the real one.
 *
 * Uses the bracketed namespace syntax deliberately: the plugin's own code calls
 * Tribe__Events__Main / tribe_events() / Tribe__Tickets__Tickets unqualified, which PHP resolves
 * to the GLOBAL namespace (the plugin's includes/ files declare no namespace of their own), so
 * every class and function the plugin actually calls must live in the `namespace { ... }` block
 * below, not under AAFM\Tests.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests {

	/**
	 * A minimal fluent repository backed by real WP post CRUD, standing in for
	 * Tribe__Repository/Tribe__Events__Repositories__{Event,Venue,Organizer}.
	 *
	 * Only the methods this plugin's TEC ability files call are implemented: page(), per_page(),
	 * search(), get_ids(), found(), where('id', $id), set_args(), create(), save(), delete().
	 */
	class AAFM_Tests_Tec_Fake_Repository {

		private string $post_type;

		/** @var array<string,string> Lowercase alias => real post field or meta key. */
		private array $aliases;

		private int $page             = 1;
		private int $per_page         = 10;
		private string $search        = '';
		private int $where_id         = 0;
		/** @var string|string[] */
		private $where_status         = '';
		private int $where_author     = 0;
		private int $found_count      = 0;

		/** @var array<string,mixed> */
		private array $pending_args = array();

		/**
		 * @param string               $post_type Post type this repository queries/creates.
		 * @param array<string,string> $aliases   Lowercase field alias => post field or meta key.
		 */
		public function __construct( string $post_type, array $aliases ) {
			$this->post_type = $post_type;
			$this->aliases   = $aliases;
		}

		public function page( int $page ): self {
			$clone       = clone $this;
			$clone->page = max( 1, $page );
			return $clone;
		}

		public function per_page( int $per_page ): self {
			$clone           = clone $this;
			$clone->per_page = max( 1, $per_page );
			return $clone;
		}

		public function search( string $term ): self {
			$clone         = clone $this;
			$clone->search = $term;
			return $clone;
		}

		/**
		 * @param string $key   'id' (target a single post for save()/delete()), 'post_status'
		 *                      (scope a list query, or 'any' to target any status for save()),
		 *                      or 'author' (mirrors the real Tribe__Repository's default
		 *                      'author' => 'post_author' modifier, used by tec-get-events to
		 *                      contain a draft/pending/future listing to the caller's own posts).
		 * @param mixed  $value The id, status, or author id.
		 */
		public function where( string $key, $value ): self {
			$clone = clone $this;
			if ( 'id' === $key ) {
				$clone->where_id = (int) $value;
			} elseif ( 'post_status' === $key ) {
				$clone->where_status = is_array( $value ) ? array_map( 'strval', $value ) : (string) $value;
			} elseif ( 'author' === $key ) {
				$clone->where_author = (int) $value;
			}
			return $clone;
		}

		/**
		 * @return array<string,mixed>
		 */
		private function query_args(): array {
			$args = array(
				'post_type'      => $this->post_type,
				'post_status'    => '' !== $this->where_status ? $this->where_status : 'any',
				'posts_per_page' => $this->per_page,
				'paged'          => $this->page,
				'fields'         => 'ids',
			);
			if ( '' !== $this->search ) {
				$args['s'] = $this->search;
			}
			if ( 0 !== $this->where_author ) {
				$args['author'] = $this->where_author;
			}
			return $args;
		}

		/**
		 * @return array<int,int>
		 */
		public function get_ids(): array {
			$query             = new \WP_Query( $this->query_args() );
			$this->found_count = (int) $query->found_posts;
			return array_map( 'intval', $query->posts );
		}

		public function found(): int {
			return $this->found_count;
		}

		/**
		 * Translate this ability's lowercase args into a wp_insert_post()-shaped array plus a
		 * meta map, via the alias table.
		 *
		 * @param array<string,mixed> $args Lowercase-keyed args.
		 * @return array{postarr:array<string,mixed>,meta:array<string,mixed>}
		 */
		private function split_args( array $args ): array {
			$postarr = array( 'post_type' => $this->post_type );
			$meta    = array();
			foreach ( $args as $key => $value ) {
				$target = $this->aliases[ $key ] ?? $key;
				if ( str_starts_with( $target, 'post_' ) ) {
					$postarr[ $target ] = $value;
				} else {
					$meta[ $target ] = $value;
				}
			}
			return array(
				'postarr' => $postarr,
				'meta'    => $meta,
			);
		}

		/**
		 * Write a split meta map, special-casing two keys the real repository handles outside a
		 * plain update_post_meta() call:
		 *
		 * - _EventOrganizerID: the real repository's update_organizers() (Repositories/Event.php)
		 *   stores it as one row per id, not one update_post_meta() call with an array value - a
		 *   plain foreach would silently store an unreadable serialized array instead, since
		 *   tribe_get_organizer_ids() reads every row of that key back individually.
		 * - _EventAllDay: the real repository (Repositories/Event.php, save_dates()) unsets this
		 *   key from $postarr['meta_input'] entirely whenever the requested value is falsy,
		 *   rather than writing a falsy value - it never clears an existing 'yes'. Mirroring that
		 *   quirk here (rather than just writing the value like every other key) is what makes
		 *   aafm_exec_tec_update_event()'s own separate delete_post_meta() call for this case
		 *   provably necessary against this stub, not merely redundant.
		 *
		 * @param int                  $id   Post id.
		 * @param array<string,mixed>  $meta Meta key => value map, from split_args().
		 * @return void
		 */
		private function write_meta( int $id, array $meta ): void {
			foreach ( $meta as $key => $value ) {
				if ( '_EventOrganizerID' === $key ) {
					delete_post_meta( $id, $key );
					foreach ( (array) $value as $organizer_id ) {
						add_post_meta( $id, $key, (int) $organizer_id );
					}
					continue;
				}
				if ( '_EventAllDay' === $key && ! $value ) {
					continue;
				}
				update_post_meta( $id, $key, $value );
			}
		}

		public function set_args( array $args ): self {
			$clone               = clone $this;
			$clone->pending_args = $args;
			return $clone;
		}

		/**
		 * @return \WP_Post|false
		 */
		public function create() {
			$split = $this->split_args( $this->pending_args );
			if ( empty( $split['postarr']['post_title'] ) ) {
				return false; // Mirrors the real Venue/Organizer repositories' own minimum-field guard.
			}
			if ( ! isset( $split['postarr']['post_status'] ) ) {
				$split['postarr']['post_status'] = 'publish';
			}
			$id = wp_insert_post( $split['postarr'], true );
			if ( is_wp_error( $id ) ) {
				return false;
			}
			$this->write_meta( $id, $split['meta'] );
			$post = get_post( $id );
			return $post instanceof \WP_Post ? $post : false;
		}

		/**
		 * @param bool $return_promise Ignored - the stub never queues, it always saves synchronously.
		 * @return array<int,mixed>
		 */
		public function save( bool $return_promise = false ) {
			if ( ! $this->where_id ) {
				return array();
			}
			$split   = $this->split_args( $this->pending_args );
			$postarr = $split['postarr'];
			if ( array( 'post_type' => $this->post_type ) !== $postarr ) {
				$postarr['ID'] = $this->where_id;
				$result        = wp_update_post( $postarr, true );
				if ( is_wp_error( $result ) ) {
					return array( $this->where_id => $result );
				}
			}
			$this->write_meta( $this->where_id, $split['meta'] );
			return array( $this->where_id => true );
		}

		/**
		 * @return array<int,int>
		 */
		public function delete(): array {
			if ( ! $this->where_id ) {
				return array();
			}
			$result = wp_delete_post( $this->where_id ); // force_delete=false -> trashes.
			return $result instanceof \WP_Post ? array( $this->where_id ) : array();
		}
	}

	/**
	 * Process-wide backing store for the Event Tickets ticket/attendee stubs.
	 *
	 * Seeded directly by tests - a test double this plugin's own tests fully control, standing in
	 * for Event Tickets' own multi-provider storage.
	 */
	class TecTicketsStubStore {

		/** @var array<int,\Tribe__Tickets__Ticket_Object> */
		public static array $tickets = array();

		/** @var array<int,array<int,array<string,mixed>>> event_id => attendee rows */
		public static array $attendees = array();

		public static function reset(): void {
			self::$tickets   = array();
			self::$attendees = array();
		}
	}

} // namespace AAFM\Tests

namespace {

	/**
	 * Registers the three TEC custom post types with their real capability_type/map_meta_cap
	 * args, copied verbatim from the installed plugin's own source.
	 *
	 * @return void
	 */
	function aafm_tec_stub_register_post_types(): void {
		if ( ! post_type_exists( 'tribe_events' ) ) {
			register_post_type(
				'tribe_events',
				array(
					'public'          => true,
					'capability_type' => array( 'tribe_event', 'tribe_events' ),
					'map_meta_cap'    => true,
					'supports'        => array( 'title', 'editor', 'author' ),
				)
			);
		}
		if ( ! post_type_exists( 'tribe_venue' ) ) {
			register_post_type(
				'tribe_venue',
				array(
					'public'          => false,
					'capability_type' => array( 'tribe_venue', 'tribe_venues' ),
					'map_meta_cap'    => true,
					'supports'        => array( 'title', 'editor' ),
				)
			);
		}
		if ( ! post_type_exists( 'tribe_organizer' ) ) {
			register_post_type(
				'tribe_organizer',
				array(
					'public'          => false,
					'capability_type' => array( 'tribe_organizer', 'tribe_organizers' ),
					'map_meta_cap'    => true,
					'supports'        => array( 'title', 'editor' ),
				)
			);
		}
	}

	/**
	 * Define every Tribe__... class and tribe_...() global function this plugin's TEC code calls, but only when
	 * a test actually opts in (via IntegrationStubs::stub_tec()/stub_event_tickets()) - NOT
	 * unconditionally at bootstrap. If these were defined at file-load time instead, TEC's own
	 * real detection (aafm_tec_active(): class_exists('Tribe__Events__Main') &&
	 * function_exists('tribe_events')) would report "active" for every test in the whole suite,
	 * not just the ones exercising this integration - exactly the class of test-pollution bug
	 * stub_woocommerce()'s own eval()-based, class_exists()-guarded pattern exists to avoid.
	 * Function/class declarations executed inside a function body are exactly as legal and exactly
	 * as idempotent (via the exists() guards) as eval() would be, so no eval() is needed here.
	 *
	 * @return void
	 */
	function aafm_tec_stub_define_globals(): void {
		if ( ! class_exists( 'Tribe__Events__Main' ) ) {
			/**
			 * Stub for Tribe__Events__Main: only the two constants this plugin's code reads.
			 */
			class Tribe__Events__Main {
				const POSTTYPE = 'tribe_events';
				const VERSION  = '6.17.3.1';
			}
		}

		if ( ! class_exists( 'Tribe__Events__Venue' ) ) {
			class Tribe__Events__Venue {
				const POSTTYPE = 'tribe_venue';
			}
		}

		if ( ! class_exists( 'Tribe__Events__Organizer' ) ) {
			class Tribe__Events__Organizer {
				const POSTTYPE = 'tribe_organizer';
			}
		}

		if ( ! class_exists( 'Tribe__Tickets__Main' ) ) {
			class Tribe__Tickets__Main {
				const VERSION = '5.29.3.1';
			}
		}

		if ( ! function_exists( 'tribe_events' ) ) {
			function tribe_events(): \AAFM\Tests\AAFM_Tests_Tec_Fake_Repository {
				return new \AAFM\Tests\AAFM_Tests_Tec_Fake_Repository(
					Tribe__Events__Main::POSTTYPE,
					array(
						'start_date' => '_EventStartDate',
						'end_date'   => '_EventEndDate',
						'all_day'    => '_EventAllDay',
						'venue'      => '_EventVenueID',
						'organizer'  => '_EventOrganizerID',
						'organizers' => '_EventOrganizerID',
					)
				);
			}
		}

		if ( ! function_exists( 'tribe_venues' ) ) {
			function tribe_venues(): \AAFM\Tests\AAFM_Tests_Tec_Fake_Repository {
				return new \AAFM\Tests\AAFM_Tests_Tec_Fake_Repository(
					Tribe__Events__Venue::POSTTYPE,
					array(
						'venue'          => 'post_title',
						'address'        => '_VenueAddress',
						'city'           => '_VenueCity',
						'state_province' => '_VenueStateProvince',
						'zip'            => '_VenueZip',
						'country'        => '_VenueCountry',
						'phone'          => '_VenuePhone',
						'website'        => '_VenueURL',
					)
				);
			}
		}

		if ( ! function_exists( 'tribe_organizers' ) ) {
			function tribe_organizers(): \AAFM\Tests\AAFM_Tests_Tec_Fake_Repository {
				return new \AAFM\Tests\AAFM_Tests_Tec_Fake_Repository(
					Tribe__Events__Organizer::POSTTYPE,
					array(
						'organizer' => 'post_title',
						'email'     => '_OrganizerEmail',
						'phone'     => '_OrganizerPhone',
						'website'   => '_OrganizerWebsite',
					)
				);
			}
		}

		if ( ! function_exists( 'tribe_get_start_date' ) ) {
			function tribe_get_start_date( $event_id, $create_date = true, $format = '' ) {
				return (string) get_post_meta( (int) $event_id, '_EventStartDate', true );
			}
		}

		if ( ! function_exists( 'tribe_get_end_date' ) ) {
			function tribe_get_end_date( $event_id, $create_date = true, $format = '' ) {
				return (string) get_post_meta( (int) $event_id, '_EventEndDate', true );
			}
		}

		if ( ! function_exists( 'tribe_event_is_all_day' ) ) {
			function tribe_event_is_all_day( $event_id ) {
				return ! empty( get_post_meta( (int) $event_id, '_EventAllDay', true ) );
			}
		}

		if ( ! function_exists( 'tribe_get_venue_id' ) ) {
			function tribe_get_venue_id( $event_id = null ) {
				return (int) get_post_meta( (int) $event_id, '_EventVenueID', true );
			}
		}

		if ( ! function_exists( 'tribe_get_organizer_ids' ) ) {
			function tribe_get_organizer_ids( $event_id = null ) {
				$ids = get_post_meta( (int) $event_id, '_EventOrganizerID', false );
				return array_filter( array_map( 'intval', (array) $ids ) );
			}
		}

		if ( ! function_exists( 'tribe_tickets' ) ) {
			function tribe_tickets() {
				return null; // Not consumed by this plugin's code; the stable static Tickets API is used instead.
			}
		}

		if ( ! class_exists( 'Tribe__Tickets__Ticket_Object' ) ) {
			/**
			 * Stub for Tribe__Tickets__Ticket_Object: the public fields and get_event() this
			 * plugin's code reads.
			 */
			class Tribe__Tickets__Ticket_Object {
				public int $ID             = 0;
				public string $name        = '';
				public string $description = '';
				public float $price        = 0.0;
				public int $capacity       = 0;
				public bool $on_sale       = false;
				private int $event_id      = 0;

				public function __construct( int $id, int $event_id, string $name, float $price = 0.0, int $capacity = 0 ) {
					$this->ID       = $id;
					$this->event_id = $event_id;
					$this->name     = $name;
					$this->price    = $price;
					$this->capacity = $capacity;
				}

				public function get_event(): ?WP_Post {
					$post = get_post( $this->event_id );
					return $post instanceof WP_Post ? $post : null;
				}
			}
		}

		if ( ! class_exists( 'Tribe__Tickets__Tickets' ) ) {
			/**
			 * Stub for Tribe__Tickets__Tickets: only the three static aggregation methods this
			 * plugin's code calls, backed by \AAFM\Tests\TecTicketsStubStore.
			 */
			class Tribe__Tickets__Tickets {

				public static function get_all_event_tickets( $post_id, ?string $context = null ): array {
					return array_values(
						array_filter(
							\AAFM\Tests\TecTicketsStubStore::$tickets,
							static function ( Tribe__Tickets__Ticket_Object $t ) use ( $post_id ): bool {
								$event = $t->get_event();
								return $event instanceof WP_Post && (int) $event->ID === (int) $post_id;
							}
						)
					);
				}

				public static function load_ticket_object( $ticket_id ): ?Tribe__Tickets__Ticket_Object {
					return \AAFM\Tests\TecTicketsStubStore::$tickets[ (int) $ticket_id ] ?? null;
				}

				public static function get_event_attendees( $post_id, $args = array() ): array {
					return \AAFM\Tests\TecTicketsStubStore::$attendees[ (int) $post_id ] ?? array();
				}
			}
		}
	}
}
