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
        return [
            15  => __( '15 min', 'meet-with-me' ),
            20  => __( '20 min', 'meet-with-me' ),
            30  => __( '30 min', 'meet-with-me' ),
            45  => __( '45 min', 'meet-with-me' ),
            60  => __( '1 hour', 'meet-with-me' ),
            75  => __( '1 h 15 min', 'meet-with-me' ),
            90  => __( '1 h 30 min', 'meet-with-me' ),
            120 => __( '2 hours', 'meet-with-me' ),
        ];
    }

    public function dispatch(): void {
        // Handle delete (GET with nonce)
        if ( isset( $_GET['action'] ) && $_GET['action'] === 'delete' && isset( $_GET['id'] ) ) {
            $this->handle_delete();
            return;
        }

        // Handle save (POST)
        if ( isset( $_POST['mwm_save_event_type'] ) ) {
            $this->handle_save();
            return; // handle_save() redirects
        }

        // Route to edit form
        if ( isset( $_GET['action'] ) && in_array( $_GET['action'], [ 'edit', 'new' ], true ) ) {
            $this->render_edit();
            return;
        }

        // Default: list
        $this->render_list();
    }

    // -------------------------------------------------------------------------
    // Write operations
    // -------------------------------------------------------------------------

    private function handle_save(): void {
        check_admin_referer( 'mwm_save_event_type' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'meet-with-me' ) );
        }

        $id   = isset( $_POST['event_type_id'] ) ? (int) $_POST['event_type_id'] : 0;
        $name = sanitize_text_field( $_POST['name'] ?? '' );

        if ( empty( $name ) ) {
            $this->redirect_with_notice( 'edit', $id, 'error', __( 'Meeting type name is required.', 'meet-with-me' ) );
            return;
        }

        // Slug handling
        $slug = sanitize_title( $_POST['slug'] ?? '' );
        if ( empty( $slug ) ) {
            $slug = MWM_Event_Type::generate_slug( $name, $id );
        } elseif ( MWM_Event_Type::slug_exists( $slug, $id ) ) {
            $this->redirect_with_notice( 'edit', $id, 'error', __( 'That slug is already in use. Please choose a different one.', 'meet-with-me' ) );
            return;
        }

        // Duration: handle "other" custom value
        $duration = (int) ( $_POST['duration_minutes'] ?? 30 );
        if ( $duration === 0 && ! empty( $_POST['duration_custom'] ) ) {
            $duration = max( 5, (int) $_POST['duration_custom'] );
        }

        $data = [
            'name'             => $name,
            'slug'             => $slug,
            'description'      => sanitize_textarea_field( $_POST['description'] ?? '' ),
            'duration_minutes' => $duration,
            'meeting_type'     => sanitize_key( $_POST['meeting_type'] ?? 'both' ),
            'online_provider'  => sanitize_key( $_POST['online_provider'] ?? '' ),
            'online_routing_field_id' => sanitize_key( $_POST['online_routing_field_id'] ?? '' ),
            'online_provider_rules'   => $_POST['online_provider_rules'] ?? [],
            'buffer_before'    => (int) ( $_POST['buffer_before'] ?? 0 ),
            'buffer_after'     => (int) ( $_POST['buffer_after'] ?? 0 ),
            'max_per_day'      => ( isset( $_POST['max_per_day'] ) && $_POST['max_per_day'] !== '' ) ? $_POST['max_per_day'] : null,
            'max_per_week'     => ( isset( $_POST['max_per_week'] ) && $_POST['max_per_week'] !== '' ) ? $_POST['max_per_week'] : null,
            'color'            => sanitize_hex_color( $_POST['color'] ?? '#3b82f6' ) ?: '#3b82f6',
            'fields'           => $_POST['mwm_fields'] ?? '[]',
            'is_active'        => isset( $_POST['is_active'] ) ? 1 : 0,
        ];

        if ( $id > 0 ) {
            MWM_Event_Type::update( $id, $data );
            $this->redirect_with_notice( 'list', 0, 'success', __( 'Meeting type updated.', 'meet-with-me' ) );
        } else {
            $new_id = MWM_Event_Type::create( $data );
            if ( $new_id ) {
                $this->redirect_with_notice( 'list', 0, 'success', __( 'Meeting type created.', 'meet-with-me' ) );
            } else {
                $this->redirect_with_notice( 'new', 0, 'error', __( 'Could not save meeting type. Please try again.', 'meet-with-me' ) );
            }
        }
    }

    private function handle_delete(): void {
        $id = (int) ( $_GET['id'] ?? 0 );
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
        $id          = (int) ( $_GET['id'] ?? 0 );
        $event_type  = $id > 0 ? MWM_Event_Type::get( $id ) : null;
        $is_new      = ! $event_type;
        $notice      = $this->get_notice();
        $durations   = self::get_durations();
        $saved_providers = [];
        if ( ! empty( $event_type['online_provider'] ) ) {
            $saved_providers[] = $event_type['online_provider'];
        }
        if ( ! empty( $event_type['online_provider_rules'] ) && is_array( $event_type['online_provider_rules'] ) ) {
            $saved_providers = array_merge( $saved_providers, array_values( $event_type['online_provider_rules'] ) );
        }
        $provider_choices = MWM_Online_Meetings::get_available_provider_choices( array_values( array_unique( array_filter( $saved_providers ) ) ) );
        require MWM_PLUGIN_DIR . 'admin/views/event-types/edit.php';
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function redirect_with_notice( string $view, int $id, string $type, string $message ): void {
        $args = [
            'page'        => 'mwm-event-types',
            'mwm_notice'  => $type,
            'mwm_message' => urlencode( $message ),
        ];
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
        return [
            'type'    => sanitize_key( $_GET['mwm_notice'] ),
            'message' => sanitize_text_field( urldecode( $_GET['mwm_message'] ?? '' ) ),
        ];
    }
}
