<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin controller for the Availability settings tab.
 */
class MWM_Admin_Availability {

	public function dispatch(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'meet-with-me' ) );
		}

		$this->render();
	}

	/**
	 * Run write actions on the load-{page} hook, before WordPress renders the
	 * admin header — redirects and JSON responses go out header-clean.
	 */
	public function handle_actions(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'meet-with-me' ) );
		}

		// All write operations go through POST or GET action+nonce
		if ( isset( $_POST['mwm_save_schedule'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified inside the handler
			$this->handle_save_schedule();
		} elseif ( isset( $_POST['mwm_add_override'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified inside the handler
			$this->handle_add_override();
		} elseif ( isset( $_POST['mwm_add_blocked'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified inside the handler
			$this->handle_add_blocked();
		} elseif ( isset( $_GET['avail_action'] ) ) {
			$this->handle_get_action();
		}
	}

	// -------------------------------------------------------------------------
	// Write operations
	// -------------------------------------------------------------------------

	private function handle_save_schedule(): void {
		check_admin_referer( 'mwm_availability_schedule' );
		global $wpdb;

		// Delete all existing weekly rules and re-insert
		$wpdb->delete( $wpdb->prefix . 'mwm_availability_rules', array( 'rule_type' => 'weekly' ), array( '%s' ) );

		$days = array_map(
			static fn( $day ): array => array_map( 'sanitize_text_field', (array) $day ),
			(array) wp_unslash( $_POST['days'] ?? array() ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each leaf sanitized by the array_map above
		);
		foreach ( range( 0, 6 ) as $dow ) {
			$day_data = $days[ $dow ] ?? array();
			$enabled  = ! empty( $day_data['enabled'] );
			if ( ! $enabled ) {
				continue;
			}

			$start = sanitize_text_field( $day_data['start'] ?? '09:00' );
			$end   = sanitize_text_field( $day_data['end'] ?? '17:00' );

			// Basic validation: start must be before end
			if ( strtotime( $start ) >= strtotime( $end ) ) {
				continue;
			}

			$wpdb->insert(
				$wpdb->prefix . 'mwm_availability_rules',
				array(
					'rule_type'    => 'weekly',
					'day_of_week'  => $dow,
					'start_time'   => $start . ':00',
					'end_time'     => $end . ':00',
					'is_available' => 1,
				)
			);
		}

		$this->redirect( 'saved' );
	}

	private function handle_add_override(): void {
		check_admin_referer( 'mwm_add_override' );
		global $wpdb;

		$date      = sanitize_text_field( wp_unslash( $_POST['override_date'] ?? '' ) );
		$available = sanitize_key( wp_unslash( $_POST['override_available'] ?? '' ) ) === '1';
		$start     = sanitize_text_field( wp_unslash( $_POST['override_start'] ?? '09:00' ) );
		$end       = sanitize_text_field( wp_unslash( $_POST['override_end'] ?? '17:00' ) );

		if ( ! $date || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			$this->redirect( 'error_override' );
			return;
		}

		// Delete any existing overrides for this date first (replace, not append)
		$wpdb->delete(
			$wpdb->prefix . 'mwm_availability_rules',
			array(
				'rule_type'     => 'override',
				'override_date' => $date,
			),
			array( '%s', '%s' )
		);

		$wpdb->insert(
			$wpdb->prefix . 'mwm_availability_rules',
			array(
				'rule_type'     => 'override',
				'override_date' => $date,
				'start_time'    => $available ? $start . ':00' : '00:00:00',
				'end_time'      => $available ? $end . ':00' : '00:00:00',
				'is_available'  => $available ? 1 : 0,
			)
		);

		$this->redirect( 'saved' );
	}

	private function handle_add_blocked(): void {
		check_admin_referer( 'mwm_add_blocked' );
		global $wpdb;

		$date   = sanitize_text_field( wp_unslash( $_POST['blocked_date'] ?? '' ) );
		$reason = sanitize_text_field( wp_unslash( $_POST['blocked_reason'] ?? '' ) );

		if ( ! $date || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			$this->redirect( 'error_blocked' );
			return;
		}

		// INSERT IGNORE equivalent — use replace
		$wpdb->replace(
			$wpdb->prefix . 'mwm_blocked_dates',
			array(
				'blocked_date' => $date,
				'reason'       => $reason,
			)
		);

		$this->redirect( 'saved' );
	}

	private function handle_get_action(): void {
		global $wpdb;
		$action = sanitize_key( wp_unslash( $_GET['avail_action'] ?? '' ) );

		if ( $action === 'delete_override' && isset( $_GET['id'] ) ) {
			$id = absint( wp_unslash( $_GET['id'] ) );
			check_admin_referer( 'mwm_delete_override_' . $id );
			$wpdb->delete( $wpdb->prefix . 'mwm_availability_rules', array( 'id' => $id ), array( '%d' ) );
			$this->redirect( 'saved' );
		}

		if ( $action === 'delete_blocked' && isset( $_GET['id'] ) ) {
			$id = absint( wp_unslash( $_GET['id'] ) );
			check_admin_referer( 'mwm_delete_blocked_' . $id );
			$wpdb->delete( $wpdb->prefix . 'mwm_blocked_dates', array( 'id' => $id ), array( '%d' ) );
			$this->redirect( 'saved' );
		}
	}

	// -------------------------------------------------------------------------
	// Render
	// -------------------------------------------------------------------------

	private function render(): void {
		$current_tab   = 'availability';
		$notice        = $this->get_notice();
		$schedule      = MWM_Availability::get_weekly_schedule();
		$overrides     = MWM_Availability::get_overrides();
		$blocked_dates = MWM_Availability::get_blocked_dates();
		require MWM_PLUGIN_DIR . 'admin/views/settings/availability.php';
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function redirect( string $status ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'         => 'mwm-settings',
					'tab'          => 'availability',
					'avail_status' => $status,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	private function get_notice(): ?array {
		$status = sanitize_key( wp_unslash( $_GET['avail_status'] ?? '' ) );
		if ( ! $status ) {
			return null;
		}
		return match ( $status ) {
			'saved'          => array(
				'type'    => 'success',
				'message' => __( 'Availability settings saved.', 'meet-with-me' ),
			),
			'error_override' => array(
				'type'    => 'error',
				'message' => __( 'Invalid date for override. Please try again.', 'meet-with-me' ),
			),
			'error_blocked'  => array(
				'type'    => 'error',
				'message' => __( 'Invalid date for blocked day. Please try again.', 'meet-with-me' ),
			),
			default          => null,
		};
	}
}
