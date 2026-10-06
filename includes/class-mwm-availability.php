<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Availability calculation engine.
 *
 * Determines which time slots are bookable for a given date and event type,
 * accounting for: weekly schedule, day overrides, blocked dates, existing
 * bookings, buffer times, min notice, max advance, and per-day/week limits.
 *
 * All stored rule times are in the admin's configured timezone.
 * All booking datetimes are stored and compared in UTC.
 */
class MWM_Availability {

	private static ?WP_Error $last_error = null;

	public static function get_last_error(): ?WP_Error {
		return self::$last_error;
	}

	public static function valid_date( $value ): bool {
		if ( ! is_string( $value ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $value ) ) {
			return false;
		}
		return checkdate( (int) substr( $value, 5, 2 ), (int) substr( $value, 8, 2 ), (int) substr( $value, 0, 4 ) );
	}

	public static function valid_datetime( $value ): bool {
		if ( ! is_string( $value ) || ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) || ! self::valid_date( substr( $value, 0, 10 ) ) ) {
			return false;
		}
		$parsed = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new DateTimeZone( 'UTC' ) );
		return $parsed && $parsed->format( 'Y-m-d H:i:s' ) === $value;
	}

	private static function read_failed(): bool {
		global $wpdb;
		if ( $wpdb->last_error ) {
			self::$last_error = new WP_Error( 'mwm_availability_unavailable', __( 'Availability could not be verified. Please try again shortly.', 'meet-with-me' ), array( 'status' => 503 ) );
			return true;
		}
		return false;
	}

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Get all bookable time slots for a given date and event type.
	 *
	 * @param string $date        Date in 'Y-m-d' format (in admin timezone).
	 * @param array  $event_type  Parsed event type row from MWM_Event_Type.
	 * @param string $booker_tz   IANA timezone string for the booker (e.g. 'America/New_York').
	 * @return array[] Array of slot arrays: { start_utc, end_utc, start_local, start_iso }
	 */
	public static function get_slots_for_date( string $date, array $event_type, string $booker_tz = 'UTC', int $exclude_booking_id = 0 ): array {
		self::$last_error = null;
		if ( ! self::valid_date( $date ) ) {
			return array();
		}
		$admin_tz = self::admin_tz();
		$utc_tz   = new DateTimeZone( 'UTC' );

		// Check blocked date
		if ( self::is_date_blocked( $date ) ) {
			return array();
		}

		// Get availability windows for this date
		$windows = self::get_windows_for_date( $date, $admin_tz );
		if ( empty( $windows ) ) {
			return array();
		}

		// Check max advance booking window
		if ( ! self::is_date_within_max_advance_window( $date, $admin_tz ) ) {
			return array();
		}

		// Earliest bookable moment (now + min notice)
		$min_notice     = max( 0, (int) MWM_Settings::get( 'min_notice_hours' ) );
		$earliest_start = ( new DateTimeImmutable( 'now', $utc_tz ) )->modify( "+{$min_notice} hours" );

		$duration      = $event_type['duration_minutes'];
		$buffer_before = $event_type['buffer_before'];
		$buffer_after  = $event_type['buffer_after'];

		// Fetch existing confirmed bookings for the day (with buffer overlap padding)
		$day_start_utc = ( new DateTimeImmutable( "{$date} 00:00:00", $admin_tz ) )->setTimezone( $utc_tz );
		$day_end_utc   = ( new DateTimeImmutable( "{$date} 23:59:59", $admin_tz ) )->setTimezone( $utc_tz );
		$existing      = self::get_bookings_in_range(
			$day_start_utc->modify( '-' . max( 0, $buffer_before ) . ' minutes' ),
			$day_end_utc->modify( '+' . max( 0, $buffer_after ) . ' minutes' ),
			$exclude_booking_id
		);

		if ( self::$last_error ) {
			return array();
		}

		// Check max bookings per day for this event type
		if ( $event_type['max_per_day'] !== null ) {
			if ( self::count_bookings_for_date( $event_type['id'], $date, $exclude_booking_id ) >= $event_type['max_per_day'] ) {
				return array();
			}
		}

		// Check max bookings per week for this event type
		if ( $event_type['max_per_week'] !== null ) {
			if ( self::count_bookings_for_week( $event_type['id'], $date, $exclude_booking_id ) >= $event_type['max_per_week'] ) {
				return array();
			}
		}

		// Fetch Google Calendar busy periods for this day (empty if not connected)
		$gcal_busy = MWM_Google_Calendar::is_connected()
			? MWM_Google_Calendar::get_busy_periods_for_date( $date, $admin_tz )
			: array();

		if ( is_wp_error( $gcal_busy ) ) {
			self::$last_error = $gcal_busy;
			return array();
		}

		// Generate slots
		$slots         = array();
		$booker_tz_obj = self::safe_timezone( $booker_tz );

		foreach ( $windows as $window ) {
			$window_start = new DateTimeImmutable( "{$date} {$window['start']}:00", $admin_tz );
			$window_end   = new DateTimeImmutable( "{$date} {$window['end']}:00", $admin_tz );
			$slot_start   = $window_start;

			while ( true ) {
				$slot_end = $slot_start->modify( "+{$duration} minutes" );
				if ( $slot_end > $window_end ) {
					break;
				}

				$slot_start_utc = $slot_start->setTimezone( $utc_tz );
				$slot_end_utc   = $slot_end->setTimezone( $utc_tz );

				if ( $slot_start_utc >= $earliest_start ) {
					if ( ! self::slot_conflicts( $slot_start_utc, $slot_end_utc, $existing, $buffer_before, $buffer_after )
					&& ! self::slot_overlaps_gcal( $slot_start_utc, $slot_end_utc, $gcal_busy ) ) {
						$slot_start_local = $slot_start_utc->setTimezone( $booker_tz_obj );
						$slots[]          = array(
							'start_utc'   => $slot_start_utc->format( 'Y-m-d H:i:s' ),
							'end_utc'     => $slot_end_utc->format( 'Y-m-d H:i:s' ),
							'start_local' => $slot_start_local->format( 'g:i A' ),
							'start_iso'   => $slot_start_utc->format( 'c' ),
						);
					}
				}

				$slot_start = $slot_start->modify( "+{$duration} minutes" );
			}
		}

		return $slots;
	}

	/**
	 * Get all dates in a calendar month that have at least one available slot.
	 * Used by the frontend calendar to grey out unavailable dates.
	 *
	 * @param int    $year
	 * @param int    $month       1–12
	 * @param array  $event_type
	 * @param string $booker_tz
	 * @return string[]  Array of 'Y-m-d' date strings.
	 */
	public static function get_available_dates_for_month( int $year, int $month, array $event_type, string $booker_tz = 'UTC', int $exclude_booking_id = 0 ): array {
		self::$last_error = null;
		if ( $year < 1 || $year > 9999 || $month < 1 || $month > 12 ) {
			return array();
		}
		$admin_tz      = self::admin_tz();
		$first_day     = new DateTimeImmutable( sprintf( '%04d-%02d-01', $year, $month ), $admin_tz );
		$days_in_month = (int) $first_day->format( 't' );
		$available     = array();

		for ( $day = 1; $day <= $days_in_month; $day++ ) {
			$date = sprintf( '%04d-%02d-%02d', $year, $month, $day );
			if ( ! empty( self::get_slots_for_date( $date, $event_type, $booker_tz, $exclude_booking_id ) ) ) {
				$available[] = $date;
			}
			if ( self::$last_error ) {
				return array();
			}
		}

		return $available;
	}

	/**
	 * Verify that a specific UTC slot is still available immediately before booking.
	 * Runs all the same checks as get_slots_for_date() for one specific slot.
	 *
	 * @param string $start_utc          'Y-m-d H:i:s' in UTC
	 * @param string $end_utc            'Y-m-d H:i:s' in UTC
	 * @param array  $event_type
	 * @param int    $exclude_booking_id Optional booking ID to exclude from conflict check (for reschedule).
	 * @return bool
	 */
	public static function is_slot_available( string $start_utc, string $end_utc, array $event_type, int $exclude_booking_id = 0 ): bool {
		self::$last_error = null;
		if ( ! self::valid_datetime( $start_utc ) || ! self::valid_datetime( $end_utc ) ) {
			return false;
		}
		$utc_tz   = new DateTimeZone( 'UTC' );
		$admin_tz = self::admin_tz();

		$start = new DateTimeImmutable( $start_utc, $utc_tz );
		$end   = new DateTimeImmutable( $end_utc, $utc_tz );
		$date  = $start->setTimezone( $admin_tz )->format( 'Y-m-d' );

		if ( self::is_date_blocked( $date ) ) {
			return false;
		}

		$windows = self::get_windows_for_date( $date, $admin_tz );
		if ( empty( $windows ) ) {
			return false;
		}

		if ( ! self::slot_matches_schedule( $start, $end, $date, $windows, $event_type['duration_minutes'], $admin_tz, $utc_tz ) ) {
			return false;
		}

		// Min notice
		$min_notice = max( 0, (int) MWM_Settings::get( 'min_notice_hours' ) );
		if ( $start < ( new DateTimeImmutable( 'now', $utc_tz ) )->modify( "+{$min_notice} hours" ) ) {
			return false;
		}

		// Max advance
		if ( ! self::is_date_within_max_advance_window( $date, $admin_tz ) ) {
			return false;
		}

		// Conflict check
		$existing = self::get_bookings_in_range(
			$start->modify( '-' . max( 0, $event_type['buffer_before'] ) . ' minutes' ),
			$end->modify( '+' . max( 0, $event_type['buffer_after'] ) . ' minutes' ),
			$exclude_booking_id
		);
		if ( self::$last_error ) {
			return false;
		}
		if ( self::slot_conflicts( $start, $end, $existing, $event_type['buffer_before'], $event_type['buffer_after'] ) ) {
			return false;
		}

		if ( MWM_Google_Calendar::is_connected() ) {
			$gcal_busy = MWM_Google_Calendar::get_busy_periods_for_date( $date, $admin_tz, true );
			if ( is_wp_error( $gcal_busy ) ) {
				self::$last_error = $gcal_busy;
				return false;
			}
			if ( self::slot_overlaps_gcal( $start, $end, $gcal_busy ) ) {
				return false;
			}
		}

		// Per-day limit
		if ( $event_type['max_per_day'] !== null ) {
			if ( self::count_bookings_for_date( $event_type['id'], $date, $exclude_booking_id ) >= $event_type['max_per_day'] ) {
				return false;
			}
		}

		// Per-week limit
		if ( $event_type['max_per_week'] !== null ) {
			if ( self::count_bookings_for_week( $event_type['id'], $date, $exclude_booking_id ) >= $event_type['max_per_week'] ) {
				return false;
			}
		}

		return true;
	}

	// -------------------------------------------------------------------------
	// Schedule helpers (also used by admin UI)
	// -------------------------------------------------------------------------

	/**
	 * Get the availability windows for a specific date.
	 * Returns override rules if any exist; otherwise falls back to weekly rules.
	 * Returns [] if the day is marked unavailable or has no rules.
	 *
	 * @return array[]  [['start' => 'HH:MM', 'end' => 'HH:MM'], ...]
	 */
	public static function get_windows_for_date( string $date, ?DateTimeZone $admin_tz = null ): array {
		global $wpdb;
		$admin_tz = $admin_tz ?? self::admin_tz();

		// Check for overrides on this specific date
		$overrides = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}mwm_availability_rules
             WHERE rule_type = 'override' AND override_date = %s
             ORDER BY start_time ASC",
				$date
			),
			ARRAY_A
		);

		if ( self::read_failed() ) {
			return array();
		}

		if ( ! empty( $overrides ) ) {
			foreach ( $overrides as $o ) {
				if ( ! (int) $o['is_available'] ) {
					return array(); // Day marked as unavailable via override
				}
			}
			return array_map(
				fn( $o ) => array(
					'start' => substr( $o['start_time'], 0, 5 ),
					'end'   => substr( $o['end_time'], 0, 5 ),
				),
				$overrides
			);
		}

		// Fall back to weekly rules
		// PHP N format: 1=Mon...7=Sun. Our DB: 0=Sun, 1=Mon...6=Sat
		$day_of_week = (int) ( new DateTimeImmutable( $date, $admin_tz ) )->format( 'N' ) % 7;

		$rules = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}mwm_availability_rules
             WHERE rule_type = 'weekly' AND day_of_week = %d
             ORDER BY start_time ASC",
				$day_of_week
			),
			ARRAY_A
		);

		if ( self::read_failed() ) {
			return array();
		}

		if ( empty( $rules ) ) {
			return array();
		}

		foreach ( $rules as $r ) {
			if ( ! (int) $r['is_available'] ) {
				return array();
			}
		}

		return array_map(
			fn( $r ) => array(
				'start' => substr( $r['start_time'], 0, 5 ),
				'end'   => substr( $r['end_time'], 0, 5 ),
			),
			$rules
		);
	}

	/**
	 * Get all weekly schedule rules keyed by day_of_week (0=Sun...6=Sat).
	 * Returns an array of ['start','end','is_available'] per day, or null if no rule.
	 *
	 * @return array<int, array|null>
	 */
	public static function get_weekly_schedule(): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			"SELECT * FROM {$wpdb->prefix}mwm_availability_rules
             WHERE rule_type = 'weekly'
             ORDER BY day_of_week ASC, start_time ASC",
			ARRAY_A
		);

		$schedule = array_fill( 0, 7, null );
		foreach ( $rows as $row ) {
			$d = (int) $row['day_of_week'];
			// For simplicity we take the first rule per day (single window UI)
			if ( $schedule[ $d ] === null ) {
				$schedule[ $d ] = array(
					'id'           => (int) $row['id'],
					'start'        => substr( $row['start_time'], 0, 5 ),
					'end'          => substr( $row['end_time'], 0, 5 ),
					'is_available' => (bool) $row['is_available'],
				);
			}
		}

		return $schedule;
	}

	/**
	 * Get all date overrides.
	 *
	 * @return array[]
	 */
	public static function get_overrides(): array {
		global $wpdb;
		return $wpdb->get_results(
			"SELECT * FROM {$wpdb->prefix}mwm_availability_rules
             WHERE rule_type = 'override'
             ORDER BY override_date ASC",
			ARRAY_A
		) ?: array();
	}

	/**
	 * Get all blocked dates.
	 *
	 * @return array[]
	 */
	public static function get_blocked_dates(): array {
		global $wpdb;
		return $wpdb->get_results(
			"SELECT * FROM {$wpdb->prefix}mwm_blocked_dates ORDER BY blocked_date ASC",
			ARRAY_A
		) ?: array();
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	private static function is_date_blocked( string $date ): bool {
		global $wpdb;
		$blocked = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}mwm_blocked_dates WHERE blocked_date = %s",
				$date
			)
		);
		return self::read_failed() || (bool) $blocked;
	}

	private static function get_bookings_in_range( DateTimeImmutable $start, DateTimeImmutable $end, int $exclude_booking_id = 0 ): array {
		global $wpdb;
		// Compare buffered existing bounds with the proposed protected interval.
		// Fixed padding misses combined buffers and bookings across midnight.
		$sql  = "SELECT b.start_datetime, b.end_datetime, et.buffer_before, et.buffer_after
			FROM {$wpdb->prefix}mwm_bookings b
			LEFT JOIN {$wpdb->prefix}mwm_event_types et ON et.id = b.event_type_id
			WHERE b.status = 'confirmed'
			AND DATE_SUB(b.start_datetime, INTERVAL COALESCE(et.buffer_before, 0) MINUTE) < %s
			AND DATE_ADD(b.end_datetime, INTERVAL COALESCE(et.buffer_after, 0) MINUTE) > %s
			AND b.id != %d";
		$rows = $wpdb->get_results(
			$wpdb->prepare( $sql, $end->format( 'Y-m-d H:i:s' ), $start->format( 'Y-m-d H:i:s' ), $exclude_booking_id ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Literal query and three matching placeholders above.
			ARRAY_A
		);
		self::read_failed();
		return $rows ?: array();
	}

	private static function count_bookings_for_date( int $event_type_id, string $date, int $exclude_booking_id = 0 ): int {
		global $wpdb;
		$utc_tz   = new DateTimeZone( 'UTC' );
		$admin_tz = self::admin_tz();
		$s        = ( new DateTimeImmutable( "{$date} 00:00:00", $admin_tz ) )->setTimezone( $utc_tz );
		$e        = ( new DateTimeImmutable( "{$date} 23:59:59", $admin_tz ) )->setTimezone( $utc_tz );

		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}mwm_bookings
             WHERE event_type_id = %d AND status = 'confirmed' AND id != %d
               AND start_datetime >= %s AND start_datetime <= %s",
				$event_type_id,
				$exclude_booking_id,
				$s->format( 'Y-m-d H:i:s' ),
				$e->format( 'Y-m-d H:i:s' )
			)
		);
		return self::read_failed() ? PHP_INT_MAX : (int) $count;
	}

	private static function count_bookings_for_week( int $event_type_id, string $date, int $exclude_booking_id = 0 ): int {
		global $wpdb;
		$utc_tz   = new DateTimeZone( 'UTC' );
		$admin_tz = self::admin_tz();
		$d        = new DateTimeImmutable( $date, $admin_tz );
		$start    = $d->modify( 'monday this week' )->setTime( 0, 0, 0 )->setTimezone( $utc_tz );
		$end      = $d->modify( 'sunday this week' )->setTime( 23, 59, 59 )->setTimezone( $utc_tz );

		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}mwm_bookings
             WHERE event_type_id = %d AND status = 'confirmed' AND id != %d
               AND start_datetime >= %s AND start_datetime <= %s",
				$event_type_id,
				$exclude_booking_id,
				$start->format( 'Y-m-d H:i:s' ),
				$end->format( 'Y-m-d H:i:s' )
			)
		);
		return self::read_failed() ? PHP_INT_MAX : (int) $count;
	}

	/**
	 * Check whether a proposed slot (with buffers applied) overlaps any existing booking.
	 * Both the proposed slot and existing bookings have their buffer windows expanded.
	 */
	/**
	 * Check whether a slot overlaps any Google Calendar busy period (no buffer — hard blocks).
	 *
	 * @param DateTimeImmutable $slot_start
	 * @param DateTimeImmutable $slot_end
	 * @param array             $busy  Each item has 'start' and 'end' keys (DateTimeImmutable in UTC).
	 */
	private static function slot_overlaps_gcal( DateTimeImmutable $slot_start, DateTimeImmutable $slot_end, array $busy ): bool {
		foreach ( $busy as $period ) {
			if ( $slot_start < $period['end'] && $slot_end > $period['start'] ) {
				return true;
			}
		}
		return false;
	}

	private static function slot_conflicts(
		DateTimeImmutable $slot_start,
		DateTimeImmutable $slot_end,
		array $bookings,
		int $buffer_before,
		int $buffer_after
	): bool {
		$protected_start = $slot_start->modify( "-{$buffer_before} minutes" );
		$protected_end   = $slot_end->modify( "+{$buffer_after} minutes" );
		$utc_tz          = new DateTimeZone( 'UTC' );

		foreach ( $bookings as $booking ) {
			$ex_start           = new DateTimeImmutable( $booking['start_datetime'], $utc_tz );
			$ex_end             = new DateTimeImmutable( $booking['end_datetime'], $utc_tz );
			$ex_buffer_before   = isset( $booking['buffer_before'] ) ? max( 0, (int) $booking['buffer_before'] ) : $buffer_before;
			$ex_buffer_after    = isset( $booking['buffer_after'] ) ? max( 0, (int) $booking['buffer_after'] ) : $buffer_after;
			$ex_protected_start = $ex_start->modify( "-{$ex_buffer_before} minutes" );
			$ex_protected_end   = $ex_end->modify( "+{$ex_buffer_after} minutes" );

			if ( $protected_start < $ex_protected_end && $protected_end > $ex_protected_start ) {
				return true;
			}
		}

		return false;
	}

	private static function is_date_within_max_advance_window( string $date, DateTimeZone $admin_tz ): bool {
		try {
			$date_obj    = new DateTimeImmutable( $date, $admin_tz );
			$latest_date = ( new DateTimeImmutable( 'today', $admin_tz ) )->modify(
				'+' . max( 1, (int) MWM_Settings::get( 'max_advance_days' ) ) . ' days'
			);
		} catch ( \Exception $e ) {
			return false;
		}

		return $date_obj <= $latest_date;
	}

	private static function slot_matches_schedule(
		DateTimeImmutable $start,
		DateTimeImmutable $end,
		string $date,
		array $windows,
		int $duration,
		DateTimeZone $admin_tz,
		DateTimeZone $utc_tz
	): bool {
		$duration = max( 1, $duration );
		if ( ( $end->getTimestamp() - $start->getTimestamp() ) !== $duration * MINUTE_IN_SECONDS ) {
			return false;
		}

		foreach ( $windows as $window ) {
			$window_start = new DateTimeImmutable( "{$date} {$window['start']}:00", $admin_tz );
			$window_end   = new DateTimeImmutable( "{$date} {$window['end']}:00", $admin_tz );

			$window_start_utc = $window_start->setTimezone( $utc_tz );
			$window_end_utc   = $window_end->setTimezone( $utc_tz );

			if ( $start < $window_start_utc || $end > $window_end_utc ) {
				continue;
			}

			$offset_seconds = $start->getTimestamp() - $window_start_utc->getTimestamp();
			return $offset_seconds % ( $duration * MINUTE_IN_SECONDS ) === 0;
		}

		return false;
	}

	private static function admin_tz(): DateTimeZone {
		$tz = MWM_Settings::get( 'timezone' ) ?: 'UTC';
		try {
			return new DateTimeZone( $tz );
		} catch ( \Exception $e ) {
			return new DateTimeZone( 'UTC' );
		}
	}

	private static function safe_timezone( string $tz ): DateTimeZone {
		try {
			return new DateTimeZone( $tz );
		} catch ( \Exception $e ) {
			return new DateTimeZone( 'UTC' );
		}
	}
}
