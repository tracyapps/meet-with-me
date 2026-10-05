<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ICS calendar invite generator.
 */
class MWM_ICS {

	/**
	 * Generate ICS file content for a booking.
	 *
	 * @param array $booking    Parsed booking row.
	 * @param array $event_type Parsed event type row.
	 * @return string  Full ICS file content.
	 */
	public static function generate( array $booking, array $event_type ): string {
		$admin_name  = MWM_Settings::get( 'admin_name' ) ?: get_option( 'blogname' );
		$admin_email = MWM_Settings::get( 'admin_email' ) ?: get_option( 'admin_email' );
		$site_url    = wp_parse_url( home_url(), PHP_URL_HOST ) ?: 'localhost';
		$uid         = 'mwm-booking-' . $booking['id'] . '-' . md5( $booking['cancel_token'] ) . '@' . $site_url;

		$dtstart = self::fmt_dt( $booking['start_datetime'] );
		$dtend   = self::fmt_dt( $booking['end_datetime'] );
		$dtstamp = self::fmt_dt( current_time( 'mysql', true ) );

		$summary  = self::ics_escape( $event_type['name'] . ' with ' . $admin_name );
		$desc     = self::build_description( $booking, $event_type, $admin_name );
		$location = self::build_location( $booking );

		$lines = array(
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//Meet With Me//meet-with-me//EN',
			'CALSCALE:GREGORIAN',
			'METHOD:REQUEST',
			'BEGIN:VEVENT',
			'UID:' . $uid,
			'DTSTAMP:' . $dtstamp,
			'DTSTART:' . $dtstart,
			'DTEND:' . $dtend,
			'SUMMARY:' . $summary,
			'DESCRIPTION:' . $desc,
		);

		if ( $location ) {
			$lines[] = 'LOCATION:' . self::ics_escape( $location );
		}

		$lines = array_merge(
			$lines,
			array(
				'ORGANIZER;CN=' . self::ics_escape( $admin_name ) . ':mailto:' . $admin_email,
				'ATTENDEE;CUTYPE=INDIVIDUAL;ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;CN=' . self::ics_escape( $booking['booker_name'] ) . ':mailto:' . $booking['booker_email'],
				'STATUS:CONFIRMED',
				'SEQUENCE:0',
				'END:VEVENT',
				'END:VCALENDAR',
			)
		);

		// ICS lines must be wrapped at 75 octets
		return implode( "\r\n", array_map( array( self::class, 'fold_line' ), $lines ) ) . "\r\n";
	}

	/**
	 * Write ICS to a temp file and return the path.
	 * Caller is responsible for unlinking the file after use.
	 */
	public static function temp_file( array $booking, array $event_type ): string {
		$content = self::generate( $booking, $event_type );
		$path    = wp_tempnam( 'mwm-invite-' . $booking['id'] . '.ics' );
		if ( ! is_string( $path ) || $path === '' ) {
			return '';
		}

		// wp_tempnam creates an empty file; we overwrite it.
		if ( file_put_contents( $path, $content ) === false ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- temp file for email attachment
			wp_delete_file( $path );
			return '';
		}

		return $path;
	}

	// -------------------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------------------

	/** Format a UTC datetime string as iCalendar UTC value: 20260325T140000Z */
	private static function fmt_dt( string $utc ): string {
		try {
			$dt = new DateTimeImmutable( $utc, new DateTimeZone( 'UTC' ) );
			return $dt->format( 'Ymd\THis\Z' );
		} catch ( \Exception $e ) {
			return '';
		}
	}

	private static function build_description( array $booking, array $event_type, string $admin_name ): string {
		$parts   = array();
		$parts[] = $event_type['name'] . ' (' . $event_type['duration_minutes'] . ' min)';

		/* translators: %s: meeting format label ("Online" or "In person"). */
		$parts[] = sprintf( __( 'Format: %s', 'meet-with-me' ), MWM_Booking::format_label( (string) $booking['meeting_type'] ) );
		/* translators: %s: site administrator's name. */
		$parts[] = sprintf( __( 'With: %s', 'meet-with-me' ), $admin_name );

		if ( ! empty( $booking['meeting_provider'] ) ) {
			/* translators: %s: meeting provider name (e.g. Zoom). */
			$parts[] = sprintf( __( 'Meeting provider: %s', 'meet-with-me' ), MWM_Online_Meetings::get_provider_label( (string) $booking['meeting_provider'] ) );
		}

		if ( ! empty( $booking['meeting_join_url'] ) ) {
			/* translators: %s: meeting join URL. */
			$parts[] = sprintf( __( 'Join link: %s', 'meet-with-me' ), $booking['meeting_join_url'] );
		}

		if ( $booking['booker_notes'] ) {
			/* translators: %s: notes added by the booker */
			$parts[] = "\n" . sprintf( __( 'Notes: %s', 'meet-with-me' ), $booking['booker_notes'] );
		}

		// Field answers
		if ( ! empty( $booking['field_answers'] ) && ! empty( $event_type['fields'] ) ) {
			foreach ( $event_type['fields'] as $field ) {
				$val = $booking['field_answers'][ $field['id'] ] ?? null;
				if ( $val === null ) {
					continue;
				}
				$display = is_array( $val ) ? implode( ', ', $val ) : $val;
				if ( $display ) {
					$parts[] = $field['label'] . ': ' . $display;
				}
			}
		}

		return self::ics_escape( implode( "\n", $parts ) );
	}

	private static function build_location( array $booking ): string {
		if ( $booking['meeting_type'] !== 'online' ) {
			return '';
		}

		return ! empty( $booking['meeting_join_url'] ) ? (string) $booking['meeting_join_url'] : __( 'Online', 'meet-with-me' );
	}

	/** Escape special characters for ICS text values. */
	private static function ics_escape( string $s ): string {
		$s = str_replace( '\\', '\\\\', $s );
		$s = str_replace( ';', '\;', $s );
		$s = str_replace( ',', '\,', $s );
		$s = str_replace( "\n", '\\n', $s );
		$s = str_replace( "\r", '', $s );
		return $s;
	}

	/** Fold long ICS lines at 75 octets (RFC 5545 §3.1). */
	private static function fold_line( string $line ): string {
		if ( strlen( $line ) <= 75 ) {
			return $line;
		}
		$out   = '';
		$chars = preg_split( '//u', $line, -1, PREG_SPLIT_NO_EMPTY );
		$len   = 0;
		foreach ( $chars as $char ) {
			$clen = strlen( $char );
			if ( $len + $clen > 75 ) {
				$out .= "\r\n ";
				$len  = 1;
			}
			$out .= $char;
			$len += $clen;
		}
		return $out;
	}
}
