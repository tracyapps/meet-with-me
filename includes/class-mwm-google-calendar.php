<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Google Calendar integration.
 *
 * Handles OAuth 2.0 flow, freebusy reads, and event write-back.
 * Uses WordPress HTTP API throughout (no cURL, no external SDK).
 *
 * Token storage: mwm_google option (client_id, client_secret,
 * access_token, refresh_token, token_expiry, calendar_ids[],
 * write_back_calendar_id).
 */
class MWM_Google_Calendar {

	private const AUTH_URL            = 'https://accounts.google.com/o/oauth2/v2/auth';
	private const TOKEN_URL           = 'https://oauth2.googleapis.com/token';
	private const REVOKE_URL          = 'https://oauth2.googleapis.com/revoke';
	private const API_BASE            = 'https://www.googleapis.com/calendar/v3';
	private const CALENDAR_LIST_CACHE = 'mwm_gcal_calendar_list';
	private const SCOPE               = 'https://www.googleapis.com/auth/calendar.calendarlist.readonly https://www.googleapis.com/auth/calendar.events https://www.googleapis.com/auth/calendar.freebusy';

	// -------------------------------------------------------------------------
	// Status
	// -------------------------------------------------------------------------

	public static function is_connected(): bool {
		$cfg = MWM_Settings::get_all( 'google' );
		return ! empty( $cfg['client_id'] )
			&& ! empty( $cfg['client_secret'] )
			&& ! empty( $cfg['refresh_token'] );
	}

	public static function has_credentials(): bool {
		$cfg = MWM_Settings::get_all( 'google' );
		return ! empty( $cfg['client_id'] ) && ! empty( $cfg['client_secret'] );
	}

	public static function flush_calendar_list_cache(): void {
		delete_transient( self::CALENDAR_LIST_CACHE );
	}

	// -------------------------------------------------------------------------
	// OAuth flow
	// -------------------------------------------------------------------------

	/**
	 * Return the URL the admin should be redirected to in order to authorise.
	 */
	public static function get_oauth_url(): string {
		$state = wp_create_nonce( 'mwm_oauth_state' );
		set_transient( self::oauth_state_key(), $state, 5 * MINUTE_IN_SECONDS );

		return self::AUTH_URL . '?' . http_build_query(
			array(
				'client_id'     => MWM_Settings::get( 'client_id', 'google' ),
				'redirect_uri'  => self::redirect_uri(),
				'response_type' => 'code',
				'scope'         => self::SCOPE,
				'access_type'   => 'offline',
				'prompt'        => 'consent',
				'state'         => $state,
			)
		);
	}

	/**
	 * Exchange an authorisation code for access + refresh tokens.
	 * Returns true on success; stores tokens in settings.
	 */
	public static function exchange_code( string $code ): bool {
		$cfg      = MWM_Settings::get_all( 'google' );
		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'body'    => array(
					'code'          => $code,
					'client_id'     => $cfg['client_id'],
					'client_secret' => $cfg['client_secret'],
					'redirect_uri'  => self::redirect_uri(),
					'grant_type'    => 'authorization_code',
				),
				'timeout' => 15,
			)
		);

		return self::store_token_response( $response );
	}

	/**
	 * Refresh the access token using the stored refresh token.
	 */
	public static function refresh_access_token(): bool {
		$cfg      = MWM_Settings::get_all( 'google' );
		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'body'    => array(
					'client_id'     => $cfg['client_id'],
					'client_secret' => $cfg['client_secret'],
					'refresh_token' => $cfg['refresh_token'],
					'grant_type'    => 'refresh_token',
				),
				'timeout' => 15,
			)
		);

		return self::store_token_response( $response );
	}

	/**
	 * Return a valid access token, refreshing if it has expired or is close to expiry.
	 *
	 * @return string|false  Access token, or false if unavailable.
	 */
	public static function get_valid_access_token(): string|false {
		$cfg     = MWM_Settings::get_all( 'google' );
		$expiry  = (int) ( $cfg['token_expiry'] ?? 0 );
		$token   = $cfg['access_token'] ?? '';
		$refresh = $cfg['refresh_token'] ?? '';

		if ( ! $refresh ) {
			return false;
		}

		// Refresh 60 seconds before actual expiry
		if ( ! $token || time() >= $expiry - 60 ) {
			if ( ! self::refresh_access_token() ) {
				return false;
			}
			$cfg   = MWM_Settings::get_all( 'google' );
			$token = $cfg['access_token'] ?? '';
		}

		return $token ?: false;
	}

	/**
	 * Revoke tokens and clear all Google settings.
	 */
	public static function disconnect(): void {
		$token = MWM_Settings::get( 'access_token', 'google' );
		if ( $token ) {
			wp_remote_post(
				self::REVOKE_URL,
				array(
					'body'    => array( 'token' => $token ),
					'timeout' => 10,
				)
			);
		}

		MWM_Settings::set_group(
			array(
				'access_token'           => '',
				'refresh_token'          => '',
				'token_expiry'           => 0,
				'calendar_ids'           => array(),
				'write_back_calendar_id' => '',
			),
			'google'
		);

		MWM_Settings::flush_cache( 'google' );
		self::flush_calendar_list_cache();
	}

	// -------------------------------------------------------------------------
	// Calendar list
	// -------------------------------------------------------------------------

	/**
	 * Fetch the user's calendar list.
	 *
	 * @return array[]  Each item: { id, summary, primary }
	 */
	public static function get_calendar_list(): array {
		$cached = get_transient( self::CALENDAR_LIST_CACHE );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$token = self::get_valid_access_token();
		if ( ! $token ) {
			return array();
		}

		$response = wp_remote_get(
			self::API_BASE . '/users/me/calendarList',
			array(
				'headers' => array( 'Authorization' => 'Bearer ' . $token ),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return array();
		}

		$data  = json_decode( wp_remote_retrieve_body( $response ), true );
		$items = $data['items'] ?? array();

		$calendars = array_map(
			fn( $item ) => array(
				'id'          => $item['id'],
				'summary'     => $item['summary'] ?? $item['id'],
				'primary'     => ! empty( $item['primary'] ),
				'access_role' => $item['accessRole'] ?? '',
				'writable'    => in_array( $item['accessRole'] ?? '', array( 'owner', 'writer' ), true ),
			),
			$items
		);

		usort(
			$calendars,
			static function ( array $a, array $b ): int {
				if ( $a['primary'] !== $b['primary'] ) {
					return $a['primary'] ? -1 : 1;
				}
				if ( $a['writable'] !== $b['writable'] ) {
					return $a['writable'] ? -1 : 1;
				}
				return strcasecmp( $a['summary'], $b['summary'] );
			}
		);

		set_transient( self::CALENDAR_LIST_CACHE, $calendars, 5 * MINUTE_IN_SECONDS );
		return $calendars;
	}

	// -------------------------------------------------------------------------
	// Freebusy
	// -------------------------------------------------------------------------

	/**
	 * Get busy periods for a specific date (in admin timezone).
	 * Results are cached per transient for 10 minutes.
	 *
	 * @param string       $date      'Y-m-d' in admin timezone.
	 * @param DateTimeZone $admin_tz
	 * @return array[]  Each item: { start: DateTimeImmutable(UTC), end: DateTimeImmutable(UTC) }
	 */
	public static function get_busy_periods_for_date( string $date, DateTimeZone $admin_tz ): array {
		if ( ! self::is_connected() ) {
			return array();
		}

		$calendar_ids = MWM_Settings::get( 'calendar_ids', 'google' );
		if ( empty( $calendar_ids ) ) {
			return array();
		}

		$utc_tz    = new DateTimeZone( 'UTC' );
		$day_start = ( new DateTimeImmutable( "{$date} 00:00:00", $admin_tz ) )->setTimezone( $utc_tz );
		$day_end   = ( new DateTimeImmutable( "{$date} 23:59:59", $admin_tz ) )->setTimezone( $utc_tz );

		$raw = self::get_busy_for_range( $day_start, $day_end, $calendar_ids );

		return array_map(
			fn( $b ) => array(
				'start' => new DateTimeImmutable( $b['start'], $utc_tz ),
				'end'   => new DateTimeImmutable( $b['end'], $utc_tz ),
			),
			$raw
		);
	}

	/**
	 * Fetch raw freebusy data for a datetime range.
	 * Cached as a transient to avoid redundant calls during month scans.
	 *
	 * @param DateTimeImmutable $start
	 * @param DateTimeImmutable $end
	 * @param array             $calendar_ids
	 * @return array[]  Each item: { start: string(ISO8601), end: string(ISO8601) }
	 */
	private static function get_busy_for_range( DateTimeImmutable $start, DateTimeImmutable $end, array $calendar_ids ): array {
		$cache_key = 'mwm_freebusy_' . md5( $start->format( 'c' ) . $end->format( 'c' ) . implode( ',', $calendar_ids ) );
		$cached    = get_transient( $cache_key );

		if ( $cached !== false ) {
			return $cached;
		}

		$token = self::get_valid_access_token();
		if ( ! $token ) {
			return array();
		}

		$items    = array_map( fn( $id ) => array( 'id' => $id ), $calendar_ids );
		$response = wp_remote_post(
			self::API_BASE . '/freeBusy',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'timeMin' => $start->format( 'c' ),
						'timeMax' => $end->format( 'c' ),
						'items'   => $items,
					)
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return array();
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		$busy = array();

		foreach ( ( $data['calendars'] ?? array() ) as $cal_data ) {
			foreach ( ( $cal_data['busy'] ?? array() ) as $period ) {
				$busy[] = array(
					'start' => $period['start'],
					'end'   => $period['end'],
				);
			}
		}

		set_transient( $cache_key, $busy, 10 * MINUTE_IN_SECONDS );
		return $busy;
	}

	// -------------------------------------------------------------------------
	// Event write-back
	// -------------------------------------------------------------------------

	/**
	 * Create a Google Calendar event for a confirmed booking.
	 *
	 * @return string|false  Event ID on success, false on failure.
	 */
	public static function create_event( array $booking, array $event_type ): string|false {
		$event = self::create_event_with_options( $booking, $event_type );
		return is_array( $event ) ? ( $event['event_id'] ?? false ) : false;
	}

	/**
	 * Create a Google Calendar event and optionally request Google Meet data.
	 *
	 * @param array $booking   Booking row.
	 * @param array $event_type Event type row.
	 * @param array $args      {
	 *   @type string $conference_provider google_meet to request Meet conference data.
	 * }
	 */
	public static function create_event_with_options( array $booking, array $event_type, array $args = array() ): array|false {
		$calendar_id = MWM_Settings::get( 'write_back_calendar_id', 'google' );
		if ( ! $calendar_id ) {
			return false;
		}

		$token = self::get_valid_access_token();
		if ( ! $token ) {
			return false;
		}

		$admin_name  = MWM_Settings::get( 'admin_name' ) ?: get_option( 'blogname' );
		$admin_email = MWM_Settings::get( 'admin_email' ) ?: get_option( 'admin_email' );

		$description_lines = array_filter(
			array(
				/* translators: %s: WordPress site name. */
				sprintf( __( 'Booked via: %s', 'meet-with-me' ), get_option( 'blogname' ) ),
				/* translators: %s: meeting format label ("Online" or "In person"). */
				sprintf( __( 'Format: %s', 'meet-with-me' ), MWM_Booking::format_label( (string) $booking['meeting_type'] ) ),
				$booking['booker_notes']
					? sprintf(
						/* translators: %s: notes added by the booker */
						__( 'Notes: %s', 'meet-with-me' ),
						$booking['booker_notes']
					)
					: '',
			)
		);

		if ( ! empty( $booking['meeting_join_url'] ) ) {
			/* translators: %s: meeting join URL. */
			$description_lines[] = sprintf( __( 'Join link: %s', 'meet-with-me' ), $booking['meeting_join_url'] );
		}

		if ( ! empty( $booking['meeting_host_url'] ) ) {
			/* translators: %s: host start URL. */
			$description_lines[] = sprintf( __( 'Host link: %s', 'meet-with-me' ), $booking['meeting_host_url'] );
		}

		$description = implode( "\n", $description_lines );
		$location    = '';

		if ( $booking['meeting_type'] === 'online' ) {
			$location = ! empty( $booking['meeting_join_url'] ) ? $booking['meeting_join_url'] : __( 'Online', 'meet-with-me' );
		}

		$event = array(
			'summary'     => $event_type['name'] . ' — ' . $booking['booker_name'],
			'description' => $description,
			'start'       => array(
				'dateTime' => self::to_rfc3339( $booking['start_datetime'] ),
				'timeZone' => 'UTC',
			),
			'end'         => array(
				'dateTime' => self::to_rfc3339( $booking['end_datetime'] ),
				'timeZone' => 'UTC',
			),
			'attendees'   => array(
				array(
					'email'       => $admin_email,
					'displayName' => $admin_name,
					'organizer'   => true,
				),
				array(
					'email'       => $booking['booker_email'],
					'displayName' => $booking['booker_name'],
				),
			),
			'status'      => 'confirmed',
		);

		if ( $location ) {
			$event['location'] = $location;
		}

		$query_args = array( 'sendUpdates' => 'all' );
		if ( ( $args['conference_provider'] ?? '' ) === 'google_meet' ) {
			$query_args['conferenceDataVersion'] = 1;
			$event['conferenceData']             = array(
				'createRequest' => array(
					'requestId'             => wp_generate_uuid4(),
					'conferenceSolutionKey' => array(
						'type' => 'hangoutsMeet',
					),
				),
			);
		}

		$url      = add_query_arg( $query_args, self::API_BASE . '/calendars/' . rawurlencode( $calendar_id ) . '/events' );
		$response = wp_remote_post(
			$url,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $event ),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) < 200 || wp_remote_retrieve_response_code( $response ) >= 300 ) {
			return false;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['id'] ) ) {
			return false;
		}

		$meeting_join_url = self::extract_conference_url( $data );
		if ( ! $meeting_join_url && ( $args['conference_provider'] ?? '' ) === 'google_meet' ) {
			$details = self::get_event_details( (string) $data['id'], $calendar_id, $token );
			if ( is_array( $details ) ) {
				$data             = $details;
				$meeting_join_url = self::extract_conference_url( $data );
			}
		}

		return array(
			'event_id'         => (string) $data['id'],
			'html_link'        => (string) ( $data['htmlLink'] ?? '' ),
			'meeting_join_url' => $meeting_join_url ?: (string) ( $booking['meeting_join_url'] ?? '' ),
			'meeting_host_url' => (string) ( $booking['meeting_host_url'] ?? '' ),
			'meeting_data'     => array(
				'google_event_link' => (string) ( $data['htmlLink'] ?? '' ),
				'conference_data'   => $data['conferenceData'] ?? array(),
			),
		);
	}

	/**
	 * Delete a Google Calendar event.
	 */
	public static function delete_event( string $event_id, string $calendar_id = '' ): bool {
		if ( ! $calendar_id ) {
			$calendar_id = MWM_Settings::get( 'write_back_calendar_id', 'google' ) ?: 'primary';
		}

		$token = self::get_valid_access_token();
		if ( ! $token ) {
			return false;
		}

		$url      = self::API_BASE . '/calendars/' . rawurlencode( $calendar_id ) . '/events/' . rawurlencode( $event_id ) . '?sendUpdates=all';
		$response = wp_remote_request(
			$url,
			array(
				'method'  => 'DELETE',
				'headers' => array( 'Authorization' => 'Bearer ' . $token ),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}
		$code = wp_remote_retrieve_response_code( $response );
		return $code === 204 || $code === 200;
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	public static function redirect_uri(): string {
		return add_query_arg(
			array(
				'page'               => 'mwm-settings',
				'tab'                => 'google',
				'mwm_oauth_callback' => '1',
			),
			admin_url( 'admin.php' )
		);
	}

	private static function store_token_response( mixed $response ): bool {
		if ( is_wp_error( $response ) ) {
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return false;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['access_token'] ) ) {
			return false;
		}

		$updates = array(
			'access_token' => $data['access_token'],
			'token_expiry' => time() + (int) ( $data['expires_in'] ?? 3600 ),
		);

		if ( ! empty( $data['refresh_token'] ) ) {
			$updates['refresh_token'] = $data['refresh_token'];
		}

		MWM_Settings::set_group( $updates, 'google' );
		MWM_Settings::flush_cache( 'google' );
		self::flush_calendar_list_cache();
		return true;
	}

	private static function to_rfc3339( string $utc_datetime ): string {
		try {
			return ( new DateTimeImmutable( $utc_datetime, new DateTimeZone( 'UTC' ) ) )->format( 'c' );
		} catch ( \Exception $e ) {
			return $utc_datetime;
		}
	}

	private static function get_event_details( string $event_id, string $calendar_id, string $token ): array|false {
		$url      = add_query_arg( array( 'conferenceDataVersion' => 1 ), self::API_BASE . '/calendars/' . rawurlencode( $calendar_id ) . '/events/' . rawurlencode( $event_id ) );
		$response = wp_remote_get(
			$url,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return false;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $data ) ? $data : false;
	}

	private static function extract_conference_url( array $event ): string {
		if ( ! empty( $event['hangoutLink'] ) ) {
			return (string) $event['hangoutLink'];
		}

		foreach ( (array) ( $event['conferenceData']['entryPoints'] ?? array() ) as $entry_point ) {
			if ( ! empty( $entry_point['uri'] ) && in_array( $entry_point['entryPointType'] ?? '', array( 'video', 'more' ), true ) ) {
				return (string) $entry_point['uri'];
			}
		}

		return '';
	}

	private static function oauth_state_key(): string {
		return 'mwm_oauth_state_' . get_current_user_id();
	}
}
