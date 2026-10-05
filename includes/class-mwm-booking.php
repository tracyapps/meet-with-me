<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Booking data layer.
 *
 * All datetimes in the DB are stored in UTC.
 * Use MWM_Booking::format_datetime() to display in admin or booker timezone.
 */
class MWM_Booking {

    /**
     * Get a single booking by ID.
     */
    public static function get( int $id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}mwm_bookings WHERE id = %d", $id ),
            ARRAY_A
        );
        return $row ? self::parse( $row ) : null;
    }

    /**
     * Get a booking by its cancel or reschedule token.
     *
     * @param string $token
     * @param string $type  'cancel' or 'reschedule'
     */
    public static function get_by_token( string $token, string $type = 'cancel' ): ?array {
        global $wpdb;
        $col = $type === 'reschedule' ? 'reschedule_token' : 'cancel_token';
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}mwm_bookings WHERE {$col} = %s", $token ),
            ARRAY_A
        );
        return $row ? self::parse( $row ) : null;
    }

    /**
     * Get the booking row occupying an event-type slot, whatever its status.
     *
     * The UNIQUE KEY `slot_datetime (event_type_id, start_datetime)` allows at
     * most one row per slot across ALL statuses, so this returns the single
     * occupant (or null).
     */
    public static function get_by_slot( int $event_type_id, string $start_datetime ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}mwm_bookings WHERE event_type_id = %d AND start_datetime = %s LIMIT 1",
                $event_type_id,
                $start_datetime
            ),
            ARRAY_A
        );
        return $row ? self::parse( $row ) : null;
    }

    /**
     * Get a paginated, filtered list of bookings.
     *
     * @param array $args {
     *   status    string        'confirmed'|'cancelled'|'rescheduled'|'' for all
     *   search    string        Search booker_name or booker_email
     *   per_page  int           Default 20
     *   page      int           1-based
     *   orderby   string        'start_datetime'|'created_at'. Default 'start_datetime'.
     *   order     string        'ASC'|'DESC'. Default 'DESC'.
     * }
     * @return array[]
     */
    public static function get_all( array $args = [] ): array {
        global $wpdb;

        [ $where, $values ] = self::build_where( $args );

        $per_page = max( 1, (int) ( $args['per_page'] ?? 20 ) );
        $page     = max( 1, (int) ( $args['page'] ?? 1 ) );
        $offset   = ( $page - 1 ) * $per_page;

        $orderby_map = [ 'start_datetime' => 'b.start_datetime', 'created_at' => 'b.created_at' ];
        $orderby     = $orderby_map[ $args['orderby'] ?? '' ] ?? 'b.start_datetime';
        $order       = strtoupper( $args['order'] ?? 'DESC' ) === 'ASC' ? 'ASC' : 'DESC';

        $sql = "SELECT b.*, et.name AS event_type_name, et.duration_minutes, et.color AS event_type_color
                FROM {$wpdb->prefix}mwm_bookings b
                LEFT JOIN {$wpdb->prefix}mwm_event_types et ON b.event_type_id = et.id
                {$where}
                ORDER BY {$orderby} {$order}
                LIMIT %d OFFSET %d";

        $values[] = $per_page;
        $values[] = $offset;

        $rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$values ), ARRAY_A );
        return array_map( [ self::class, 'parse' ], $rows ?: [] );
    }

    /**
     * Count bookings matching the same args (without pagination).
     */
    public static function count( array $args = [] ): int {
        global $wpdb;
        [ $where, $values ] = self::build_where( $args );
        $sql = "SELECT COUNT(*) FROM {$wpdb->prefix}mwm_bookings b {$where}";
        return (int) $wpdb->get_var( empty( $values ) ? $sql : $wpdb->prepare( $sql, ...$values ) );
    }

    /**
     * Count bookings grouped by status. Returns ['confirmed'=>N, 'cancelled'=>N, 'rescheduled'=>N, 'total'=>N].
     */
    public static function count_by_status(): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT status, COUNT(*) AS n FROM {$wpdb->prefix}mwm_bookings GROUP BY status",
            ARRAY_A
        ) ?: [];

        $counts = [ 'confirmed' => 0, 'cancelled' => 0, 'rescheduled' => 0 ];
        $total  = 0;
        foreach ( $rows as $row ) {
            $counts[ $row['status'] ] = (int) $row['n'];
            $total += (int) $row['n'];
        }
        $counts['total'] = $total;
        return $counts;
    }

    /**
     * Create a new booking. Generates cancel/reschedule tokens automatically.
     *
     * @param array $data  Should include all required fields except tokens and timestamps.
     * @return int|false   New booking ID, or false on failure.
     */
    public static function create( array $data ): int|false {
        global $wpdb;

        $data['cancel_token']     = $data['cancel_token']     ?? self::generate_token();
        $data['reschedule_token'] = $data['reschedule_token'] ?? self::generate_token();

        if ( isset( $data['field_answers'] ) && is_array( $data['field_answers'] ) ) {
            $data['field_answers'] = wp_json_encode( $data['field_answers'] );
        }

        $clean = self::sanitize_for_db( $data );
        $result = $wpdb->insert( $wpdb->prefix . 'mwm_bookings', $clean );
        return $result ? (int) $wpdb->insert_id : false;
    }

    /**
     * Update specific fields on a booking.
     */
    public static function update( int $id, array $data ): bool {
        global $wpdb;
        if ( isset( $data['field_answers'] ) && is_array( $data['field_answers'] ) ) {
            $data['field_answers'] = wp_json_encode( $data['field_answers'] );
        }
        $clean = self::sanitize_for_db( $data );
        return $wpdb->update( $wpdb->prefix . 'mwm_bookings', $clean, [ 'id' => $id ], null, [ '%d' ] ) !== false;
    }

    /**
     * Reactivate a cancelled/rescheduled occupant row in place for a new booking.
     *
     * Used when a freed slot is booked again: the slot row is reused (there is
     * only ever one row per (event_type_id, start_datetime)) and refilled with
     * the new booking's data and freshly generated tokens.
     *
     * The UPDATE is guarded on the expected non-confirmed status so two
     * concurrent rebookings of the same freed slot cannot both succeed: the
     * loser's WHERE clause no longer matches (another request already flipped
     * the row to `confirmed`) and the affected-row count is 0. Callers treat
     * that like a lost race and keep the 409 response.
     *
     * @param int    $id               Occupant row ID.
     * @param string $expected_status  Status the occupant held when fetched ('cancelled'|'rescheduled').
     * @param array  $data             Full replacement payload (filtered through sanitize_for_db()).
     * @return bool  True when the row was reactivated; false on lost race or DB error.
     */
    public static function reactivate( int $id, string $expected_status, array $data ): bool {
        global $wpdb;

        if ( ! in_array( $expected_status, [ 'cancelled', 'rescheduled' ], true ) ) {
            return false;
        }

        if ( isset( $data['field_answers'] ) && is_array( $data['field_answers'] ) ) {
            $data['field_answers'] = wp_json_encode( $data['field_answers'] );
        }

        $clean = self::sanitize_for_db( $data );

        // Guarded UPDATE: only matches while the row still carries the
        // non-confirmed status we fetched, which makes the takeover atomic.
        $updated = $wpdb->update(
            $wpdb->prefix . 'mwm_bookings',
            $clean,
            [ 'id' => $id, 'status' => $expected_status ],
            null,
            [ '%d', '%s' ]
        );

        return is_int( $updated ) && $updated > 0;
    }

    /**
     * Cancel a booking by ID.
     */
    public static function cancel( int $id ): bool {
        global $wpdb;
        return (bool) $wpdb->update(
            $wpdb->prefix . 'mwm_bookings',
            [ 'status' => 'cancelled', 'updated_at' => current_time( 'mysql', true ) ],
            [ 'id' => $id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );
    }

    /**
     * Generate a cryptographically random 64-char hex token.
     */
    public static function generate_token(): string {
        return bin2hex( random_bytes( 32 ) );
    }

    /**
     * Translated label for a meeting format type.
     *
     * Single source of truth for the online/in_person/both labels used by
     * the REST API, emails, ICS files, Google Calendar descriptions, and views.
     *
     * @param string $type 'online'|'in_person'|'both'
     * @return string  Translated label, or the raw value for unknown types.
     */
    public static function format_label( string $type ): string {
        return match ( $type ) {
            'online'    => __( 'Online', 'meet-with-me' ),
            'in_person' => __( 'In person', 'meet-with-me' ),
            'both'      => __( 'Online or in person', 'meet-with-me' ),
            default     => $type,
        };
    }

    /**
     * Format a UTC datetime string for display in a given timezone.
     *
     * @param string $utc_datetime  'Y-m-d H:i:s' in UTC
     * @param string $timezone      IANA timezone string
     * @param string $format        PHP date format. Defaults to WP date+time format.
     */
    public static function format_datetime( string $utc_datetime, string $timezone, string $format = '' ): string {
        if ( ! $format ) {
            $format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
        }
        try {
            $dt = new DateTimeImmutable( $utc_datetime, new DateTimeZone( 'UTC' ) );
            $dt = $dt->setTimezone( new DateTimeZone( $timezone ) );
            return $dt->format( $format );
        } catch ( \Exception $e ) {
            return $utc_datetime;
        }
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    private static function parse( array $row ): array {
        $row['id']            = (int) $row['id'];
        $row['event_type_id'] = (int) $row['event_type_id'];
        $row['field_answers'] = ! empty( $row['field_answers'] )
            ? json_decode( $row['field_answers'], true ) : [];
        $row['meeting_data']  = ! empty( $row['meeting_data'] )
            ? json_decode( $row['meeting_data'], true ) : [];
        return $row;
    }

    private static function build_where( array $args ): array {
        $conditions = [];
        $values     = [];

        if ( ! empty( $args['status'] ) ) {
            $conditions[] = 'b.status = %s';
            $values[]     = $args['status'];
        }

        if ( ! empty( $args['search'] ) ) {
            $like         = '%' . $GLOBALS['wpdb']->esc_like( $args['search'] ) . '%';
            $conditions[] = '( b.booker_name LIKE %s OR b.booker_email LIKE %s )';
            $values[]     = $like;
            $values[]     = $like;
        }

        if ( ! empty( $args['event_type_id'] ) ) {
            $conditions[] = 'b.event_type_id = %d';
            $values[]     = (int) $args['event_type_id'];
        }

        $where = $conditions ? 'WHERE ' . implode( ' AND ', $conditions ) : '';
        return [ $where, $values ];
    }

    private static function sanitize_for_db( array $data ): array {
        if ( isset( $data['meeting_data'] ) && is_array( $data['meeting_data'] ) ) {
            $data['meeting_data'] = wp_json_encode( $data['meeting_data'] );
        }

        $allowed = [
            'event_type_id', 'booker_name', 'booker_email', 'booker_phone',
            'booker_notes', 'field_answers', 'start_datetime', 'end_datetime',
            'booker_timezone', 'meeting_type', 'meeting_provider', 'meeting_join_url',
            'meeting_host_url', 'meeting_data', 'status', 'cancel_token',
            'reschedule_token', 'gcal_event_id', 'admin_notes', 'created_at', 'updated_at',
        ];
        return array_intersect_key( $data, array_flip( $allowed ) );
    }
}
