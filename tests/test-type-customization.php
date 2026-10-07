<?php
/**
 * Per-type customization: question layout/display-for flags, per-type weekly
 * availability overrides, and the REST booking flow over filtered questions.
 *
 * @package meet-with-me
 */

class MWM_Type_Customization_Test extends WP_UnitTestCase {

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
				'admin_name'       => 'Custom test host',
				'admin_email'      => 'host@example.org',
				'min_notice_hours' => 0,
				'max_advance_days' => 365,
			)
		);
		update_option( 'mwm_google', array() );
		update_option( 'mwm_meetings', array() );
		MWM_Settings::flush_cache();
		$this->date = ( new DateTimeImmutable( 'next monday +2 weeks', new DateTimeZone( 'UTC' ) ) )->format( 'Y-m-d' );
		// Global default schedule: Mon–Fri 08:00–20:00.
		for ( $day = 1; $day <= 5; $day++ ) {
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
		$this->event_type = $this->make_event_type( 'custom-main', 'both' );
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

	private function make_event_type( string $slug, string $meeting_type = 'in_person', array $overrides = array() ): array {
		$id = MWM_Event_Type::create(
			array_merge(
				array(
					'name'             => 'Custom test ' . $slug,
					'slug'             => $slug,
					'duration_minutes' => 60,
					'meeting_type'     => $meeting_type,
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

	private function slot_times( string $slug, string $time ): array {
		$request = new WP_REST_Request( 'GET', '/mwm/v1/availability/slots' );
		$request->set_query_params(
			array(
				'event_type' => $slug,
				'date'       => $this->date,
				'tz'         => 'UTC',
			)
		);
		$response = $this->server->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		return array_map( fn( $s ) => substr( $s['start_utc'], 11, 5 ), $response->get_data()['slots'] );
	}

	// -------------------------------------------------------------------------
	// Fields schema
	// -------------------------------------------------------------------------

	public function test_field_layout_and_display_flags_round_trip() {
		$et = $this->make_event_type( 'fields-roundtrip', 'both', array(
			'fields' => wp_json_encode( array(
				array(
					'id'             => 'fq1',
					'label'          => 'Where?',
					'type'           => 'radio',
					'required'       => true,
					'options'        => array( 'Coffee', 'Office' ),
					'layout'         => 'inline',
					'show_online'    => false,
					'show_in_person' => true,
					'order'          => 0,
				),
			) ),
		) );

		$this->assertSame( 'inline', $et['fields'][0]['layout'] );
		$this->assertFalse( $et['fields'][0]['show_online'] );
		$this->assertTrue( $et['fields'][0]['show_in_person'] );
	}

	public function test_field_defaults_apply_for_missing_keys() {
		$et = $this->make_event_type( 'fields-defaults', 'both', array(
			'fields' => wp_json_encode( array(
				array( 'id' => 'fd1', 'label' => 'Notes?', 'type' => 'text', 'order' => 0 ),
			) ),
		) );

		$this->assertSame( 'stacked', $et['fields'][0]['layout'] );
		$this->assertTrue( $et['fields'][0]['show_online'] );
		$this->assertTrue( $et['fields'][0]['show_in_person'] );
	}

	public function test_visible_fields_filters_only_for_chooser_types() {
		$fields = array(
			array( 'id' => 'a', 'label' => 'A', 'show_online' => false, 'show_in_person' => true ),
			array( 'id' => 'b', 'label' => 'B' ),
		);

		$this->assertSame( array( 'b' ), array_column( MWM_Event_Type::visible_fields( $fields, 'online' ), 'id' ) );
		$this->assertSame( array( 'a', 'b' ), array_column( MWM_Event_Type::visible_fields( $fields, 'in_person' ), 'id' ) );
		// Fixed-format types ignore the flags entirely.
		$this->assertSame( array( 'a', 'b' ), array_column( MWM_Event_Type::visible_fields( $fields, 'both' ), 'id' ) );
	}

	// -------------------------------------------------------------------------
	// Per-type weekly availability override
	// -------------------------------------------------------------------------

	public function test_override_changes_windows_for_that_type_only() {
		$other = $this->make_event_type( 'custom-other', 'in_person' );
		$dow   = (int) ( new DateTimeImmutable( $this->date, new DateTimeZone( 'UTC' ) ) )->format( 'N' ) % 7;
		$this->assertContains( '10:00', $this->slot_times( 'custom-main', $this->date ), 'Global schedule should offer 10:00 before the override.' );

		$override = array( 'weekly' => array( $dow => array( 'enabled' => true, 'start' => '12:00', 'end' => '14:00' ) ) );
		MWM_Event_Type::update( $this->event_type['id'], array( 'availability_override' => wp_json_encode( $override ) ) );

		$mine  = $this->slot_times( 'custom-main', $this->date );
		$theirs = $this->slot_times( 'custom-other', $this->date );

		$this->assertSame( array( '12:00', '13:00' ), $mine, 'Overridden type should only offer the custom window (60-min slots).' );
		$this->assertContains( '10:00', $theirs, 'Other types must keep using the global schedule.' );
	}

	public function test_override_disabled_day_yields_no_slots() {
		$dow = (int) ( new DateTimeImmutable( $this->date, new DateTimeZone( 'UTC' ) ) )->format( 'N' ) % 7;
		MWM_Event_Type::update(
			$this->event_type['id'],
			array( 'availability_override' => wp_json_encode( array( 'weekly' => array( $dow => array( 'enabled' => false ) ) ) ) )
		);

		$this->assertSame( array(), $this->slot_times( 'custom-main', $this->date ) );
	}

	public function test_global_date_override_still_beats_type_weekly() {
		global $wpdb;
		$dow = (int) ( new DateTimeImmutable( $this->date, new DateTimeZone( 'UTC' ) ) )->format( 'N' ) % 7;
		MWM_Event_Type::update(
			$this->event_type['id'],
			array( 'availability_override' => wp_json_encode( array( 'weekly' => array( $dow => array( 'enabled' => true, 'start' => '09:00', 'end' => '17:00' ) ) ) ) )
		);
		// A global day-off for everyone wins over any weekly schedule.
		$wpdb->insert(
			$wpdb->prefix . 'mwm_blocked_dates',
			array( 'blocked_date' => $this->date, 'reason' => 'holiday' )
		);
		$wpdb->query( 'COMMIT' );

		$this->assertSame( array(), $this->slot_times( 'custom-main', $this->date ) );
	}

	public function test_override_never_stored_for_default_mode() {
		// Simulate the editor posting mode=default with a stray JSON payload:
		// the controller only passes the JSON through for custom mode, but the
		// model must also drop unparseable payloads to null.
		MWM_Event_Type::update( $this->event_type['id'], array( 'availability_override' => 'not json' ) );
		$et = MWM_Event_Type::get( $this->event_type['id'] );
		$this->assertNull( $et['availability_override'] );
		$this->assertContains( '10:00', $this->slot_times( 'custom-main', $this->date ) );
	}

	// -------------------------------------------------------------------------
	// Display-for questions through the booking flow
	// -------------------------------------------------------------------------

	private function book( string $slug, string $time, string $meeting_type, array $field_answers = array(), ?string $date = null ): WP_REST_Response {
		$start = new DateTimeImmutable( ( $date ?? $this->date ) . ' ' . $time, new DateTimeZone( 'UTC' ) );
		$request = new WP_REST_Request( 'POST', '/mwm/v1/bookings' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'event_type'    => $slug,
					'start_utc'     => $start->format( 'Y-m-d H:i:s' ),
					'end_utc'       => $start->modify( '+60 minutes' )->format( 'Y-m-d H:i:s' ),
					'timezone'      => 'UTC',
					'meeting_type'  => $meeting_type,
					'booker_name'   => 'Custom test visitor',
					'booker_email'  => 'visitor@example.org',
					'field_answers' => $field_answers,
				)
			)
		);
		return $this->server->dispatch( $request );
	}

	public function test_hidden_required_question_does_not_block_filtered_bookings() {
		MWM_Event_Type::update(
			$this->event_type['id'],
			array(
				'fields' => wp_json_encode( array(
					array(
						'id'             => 'fq9',
						'label'          => 'Office visit prep',
						'type'           => 'text',
						'required'       => true,
						'show_online'    => false,
						'show_in_person' => true,
						'order'          => 0,
					),
				) ),
			)
		);

		// Online booking: the question is hidden → no answer needed.
		$this->assertSame( 201, $this->book( 'custom-main', '10:00:00', 'online' )->get_status() );

		// In-person booking: the question is visible and required → 400.
		$this->assertSame( 400, $this->book( 'custom-main', '11:00:00', 'in_person' )->get_status() );

		// With the answer supplied, in-person succeeds.
		$this->assertSame( 201, $this->book( 'custom-main', '12:00:00', 'in_person', array( 'fq9' => 'Prep notes' ) )->get_status() );
	}
}
