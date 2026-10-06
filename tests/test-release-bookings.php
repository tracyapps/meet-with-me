<?php
/**
 * Public booking lifecycle, failure safety, and shared-calendar regressions.
 *
 * These fixtures require the disposable WordPress test database. REST mutations
 * commit their transactions, so teardown explicitly removes committed fixtures.
 *
 * @package meet-with-me
 */

class MWM_Release_Bookings_Test extends WP_UnitTestCase {

	private WP_REST_Server $server;
	private mixed $previous_server;
	private array $previous_options = array();
	private array $event_type;
	private string $date;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		MWM_Install::create_tables();
		$this->clear_custom_tables();
		foreach ( array( 'mwm_general', 'mwm_google', 'mwm_meetings', 'mwm_email' ) as $option ) {
			$this->previous_options[ $option ] = get_option( $option, null );
		}
		MWM_Settings::flush_cache();
		MWM_Settings::set_group(
			array(
				'timezone'         => 'UTC',
				'admin_name'       => 'Release test host',
				'admin_email'      => 'host@example.org',
				'min_notice_hours' => 0,
				'max_advance_days' => 365,
			)
		);
		update_option( 'mwm_google', array() );
		update_option( 'mwm_meetings', array() );
		MWM_Settings::flush_cache();
		$this->date = ( new DateTimeImmutable( 'next monday +2 weeks', new DateTimeZone( 'UTC' ) ) )->format( 'Y-m-d' );
		for ( $day = 0; $day < 7; $day++ ) {
			$wpdb->insert(
				$wpdb->prefix . 'mwm_availability_rules',
				array(
					'rule_type'    => 'weekly',
					'day_of_week'  => $day,
					'start_time'   => '08:00:00',
					'end_time'     => '20:00:00',
					'is_available' => 1,
				)
			);
		}
		$this->event_type = $this->make_event_type( 'release-main' );
		// Fixture writes must be visible to the second SQL connection as well.
		$wpdb->query( 'COMMIT' );
		add_filter( 'pre_wp_mail', '__return_true' );
		add_filter( 'mwm_booking_rate_limit_burst', '__return_zero' );
		add_filter( 'mwm_booking_rate_limit_hourly', '__return_zero' );
		wp_set_current_user( 0 );
		$this->previous_server       = $GLOBALS['wp_rest_server'] ?? null;
		$GLOBALS['wp_rest_server']   = new WP_REST_Server();
		$this->server               = $GLOBALS['wp_rest_server'];
		do_action( 'rest_api_init' );
	}

	public function tear_down(): void {
		global $wpdb;
		// A failed mutation may have left an open transaction; cleanup must persist.
		$wpdb->query( 'ROLLBACK' );
		$this->clear_custom_tables();
		foreach ( $this->previous_options as $option => $value ) {
			if ( null === $value ) {
				delete_option( $option );
			} else {
				update_option( $option, $value );
			}
		}
		MWM_Settings::flush_cache();
		remove_filter( 'pre_wp_mail', '__return_true' );
		remove_filter( 'mwm_booking_rate_limit_burst', '__return_zero' );
		remove_filter( 'mwm_booking_rate_limit_hourly', '__return_zero' );
		$GLOBALS['wp_rest_server'] = $this->previous_server;
		parent::tear_down();
	}

	private function clear_custom_tables(): void {
		global $wpdb;
		foreach ( array( 'mwm_bookings', 'mwm_event_types', 'mwm_availability_rules', 'mwm_blocked_dates' ) as $table ) {
			$wpdb->query( "DELETE FROM {$wpdb->prefix}{$table}" );
		}
	}

	private function make_event_type( string $slug, array $overrides = array() ): array {
		$id = MWM_Event_Type::create(
			array_merge(
				array(
					'name'             => 'Release test ' . $slug,
					'slug'             => $slug,
					'duration_minutes' => 30,
					'meeting_type'     => 'in_person',
					'buffer_before'    => 0,
					'buffer_after'     => 0,
					'max_per_day'      => null,
					'max_per_week'     => null,
					'fields'           => array(),
					'is_active'        => 1,
				),
				$overrides
			)
		);
		$this->assertIsInt( $id, 'The event-type fixture must be saved.' );
		return MWM_Event_Type::get( $id );
	}

	private function request( string $method, string $route, mixed $data = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/mwm/v1' . $route );
		if ( 'GET' === $method ) {
			$request->set_query_params( $data );
		} else {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $data ) );
		}
		return $this->server->dispatch( $request );
	}

	private function payload( string $time = '10:00:00', array $overrides = array(), ?string $date = null ): array {
		$start = new DateTimeImmutable( ( $date ?? $this->date ) . ' ' . $time, new DateTimeZone( 'UTC' ) );
		return array_merge(
			array(
				'event_type'   => $this->event_type['slug'],
				'start_utc'    => $start->format( 'Y-m-d H:i:s' ),
				'end_utc'      => $start->modify( '+30 minutes' )->format( 'Y-m-d H:i:s' ),
				'timezone'     => 'UTC',
				'booker_name'  => 'Release test visitor',
				'booker_email' => 'visitor@example.org',
				'meeting_type' => 'in_person',
			),
			$overrides
		);
	}

	private function book( string $time = '10:00:00', array $overrides = array() ): array {
		$response = $this->request( 'POST', '/bookings', $this->payload( $time, $overrides ) );
		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertTrue( $response->get_data()['success'] );
		$booking = MWM_Booking::get( (int) $response->get_data()['booking']['id'] );
		$this->assertNotNull( $booking );
		return $booking;
	}

	private function cancel( array $booking, array $overrides = array() ): WP_REST_Response {
		return $this->request(
			'POST',
			'/bookings/' . $booking['id'] . '/cancel',
			array_merge( array( 'cancel_token' => $booking['cancel_token'] ), $overrides )
		);
	}

	private function reschedule( array $booking, string $time = '14:00:00', array $overrides = array(), ?string $date = null ): WP_REST_Response {
		$slot = $this->payload( $time, array(), $date );
		return $this->request(
			'POST',
			'/bookings/' . $booking['id'] . '/reschedule',
			array_merge(
				array(
					'reschedule_token' => $booking['reschedule_token'],
					'start_utc'        => $slot['start_utc'],
					'end_utc'          => $slot['end_utc'],
					'timezone'         => 'UTC',
				),
				$overrides
			)
		);
	}

	private function booking_count( ?string $status = null ): int {
		global $wpdb;
		if ( null === $status ) {
			return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}mwm_bookings" );
		}
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}mwm_bookings WHERE status = %s", $status ) );
	}

	public function test_reschedule_availability_excludes_only_the_token_owners_booking(): void {
		MWM_Event_Type::update( $this->event_type['id'], array( 'max_per_day' => 1, 'max_per_week' => 1 ) );
		$booking = $this->book();
		$public = $this->request( 'GET', '/availability/slots', array( 'event_type' => $this->event_type['slug'], 'date' => $this->date, 'tz' => 'UTC', 'exclude_booking_id' => $booking['id'] ) );
		$this->assertSame( 200, $public->get_status() );
		$this->assertSame( array(), $public->get_data()['slots'] );
		$path = '/bookings/' . $booking['id'] . '/availability';
		$body = array( 'reschedule_token' => $booking['reschedule_token'], 'date' => $this->date, 'timezone' => 'UTC' );
		$private = $this->request( 'POST', $path, $body );
		$this->assertSame( 200, $private->get_status() );
		$this->assertNotEmpty( $private->get_data()['slots'] );
		$this->assertContains( $this->date . ' 14:00:00', array_column( $private->get_data()['slots'], 'start_utc' ) );
		$this->assertSame( 'private, no-store, max-age=0', $private->get_headers()['Cache-Control'] );
		$this->assertSame( 403, $this->request( 'POST', $path, array_merge( $body, array( 'reschedule_token' => 'invalid' ) ) )->get_status() );
		$this->assertSame( 400, $this->request( 'POST', $path, array_merge( $body, array( 'date' => '2026-02-30' ) ) )->get_status() );
		$month = $this->request( 'POST', $path, array( 'reschedule_token' => $booking['reschedule_token'], 'year' => (int) substr( $this->date, 0, 4 ), 'month' => (int) substr( $this->date, 5, 2 ) ) );
		$this->assertSame( 200, $month->get_status() );
		$this->assertContains( $this->date, $month->get_data()['available_dates'] );
		$this->assertSame( 200, $this->cancel( $booking )->get_status() );
		$this->assertSame( 409, $this->request( 'POST', $path, $body )->get_status() );
	}

	public function test_nontransactional_booking_storage_refuses_mutations(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'mwm_bookings';
		try {
			$this->assertNotFalse( $wpdb->query( "ALTER TABLE {$table} ENGINE=MyISAM" ) );
			$response = $this->request( 'POST', '/bookings', $this->payload() );
			$this->assertSame( 503, $response->get_status() );
			$this->assertSame( 0, $this->booking_count() );
		} finally {
			$wpdb->query( "ALTER TABLE {$table} ENGINE=InnoDB" );
		}
	}

	public function test_failed_commit_rolls_back_without_reporting_or_notifying_success(): void {
		global $wpdb;
		$hit = false;
		$filter = static function ( $sql ) use ( &$hit ) {
			if ( $sql === 'COMMIT' ) {
				$hit = true;
				return 'INVALID RELEASE COMMIT';
			}
			return $sql;
		};
		$notified = false;
		$notice = static function () use ( &$notified ) { $notified = true; };
		$previous = $wpdb->suppress_errors( true );
		add_filter( 'query', $filter );
		add_action( 'mwm_booking_created', $notice );
		try {
			$response = $this->request( 'POST', '/bookings', $this->payload() );
		} finally {
			remove_filter( 'query', $filter );
			remove_action( 'mwm_booking_created', $notice );
			$wpdb->suppress_errors( $previous );
		}
		$this->assertTrue( $hit );
		$this->assertSame( 503, $response->get_status() );
		$this->assertFalse( $notified );
		$this->assertSame( 0, $this->booking_count() );
	}

	public function test_public_booking_can_be_cancelled_then_replaced_and_rescheduled(): void {
		$first = $this->book();
		$this->assertSame( 'confirmed', $first['status'] );
		$this->assertSame( 200, $this->cancel( $first )->get_status() );
		$this->assertSame( 'cancelled', MWM_Booking::get( $first['id'] )['status'] );
		$replacement = $this->book( '10:00:00', array( 'booker_email' => 'replacement@example.org' ) );
		$this->assertSame( 'replacement@example.org', $replacement['booker_email'] );
		$response = $this->reschedule( $replacement );
		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'rescheduled', MWM_Booking::get( $replacement['id'] )['status'] );
		$new = MWM_Booking::get( (int) $response->get_data()['booking']['id'] );
		$this->assertSame( $this->date . ' 14:00:00', $new['start_datetime'] );
		$this->assertSame( 'replacement@example.org', $new['booker_email'] );
		$this->assertSame( 1, $this->booking_count( 'confirmed' ) );
	}

	public function test_old_manage_tokens_cannot_control_the_replacement_of_a_cancelled_slot(): void {
		$first = $this->book();
		$this->assertSame( 200, $this->cancel( $first )->get_status() );
		$replacement = $this->book( '10:00:00', array( 'booker_email' => 'replacement@example.org' ) );
		$this->assertNotSame( $first['cancel_token'], $replacement['cancel_token'] );
		$this->assertNotSame( $first['reschedule_token'], $replacement['reschedule_token'] );
		$before = $this->booking_count();
		$cancel = $this->cancel( $replacement, array( 'cancel_token' => $first['cancel_token'] ) );
		$this->assertSame( 403, $cancel->get_status() );
		$move = $this->reschedule( $replacement, '14:00:00', array( 'reschedule_token' => $first['reschedule_token'] ) );
		$this->assertSame( 403, $move->get_status() );
		$this->assertSame( 'confirmed', MWM_Booking::get( $replacement['id'] )['status'] );
		$this->assertSame( $before, $this->booking_count() );
	}

	public function test_cancelled_source_cannot_create_a_rescheduled_booking(): void {
		$booking = $this->book();
		$this->assertSame( 200, $this->cancel( $booking )->get_status() );
		$response = $this->reschedule( $booking );
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 1, $this->booking_count() );
		$this->assertSame( 0, $this->booking_count( 'confirmed' ) );
	}

	public function test_rescheduled_source_cannot_create_another_booking(): void {
		$booking = $this->book();
		$this->assertSame( 201, $this->reschedule( $booking )->get_status() );
		$response = $this->reschedule( $booking, '16:00:00' );
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 2, $this->booking_count() );
		$this->assertSame( 1, $this->booking_count( 'confirmed' ) );
	}

	/** @dataProvider capacity_limits */
	public function test_reschedule_excludes_itself_from_a_one_booking_capacity( string $limit, int $days ): void {
		MWM_Event_Type::update( $this->event_type['id'], array( $limit => 1 ) );
		$booking = $this->book();
		$date    = ( new DateTimeImmutable( $this->date, new DateTimeZone( 'UTC' ) ) )->modify( '+' . $days . ' days' )->format( 'Y-m-d' );
		$another = $this->request( 'POST', '/bookings', $this->payload( '14:00:00', array(), $date ) );
		$this->assertSame( 409, $another->get_status(), 'The cap must still block an additional booking.' );
		$response = $this->reschedule( $booking, '14:00:00', array(), $date );
		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( 1, $this->booking_count( 'confirmed' ) );
		$this->assertSame( 'rescheduled', MWM_Booking::get( $booking['id'] )['status'] );
	}

	public static function capacity_limits(): array {
		return array(
			'same day at daily cap'   => array( 'max_per_day', 0 ),
			'same week at weekly cap' => array( 'max_per_week', 1 ),
		);
	}

	/** @dataProvider invalid_utc_datetimes */
	public function test_invalid_utc_datetimes_return_400_without_saving( string $field, string $value ): void {
		$payload           = $this->payload();
		$payload[ $field ] = $value;
		$this->assertSame( 400, $this->request( 'POST', '/bookings', $payload )->get_status() );
		$this->assertSame( 0, $this->booking_count() );
		$booking = $this->book();
		$this->assertSame( 400, $this->reschedule( $booking, '14:00:00', array( $field => $value ) )->get_status() );
		$this->assertSame( 1, $this->booking_count() );
		$this->assertSame( 'confirmed', MWM_Booking::get( $booking['id'] )['status'] );
	}

	public static function invalid_utc_datetimes(): array {
		return array(
			'normalized February date' => array( 'start_utc', '2028-02-30 10:00:00' ),
			'non-leap February date'   => array( 'end_utc', '2027-02-29 10:30:00' ),
			'invalid month'           => array( 'start_utc', '2028-13-01 10:00:00' ),
			'normalized hour'         => array( 'start_utc', '2028-01-01 24:00:00' ),
			'normalized second'       => array( 'end_utc', '2028-01-01 10:30:60' ),
			'UTC wire format'         => array( 'start_utc', '2028-01-01T10:00:00Z' ),
		);
	}

	/** @dataProvider nonscalar_booking_fields */
	public function test_nonscalar_booking_fields_return_400( string $field ): void {
		$payload           = $this->payload();
		$payload[ $field ] = array( 'unexpected' => 'array' );
		$this->assertSame( 400, $this->request( 'POST', '/bookings', $payload )->get_status() );
		$this->assertSame( 0, $this->booking_count() );
	}

	public static function nonscalar_booking_fields(): array {
		return array(
			'event type' => array( 'event_type' ),
			'start UTC'  => array( 'start_utc' ),
			'end UTC'    => array( 'end_utc' ),
			'timezone'   => array( 'timezone' ),
			'name'       => array( 'booker_name' ),
			'email'      => array( 'booker_email' ),
		);
	}

	public function test_nonscalar_management_tokens_and_reschedule_fields_return_400(): void {
		$booking = $this->book();
		$this->assertSame( 400, $this->cancel( $booking, array( 'cancel_token' => array( 'bad' ) ) )->get_status() );
		foreach ( array( 'reschedule_token', 'start_utc', 'end_utc', 'timezone' ) as $field ) {
			$this->assertSame( 400, $this->reschedule( $booking, '14:00:00', array( $field => array( 'bad' ) ) )->get_status(), $field );
		}
		$this->assertSame( 'confirmed', MWM_Booking::get( $booking['id'] )['status'] );
		$this->assertSame( 1, $this->booking_count() );
	}

	public function test_nonobject_json_payloads_return_400_without_creating_bookings(): void {
		foreach ( array( 'unexpected string', 123, true, array( 'unexpected list' ) ) as $payload ) {
			$this->assertSame( 400, $this->request( 'POST', '/bookings', $payload )->get_status() );
		}
		$this->assertSame( 0, $this->booking_count() );
	}

	public function test_invalid_calendar_dates_and_nonscalar_availability_params_return_400(): void {
		foreach ( array( '2028-02-30', '2027-02-29', '2028-13-01', array( 'bad' ) ) as $date ) {
			$response = $this->request( 'GET', '/availability/slots', array( 'event_type' => $this->event_type['slug'], 'date' => $date ) );
			$this->assertSame( 400, $response->get_status() );
		}
		foreach ( array( array( 'year' => 2028, 'month' => 13 ), array( 'year' => 10000, 'month' => 1 ), array( 'year' => array( 'bad' ), 'month' => 1 ) ) as $params ) {
			$params['event_type'] = $this->event_type['slug'];
			$this->assertSame( 400, $this->request( 'GET', '/availability/month', $params )->get_status() );
		}
		$this->assertSame( 0, $this->booking_count() );
	}

	/** @dataProvider failed_availability_reads */
	public function test_failed_availability_read_returns_503_and_never_inserts( string $boundary, string $method ): void {
		global $wpdb;
		$hit    = false;
		$filter = static function ( $query ) use ( $boundary, &$hit ) {
			if ( ! $hit && preg_match( '/^\s*SELECT\b/i', $query ) && str_contains( $query, $boundary ) ) {
				$hit = true;
				return 'INVALID RELEASE AVAILABILITY SELECT';
			}
			return $query;
		};
		$previous = $wpdb->suppress_errors( true );
		add_filter( 'query', $filter );
		try {
			$response = 'POST' === $method
				? $this->request( 'POST', '/bookings', $this->payload() )
				: $this->request( 'GET', '/availability/slots', array( 'event_type' => $this->event_type['slug'], 'date' => $this->date, 'tz' => 'UTC' ) );
		} finally {
			remove_filter( 'query', $filter );
			$wpdb->suppress_errors( $previous );
		}
		$this->assertTrue( $hit, 'The intended real SQL read must be fault-injected.' );
		$this->assertSame( 503, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( 0, $this->booking_count() );
	}

	public static function failed_availability_reads(): array {
		return array(
			'schedule display'  => array( 'mwm_availability_rules', 'GET' ),
			'schedule booking'  => array( 'mwm_availability_rules', 'POST' ),
			'blocked display'   => array( 'mwm_blocked_dates', 'GET' ),
			'blocked booking'   => array( 'mwm_blocked_dates', 'POST' ),
			'conflict display'  => array( 'b.start_datetime', 'GET' ),
			'conflict booking'  => array( 'b.start_datetime', 'POST' ),
		);
	}

	/** @dataProvider reschedule_targets */
	public function test_failed_source_transition_rolls_back_the_new_or_reused_target( bool $reuse ): void {
		global $wpdb;
		$source = $this->book();
		$target = null;
		if ( $reuse ) {
			$target = $this->book( '14:00:00', array( 'booker_email' => 'target@example.org' ) );
			$this->assertSame( 200, $this->cancel( $target )->get_status() );
			$target = MWM_Booking::get( $target['id'] );
		}
		$before = $this->booking_count();
		$hit    = false;
		$filter = static function ( $query ) use ( &$hit ) {
			$plain = str_replace( '`', '', $query );
			if ( ! $hit && preg_match( '/^\s*UPDATE\b/i', $plain ) && str_contains( $plain, 'mwm_bookings' ) && preg_match( "/\bstatus\s*=\s*'rescheduled'/i", $plain ) ) {
				$hit = true;
				return 'INVALID RELEASE SOURCE TRANSITION';
			}
			return $query;
		};
		$notified = false;
		$notice  = static function () use ( &$notified ) { $notified = true; };
		$previous = $wpdb->suppress_errors( true );
		add_filter( 'query', $filter );
		add_action( 'mwm_booking_rescheduled', $notice );
		try {
			$response = $this->reschedule( $source );
		} finally {
			remove_filter( 'query', $filter );
			remove_action( 'mwm_booking_rescheduled', $notice );
			$wpdb->suppress_errors( $previous );
		}
		$this->assertTrue( $hit, 'The original booking UPDATE must reach the SQL failure seam.' );
		$this->assertSame( 503, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertFalse( $notified, 'A failed reschedule must not notify downstream consumers.' );
		$this->assertSame( $before, $this->booking_count() );
		$this->assertSame( 'confirmed', MWM_Booking::get( $source['id'] )['status'] );
		$this->assertSame( $source['reschedule_token'], MWM_Booking::get( $source['id'] )['reschedule_token'] );
		if ( $reuse ) {
			$after = MWM_Booking::get( $target['id'] );
			$this->assertSame( 'cancelled', $after['status'] );
			$this->assertSame( $target['booker_email'], $after['booker_email'] );
			$this->assertSame( $target['cancel_token'], $after['cancel_token'] );
			$this->assertSame( $target['reschedule_token'], $after['reschedule_token'] );
		} else {
			$this->assertNull( MWM_Booking::get_by_slot( $this->event_type['id'], $this->date . ' 14:00:00' ) );
		}
	}

	public static function reschedule_targets(): array {
		return array( 'new row' => array( false ), 'cancelled target row' => array( true ) );
	}

	/** @dataProvider buffer_directions */
	public function test_combined_existing_and_proposed_buffers_block_a_distant_raw_slot( bool $existing_first ): void {
		$existing = $this->make_event_type( 'release-existing', array( $existing_first ? 'buffer_after' : 'buffer_before' => 120 ) );
		$proposed = $this->make_event_type( 'release-proposed', array( $existing_first ? 'buffer_before' : 'buffer_after' => 120 ) );
		$this->book( $existing_first ? '10:00:00' : '14:00:00', array( 'event_type' => $existing['slug'] ) );
		$response = $this->request( 'POST', '/bookings', $this->payload( $existing_first ? '14:00:00' : '10:00:00', array( 'event_type' => $proposed['slug'] ) ) );
		$this->assertSame( 409, $response->get_status(), 'Raw meetings are apart, but their two 120-minute buffers overlap.' );
		$this->assertSame( 1, $this->booking_count( 'confirmed' ) );
		// A non-overlapping boundary remains bookable; buffers must not block all day.
		$boundary = $existing_first ? '14:30:00' : '09:30:00';
		$this->assertSame( 201, $this->request( 'POST', '/bookings', $this->payload( $boundary, array( 'event_type' => $proposed['slug'] ) ) )->get_status() );
	}

	public static function buffer_directions(): array {
		return array( 'existing earlier' => array( true ), 'existing later' => array( false ) );
	}

	public function test_different_meeting_types_share_the_same_host_calendar(): void {
		$this->book();
		$other = $this->make_event_type( 'release-other' );
		$slots = $this->request( 'GET', '/availability/slots', array( 'event_type' => $other['slug'], 'date' => $this->date, 'tz' => 'UTC' ) );
		$this->assertSame( 200, $slots->get_status() );
		$starts = array_column( $slots->get_data()['slots'], 'start_utc' );
		$this->assertNotContains( $this->date . ' 10:00:00', $starts );
		$this->assertContains( $this->date . ' 11:00:00', $starts );
		$response = $this->request( 'POST', '/bookings', $this->payload( '10:00:00', array( 'event_type' => $other['slug'] ) ) );
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 1, $this->booking_count( 'confirmed' ) );
		$this->book( '11:00:00', array( 'event_type' => $other['slug'] ) );
		$this->assertSame( 2, $this->booking_count( 'confirmed' ) );
	}

	public function test_another_sql_connection_holding_the_host_lock_blocks_mutation_until_release(): void {
		global $wpdb;
		$other = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$other->suppress_errors( true );
		$this->assertSame( $wpdb->get_var( 'SELECT DATABASE()' ), $other->get_var( 'SELECT DATABASE()' ), 'Both connections must use the isolated test database.' );
		$this->assertNotSame( $wpdb->get_var( 'SELECT CONNECTION_ID()' ), $other->get_var( 'SELECT CONNECTION_ID()' ) );
		$lock = MWM_Booking::lock_name();
		try {
			$this->assertSame( '1', (string) $other->get_var( $other->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) );
			$response = $this->request( 'POST', '/bookings', $this->payload() );
			$this->assertSame( 503, $response->get_status() );
			$this->assertSame( 0, $this->booking_count() );
		} finally {
			$other->get_var( $other->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
			$other->close();
		}
		$this->book();
		$this->assertSame( 1, $this->booking_count( 'confirmed' ) );
	}
}
