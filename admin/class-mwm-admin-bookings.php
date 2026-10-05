<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin controller for the Bookings section.
 */
class MWM_Admin_Bookings {

	public function dispatch(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'meet-with-me' ) );
		}

		$action = sanitize_key( wp_unslash( $_GET['action'] ?? '' ) );

		if ( $action === 'view' && isset( $_GET['id'] ) ) {
			$this->handle_detail();
			return;
		}

		$this->render_list();
	}

	/**
	 * Run write actions on the load-{page} hook, before WordPress renders the
	 * admin header — redirects and JSON responses go out header-clean.
	 */
	public function handle_actions(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'meet-with-me' ) );
		}

		$action = sanitize_key( wp_unslash( $_GET['action'] ?? '' ) );

		if ( isset( $_POST['mwm_save_admin_notes'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified inside the handler
			$this->handle_save_notes();
			return;
		}

		if ( $action === 'cancel' && isset( $_GET['id'] ) ) {
			$this->handle_cancel();
			return;
		}
	}

	// -------------------------------------------------------------------------
	// Write operations
	// -------------------------------------------------------------------------

	private function handle_save_notes(): void {
		$id = absint( wp_unslash( $_POST['booking_id'] ?? 0 ) );
		check_admin_referer( 'mwm_booking_notes_' . $id );

		if ( ! current_user_can( 'manage_options' ) || ! $id ) {
			wp_die( esc_html__( 'Permission denied.', 'meet-with-me' ) );
		}

		MWM_Booking::update(
			$id,
			array(
				'admin_notes' => sanitize_textarea_field( wp_unslash( $_POST['admin_notes'] ?? '' ) ),
			)
		);

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'           => 'meet-with-me',
					'action'         => 'view',
					'id'             => $id,
					'booking_notice' => 'notes_saved',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	private function handle_cancel(): void {
		$id = absint( wp_unslash( $_GET['id'] ?? 0 ) );
		check_admin_referer( 'mwm_cancel_booking_' . $id );

		if ( ! current_user_can( 'manage_options' ) || ! $id ) {
			wp_die( esc_html__( 'Permission denied.', 'meet-with-me' ) );
		}

		MWM_Booking::cancel( $id );

		$booking    = MWM_Booking::get( $id );
		$event_type = $booking ? MWM_Event_Type::get( $booking['event_type_id'] ) : null;
		if ( $booking && $event_type ) {
			do_action( 'mwm_booking_cancelled', $booking, $event_type );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'           => 'meet-with-me',
					'action'         => 'view',
					'id'             => $id,
					'booking_notice' => 'cancelled',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	// -------------------------------------------------------------------------
	// Views
	// -------------------------------------------------------------------------

	private function render_list(): void {
		$status   = sanitize_key( wp_unslash( $_GET['booking_status'] ?? '' ) );
		$search   = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
		$page_num = max( 1, absint( wp_unslash( $_GET['paged'] ?? 1 ) ) );
		$per_page = 20;

		$args = array(
			'status'   => $status,
			'search'   => $search,
			'per_page' => $per_page,
			'page'     => $page_num,
			'orderby'  => 'start_datetime',
			'order'    => 'DESC',
		);

		$bookings      = MWM_Booking::get_all( $args );
		$total         = MWM_Booking::count( $args );
		$status_counts = MWM_Booking::count_by_status();
		$total_pages   = (int) ceil( $total / $per_page );
		$notice        = $this->get_notice();
		$admin_tz      = MWM_Settings::get( 'timezone' ) ?: 'UTC';

		require MWM_PLUGIN_DIR . 'admin/views/bookings/list.php';
	}

	private function handle_detail(): void {
		$id      = absint( wp_unslash( $_GET['id'] ?? 0 ) );
		$booking = $id ? MWM_Booking::get( $id ) : null;

		if ( ! $booking ) {
			wp_die( esc_html__( 'Booking not found.', 'meet-with-me' ) );
		}

		$admin_tz = MWM_Settings::get( 'timezone' ) ?: 'UTC';
		$notice   = $this->get_notice();

		// Get the event type for field definitions (to label the answers)
		$event_type = \MWM_Event_Type::get( $booking['event_type_id'] );

		require MWM_PLUGIN_DIR . 'admin/views/bookings/detail.php';
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function get_notice(): ?array {
		$key = sanitize_key( wp_unslash( $_GET['booking_notice'] ?? '' ) );
		if ( ! $key ) {
			return null;
		}
		return match ( $key ) {
			'notes_saved' => array(
				'type'    => 'success',
				'message' => __( 'Notes saved.', 'meet-with-me' ),
			),
			'cancelled'   => array(
				'type'    => 'success',
				'message' => __( 'Booking cancelled.', 'meet-with-me' ),
			),
			'created'     => array(
				'type'    => 'success',
				'message' => __( 'Booking confirmed.', 'meet-with-me' ),
			),
			default       => null,
		};
	}
}
