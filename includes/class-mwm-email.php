<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Email notification sender.
 *
 * Template variables available in all email bodies:
 *   {booker_name}        Booker's full name
 *   {booker_email}       Booker's email address
 *   {event_name}         Meeting type name
 *   {duration}           Duration in minutes
 *   {date}               Formatted date in booker's timezone
 *   {time}               Formatted time in booker's timezone
 *   {timezone}           Booker's timezone string
 *   {meeting_type_label} "Online" or "In person"
 *   {meeting_provider}   Connected provider label such as Zoom or Google Meet
 *   {meeting_join_url}   Booker-facing join URL
 *   {meeting_host_url}   Host-facing join URL
 *   {meeting_details}    Preformatted online meeting details block
 *   {manage_url}         Cancel/reschedule link for the booker
 *   {booking_url}        Link to book again (home URL)
 *   {admin_name}         Your name (from General settings)
 *   {admin_booking_url}  Link to this booking in WP admin
 *   {field_answers}      Formatted custom field responses
 *   {site_name}          WordPress site title
 */
class MWM_Email {

	/**
	 * Send booking confirmation to the booker (with ICS attachment).
	 */
	public function send_confirmation( array $booking, array $event_type ): bool {
		$settings = MWM_Settings::get_all( 'email' );
		$vars     = $this->build_vars( $booking, $event_type );

		$subject = $this->replace( $settings['confirmation_subject'] ?? '', $vars );
		$body    = $this->inject_meeting_details(
			$this->replace( $settings['confirmation_body'] ?? '', $vars ),
			$vars
		);

		$ics_path    = MWM_ICS::temp_file( $booking, $event_type );
		$attachments = ( $ics_path && file_exists( $ics_path ) ) ? array( $ics_path ) : array();
		$result      = $this->send( $booking['booker_email'], $subject, $body, array(), $attachments );

		if ( $ics_path && file_exists( $ics_path ) ) {
			wp_delete_file( $ics_path );
		}

		return $result;
	}

	/**
	 * Send new-booking notification to the admin.
	 */
	public function send_admin_notification( array $booking, array $event_type ): bool {
		$settings = MWM_Settings::get_all( 'email' );
		$vars     = $this->build_vars( $booking, $event_type );

		$to      = MWM_Settings::get( 'admin_email' ) ?: get_option( 'admin_email' );
		$subject = $this->replace( $settings['admin_subject'] ?? '', $vars );
		$body    = $this->inject_meeting_details(
			$this->replace( $settings['admin_body'] ?? '', $vars ),
			$vars
		);

		return $this->send( $to, $subject, $body );
	}

	/**
	 * Send cancellation notice to the booker.
	 */
	public function send_cancellation( array $booking, array $event_type ): bool {
		$settings = MWM_Settings::get_all( 'email' );
		$vars     = $this->build_vars( $booking, $event_type );

		$subject = $this->replace( $settings['cancellation_subject'] ?? '', $vars );
		$body    = $this->replace( $settings['cancellation_body'] ?? '', $vars );

		return $this->send( $booking['booker_email'], $subject, $body );
	}

	/**
	 * Send reschedule confirmation to the booker (with updated ICS attachment).
	 */
	public function send_reschedule_confirmation( array $new_booking, array $event_type ): bool {
		$settings = MWM_Settings::get_all( 'email' );
		$vars     = $this->build_vars( $new_booking, $event_type );

		$subject = $this->replace( $settings['reschedule_subject'] ?? '', $vars );
		$body    = $this->inject_meeting_details(
			$this->replace( $settings['reschedule_body'] ?? '', $vars ),
			$vars
		);

		$ics_path    = MWM_ICS::temp_file( $new_booking, $event_type );
		$attachments = ( $ics_path && file_exists( $ics_path ) ) ? array( $ics_path ) : array();
		$result      = $this->send( $new_booking['booker_email'], $subject, $body, array(), $attachments );

		if ( $ics_path && file_exists( $ics_path ) ) {
			wp_delete_file( $ics_path );
		}

		return $result;
	}

	// -------------------------------------------------------------------------
	// Core send method
	// -------------------------------------------------------------------------

	/**
	 * Send an email via wp_mail() with HTML content type.
	 *
	 * @param string   $to          Recipient email.
	 * @param string   $subject
	 * @param string   $body        Plain-text body (newlines converted to <br>).
	 * @param string[] $extra_headers
	 * @param string[] $attachments File paths.
	 */
	private function send( string $to, string $subject, string $body, array $extra_headers = array(), array $attachments = array() ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- passed into the mwm_email filter via compact()
		$settings   = MWM_Settings::get_all( 'email' );
		$from_name  = $settings['from_name'] ?: get_option( 'blogname' );
		$from_email = $settings['from_email'] ?: get_option( 'admin_email' );

		$headers = array_merge(
			array(
				'Content-Type: text/html; charset=UTF-8',
				'From: ' . $from_name . ' <' . $from_email . '>',
			),
			$extra_headers
		);

		$html_body = $this->wrap_html( $body, $from_name );

		/**
		 * Filter the outgoing email before it is sent.
		 *
		 * @param array $email { to, subject, html_body, headers, attachments }
		 * @param array $context { type, booking, event_type }
		 */
		$email = apply_filters( 'mwm_email', compact( 'to', 'subject', 'html_body', 'headers', 'attachments' ) );

		try {
			return wp_mail(
				$email['to'],
				$email['subject'],
				$email['html_body'],
				$email['headers'],
				$email['attachments']
			);
		} catch ( \Throwable $e ) {
			error_log( '[Meet With Me] Email send failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- deliberate failure log
			return false;
		}
	}

	// -------------------------------------------------------------------------
	// Template variables
	// -------------------------------------------------------------------------

	private function build_vars( array $booking, array $event_type ): array {
		$tz         = $booking['booker_timezone'] ?: 'UTC';
		$date       = MWM_Booking::format_datetime( $booking['start_datetime'], $tz, get_option( 'date_format' ) );
		$time       = MWM_Booking::format_datetime( $booking['start_datetime'], $tz, get_option( 'time_format' ) );
		$manage_url = add_query_arg( array( 'mwm_token' => $booking['cancel_token'] ), home_url( '/' ) );
		$admin_url  = add_query_arg(
			array(
				'page'   => 'meet-with-me',
				'action' => 'view',
				'id'     => $booking['id'],
			),
			admin_url( 'admin.php' )
		);

		$meeting_provider = MWM_Online_Meetings::get_provider_label( (string) ( $booking['meeting_provider'] ?? '' ) );
		$meeting_details  = $this->build_meeting_details( $booking, $meeting_provider );

		return array(
			'{booker_name}'        => $booking['booker_name'],
			'{booker_email}'       => $booking['booker_email'],
			'{event_name}'         => $event_type['name'],
			'{duration}'           => $event_type['duration_minutes'],
			'{date}'               => $date,
			'{time}'               => $time,
			'{timezone}'           => $tz,
			'{meeting_type_label}' => MWM_Booking::format_label( (string) $booking['meeting_type'] ),
			'{meeting_provider}'   => $meeting_provider,
			'{meeting_join_url}'   => $booking['meeting_join_url'] ?? '',
			'{meeting_host_url}'   => $booking['meeting_host_url'] ?? '',
			'{meeting_details}'    => $meeting_details,
			'{manage_url}'         => $manage_url,
			'{booking_url}'        => home_url( '/' ),
			'{admin_name}'         => MWM_Settings::get( 'admin_name' ) ?: get_option( 'blogname' ),
			'{admin_booking_url}'  => $admin_url,
			'{field_answers}'      => $this->format_field_answers( $booking, $event_type ),
			'{site_name}'          => get_option( 'blogname' ),
		);
	}

	private function replace( string $template, array $vars ): string {
		return str_replace( array_keys( $vars ), array_values( $vars ), $template );
	}

	private function format_field_answers( array $booking, array $event_type ): string {
		if ( empty( $booking['field_answers'] ) || empty( $event_type['fields'] ) ) {
			return '';
		}
		$lines = array();
		foreach ( $event_type['fields'] as $field ) {
			$val = $booking['field_answers'][ $field['id'] ] ?? null;
			if ( $val === null ) {
				continue;
			}
			$display = is_array( $val ) ? implode( ', ', $val ) : $val;
			if ( $display !== '' ) {
				$lines[] = $field['label'] . ': ' . $display;
			}
		}
		return implode( "\n", $lines );
	}

	private function build_meeting_details( array $booking, string $meeting_provider ): string {
		if ( ( $booking['meeting_type'] ?? '' ) !== 'online' || empty( $booking['meeting_join_url'] ) ) {
			return '';
		}

		$lines = array();
		if ( $meeting_provider ) {
			/* translators: %s: meeting provider name (e.g. Zoom). */
			$lines[] = sprintf( __( 'Meeting provider: %s', 'meet-with-me' ), $meeting_provider );
		}
		/* translators: %s: meeting join URL. */
		$lines[] = sprintf( __( 'Join link: %s', 'meet-with-me' ), $booking['meeting_join_url'] );

		if ( ! empty( $booking['meeting_data']['password'] ) ) {
			/* translators: %s: meeting passcode. */
			$lines[] = sprintf( __( 'Passcode: %s', 'meet-with-me' ), $booking['meeting_data']['password'] );
		}

		return implode( "\n", $lines );
	}

	private function inject_meeting_details( string $body, array $vars ): string {
		if ( empty( $vars['{meeting_details}'] ) ) {
			return $body;
		}

		if ( str_contains( $body, $vars['{meeting_join_url}'] ) || str_contains( $body, $vars['{meeting_details}'] ) ) {
			return $body;
		}

		return rtrim( $body ) . "\n\n" . $vars['{meeting_details}'];
	}

	// -------------------------------------------------------------------------
	// HTML wrapper
	// -------------------------------------------------------------------------

	private function wrap_html( string $body, string $from_name ): string {
		$safe_body      = nl2br( esc_html( $body ) );
		$safe_from_name = esc_html( $from_name );
		$safe_site      = esc_html( get_option( 'blogname' ) );
		$home           = esc_url( home_url( '/' ) );
		$lang           = esc_attr( get_bloginfo( 'language' ) ?: 'en' );

		/**
		 * Filter the accent (link) color used in HTML emails.
		 *
		 * @param string $accent Hex color. Defaults to the Style setting, or #2563eb.
		 */
		$default_accent = MWM_Settings::get( 'accent_color', 'style' ) ?: '#2563eb';
		$accent         = sanitize_hex_color( (string) apply_filters( 'mwm_email_accent_color', $default_accent ) ) ?: '#2563eb';

		return '<!DOCTYPE html>
<html lang="' . $lang . '">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  body { margin:0; padding:0; background:#f4f4f5; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; color:#333; }
  .wrap { max-width:600px; margin:32px auto; background:#fff; border-radius:8px; overflow:hidden; box-shadow:0 1px 4px rgba(0,0,0,.08); }
  .body { padding:32px 36px; font-size:15px; line-height:1.65; }
  .footer { background:#f4f4f5; padding:16px 36px; font-size:12px; color:#888; text-align:center; }
  a { color:' . $accent . '; }
</style>
</head>
<body>
  <div class="wrap">
    <div class="body">' . $safe_body . '</div>
    <div class="footer">Sent by <a href="' . $home . '">' . $safe_site . '</a> via ' . $safe_from_name . '</div>
  </div>
</body>
</html>';
	}
}
