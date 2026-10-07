<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin controller for Meeting Types (event types).
 *
 * Handles routing between list/edit views and all write operations.
 */
class MWM_Admin_Event_Types {

	/**
	 * Common durations offered in the dropdown (minutes => translated label).
	 *
	 * @return array<int, string>
	 */
	public static function get_durations(): array {
		return array(
			15  => __( '15 min', 'meet-with-me' ),
			20  => __( '20 min', 'meet-with-me' ),
			30  => __( '30 min', 'meet-with-me' ),
			45  => __( '45 min', 'meet-with-me' ),
			60  => __( '1 hour', 'meet-with-me' ),
			75  => __( '1 h 15 min', 'meet-with-me' ),
			90  => __( '1 h 30 min', 'meet-with-me' ),
			120 => __( '2 hours', 'meet-with-me' ),
		);
	}

	public function dispatch(): void {
		// Route to edit form
		if ( isset( $_GET['action'] ) && in_array( sanitize_key( wp_unslash( $_GET['action'] ?? '' ) ), array( 'edit', 'new' ), true ) ) {
			$this->render_edit();
			return;
		}

		// phpcs:ignore Squiz.PHP.CommentedOutCode.Found -- label comment, not code
		// Default: list
		$this->render_list();
	}

	/**
	 * Run write actions on the load-{page} hook, before WordPress renders the
	 * admin header — redirects and JSON responses go out header-clean.
	 */
	public function handle_actions(): void {
		// Handle delete (GET with nonce)
		if ( isset( $_GET['action'] ) && sanitize_key( wp_unslash( $_GET['action'] ?? '' ) ) === 'delete' && isset( $_GET['id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified inside the handler
			$this->handle_delete();
			return;
		}

		// Handle save (POST)
		if ( isset( $_POST['mwm_save_event_type'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified inside handle_save()
			$this->handle_save();
			return; // handle_save() redirects
		}
	}

	// -------------------------------------------------------------------------
	// Write operations
	// -------------------------------------------------------------------------

	private function handle_save(): void {
		check_admin_referer( 'mwm_save_event_type' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'meet-with-me' ) );
		}

		$data = self::prepare_data();
		if ( is_wp_error( $data ) ) {
			$id = isset( $_POST['event_type_id'] ) ? absint( wp_unslash( $_POST['event_type_id'] ) ) : 0;
			$this->redirect_with_notice( 'edit', $id, 'error', $data->get_error_message() );
			return;
		}

		if ( $data['id'] > 0 ) {
			MWM_Event_Type::update( $data['id'], $data['data'] );
			// Stay on the edit screen — changes autosave in place, so leaving
			// for the list after every save broke the editor's flow.
			$this->redirect_with_notice( 'edit', $data['id'], 'success', __( 'Meeting type updated.', 'meet-with-me' ) );
		} else {
			$new_id = MWM_Event_Type::create( $data['data'] );
			if ( $new_id ) {
				$this->redirect_with_notice( 'edit', $new_id, 'success', __( 'Meeting type created.', 'meet-with-me' ) );
			} else {
				$this->redirect_with_notice( 'new', 0, 'error', __( 'Could not save meeting type. Please try again.', 'meet-with-me' ) );
			}
		}
	}

	/**
	 * AJAX autosave: the editor debounces changes and posts the full form.
	 * Same nonce, capability, and sanitization as the classic save.
	 */
	public static function ajax_save(): void {
		check_ajax_referer( 'mwm_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'meet-with-me' ) ), 403 );
		}

		$data = self::prepare_data();
		if ( is_wp_error( $data ) ) {
			wp_send_json_error( array( 'message' => $data->get_error_message() ), 400 );
		}

		if ( $data['id'] > 0 ) {
			$ok = MWM_Event_Type::update( $data['id'], $data['data'] );
			if ( ! $ok ) {
				wp_send_json_error( array( 'message' => __( 'Could not save meeting type. Please try again.', 'meet-with-me' ) ), 500 );
			}
			wp_send_json_success(
				array(
					'id'      => $data['id'],
					'slug'    => $data['data']['slug'],
					'message' => __( 'Saved', 'meet-with-me' ),
					'savedAt' => gmdate( 'H:i' ),
				)
			);
		}

		$new_id = MWM_Event_Type::create( $data['data'] );
		if ( ! $new_id ) {
			wp_send_json_error( array( 'message' => __( 'Could not save meeting type. Please try again.', 'meet-with-me' ) ), 500 );
		}
		wp_send_json_success(
			array(
				'id'      => $new_id,
				'slug'    => $data['data']['slug'],
				'isNew'   => true,
				'message' => __( 'Created', 'meet-with-me' ),
				'savedAt' => gmdate( 'H:i' ),
			)
		);
	}

	/**
	 * Build the sanitized data array from the current POST body (shared by the
	 * classic form save and the AJAX autosave).
	 *
	 * @return array{id:int,data:array}|WP_Error Shape: ['id' => row id (0 = new), 'data' => sanitized columns].
	 */
	private static function prepare_data(): array|WP_Error {
		/*
		 * Nonce verification happens in both callers: handle_save() runs
		 * check_admin_referer( 'mwm_save_event_type' ) and ajax_save() runs
		 * check_ajax_referer( 'mwm_admin', 'nonce' ) before calling this.
		 *
		 * phpcs:disable WordPress.Security.NonceVerification.Missing -- see above
		 */
		$id   = isset( $_POST['event_type_id'] ) ? absint( wp_unslash( $_POST['event_type_id'] ) ) : 0;
		$name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );

		if ( empty( $name ) ) {
			return new WP_Error( 'mwm_name_required', __( 'Meeting type name is required.', 'meet-with-me' ) );
		}

		// Slug handling
		$slug = sanitize_title( wp_unslash( $_POST['slug'] ?? '' ) );
		if ( empty( $slug ) ) {
			$slug = MWM_Event_Type::generate_slug( $name, $id );
		} elseif ( MWM_Event_Type::slug_exists( $slug, $id ) ) {
			return new WP_Error( 'mwm_slug_taken', __( 'That slug is already in use. Please choose a different one.', 'meet-with-me' ) );
		}

		// Duration: handle "other" custom value
		$duration = absint( wp_unslash( $_POST['duration_minutes'] ?? 30 ) );
		if ( $duration === 0 && ! empty( $_POST['duration_custom'] ) ) {
			$duration = max( 5, absint( wp_unslash( $_POST['duration_custom'] ) ) );
		}

		// Per-type availability: only stored when the editor is in custom mode.
		$availability_override = null;
		if ( sanitize_key( wp_unslash( $_POST['availability_mode'] ?? 'default' ) ) === 'custom' ) {
			$availability_override = wp_unslash( $_POST['availability_override'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized downstream in the model
		}

		return array(
			'id'   => $id,
			'data' => array(
				'name'                    => $name,
				'slug'                    => $slug,
				'description'             => sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) ),
				'duration_minutes'        => $duration,
				'meeting_type'            => sanitize_key( wp_unslash( $_POST['meeting_type'] ?? 'both' ) ),
				'online_provider'         => sanitize_key( wp_unslash( $_POST['online_provider'] ?? '' ) ),
				'online_routing_field_id' => sanitize_key( wp_unslash( $_POST['online_routing_field_id'] ?? '' ) ),
				'online_provider_rules'   => array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['online_provider_rules'] ?? array() ) ),
				'buffer_before'           => absint( wp_unslash( $_POST['buffer_before'] ?? 0 ) ),
				'buffer_after'            => absint( wp_unslash( $_POST['buffer_after'] ?? 0 ) ),
				'max_per_day'             => ( isset( $_POST['max_per_day'] ) && absint( wp_unslash( $_POST['max_per_day'] ) ) > 0 ) ? absint( wp_unslash( $_POST['max_per_day'] ) ) : null,
				'max_per_week'            => ( isset( $_POST['max_per_week'] ) && absint( wp_unslash( $_POST['max_per_week'] ) ) > 0 ) ? absint( wp_unslash( $_POST['max_per_week'] ) ) : null,
				'color'                   => sanitize_hex_color( wp_unslash( $_POST['color'] ?? '#3b82f6' ) ) ?: '#3b82f6',
				'fields'                  => wp_unslash( $_POST['mwm_fields'] ?? '[]' ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized downstream in the model
				'availability_override'   => $availability_override,
				'is_active'               => isset( $_POST['is_active'] ) ? 1 : 0,
			),
		);
		// phpcs:enable
	}

	private function handle_delete(): void {
		$id = absint( wp_unslash( $_GET['id'] ?? 0 ) );
		check_admin_referer( 'mwm_delete_event_type_' . $id );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'meet-with-me' ) );
		}

		MWM_Event_Type::delete( $id );
		$this->redirect_with_notice( 'list', 0, 'success', __( 'Meeting type deleted.', 'meet-with-me' ) );
	}

	// -------------------------------------------------------------------------
	// View rendering
	// -------------------------------------------------------------------------

	private function render_list(): void {
		$event_types = MWM_Event_Type::get_all();
		$notice      = $this->get_notice();
		require MWM_PLUGIN_DIR . 'admin/views/event-types/list.php';
	}

	private function render_edit(): void {
		$id              = absint( wp_unslash( $_GET['id'] ?? 0 ) );
		$event_type      = $id > 0 ? MWM_Event_Type::get( $id ) : null;
		$is_new          = ! $event_type;
		$notice          = $this->get_notice();
		$durations       = self::get_durations();
		$saved_providers = array();
		if ( ! empty( $event_type['online_provider'] ) ) {
			$saved_providers[] = $event_type['online_provider'];
		}
		if ( ! empty( $event_type['online_provider_rules'] ) && is_array( $event_type['online_provider_rules'] ) ) {
			$saved_providers = array_merge( $saved_providers, array_values( $event_type['online_provider_rules'] ) );
		}
		$provider_choices = MWM_Online_Meetings::get_available_provider_choices( array_values( array_unique( array_filter( $saved_providers ) ) ) );

		// Availability: global defaults (the "ghost") + this type's override.
		if ( ! class_exists( 'MWM_Availability' ) ) {
			require_once MWM_PLUGIN_DIR . 'includes/class-mwm-availability.php';
		}
		$global_weekly = MWM_Availability::get_weekly_schedule();

		require MWM_PLUGIN_DIR . 'admin/views/event-types/edit.php';
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function redirect_with_notice( string $view, int $id, string $type, string $message ): void {
		$args = array(
			'page'        => 'mwm-event-types',
			'mwm_notice'  => $type,
			'mwm_message' => rawurlencode( $message ),
		);
		if ( $view === 'edit' ) {
			$args['action'] = $id > 0 ? 'edit' : 'new';
			if ( $id > 0 ) {
				$args['id'] = $id;
			}
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	private function get_notice(): ?array {
		if ( empty( $_GET['mwm_notice'] ) ) {
			return null;
		}
		return array(
			'type'    => sanitize_key( wp_unslash( $_GET['mwm_notice'] ?? '' ) ),
			'message' => sanitize_text_field( wp_unslash( urldecode( $_GET['mwm_message'] ?? '' ) ) ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- decoded, unslashed and sanitized on this line
		);
	}
}
