<?php
/**
 * Tests for the token-gated .ics download (?mwm_ics=<cancel token>).
 *
 * @package meet-with-me
 */

/**
 * Class MWM_ICS_Download_Test
 */
class MWM_ICS_Download_Test extends WP_UnitTestCase {

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
				'admin_name'       => 'ICS test host',
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
		$this->event_type = $this->make_event_type( 'ics-download' );
		$wpdb->query( 'COMMIT' );
		add_filter( 'pre_wp_mail', '__return_true' );
		add_filter( 'mwm_booking_rate_limit_burst', '__return_zero' );
		add_filter( 'mwm_booking_rate_limit_hourly', '__return_zero' );
		wp_set_current_user( 0 );
		$this->previous_server     = $GLOBALS['wp_rest_server'] ?? null;
		$GLOBALS['wp_rest_server'] = new WP_REST_Server();
		$this->server              = $GLOBALS['wp_rest_server'];
		do_action( 'rest_api_init' );
	}

	public function tear_down(): void {
		global $wpdb;
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

	private function make_event_type( string $slug ): array {
		$id = MWM_Event_Type::create(
			array(
				'name'             => 'ICS test meeting',
				'slug'             => $slug,
				'duration_minutes' => 30,
				'meeting_type'     => 'in_person',
				'buffer_before'    => 0,
				'buffer_after'     => 0,
				'max_per_day'      => null,
				'max_per_week'     => null,
				'fields'           => array(),
				'is_active'        => 1,
			)
		);
		$this->assertIsInt( $id, 'The event-type fixture must be saved.' );
		return MWM_Event_Type::get( $id );
	}

	/**
	 * Create one confirmed booking via the public REST endpoint and return
	 * its stored row (id + cancel token included).
	 */
	private function make_booking( string $time = '10:00:00' ): array {
		$start   = new DateTimeImmutable( $this->date . ' ' . $time, new DateTimeZone( 'UTC' ) );
		$request = new WP_REST_Request( 'POST', '/mwm/v1/bookings' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'event_type'   => $this->event_type['slug'],
					'start_utc'    => $start->format( 'Y-m-d H:i:s' ),
					'end_utc'      => $start->modify( '+30 minutes' )->format( 'Y-m-d H:i:s' ),
					'timezone'     => 'UTC',
					'booker_name'  => 'ICS test visitor',
					'booker_email' => 'visitor@example.org',
					'meeting_type' => 'in_person',
				)
			)
		);
		$response = $this->server->dispatch( $request );
		$this->assertSame( 201, $response->get_status(), 'Booking fixture must be created.' );
		$data = $response->get_data();
		$this->assertSame( true, $data['success'] );

		$booking = MWM_Booking::get( $data['booking']['id'] );
		$this->assertNotNull( $booking );
		return $booking;
	}

	public function test_confirmed_booking_serves_ics() {
		$booking = $this->make_booking();

		$result = MWM_Public::ics_response( $booking['cancel_token'] );

		$this->assertIsArray( $result );
		$this->assertStringStartsWith( 'BEGIN:VCALENDAR', $result['body'] );
		$this->assertStringContainsString( 'SUMMARY:ICS test meeting with ICS test host', $result['body'] );
		$this->assertSame( 'meet-with-me-booking-' . (int) $booking['id'] . '.ics', $result['filename'] );
	}

	public function test_confirmation_payload_carries_ics_url_and_utc_times() {
		$start    = new DateTimeImmutable( $this->date . ' 11:00:00', new DateTimeZone( 'UTC' ) );
		$request  = new WP_REST_Request( 'POST', '/mwm/v1/bookings' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'event_type'   => $this->event_type['slug'],
					'start_utc'    => $start->format( 'Y-m-d H:i:s' ),
					'end_utc'      => $start->modify( '+30 minutes' )->format( 'Y-m-d H:i:s' ),
					'timezone'     => 'UTC',
					'booker_name'  => 'ICS test visitor',
					'booker_email' => 'visitor@example.org',
					'meeting_type' => 'in_person',
				)
			)
		);
		$response = $this->server->dispatch( $request );
		$booking  = $response->get_data()['booking'];

		$this->assertSame( $start->format( 'Y-m-d H:i:s' ), $booking['start_utc'] );
		$this->assertSame( $start->modify( '+30 minutes' )->format( 'Y-m-d H:i:s' ), $booking['end_utc'] );
		$this->assertStringContainsString( 'mwm_ics=', $booking['ics_url'] );
	}

	public function test_cancelled_booking_refuses_ics() {
		$booking = $this->make_booking();

		$cancel = new WP_REST_Request( 'POST', '/mwm/v1/bookings/' . $booking['id'] . '/cancel' );
		$cancel->set_header( 'Content-Type', 'application/json' );
		$cancel->set_body( wp_json_encode( array( 'cancel_token' => $booking['cancel_token'] ) ) );
		$this->server->dispatch( $cancel );

		$this->assertWPError( MWM_Public::ics_response( $booking['cancel_token'] ) );
	}

	public function test_malformed_token_rejected() {
		$this->assertWPError( MWM_Public::ics_response( 'not-a-token' ) );
		$this->assertWPError( MWM_Public::ics_response( str_repeat( 'z', 64 ) ) );
	}

	public function test_unknown_token_rejected() {
		$this->assertWPError( MWM_Public::ics_response( str_repeat( 'a', 64 ) ) );
	}
}
