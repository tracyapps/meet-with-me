<?php
/**
 * Google Calendar release boundaries: guest payloads and failed availability reads.
 *
 * @package meet-with-me
 */

class MWM_Google_Release_Test extends WP_UnitTestCase {

	private $response;
	private array $requests = array();
	private const DATE = '2030-01-15';

	public function set_up(): void {
		parent::set_up();
		$this->requests = array();
		MWM_Settings::set_group(
			array(
				'client_id'              => 'fixture.apps.googleusercontent.com',
				'client_secret'          => 'fixture-client-secret',
				'access_token'           => 'fixture-access-token',
				'refresh_token'          => 'fixture-refresh-token',
				'token_expiry'           => time() + HOUR_IN_SECONDS,
				'calendar_ids'           => array( 'first@example.org', 'second@example.org' ),
				'write_back_calendar_id' => 'first@example.org',
			),
			'google'
		);
		$this->clear_busy_cache();
		add_filter( 'pre_http_request', array( $this, 'capture_request' ), 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'capture_request' ), 10 );
		$this->clear_busy_cache();
		MWM_Settings::flush_cache( 'google' );
		parent::tear_down();
	}

	private function clear_busy_cache(): void {
		$start = self::DATE . 'T00:00:00+00:00';
		$end   = self::DATE . 'T23:59:59+00:00';
		delete_transient( 'mwm_freebusy_' . md5( $start . $end . 'first@example.org,second@example.org' ) );
	}

	public function capture_request( $preempt, array $args, string $url ) {
		$this->requests[] = array( 'url' => $url, 'args' => $args );
		if ( $this->response === null ) {
			throw new RuntimeException( 'Every HTTP response must be explicitly stubbed.' );
		}
		return $this->response;
	}

	private function json_response( $body, int $code = 200 ): array {
		return array( 'response' => array( 'code' => $code ), 'body' => wp_json_encode( $body ) );
	}

	private function free_calendars(): array {
		return array( 'calendars' => array( 'first@example.org' => array( 'busy' => array() ), 'second@example.org' => array( 'busy' => array() ) ) );
	}

	public function test_attendee_event_never_contains_the_host_capability(): void {
		$this->response = $this->json_response( array( 'id' => 'fixture-event-id' ) );
		$host_url       = 'https://zoom.us/s/123?zak=private-host-capability';
		$join_url       = 'https://zoom.us/j/123';
		$booking        = array(
			'booker_name'      => 'Guest Booker',
			'booker_email'     => 'guest@example.org',
			'booker_notes'     => 'A meeting about the project.',
			'meeting_type'     => 'online',
			'meeting_join_url' => $join_url,
			'meeting_host_url' => $host_url,
			'start_datetime'   => '2030-01-15 10:00:00',
			'end_datetime'     => '2030-01-15 10:30:00',
		);
		$result = MWM_Google_Calendar::create_event_with_options( $booking, array( 'name' => 'Project Meeting' ) );
		$this->assertIsArray( $result );
		$this->assertCount( 1, $this->requests );
		$body = $this->requests[0]['args']['body'];
		$event = json_decode( $body, true );
		$this->assertSame( 'guest@example.org', $event['attendees'][1]['email'] );
		$this->assertStringContainsString( $join_url, $event['description'] );
		$this->assertStringNotContainsString( $host_url, $body );
		$this->assertStringNotContainsString( 'private-host-capability', $body );
		$this->assertStringNotContainsString( 'Host link:', $event['description'] );
	}

	public function test_token_refresh_failure_returns_unavailable_without_a_busy_request(): void {
		MWM_Settings::set_group( array( 'access_token' => '', 'token_expiry' => 0 ), 'google' );
		$this->response = new WP_Error( 'http_request_failed', 'Fixture OAuth timeout.' );
		$result = MWM_Google_Calendar::get_busy_periods_for_date( self::DATE, new DateTimeZone( 'UTC' ), true );
		$this->assertWPError( $result );
		$this->assertSame( 'mwm_google_busy_unavailable', $result->get_error_code() );
		$this->assertSame( 503, $result->get_error_data()['status'] );
		$this->assertCount( 1, $this->requests );
		$this->assertStringContainsString( '/token', $this->requests[0]['url'] );
	}

	public function test_transport_and_http_errors_are_not_free_or_cached(): void {
		foreach ( array( new WP_Error( 'http_request_failed', 'Fixture timeout.' ), $this->json_response( array( 'error' => 'upstream failure' ), 503 ) ) as $failure ) {
			$this->clear_busy_cache();
			$this->response = $failure;
			$this->assertWPError( MWM_Google_Calendar::get_busy_periods_for_date( self::DATE, new DateTimeZone( 'UTC' ) ) );
			$this->response = $this->json_response( $this->free_calendars() );
			$this->assertSame( array(), MWM_Google_Calendar::get_busy_periods_for_date( self::DATE, new DateTimeZone( 'UTC' ) ) );
		}
		$this->assertCount( 4, $this->requests );
	}

	public function test_missing_calendar_errors_and_malformed_results_are_unavailable(): void {
		$cases = array(
			null,
			array( 'calendars' => array() ),
			array( 'calendars' => array( 'first@example.org' => array( 'busy' => array() ) ) ),
			array( 'calendars' => array( 'first@example.org' => array( 'busy' => array() ), 'second@example.org' => array( 'errors' => array( array( 'reason' => 'notFound' ) ) ) ) ),
			array( 'calendars' => array( 'first@example.org' => array( 'busy' => array( array( 'start' => 'not-a-date', 'end' => 'not-a-date' ) ) ), 'second@example.org' => array( 'busy' => array() ) ) ),
			array( 'calendars' => array( 'first@example.org' => array( 'busy' => array( array( 'start' => '2030-02-30T10:00:00Z', 'end' => '2030-02-30T10:30:00Z' ) ) ), 'second@example.org' => array( 'busy' => array() ) ) ),
		);
		foreach ( $cases as $case ) {
			$this->response = $this->json_response( $case );
			$result = MWM_Google_Calendar::get_busy_periods_for_date( self::DATE, new DateTimeZone( 'UTC' ), true );
			$this->assertWPError( $result );
			$this->assertSame( 'mwm_google_busy_unavailable', $result->get_error_code() );
		}
	}

	public function test_verified_periods_are_returned_in_utc(): void {
		$calendars = $this->free_calendars();
		$calendars['calendars']['first@example.org']['busy'] = array( array( 'start' => '2030-01-15T10:00:00-06:00', 'end' => '2030-01-15T10:30:00-06:00' ) );
		$this->response = $this->json_response( $calendars );
		$result = MWM_Google_Calendar::get_busy_periods_for_date( self::DATE, new DateTimeZone( 'UTC' ), true );
		$this->assertIsArray( $result );
		$this->assertCount( 1, $result );
		$this->assertSame( '2030-01-15T16:00:00+00:00', $result[0]['start']->format( 'c' ) );
		$this->assertSame( '2030-01-15T16:30:00+00:00', $result[0]['end']->format( 'c' ) );
	}

	public function test_forced_read_does_not_accept_a_cached_free_calendar_during_an_outage(): void {
		$this->response = $this->json_response( $this->free_calendars() );
		$this->assertSame( array(), MWM_Google_Calendar::get_busy_periods_for_date( self::DATE, new DateTimeZone( 'UTC' ) ) );
		$this->response = new WP_Error( 'http_request_failed', 'Fixture timeout.' );
		$this->assertSame( array(), MWM_Google_Calendar::get_busy_periods_for_date( self::DATE, new DateTimeZone( 'UTC' ) ) );
		$this->assertCount( 1, $this->requests );
		$this->assertWPError( MWM_Google_Calendar::get_busy_periods_for_date( self::DATE, new DateTimeZone( 'UTC' ), true ) );
		$this->assertCount( 2, $this->requests );
	}
}
