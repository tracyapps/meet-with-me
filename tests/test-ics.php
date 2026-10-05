<?php
/**
 * Tests for MWM_ICS::generate() output structure and escaping.
 *
 * @package meet-with-me
 */

/**
 * Class MWM_ICS_Test
 */
class MWM_ICS_Test extends WP_UnitTestCase {

	/**
	 * Build a minimal booking array for ICS generation.
	 *
	 * @param array $overrides Field overrides.
	 * @return array
	 */
	private function make_booking( array $overrides = [] ): array {
		return array_merge( [
			'id'               => 7,
			'booker_name'      => 'Jane Smith',
			'booker_email'     => 'jane@example.com',
			'booker_notes'     => '',
			'start_datetime'   => '2026-04-07 16:00:00',
			'end_datetime'     => '2026-04-07 16:30:00',
			'meeting_type'     => 'online',
			'meeting_provider' => '',
			'meeting_join_url' => '',
			'meeting_host_url' => '',
			'cancel_token'     => str_repeat( 'a', 64 ),
			'field_answers'    => [],
		], $overrides );
	}

	/**
	 * Build a minimal event type array for ICS generation.
	 *
	 * @param array $overrides Field overrides.
	 * @return array
	 */
	private function make_event_type( array $overrides = [] ): array {
		return array_merge( [
			'id'               => 1,
			'name'             => 'Intro Call',
			'duration_minutes' => 30,
			'fields'           => [],
		], $overrides );
	}

	/**
	 * The generated file contains a structurally valid VEVENT.
	 */
	public function test_generate_produces_valid_vevent_structure() {
		$ics = MWM_ICS::generate( $this->make_booking(), $this->make_event_type() );

		$this->assertStringContainsString( 'BEGIN:VCALENDAR', $ics );
		$this->assertStringContainsString( 'VERSION:2.0', $ics );
		$this->assertStringContainsString( 'BEGIN:VEVENT', $ics );
		$this->assertStringContainsString( 'END:VEVENT', $ics );
		$this->assertStringContainsString( 'END:VCALENDAR', $ics );
		$this->assertStringContainsString( 'DTSTART:20260407T160000Z', $ics );
		$this->assertStringContainsString( 'DTEND:20260407T163000Z', $ics );
		$this->assertTrue( str_ends_with( $ics, "END:VCALENDAR\r\n" ) );
	}

	/**
	 * The UID embeds the booking ID, a token hash, and the site host.
	 */
	public function test_uid_contains_booking_id_and_host() {
		$ics  = MWM_ICS::generate( $this->make_booking(), $this->make_event_type() );
		$host = parse_url( home_url(), PHP_URL_HOST ) ?: 'localhost';

		$this->assertMatchesRegularExpression(
			'/UID:mwm-booking-7-[0-9a-f]{32}@' . preg_quote( $host, '/' ) . '\r\n/',
			$ics
		);
	}

	/**
	 * Commas and semicolons in text values are escaped per RFC 5545.
	 */
	public function test_summary_and_description_are_escaped() {
		$ics = MWM_ICS::generate(
			$this->make_booking(),
			$this->make_event_type( [ 'name' => 'Intro, Planning; Session' ] )
		);

		$this->assertStringContainsString( 'SUMMARY:Intro\, Planning\; Session', $ics );
	}

	/**
	 * No output line exceeds 75 octets (RFC 5545 folding).
	 */
	public function test_lines_are_folded_to_75_octets() {
		$long_name = str_repeat( 'Long name ', 30 );
		$ics       = MWM_ICS::generate( $this->make_booking(), $this->make_event_type( [ 'name' => $long_name ] ) );

		foreach ( explode( "\r\n", $ics ) as $line ) {
			$this->assertLessThanOrEqual( 75, strlen( $line ), 'ICS line exceeds 75 octets: ' . $line );
		}
	}

	/**
	 * In-person bookings do not get a LOCATION property.
	 */
	public function test_in_person_booking_has_no_location() {
		$ics = MWM_ICS::generate(
			$this->make_booking( [ 'meeting_type' => 'in_person' ] ),
			$this->make_event_type()
		);

		$this->assertStringNotContainsString( 'LOCATION:', $ics );
	}

	/**
	 * Online bookings with a join URL include it as the location.
	 */
	public function test_online_booking_uses_join_url_as_location() {
		$ics = MWM_ICS::generate(
			$this->make_booking(
				[
					'meeting_type'     => 'online',
					'meeting_join_url' => 'https://example.zoom.us/j/123',
				]
			),
			$this->make_event_type()
		);

		$this->assertStringContainsString( 'LOCATION:https://example.zoom.us/j/123', $ics );
	}
}
