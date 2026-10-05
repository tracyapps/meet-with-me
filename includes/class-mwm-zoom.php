<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Zoom meeting creation and deletion via Server-to-Server OAuth.
 */
class MWM_Zoom {

	private const OAUTH_URL      = 'https://zoom.us/oauth/token';
	private const API_BASE       = 'https://api.zoom.us/v2';
	private const TOKEN_KEY      = 'mwm_zoom_access_token';
	private const TOKEN_EXPIRY   = 'mwm_zoom_access_token_expiry';
	private const SETTINGS_GROUP = 'meetings';

	public static function is_configured(): bool {
		$settings = MWM_Settings::get_all( self::SETTINGS_GROUP );

		return ! empty( $settings['zoom_account_id'] )
			&& ! empty( $settings['zoom_client_id'] )
			&& ! empty( $settings['zoom_client_secret'] )
			&& ! empty( $settings['zoom_user_id'] );
	}

	public static function flush_token_cache(): void {
		delete_transient( self::TOKEN_KEY );
		delete_transient( self::TOKEN_EXPIRY );
	}

	/**
	 * Test the stored credentials by requesting a fresh access token.
	 *
	 * @return true|WP_Error  True on success, WP_Error with a friendly message on failure.
	 */
	public static function test_connection(): bool|WP_Error {
		$settings = MWM_Settings::get_all( self::SETTINGS_GROUP );

		if ( empty( $settings['zoom_account_id'] ) || empty( $settings['zoom_client_id'] ) || empty( $settings['zoom_client_secret'] ) ) {
			return new WP_Error( 'mwm_zoom_not_configured', __( 'Add your Zoom Account ID, Client ID, and Client Secret first.', 'meet-with-me' ) );
		}

		self::flush_token_cache();
		$token = self::request_access_token( $settings );

		if ( is_wp_error( $token ) ) {
			return $token;
		}

		return true;
	}

	/**
	 * Create a scheduled Zoom meeting for a booking.
	 */
	public static function create_meeting( array $booking, array $event_type ): array|false {
		if ( ! self::is_configured() ) {
			return false;
		}

		$settings = MWM_Settings::get_all( self::SETTINGS_GROUP );
		$token    = self::get_access_token();
		if ( ! $token ) {
			self::record_booking_error( $booking, __( 'Zoom authentication failed. Check your Zoom credentials in Settings → Online Meetings.', 'meet-with-me' ) );
			return false;
		}

		$user_id = rawurlencode( (string) $settings['zoom_user_id'] );
		$url     = self::API_BASE . '/users/' . $user_id . '/meetings';

		$agenda_parts = array_filter(
			array(
				sprintf(
				/* translators: %s = site name */
					__( 'Booked via %s', 'meet-with-me' ),
					get_option( 'blogname' )
				),
				$booking['booker_notes']
						? sprintf(
							/* translators: %s: notes added by the booker */
							__( 'Notes: %s', 'meet-with-me' ),
							$booking['booker_notes']
						)
						: '',
			)
		);

		$payload = array(
			'topic'      => sprintf( '%s - %s', $event_type['name'], $booking['booker_name'] ),
			'type'       => 2,
			'start_time' => self::to_rfc3339( $booking['start_datetime'] ),
			'duration'   => max( 5, (int) $event_type['duration_minutes'] ),
			'timezone'   => 'UTC',
			'agenda'     => implode( "\n", $agenda_parts ),
			'settings'   => array(
				'join_before_host'  => ! empty( $settings['zoom_join_before_host'] ),
				'waiting_room'      => ! empty( $settings['zoom_waiting_room'] ),
				'participant_video' => true,
				'host_video'        => true,
			),
		);

		if ( ! empty( $settings['zoom_default_password'] ) ) {
			$payload['password'] = (string) $settings['zoom_default_password'];
		}

		$response = wp_remote_post(
			$url,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
				'timeout' => 20,
			)
		);

		if ( is_wp_error( $response ) ) {
			self::record_booking_error( $booking, $response->get_error_message() );
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$reason = is_array( $data ) ? ( $data['message'] ?? $data['reason'] ?? '' ) : '';
			self::record_booking_error(
				$booking,
				$reason !== ''
					? sprintf(
						/* translators: 1: HTTP status code, 2: error reason returned by Zoom */
						__( 'Zoom API error (%1$d): %2$s', 'meet-with-me' ),
						$code,
						sanitize_text_field( (string) $reason )
					)
					: sprintf(
						/* translators: %d = HTTP status code */
						__( 'Zoom API error (%d).', 'meet-with-me' ),
						$code
					)
			);
			return false;
		}

		if ( empty( $data['id'] ) ) {
			self::record_booking_error( $booking, __( 'Zoom response did not include a meeting ID.', 'meet-with-me' ) );
			return false;
		}

		return array(
			'meeting_provider' => 'zoom',
			'meeting_join_url' => (string) ( $data['join_url'] ?? '' ),
			'meeting_host_url' => (string) ( $data['start_url'] ?? '' ),
			'meeting_data'     => array(
				'zoom_meeting_id' => $data['id'],
				'password'        => $data['password'] ?? '',
				'topic'           => $data['topic'] ?? '',
			),
		);
	}

	/**
	 * Delete a scheduled Zoom meeting.
	 */
	public static function delete_meeting( string|int $meeting_id ): bool {
		$meeting_id = trim( (string) $meeting_id );
		if ( $meeting_id === '' ) {
			return false;
		}

		$token = self::get_access_token();
		if ( ! $token ) {
			return false;
		}

		$response = wp_remote_request(
			self::API_BASE . '/meetings/' . rawurlencode( $meeting_id ),
			array(
				'method'  => 'DELETE',
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
				),
				'timeout' => 20,
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );
		return $code === 204 || $code === 200;
	}

	/**
	 * Get a cached or fresh Zoom access token.
	 */
	private static function get_access_token(): string|false {
		$token  = get_transient( self::TOKEN_KEY );
		$expiry = (int) get_transient( self::TOKEN_EXPIRY );

		if ( is_string( $token ) && $token !== '' && $expiry > time() + 60 ) {
			return $token;
		}

		$settings = MWM_Settings::get_all( self::SETTINGS_GROUP );
		$result   = self::request_access_token( $settings );

		if ( is_wp_error( $result ) ) {
			return false;
		}

		return $result;
	}

	/**
	 * Request a fresh access token from Zoom and cache it.
	 *
	 * @return string|WP_Error  Token on success, WP_Error with a friendly message on failure.
	 */
	private static function request_access_token( array $settings ): string|WP_Error {
		$auth = base64_encode( $settings['zoom_client_id'] . ':' . $settings['zoom_client_secret'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP basic auth header

		$response = wp_remote_post(
			self::OAUTH_URL,
			array(
				'headers' => array(
					'Authorization' => 'Basic ' . $auth,
					'Content-Type'  => 'application/x-www-form-urlencoded',
				),
				'body'    => http_build_query(
					array(
						'grant_type' => 'account_credentials',
						'account_id' => $settings['zoom_account_id'],
					),
					'',
					'&'
				),
				'timeout' => 20,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'mwm_zoom_http_error', $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$reason = is_array( $data ) ? ( $data['reason'] ?? $data['error_description'] ?? $data['error'] ?? '' ) : '';

			return new WP_Error(
				'mwm_zoom_auth_failed',
				$reason !== ''
					? sprintf(
						/* translators: %s = error reason returned by Zoom */
						__( 'Zoom rejected the credentials: %s', 'meet-with-me' ),
						sanitize_text_field( (string) $reason )
					)
					: __( 'Could not authenticate with Zoom. Check your Account ID, Client ID, and Client Secret.', 'meet-with-me' )
			);
		}

		if ( empty( $data['access_token'] ) ) {
			return new WP_Error( 'mwm_zoom_no_token', __( 'Zoom did not return an access token.', 'meet-with-me' ) );
		}

		$expires_in = max( 300, (int) ( $data['expires_in'] ?? HOUR_IN_SECONDS ) );
		set_transient( self::TOKEN_KEY, $data['access_token'], $expires_in );
		set_transient( self::TOKEN_EXPIRY, time() + $expires_in, $expires_in );

		return (string) $data['access_token'];
	}

	/**
	 * Store the most recent booking-time Zoom failure for an admin notice.
	 */
	private static function record_booking_error( array $booking, string $reason ): void {
		if ( empty( $booking['id'] ) ) {
			return;
		}

		set_transient(
			'mwm_zoom_last_error',
			array(
				'booking_id' => (int) $booking['id'],
				'reason'     => $reason,
				'time'       => time(),
			),
			12 * HOUR_IN_SECONDS
		);
	}

	private static function to_rfc3339( string $utc_datetime ): string {
		try {
			return ( new DateTimeImmutable( $utc_datetime, new DateTimeZone( 'UTC' ) ) )->format( 'c' );
		} catch ( \Exception $e ) {
			return $utc_datetime;
		}
	}
}
