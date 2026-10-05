<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin controller for online meeting provider settings.
 */
class MWM_Admin_Meetings {

	public function dispatch(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'meet-with-me' ) );
		}

		if ( isset( $_POST['mwm_save_meetings'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified inside the handler
			$this->handle_save();
		}

		$this->render();
	}

	private function handle_save(): void {
		check_admin_referer( 'mwm_meeting_settings' );

		$existing = MWM_Settings::get_all( 'meetings' );

		// Never read a stored secret back into HTML: an empty submission keeps
		// the existing secret, a non-empty submission replaces it.
		$submitted_secret   = sanitize_text_field( wp_unslash( $_POST['zoom_client_secret'] ?? '' ) );
		$zoom_client_secret = $submitted_secret !== ''
			? $submitted_secret
			: (string) ( $existing['zoom_client_secret'] ?? '' );

		MWM_Settings::set_group(
			array(
				'zoom_account_id'       => sanitize_text_field( wp_unslash( $_POST['zoom_account_id'] ?? '' ) ),
				'zoom_client_id'        => sanitize_text_field( wp_unslash( $_POST['zoom_client_id'] ?? '' ) ),
				'zoom_client_secret'    => $zoom_client_secret,
				'zoom_user_id'          => sanitize_text_field( wp_unslash( $_POST['zoom_user_id'] ?? 'me' ) ),
				'zoom_default_password' => sanitize_text_field( wp_unslash( $_POST['zoom_default_password'] ?? '' ) ),
				'zoom_waiting_room'     => isset( $_POST['zoom_waiting_room'] ) ? 1 : 0,
				'zoom_join_before_host' => isset( $_POST['zoom_join_before_host'] ) ? 1 : 0,
			),
			'meetings'
		);
		MWM_Zoom::flush_token_cache();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'            => 'mwm-settings',
					'tab'             => 'meetings',
					'meetings_status' => 'saved',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	private function render(): void {
		$current_tab       = 'meetings';
		$settings          = MWM_Settings::get_all( 'meetings' );
		$zoom_ready        = MWM_Zoom::is_configured();
		$google_meet_ready = MWM_Online_Meetings::is_provider_available( 'google_meet' );
		$google_tab_url    = add_query_arg(
			array(
				'page' => 'mwm-settings',
				'tab'  => 'google',
			),
			admin_url( 'admin.php' )
		);
		$help_url          = add_query_arg(
			array(
				'page' => 'mwm-settings',
				'tab'  => 'help',
			),
			admin_url( 'admin.php' )
		) . '#mwm-help-zoom';
		$has_zoom_secret   = ! empty( $settings['zoom_client_secret'] );
		$notice_key        = sanitize_key( wp_unslash( $_GET['meetings_status'] ?? '' ) );
		$notice            = $notice_key === 'saved'
			? array(
				'type'    => 'success',
				'message' => __( 'Online meeting settings saved.', 'meet-with-me' ),
			)
			: null;

		require MWM_PLUGIN_DIR . 'admin/views/settings/meetings.php';
	}
}
