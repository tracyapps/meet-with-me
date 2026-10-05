<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin controller for the Google Calendar settings tab.
 */
class MWM_Admin_Google {

	public function dispatch(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'meet-with-me' ) );
		}

		// OAuth callback from Google
		if ( isset( $_GET['mwm_oauth_callback'], $_GET['code'] ) ) {
			$this->handle_oauth_callback();
			return;
		}

		if ( isset( $_GET['mwm_oauth_callback'], $_GET['error'] ) ) {
			$this->redirect( 'oauth_denied' );
			return;
		}

		// AJAX: fetch calendar list
		if ( isset( $_POST['mwm_fetch_calendars'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified inside the handler
			$this->handle_fetch_calendars();
			return;
		}

		// Save credentials + settings
		if ( isset( $_POST['mwm_save_google'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified inside the handler
			$this->handle_save();
			return;
		}

		// Disconnect
		if ( isset( $_GET['mwm_google_action'] ) && sanitize_key( wp_unslash( $_GET['mwm_google_action'] ?? '' ) ) === 'disconnect' ) {
			$this->handle_disconnect();
			return;
		}

		$this->render();
	}

	// -------------------------------------------------------------------------
	// Write operations
	// -------------------------------------------------------------------------

	private function handle_oauth_callback(): void {
		// Verify state
		$state        = sanitize_text_field( wp_unslash( $_GET['state'] ?? '' ) );
		$state_key    = 'mwm_oauth_state_' . get_current_user_id();
		$stored_state = get_transient( $state_key );
		delete_transient( $state_key );

		if ( ! $state || ! is_string( $stored_state ) || ! hash_equals( $stored_state, $state ) ) {
			$this->redirect( 'oauth_error' );
			return;
		}

		$code   = sanitize_text_field( wp_unslash( $_GET['code'] ?? '' ) );
		$result = MWM_Google_Calendar::exchange_code( $code );

		$this->redirect( $result ? 'connected' : 'oauth_error' );
	}

	private function handle_save(): void {
		check_admin_referer( 'mwm_google_settings' );

		$existing  = MWM_Settings::get_all( 'google' );
		$client_id = sanitize_text_field( wp_unslash( $_POST['client_id'] ?? '' ) );

		// Never read a stored secret back into HTML: an empty submission keeps
		// the existing secret, a non-empty submission replaces it.
		$submitted_secret = sanitize_text_field( wp_unslash( $_POST['client_secret'] ?? '' ) );
		$client_secret    = $submitted_secret !== ''
			? $submitted_secret
			: (string) ( $existing['client_secret'] ?? '' );

		// Validate the client ID format when one is provided.
		if ( $client_id !== '' && ! str_ends_with( $client_id, '.apps.googleusercontent.com' ) ) {
			$this->redirect( 'invalid_client_id' );
			return;
		}

		$credentials_changed = $client_id !== ( $existing['client_id'] ?? '' )
			|| $client_secret !== ( $existing['client_secret'] ?? '' );

		// Save credentials
		$updates = array(
			'client_id'              => $client_id,
			'client_secret'          => $client_secret,
			'write_back_calendar_id' => sanitize_text_field( wp_unslash( $_POST['write_back_calendar_id'] ?? '' ) ),
			'calendar_ids'           => array_values(
				array_filter(
					array_map(
						'sanitize_text_field',
						(array) ( wp_unslash( $_POST['calendar_ids'] ?? array() ) )
					)
				)
			),
		);

		if ( $credentials_changed ) {
			$updates['access_token']           = '';
			$updates['refresh_token']          = '';
			$updates['token_expiry']           = 0;
			$updates['calendar_ids']           = array();
			$updates['write_back_calendar_id'] = '';
		}

		MWM_Settings::set_group( $updates, 'google' );

		MWM_Settings::flush_cache( 'google' );
		MWM_Google_Calendar::flush_calendar_list_cache();
		$this->redirect( 'saved' );
	}

	private function handle_disconnect(): void {
		check_admin_referer( 'mwm_google_disconnect' );
		MWM_Google_Calendar::disconnect();
		$this->redirect( 'disconnected' );
	}

	private function handle_fetch_calendars(): void {
		// Nonce field name must match the one rendered in google.php and sent by admin.js.
		check_admin_referer( 'mwm_fetch_calendars', 'mwm_fetch_cals_nonce' );

		if ( ! MWM_Google_Calendar::is_connected() ) {
			wp_send_json_error( array( 'message' => __( 'Not connected to Google Calendar.', 'meet-with-me' ) ) );
		}

		$calendars = MWM_Google_Calendar::get_calendar_list();
		if ( empty( $calendars ) ) {
			wp_send_json_error( array( 'message' => __( 'Could not fetch calendars. Check your connection.', 'meet-with-me' ) ) );
		}

		wp_send_json_success( array( 'calendars' => $calendars ) );
	}

	// -------------------------------------------------------------------------
	// Render
	// -------------------------------------------------------------------------

	private function render(): void {
		$current_tab     = 'google';
		$notice          = $this->get_notice();
		$settings        = MWM_Settings::get_all( 'google' );
		$is_connected    = MWM_Google_Calendar::is_connected();
		$has_credentials = MWM_Google_Calendar::has_credentials();
		$redirect_uri    = MWM_Google_Calendar::redirect_uri();
		$calendar_list   = $is_connected ? MWM_Google_Calendar::get_calendar_list() : array();
		require MWM_PLUGIN_DIR . 'admin/views/settings/google.php';
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function redirect( string $status ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'          => 'mwm-settings',
					'tab'           => 'google',
					'google_status' => $status,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	private function get_notice(): ?array {
		$s = sanitize_key( wp_unslash( $_GET['google_status'] ?? '' ) );
		if ( ! $s ) {
			return null;
		}
		return match ( $s ) {
			'connected'         => array(
				'type'    => 'success',
				'message' => __( 'Google Calendar connected successfully!', 'meet-with-me' ),
			),
			'disconnected'      => array(
				'type'    => 'success',
				'message' => __( 'Google Calendar disconnected.', 'meet-with-me' ),
			),
			'saved'             => array(
				'type'    => 'success',
				'message' => __( 'Settings saved.', 'meet-with-me' ),
			),
			'oauth_denied'      => array(
				'type'    => 'warning',
				'message' => __( 'Google authorisation was cancelled.', 'meet-with-me' ),
			),
			'oauth_error'       => array(
				'type'    => 'error',
				'message' => __( 'Could not connect to Google. Please try again.', 'meet-with-me' ),
			),
			'invalid_client_id' => array(
				'type'    => 'error',
				'message' => __( 'That does not look like a Google OAuth Client ID. It should end with ".apps.googleusercontent.com". Please check and try again.', 'meet-with-me' ),
			),
			default             => null,
		};
	}
}
