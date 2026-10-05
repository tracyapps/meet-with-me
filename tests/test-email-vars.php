<?php
/**
 * Tests for MWM_Email template variable replacement.
 *
 * Uses the `pre_wp_mail` filter to capture outbound mail without sending it.
 *
 * @package meet-with-me
 */

/**
 * Class MWM_Email_Vars_Test
 */
class MWM_Email_Vars_Test extends WP_UnitTestCase {

	/**
	 * Captured wp_mail() arguments.
	 *
	 * @var array[]
	 */
	private $captured = [];

	/**
	 * Set up filters and clean state.
	 */
	public function set_up() {
		parent::set_up();

		$this->captured = [];
		add_filter( 'pre_wp_mail', [ $this, 'capture_mail' ], 10, 2 );
	}

	/**
	 * Tear down filters and stored settings.
	 */
	public function tear_down() {
		remove_filter( 'pre_wp_mail', [ $this, 'capture_mail' ], 10 );

		delete_option( 'mwm_email' );
		MWM_Settings::flush_cache( 'email' );

		parent::tear_down();
	}

	/**
	 * Capture wp_mail() args and short-circuit sending.
	 *
	 * @param null  $return Short-circuit value.
	 * @param array $atts   Parsed wp_mail() attributes.
	 * @return bool
	 */
	public function capture_mail( $return, $atts ) {
		$this->captured[] = $atts;
		return true;
	}

	/**
	 * Build a booking array for email building.
	 *
	 * @return array
	 */
	private function make_booking(): array {
		return [
			'id'               => 42,
			'booker_name'      => 'Jane Smith',
			'booker_email'     => 'jane@example.com',
			'booker_phone'     => '',
			'booker_notes'     => '',
			'booker_timezone'  => 'America/Vancouver',
			'start_datetime'   => '2026-04-07 16:00:00',
			'end_datetime'     => '2026-04-07 16:30:00',
			'meeting_type'     => 'online',
			'meeting_provider' => 'zoom',
			'meeting_join_url' => 'https://example.zoom.us/j/123456789',
			'meeting_host_url' => 'https://example.zoom.us/s/123456789',
			'cancel_token'     => 'abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789',
			'reschedule_token' => '123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef0',
			'field_answers'    => [],
		];
	}

	/**
	 * Build an event type array for email building.
	 *
	 * @return array
	 */
	private function make_event_type(): array {
		return [
			'id'               => 1,
			'name'             => 'Intro Call',
			'duration_minutes' => 30,
			'fields'           => [],
		];
	}

	/**
	 * Subject and body placeholders are replaced with booking data.
	 */
	public function test_confirmation_email_replaces_placeholders() {
		MWM_Settings::set_group(
			[
				'confirmation_subject' => 'Confirmed: {event_name}',
				'confirmation_body'    => 'Hi {booker_name}!',
			],
			'email'
		);
		MWM_Settings::flush_cache( 'email' );

		( new MWM_Email() )->send_confirmation( $this->make_booking(), $this->make_event_type() );

		$this->assertNotEmpty( $this->captured, 'A wp_mail() call should have been captured.' );
		$this->assertSame( 'Confirmed: Intro Call', $this->captured[0]['subject'] );
		$this->assertStringContainsString( 'Hi Jane Smith!', $this->captured[0]['message'] );
	}

	/**
	 * The manage URL contains the booking's cancel token.
	 */
	public function test_manage_url_contains_cancel_token() {
		MWM_Settings::set_group(
			[
				'confirmation_subject' => 'Confirmed',
				'confirmation_body'    => 'Manage: {manage_url}',
			],
			'email'
		);
		MWM_Settings::flush_cache( 'email' );

		$booking = $this->make_booking();

		( new MWM_Email() )->send_confirmation( $booking, $this->make_event_type() );

		$this->assertNotEmpty( $this->captured );
		$this->assertStringContainsString( 'mwm_token=' . $booking['cancel_token'], $this->captured[0]['message'] );
	}

	/**
	 * An online booking with a join URL but no {meeting_details} token in the
	 * body still gets meeting details appended (current inject behavior).
	 */
	public function test_meeting_details_appended_when_not_referenced() {
		MWM_Settings::set_group(
			[
				'confirmation_subject' => 'Confirmed',
				'confirmation_body'    => 'See you then!',
			],
			'email'
		);
		MWM_Settings::flush_cache( 'email' );

		( new MWM_Email() )->send_confirmation( $this->make_booking(), $this->make_event_type() );

		$this->assertNotEmpty( $this->captured );
		$this->assertStringContainsString( 'Join link: https://example.zoom.us/j/123456789', $this->captured[0]['message'] );
	}
}
