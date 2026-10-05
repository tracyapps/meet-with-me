<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin controller for the General settings tab.
 */
class MWM_Admin_General {

	public function dispatch(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'meet-with-me' ) );
		}

		if ( isset( $_POST['mwm_save_general'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified inside the handler
			$this->handle_save();
		}

		$this->render();
	}

	private function handle_save(): void {
		check_admin_referer( 'mwm_settings_general' );

		MWM_Settings::set_group(
			array(
				'admin_name'       => sanitize_text_field( wp_unslash( $_POST['admin_name'] ?? '' ) ),
				'admin_email'      => sanitize_email( wp_unslash( $_POST['admin_email'] ?? '' ) ),
				'timezone'         => sanitize_text_field( wp_unslash( $_POST['timezone'] ?? 'UTC' ) ),
				'min_notice_hours' => max( 0, absint( wp_unslash( $_POST['min_notice_hours'] ?? 24 ) ) ),
				'max_advance_days' => max( 1, absint( wp_unslash( $_POST['max_advance_days'] ?? 60 ) ) ),
			),
			'general'
		);

		MWM_Settings::flush_cache( 'general' );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'           => 'mwm-settings',
					'tab'            => 'general',
					'general_status' => 'saved',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	private function render(): void {
		$current_tab = 'general';
		$notice      = $this->get_notice();
		$settings    = MWM_Settings::get_all( 'general' );
		$timezones   = DateTimeZone::listIdentifiers();
		require MWM_PLUGIN_DIR . 'admin/views/settings/general.php';
	}

	private function get_notice(): ?array {
		$status = sanitize_key( wp_unslash( $_GET['general_status'] ?? '' ) );
		if ( ! $status ) {
			return null;
		}
		return match ( $status ) {
			'saved' => array(
				'type'    => 'success',
				'message' => __( 'Settings saved.', 'meet-with-me' ),
			),
			default => null,
		};
	}
}
