<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Admin controller for the Email Templates settings tab.
 */
class MWM_Admin_Email {

    /**
     * All variable tokens shown in the UI with translated descriptions.
     *
     * @return array<string, string>
     */
    public static function get_vars(): array {
        return [
            '{booker_name}'        => __( 'Booker\'s full name', 'meet-with-me' ),
            '{booker_email}'       => __( 'Booker\'s email address', 'meet-with-me' ),
            '{event_name}'         => __( 'Meeting type name', 'meet-with-me' ),
            '{duration}'           => __( 'Duration in minutes', 'meet-with-me' ),
            '{date}'               => __( 'Formatted date (booker\'s timezone)', 'meet-with-me' ),
            '{time}'               => __( 'Formatted time (booker\'s timezone)', 'meet-with-me' ),
            '{timezone}'           => __( 'Booker\'s timezone', 'meet-with-me' ),
            '{meeting_type_label}' => __( '“Online” or “In person”', 'meet-with-me' ),
            '{meeting_provider}'   => __( 'Provider label such as Zoom or Google Meet', 'meet-with-me' ),
            '{meeting_join_url}'   => __( 'Booker-facing join URL', 'meet-with-me' ),
            '{meeting_host_url}'   => __( 'Host-facing start URL when available', 'meet-with-me' ),
            '{meeting_details}'    => __( 'Preformatted online meeting details block', 'meet-with-me' ),
            '{manage_url}'         => __( 'Cancel / reschedule link', 'meet-with-me' ),
            '{booking_url}'        => __( 'Link to book again', 'meet-with-me' ),
            '{admin_name}'         => __( 'Your name (from General settings)', 'meet-with-me' ),
            '{admin_booking_url}'  => __( 'View booking in WP admin', 'meet-with-me' ),
            '{field_answers}'      => __( 'Custom question responses', 'meet-with-me' ),
            '{site_name}'          => __( 'WordPress site title', 'meet-with-me' ),
        ];
    }

    public function dispatch(): void {
        if ( isset( $_POST['mwm_save_email'] ) ) {
            $this->handle_save();
        } elseif ( isset( $_POST['mwm_test_email'] ) ) {
            $this->handle_test();
        }
        $this->render();
    }

    // -------------------------------------------------------------------------
    // Write operations
    // -------------------------------------------------------------------------

    private function handle_save(): void {
        check_admin_referer( 'mwm_email_settings' );

        MWM_Settings::set_group( [
            'from_name'             => sanitize_text_field( $_POST['from_name']             ?? '' ),
            'from_email'            => sanitize_email(      $_POST['from_email']             ?? '' ),
            'confirmation_subject'  => sanitize_text_field( $_POST['confirmation_subject']   ?? '' ),
            'confirmation_body'     => sanitize_textarea_field( $_POST['confirmation_body']  ?? '' ),
            'admin_subject'         => sanitize_text_field( $_POST['admin_subject']          ?? '' ),
            'admin_body'            => sanitize_textarea_field( $_POST['admin_body']         ?? '' ),
            'cancellation_subject'  => sanitize_text_field( $_POST['cancellation_subject']   ?? '' ),
            'cancellation_body'     => sanitize_textarea_field( $_POST['cancellation_body']  ?? '' ),
            'reschedule_subject'    => sanitize_text_field( $_POST['reschedule_subject']     ?? '' ),
            'reschedule_body'       => sanitize_textarea_field( $_POST['reschedule_body']    ?? '' ),
        ], 'email' );

        $this->redirect( 'saved' );
    }

    private function handle_test(): void {
        check_admin_referer( 'mwm_email_settings' );

        $to      = MWM_Settings::get( 'admin_email' ) ?: get_option( 'admin_email' );
        $subject = '[Test] ' . ( MWM_Settings::get( 'admin_subject', 'email' ) ?: 'New booking notification' );

        // Build a sample vars payload
        $sample = $this->sample_vars();
        $body   = MWM_Settings::get( 'admin_body', 'email' );
        $body   = str_replace( array_keys( $sample ), array_values( $sample ), $body );

        $sent = wp_mail(
            $to,
            $subject,
            $body . "\n\n— This is a test email sent from Meet With Me settings.",
            [ 'Content-Type: text/plain; charset=UTF-8' ]
        );

        $this->redirect( $sent ? 'test_sent' : 'test_failed' );
    }

    // -------------------------------------------------------------------------
    // Render
    // -------------------------------------------------------------------------

    private function render(): void {
        $current_tab = 'email';
        $notice      = $this->get_notice();
        $settings    = MWM_Settings::get_all( 'email' );
        $vars        = self::get_vars();
        require MWM_PLUGIN_DIR . 'admin/views/settings/email.php';
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function redirect( string $status ): void {
        wp_safe_redirect( add_query_arg( [
            'page'         => 'mwm-settings',
            'tab'          => 'email',
            'email_status' => $status,
        ], admin_url( 'admin.php' ) ) );
        exit;
    }

    private function get_notice(): ?array {
        $s = sanitize_key( $_GET['email_status'] ?? '' );
        if ( ! $s ) return null;
        return match ( $s ) {
            'saved'       => [ 'type' => 'success', 'message' => __( 'Email settings saved.',                    'meet-with-me' ) ],
            'test_sent'   => [ 'type' => 'success', 'message' => __( 'Test email sent. Check your inbox.',       'meet-with-me' ) ],
            'test_failed' => [ 'type' => 'error',   'message' => __( 'Test email failed. Check your WP mail configuration.', 'meet-with-me' ) ],
            default       => null,
        };
    }

    private function sample_vars(): array {
        return [
            '{booker_name}'        => 'Jane Smith',
            '{booker_email}'       => 'jane@example.com',
            '{event_name}'         => '30-min Intro Call',
            '{duration}'           => '30',
            '{date}'               => wp_date( get_option( 'date_format' ) ),
            '{time}'               => '10:00 AM',
            '{timezone}'           => 'America/Vancouver',
            '{meeting_type_label}' => 'Online',
            '{meeting_provider}'   => 'Zoom',
            '{meeting_join_url}'   => 'https://example.zoom.us/j/123456789',
            '{meeting_host_url}'   => 'https://example.zoom.us/s/123456789',
            '{meeting_details}'    => "Meeting provider: Zoom\nJoin link: https://example.zoom.us/j/123456789",
            '{manage_url}'         => home_url( '/?mwm_token=sample' ),
            '{booking_url}'        => home_url( '/' ),
            '{admin_name}'         => MWM_Settings::get( 'admin_name' ) ?: get_option( 'blogname' ),
            '{admin_booking_url}'  => admin_url( 'admin.php?page=meet-with-me&action=view&id=1' ),
            '{field_answers}'      => "Where to meet: Coffee shop",
            '{site_name}'          => get_option( 'blogname' ),
        ];
    }
}
