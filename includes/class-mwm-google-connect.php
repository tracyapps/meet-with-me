<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One-click Google connection via the plugins.tapps.design connect relay.
 *
 * Google requires an exact-match redirect URI, so a shared OAuth client can
 * never redirect back to arbitrary WordPress domains. The relay is the fixed
 * redirect target: it exchanges the authorization code (keeping the client
 * secret server-side) and hands the tokens back to this site.
 *
 * Handshake (mirrors relay/connect/index.php):
 *   1. get_oauth_url() sends the admin to Google with
 *      state = base64url( { r: return url, t: ticket, x: expiry } )
 *      and the ticket stored in a transient.
 *   2. Google redirects to the relay; the relay exchanges the code and
 *      redirects the browser back to the return URL with &otc=<one-time code>.
 *   3. handle_relay_callback() redeems { otc, ticket } at the relay. The
 *      ticket binds the handoff to this site. The response carries the
 *      tokens plus the OAuth client credentials, which are stored so the
 *      existing MWM_Google_Calendar refresh flow keeps working untouched.
 *
 * After connect, this site talks to Google directly — the relay is never in
 * the runtime path.
 */
class MWM_Google_Connect {

	private const RELAY_URL      = 'https://plugins.tapps.design/connect';
	private const SCOPE          = 'https://www.googleapis.com/auth/calendar.events https://www.googleapis.com/auth/calendar.calendarlist.readonly https://www.googleapis.com/auth/calendar.freebusy';
	private const TICKET_TTL     = 600;   // seconds the ticket stays valid.
	private const OAUTH_AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

	/**
	 * The shared OAuth client id. Empty until configured via setting or
	 * filter — the UI falls back to the bring-your-own-credentials flow.
	 */
	private const DEFAULT_CLIENT_ID = '';

	public static function relay_url(): string {
		/**
		 * Point the plugin at a different relay (e.g. a local one during
		 * development: http://127.0.0.1:8788).
		 *
		 * @param string $relay_url Base URL of the connect relay.
		 */
		return apply_filters( 'mwm_google_relay_url', self::RELAY_URL );
	}

	public static function client_id(): string {
		$setting = MWM_Settings::get( 'relay_client_id', 'google' );
		$id      = $setting !== '' && $setting !== null ? $setting : self::DEFAULT_CLIENT_ID;

		/**
		 * Override the shared OAuth client id (e.g. for testing against your
		 * own Google project while the shared one is pending verification).
		 *
		 * @param string $client_id The OAuth client id used by the relay flow.
		 */
		return apply_filters( 'mwm_google_relay_client_id', $id );
	}

	public static function is_available(): bool {
		return self::client_id() !== '';
	}

	/**
	 * The URL Google redirects to after consent — the relay's fixed callback.
	 */
	public static function redirect_uri(): string {
		return self::relay_url() . '/google/callback';
	}

	/**
	 * Encode the OAuth state payload: base64url JSON { r, t, x }.
	 */
	public static function encode_state( string $return_url, string $ticket, int $expires_at ): string {
		$payload = wp_json_encode(
			array(
				'r' => $return_url,
				't' => $ticket,
				'x' => $expires_at,
			)
		);
		return rtrim( strtr( base64_encode( $payload ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- URL-safe transport encoding
	}

	/**
	 * Decode and validate a state payload. Returns array{r,t} or null.
	 */
	public static function decode_state( string $state ): ?array {
		$pad = strlen( $state ) % 4;
		if ( $pad ) {
			$state .= str_repeat( '=', 4 - $pad );
		}
		$raw = base64_decode( strtr( $state, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- URL-safe transport decoding of our own state payload
		if ( ! is_string( $raw ) ) {
			return null;
		}
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			return null;
		}
		$return = (string) ( $data['r'] ?? '' );
		$ticket = (string) ( $data['t'] ?? '' );
		$exp    = (int) ( $data['x'] ?? 0 );
		if ( $return === '' || $ticket === '' || $exp < time() || $exp > time() + self::TICKET_TTL + 60 ) {
			return null;
		}
		return array(
			'return' => $return,
			'ticket' => $ticket,
		);
	}

	/**
	 * Build the Google authorization URL for the relay flow and stash the
	 * ticket so the callback can verify this site started the flow.
	 */
	public static function get_oauth_url(): string {
		$ticket = bin2hex( random_bytes( 20 ) );
		set_transient( self::ticket_key( $ticket ), time(), self::TICKET_TTL );

		$return_url = add_query_arg(
			array(
				'page'         => 'mwm-settings',
				'tab'          => 'google',
				'mwm_relay_cb' => '1',
				'ticket'       => $ticket,
			),
			admin_url( 'admin.php' )
		);

		$state = self::encode_state( $return_url, $ticket, time() + self::TICKET_TTL );

		return self::OAUTH_AUTH_URL . '?' . build_query(
			array(
				'client_id'     => self::client_id(),
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
	 * Redeem a one-time code at the relay. Returns the token payload
	 * (access_token, refresh_token, expires_in, client_id, client_secret)
	 * or a WP_Error.
	 */
	public static function redeem( string $ticket, string $otc ): array|WP_Error {
		$response = wp_remote_post(
			self::relay_url() . '/google/redeem',
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'otc'    => $otc,
						'ticket' => $ticket,
					)
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code !== 200 || ! is_array( $body ) || empty( $body['access_token'] ) ) {
			return new WP_Error(
				'mwm_relay_redeem_failed',
				sprintf(
					/* translators: %d: HTTP status code returned by the connect relay. */
					__( 'The connect service rejected the handoff (HTTP %d). Please start the connection again.', 'meet-with-me' ),
					$code
				)
			);
		}

		return $body;
	}

	/**
	 * Store a redeemed relay payload using the same settings the
	 * bring-your-own-credentials flow uses, so token refresh and every
	 * existing calendar feature work unchanged.
	 */
	public static function store_tokens( array $payload ): void {
		MWM_Settings::set_group(
			array(
				'connect_mode'  => 'relay',
				'client_id'     => (string) ( $payload['client_id'] ?? '' ),
				'client_secret' => (string) ( $payload['client_secret'] ?? '' ),
				'access_token'  => (string) ( $payload['access_token'] ?? '' ),
				'refresh_token' => (string) ( $payload['refresh_token'] ?? '' ),
				'token_expiry'  => time() + (int) ( $payload['expires_in'] ?? 3600 ),
			),
			'google'
		);
		MWM_Settings::flush_cache( 'google' );
		MWM_Google_Calendar::flush_calendar_list_cache();
	}

	/**
	 * Whether this site is waiting on a handoff started with this ticket.
	 */
	public static function ticket_is_pending( string $ticket ): bool {
		return (bool) get_transient( self::ticket_key( $ticket ) );
	}

	public static function forget_ticket( string $ticket ): void {
		delete_transient( self::ticket_key( $ticket ) );
	}

	private static function ticket_key( string $ticket ): string {
		return 'mwm_relay_ticket_' . $ticket;
	}
}
