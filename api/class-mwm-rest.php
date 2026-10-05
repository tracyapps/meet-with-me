<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * REST API route registration and handlers.
 * Namespace: mwm/v1
 *
 * Routes:
 *   GET  /event-types
 *   GET  /availability/slots   ?event_type=slug&date=YYYY-MM-DD&tz=...
 *   GET  /availability/month   ?event_type=slug&year=N&month=N&tz=...
 *   POST /bookings
 */
class MWM_REST {

    public function register_routes(): void {
        register_rest_route( 'mwm/v1', '/event-types', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_event_types' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( 'mwm/v1', '/availability/slots', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_slots' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'event_type' => [
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_key',
                ],
                'date' => [
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => fn( $v ) => (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ),
                ],
                'tz' => [
                    'default'           => 'UTC',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ] );

        register_rest_route( 'mwm/v1', '/availability/month', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_month' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'event_type' => [ 'required' => true, 'sanitize_callback' => 'sanitize_key' ],
                'year'       => [ 'required' => true, 'sanitize_callback' => 'absint' ],
                'month'      => [ 'required' => true, 'sanitize_callback' => 'absint' ],
                'tz'         => [ 'default' => 'UTC', 'sanitize_callback' => 'sanitize_text_field' ],
            ],
        ] );

        register_rest_route( 'mwm/v1', '/bookings', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'create_booking' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( 'mwm/v1', '/bookings/(?P<id>\d+)/cancel', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'cancel_booking' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'id' => [ 'validate_callback' => fn( $v ) => is_numeric( $v ) ],
            ],
        ] );

        register_rest_route( 'mwm/v1', '/bookings/(?P<id>\d+)/reschedule', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'reschedule_booking' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'id' => [ 'validate_callback' => fn( $v ) => is_numeric( $v ) ],
            ],
        ] );
    }

    // -------------------------------------------------------------------------
    // Handlers
    // -------------------------------------------------------------------------

    public function get_event_types( WP_REST_Request $request ): WP_REST_Response {
        $types = MWM_Event_Type::get_all( true );
        $data  = array_map( fn( $et ) => $this->format_event_type( $et ), $types );
        return new WP_REST_Response( $data, 200 );
    }

    public function get_slots( WP_REST_Request $request ): WP_REST_Response {
        $slug = $request->get_param( 'event_type' );
        $date = $request->get_param( 'date' );
        $tz   = $this->safe_tz( $request->get_param( 'tz' ) );

        $event_type = MWM_Event_Type::get_by_slug( $slug );
        if ( ! $event_type || ! $event_type['is_active'] ) {
            return new WP_REST_Response( [ 'message' => __( 'Meeting type not found.', 'meet-with-me' ) ], 404 );
        }

        $slots = MWM_Availability::get_slots_for_date( $date, $event_type, $tz );

        return new WP_REST_Response( [
            'date'     => $date,
            'timezone' => $tz,
            'slots'    => $slots,
        ], 200 );
    }

    public function get_month( WP_REST_Request $request ): WP_REST_Response {
        $slug  = $request->get_param( 'event_type' );
        $year  = (int) $request->get_param( 'year' );
        $month = (int) $request->get_param( 'month' );
        $tz    = $this->safe_tz( $request->get_param( 'tz' ) );

        if ( $month < 1 || $month > 12 ) {
            return new WP_REST_Response( [ 'message' => __( 'Invalid month.', 'meet-with-me' ) ], 400 );
        }

        $event_type = MWM_Event_Type::get_by_slug( $slug );
        if ( ! $event_type || ! $event_type['is_active'] ) {
            return new WP_REST_Response( [ 'message' => __( 'Meeting type not found.', 'meet-with-me' ) ], 404 );
        }

        $available_dates = MWM_Availability::get_available_dates_for_month( $year, $month, $event_type, $tz );

        return new WP_REST_Response( [
            'year'            => $year,
            'month'           => $month,
            'available_dates' => $available_dates,
        ], 200 );
    }

    public function create_booking( WP_REST_Request $request ): WP_REST_Response {
        $body = $request->get_json_params() ?: [];

        // Honeypot
        if ( ! empty( $body['mwm_hp'] ) ) {
            return new WP_REST_Response( [ 'success' => true, 'booking' => [] ], 200 ); // silent discard
        }

        // Abuse mitigation: transient-based rate limiting per client IP.
        $limited = $this->check_rate_limit();
        if ( $limited ) {
            return $limited;
        }

        // Required fields
        $required = [ 'event_type', 'start_utc', 'end_utc', 'timezone', 'booker_name', 'booker_email' ];
        foreach ( $required as $field ) {
            if ( empty( $body[ $field ] ) ) {
                /* translators: %s = request field name */
                return new WP_REST_Response( [ 'message' => sprintf( __( 'Missing required field: %s', 'meet-with-me' ), $field ) ], 400 );
            }
        }

        $slug        = sanitize_key( $body['event_type'] );
        $start_utc   = sanitize_text_field( $body['start_utc'] );
        $end_utc     = sanitize_text_field( $body['end_utc'] );
        $tz          = $this->safe_tz( sanitize_text_field( $body['timezone'] ) );
        $email       = sanitize_email( $body['booker_email'] );
        $name        = sanitize_text_field( $body['booker_name'] );
        $meeting_type = sanitize_key( $body['meeting_type'] ?? 'online' );

        if ( ! is_email( $email ) ) {
            return new WP_REST_Response( [ 'message' => __( 'Please enter a valid email address.', 'meet-with-me' ) ], 400 );
        }

        if ( ! in_array( $meeting_type, [ 'online', 'in_person' ], true ) ) {
            $meeting_type = 'online';
        }

        $event_type = MWM_Event_Type::get_by_slug( $slug );
        if ( ! $event_type || ! $event_type['is_active'] ) {
            return new WP_REST_Response( [ 'message' => __( 'Meeting type not found.', 'meet-with-me' ) ], 404 );
        }

        // Validate meeting_type against event type config
        if ( $event_type['meeting_type'] !== 'both' ) {
            $meeting_type = $event_type['meeting_type'];
        }

        // Validate datetime formats
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $start_utc ) ||
             ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $end_utc ) ) {
            return new WP_REST_Response( [ 'message' => __( 'Invalid datetime format.', 'meet-with-me' ) ], 400 );
        }

        // Sanitize field answers
        $field_answers = [];
        if ( ! empty( $body['field_answers'] ) && is_array( $body['field_answers'] ) ) {
            foreach ( $event_type['fields'] ?? [] as $field ) {
                $fid = $field['id'];
                if ( ! isset( $body['field_answers'][ $fid ] ) ) {
                    continue;
                }
                $val = $body['field_answers'][ $fid ];
                if ( is_array( $val ) ) {
                    $field_answers[ $fid ] = array_map( 'sanitize_text_field', $val );
                } else {
                    $field_answers[ $fid ] = sanitize_textarea_field( (string) $val );
                }
            }
        }

        // Verify slot is still available immediately before insert.
        if ( ! MWM_Availability::is_slot_available( $start_utc, $end_utc, $event_type ) ) {
            return new WP_REST_Response( [ 'message' => __( 'Sorry, that time is no longer available. Please choose another slot.', 'meet-with-me' ) ], 409 );
        }

        // Create booking
        global $wpdb;
        $booking_id = MWM_Booking::create( [
            'event_type_id'   => $event_type['id'],
            'booker_name'     => $name,
            'booker_email'    => $email,
            'booker_phone'    => sanitize_text_field( $body['booker_phone'] ?? '' ),
            'booker_notes'    => sanitize_textarea_field( $body['booker_notes'] ?? '' ),
            'field_answers'   => $field_answers,
            'start_datetime'  => $start_utc,
            'end_datetime'    => $end_utc,
            'booker_timezone' => $tz,
            'meeting_type'    => $meeting_type,
            'status'          => 'confirmed',
        ] );

        if ( ! $booking_id ) {
            // The UNIQUE KEY slot_datetime (event_type_id, start_datetime) spans
            // ALL booking statuses, and cancel/reschedule keep their row (status
            // change only). A duplicate-key failure therefore does not necessarily
            // mean the slot is taken: it may be occupied by a cancelled/rescheduled
            // row whose slot is free again. Reactivate that row in place for this
            // booking instead of returning a false 409 (one row per slot is kept;
            // the index still serialises simultaneous inserts). A genuine
            // concurrent race — occupant still `confirmed` — keeps the 409.
            $is_duplicate = str_contains( (string) $wpdb->last_error, 'Duplicate entry' );

            if ( $is_duplicate ) {
                $booking_id = $this->reactivate_freed_slot( $event_type['id'], $start_utc, $end_utc, [
                    'booker_name'     => $name,
                    'booker_email'    => $email,
                    'booker_phone'    => sanitize_text_field( $body['booker_phone'] ?? '' ),
                    'booker_notes'    => sanitize_textarea_field( $body['booker_notes'] ?? '' ),
                    'field_answers'   => $field_answers,
                    'booker_timezone' => $tz,
                    'meeting_type'    => $meeting_type,
                ] );
            }

            if ( ! $booking_id ) {
                if ( $is_duplicate ) {
                    return new WP_REST_Response( [ 'message' => __( 'Sorry, that time was just taken. Please choose another slot.', 'meet-with-me' ) ], 409 );
                }
                return new WP_REST_Response( [ 'message' => __( 'Could not save your booking. Please try again.', 'meet-with-me' ) ], 500 );
            }
        }

        $booking = MWM_Booking::get( $booking_id );

        try {
            do_action( 'mwm_booking_confirmed', $booking, $event_type );
        } catch ( \Throwable $e ) {
            error_log( '[Meet With Me] Booking confirmation hooks failed: ' . $e->getMessage() );
        }

        $booking = MWM_Booking::get( $booking_id ) ?: $booking;

        // Build confirmation data for the frontend
        $start_local   = MWM_Booking::format_datetime( $start_utc, $tz, get_option( 'date_format' ) . ' \a\t ' . get_option( 'time_format' ) );
        $manage_url    = add_query_arg( [ 'mwm_token' => $booking['cancel_token'] ], home_url( '/' ) );

        return new WP_REST_Response( [
            'success' => true,
            'booking' => [
                'id'                => $booking_id,
                'booker_name'       => $name,
                'booker_email'      => $email,
                'event_type_name'   => $event_type['name'],
                'start_local'       => $start_local,
                'timezone'          => $tz,
                'meeting_type_label'=> MWM_Booking::format_label( $meeting_type ),
                'meeting_provider'  => MWM_Online_Meetings::get_provider_label( (string) ( $booking['meeting_provider'] ?? '' ) ),
                'meeting_join_url'  => $booking['meeting_join_url'] ?? '',
                'manage_url'        => $manage_url,
            ],
        ], 201 );
    }

    /**
     * Reactivate the occupant row of a freed slot for a replacement booking.
     *
     * The `slot_datetime` unique index allows exactly one row per
     * (event_type_id, start_datetime) across all statuses. Cancel/reschedule
     * keep their row (status change only), so a duplicate-key failure on a new
     * booking means the slot row exists but may be free. This method inspects
     * the occupant: a cancelled/rescheduled row is reused in place — refilled
     * with the new booking's data and FRESH cancel/reschedule tokens (the
     * previous booker still holds the old manage links, which must never grant
     * control over this booking). The row is reset to the same shape a fresh
     * insert produces (booking fields, cleared provider/calendar artifacts and
     * admin notes, timestamps as for a new booking), so the caller's success
     * path runs unchanged and provisions/notifies exactly once for THIS booking.
     *
     * @param int    $event_type_id
     * @param string $start_utc      Slot start ('Y-m-d H:i:s', UTC).
     * @param string $end_utc        Slot end ('Y-m-d H:i:s', UTC).
     * @param array  $data           New booking payload: booker_name, booker_email,
     *                               booker_phone, booker_notes, field_answers,
     *                               booker_timezone, meeting_type.
     * @return int|false             Booking ID on success; false when the slot is
     *                               genuinely taken or the takeover lost a race.
     */
    private function reactivate_freed_slot( int $event_type_id, string $start_utc, string $end_utc, array $data ): int|false {
        $occupant = MWM_Booking::get_by_slot( $event_type_id, $start_utc );

        if ( ! $occupant ) {
            // Duplicate key fired but no occupant row could be fetched — keep
            // the 409 path; log for diagnosis.
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( sprintf( '[Meet With Me] Slot duplicate without occupant row (event_type %d @ %s).', $event_type_id, $start_utc ) );
            }
            return false;
        }

        $occupant_status = (string) $occupant['status'];
        if ( ! in_array( $occupant_status, [ 'cancelled', 'rescheduled' ], true ) ) {
            // Still confirmed: a true concurrent race — keep the 409.
            return false;
        }

        // A fresh booking would have empty provider/calendar artifacts and no
        // admin notes; reset them so the confirmation hooks provision new
        // meeting resources and no stale links leak into the response.
        $now_utc = current_time( 'mysql', true );

        $reactivated = MWM_Booking::reactivate( (int) $occupant['id'], $occupant_status, [
            'event_type_id'    => $event_type_id,
            'booker_name'      => $data['booker_name'],
            'booker_email'     => $data['booker_email'],
            'booker_phone'     => $data['booker_phone'] ?? '',
            'booker_notes'     => $data['booker_notes'] ?? '',
            'field_answers'    => $data['field_answers'] ?? [],
            'start_datetime'   => $start_utc,
            'end_datetime'     => $end_utc,
            'booker_timezone'  => $data['booker_timezone'],
            'meeting_type'     => $data['meeting_type'],
            'status'           => 'confirmed',
            // Fresh tokens: the old links keep working only for the old booking.
            'cancel_token'     => MWM_Booking::generate_token(),
            'reschedule_token' => MWM_Booking::generate_token(),
            // Reset stale artifacts to fresh-insert shape.
            'meeting_provider' => '',
            'meeting_join_url' => null,
            'meeting_host_url' => null,
            'meeting_data'     => null,
            'gcal_event_id'    => null,
            'admin_notes'      => null,
            // `created_at` semantics match a new row: this booking is created
            // now (the previous booking's data is replaced wholesale).
            'created_at'       => $now_utc,
            'updated_at'       => $now_utc,
        ] );

        if ( ! $reactivated ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( sprintf( '[Meet With Me] Slot reactivation lost a race or failed (booking #%d).', (int) $occupant['id'] ) );
            }
            return false;
        }

        return (int) $occupant['id'];
    }

    public function cancel_booking( WP_REST_Request $request ): WP_REST_Response {
        $id   = (int) $request->get_param( 'id' );
        $body = $request->get_json_params() ?: [];

        $token = sanitize_text_field( $body['cancel_token'] ?? '' );
        if ( ! $token ) {
            return new WP_REST_Response( [ 'message' => __( 'Missing cancel token.', 'meet-with-me' ) ], 400 );
        }

        $booking = MWM_Booking::get( $id );
        // Constant-time comparison to avoid leaking token bytes via timing.
        if ( ! $booking || ! hash_equals( (string) $booking['cancel_token'], $token ) ) {
            return new WP_REST_Response( [ 'message' => __( 'Invalid token.', 'meet-with-me' ) ], 403 );
        }

        if ( $booking['status'] !== 'confirmed' ) {
            return new WP_REST_Response( [ 'message' => __( 'This booking is not active.', 'meet-with-me' ) ], 409 );
        }

        MWM_Booking::cancel( $id );

        $cancelled = MWM_Booking::get( $id );
        $event_type = MWM_Event_Type::get( $booking['event_type_id'] );

        try {
            do_action( 'mwm_booking_cancelled', $cancelled, $event_type );
        } catch ( \Throwable $e ) {
            error_log( '[Meet With Me] Booking cancellation hooks failed: ' . $e->getMessage() );
        }

        return new WP_REST_Response( [ 'success' => true ], 200 );
    }

    public function reschedule_booking( WP_REST_Request $request ): WP_REST_Response {
        $id   = (int) $request->get_param( 'id' );
        $body = $request->get_json_params() ?: [];

        $token     = sanitize_text_field( $body['reschedule_token'] ?? '' );
        $start_utc = sanitize_text_field( $body['start_utc']        ?? '' );
        $end_utc   = sanitize_text_field( $body['end_utc']          ?? '' );
        $tz        = $this->safe_tz( sanitize_text_field( $body['timezone'] ?? 'UTC' ) );

        if ( ! $token || ! $start_utc || ! $end_utc ) {
            return new WP_REST_Response( [ 'message' => __( 'Missing required fields.', 'meet-with-me' ) ], 400 );
        }

        $booking = MWM_Booking::get( $id );
        // Constant-time comparison to avoid leaking token bytes via timing.
        if ( ! $booking || ! hash_equals( (string) $booking['reschedule_token'], $token ) ) {
            return new WP_REST_Response( [ 'message' => __( 'Invalid token.', 'meet-with-me' ) ], 403 );
        }

        if ( $booking['status'] !== 'confirmed' ) {
            return new WP_REST_Response( [ 'message' => __( 'This booking is not active.', 'meet-with-me' ) ], 409 );
        }

        // Enforce minimum notice period
        $min_notice  = (int) MWM_Settings::get( 'min_notice_hours' );
        $now_utc     = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
        $meeting_utc = new DateTimeImmutable( $booking['start_datetime'], new DateTimeZone( 'UTC' ) );
        $hours_until = ( $meeting_utc->getTimestamp() - $now_utc->getTimestamp() ) / 3600;

        if ( $hours_until < $min_notice ) {
            return new WP_REST_Response( [ 'message' => __( 'Cannot reschedule this close to the meeting time.', 'meet-with-me' ) ], 409 );
        }

        // Validate datetime formats
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $start_utc ) ||
             ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $end_utc ) ) {
            return new WP_REST_Response( [ 'message' => __( 'Invalid datetime format.', 'meet-with-me' ) ], 400 );
        }

        $event_type = MWM_Event_Type::get( $booking['event_type_id'] );
        if ( ! $event_type ) {
            return new WP_REST_Response( [ 'message' => __( 'Meeting type not found.', 'meet-with-me' ) ], 404 );
        }

        // Verify slot is available immediately before insert
        // (pass old booking ID to exclude it from conflict check)
        if ( ! MWM_Availability::is_slot_available( $start_utc, $end_utc, $event_type, $id ) ) {
            return new WP_REST_Response( [ 'message' => __( 'Sorry, that time is no longer available. Please choose another slot.', 'meet-with-me' ) ], 409 );
        }

        // Create new booking inheriting all booker data from original
        global $wpdb;
        $new_id = MWM_Booking::create( [
            'event_type_id'   => $booking['event_type_id'],
            'booker_name'     => $booking['booker_name'],
            'booker_email'    => $booking['booker_email'],
            'booker_phone'    => $booking['booker_phone'],
            'booker_notes'    => $booking['booker_notes'],
            'field_answers'   => $booking['field_answers'],
            'start_datetime'  => $start_utc,
            'end_datetime'    => $end_utc,
            'booker_timezone' => $tz,
            'meeting_type'    => $booking['meeting_type'],
            'status'          => 'confirmed',
        ] );

        if ( ! $new_id ) {
            // Mirror create_booking: a freed slot (cancelled/rescheduled
            // occupant row) is reactivated in place for the rescheduled booking
            // rather than returning a false "just taken" 409.
            $is_duplicate = str_contains( (string) $wpdb->last_error, 'Duplicate entry' );

            if ( $is_duplicate ) {
                $new_id = $this->reactivate_freed_slot( (int) $booking['event_type_id'], $start_utc, $end_utc, [
                    'booker_name'     => $booking['booker_name'],
                    'booker_email'    => $booking['booker_email'],
                    'booker_phone'    => $booking['booker_phone'],
                    'booker_notes'    => $booking['booker_notes'],
                    'field_answers'   => $booking['field_answers'],
                    'booker_timezone' => $tz,
                    'meeting_type'    => $booking['meeting_type'],
                ] );
            }

            if ( ! $new_id ) {
                if ( $is_duplicate ) {
                    return new WP_REST_Response( [ 'message' => __( 'Sorry, that time was just taken. Please choose another slot.', 'meet-with-me' ) ], 409 );
                }
                return new WP_REST_Response( [ 'message' => __( 'Could not save your new booking. Please try again.', 'meet-with-me' ) ], 500 );
            }
        }

        // Mark original booking as rescheduled
        MWM_Booking::update( $id, [
            'status'     => 'rescheduled',
            'updated_at' => current_time( 'mysql', true ),
        ] );

        $new_booking = MWM_Booking::get( $new_id );

        try {
            do_action( 'mwm_booking_rescheduled', $new_booking, $booking, $event_type );
        } catch ( \Throwable $e ) {
            error_log( '[Meet With Me] Booking reschedule hooks failed: ' . $e->getMessage() );
        }

        $start_local = MWM_Booking::format_datetime(
            $start_utc,
            $tz,
            get_option( 'date_format' ) . ' \a\t ' . get_option( 'time_format' )
        );

        return new WP_REST_Response( [
            'success' => true,
            'booking' => [
                'id'              => $new_id,
                'event_type_name' => $event_type['name'],
                'start_local'     => $start_local,
                'timezone'        => $tz,
            ],
        ], 201 );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Transient-based rate limiting for public booking creation.
     *
     * Counts attempts per client IP address. Only `REMOTE_ADDR` is used — do
     * not trust `X-Forwarded-For` or similar headers, which are spoofable.
     *
     * Limits are filterable:
     *   - `mwm_booking_rate_limit_burst`  default 3 attempts per 5 minutes (0 disables)
     *   - `mwm_booking_rate_limit_hourly` default 5 attempts per hour   (0 disables)
     *
     * @return WP_REST_Response|null 429 response when limited, null when allowed.
     */
    private function check_rate_limit(): ?WP_REST_Response {
        $ip = isset( $_SERVER['REMOTE_ADDR'] )
            ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
            : '';

        if ( $ip === '' ) {
            return null; // Cannot rate limit without an address.
        }

        $ip_key       = md5( $ip );
        $burst_limit  = (int) apply_filters( 'mwm_booking_rate_limit_burst', 3 );
        $hourly_limit = (int) apply_filters( 'mwm_booking_rate_limit_hourly', 5 );
        $message      = __( 'Too many booking attempts from this connection. Please wait a few minutes and try again.', 'meet-with-me' );

        if ( $burst_limit > 0 ) {
            $key   = 'mwm_rl_burst_' . $ip_key;
            $count = (int) get_transient( $key );
            if ( $count >= $burst_limit ) {
                return new WP_REST_Response( [ 'message' => $message ], 429 );
            }
            set_transient( $key, $count + 1, 5 * MINUTE_IN_SECONDS );
        }

        if ( $hourly_limit > 0 ) {
            $key   = 'mwm_rl_hour_' . $ip_key;
            $count = (int) get_transient( $key );
            if ( $count >= $hourly_limit ) {
                return new WP_REST_Response( [ 'message' => $message ], 429 );
            }
            set_transient( $key, $count + 1, HOUR_IN_SECONDS );
        }

        return null;
    }

    private function format_event_type( array $et ): array {
        return [
            'id'               => $et['id'],
            'name'             => $et['name'],
            'slug'             => $et['slug'],
            'description'      => $et['description'],
            'duration_minutes' => $et['duration_minutes'],
            'meeting_type'     => $et['meeting_type'],
            'color'            => $et['color'],
            'fields'           => $et['fields'] ?? [],
        ];
    }

    private function safe_tz( string $tz ): string {
        try {
            new DateTimeZone( $tz );
            return $tz;
        } catch ( \Exception $e ) {
            return 'UTC';
        }
    }
}
