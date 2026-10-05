<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Online meeting provider routing and provisioning.
 */
class MWM_Online_Meetings {

	private const LABELS = array(
		'zoom'        => 'Zoom',
		'google_meet' => 'Google Meet',
	);

	/**
	 * Return provider choices that are currently usable, plus any saved choices
	 * that need to remain visible in admin forms.
	 */
	public static function get_available_provider_choices( array $includes = array() ): array {
		$choices = array();
		foreach ( self::LABELS as $provider => $label ) {
			if ( self::is_provider_available( $provider ) || in_array( $provider, $includes, true ) ) {
				$choices[ $provider ] = self::get_provider_label( $provider ) . ( self::is_provider_available( $provider ) ? '' : __( ' (not configured)', 'meet-with-me' ) );
			}
		}

		return $choices;
	}

	public static function get_provider_label( string $provider ): string {
		return match ( $provider ) {
			'zoom'        => __( 'Zoom', 'meet-with-me' ),
			'google_meet' => __( 'Google Meet', 'meet-with-me' ),
			default       => ucfirst( str_replace( '_', ' ', $provider ) ),
		};
	}

	public static function is_provider_available( string $provider ): bool {
		return match ( $provider ) {
			'zoom'        => MWM_Zoom::is_configured(),
			'google_meet' => MWM_Google_Calendar::is_connected() && (bool) MWM_Settings::get( 'write_back_calendar_id', 'google' ),
			default       => false,
		};
	}

	/**
	 * Return single-choice custom fields that can drive provider routing.
	 */
	public static function get_routing_fields( array $event_type ): array {
		$fields = array();
		foreach ( (array) ( $event_type['fields'] ?? array() ) as $field ) {
			if ( ! in_array( $field['type'] ?? '', array( 'select', 'radio' ), true ) ) {
				continue;
			}

			$options = array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $field['options'] ?? array() ) ) ) );
			if ( empty( $options ) ) {
				continue;
			}

			$fields[] = array(
				'id'      => sanitize_key( $field['id'] ?? '' ),
				'label'   => sanitize_text_field( $field['label'] ?? '' ),
				'type'    => $field['type'],
				'options' => $options,
			);
		}

		return $fields;
	}

	/**
	 * Provision meeting details for a confirmed booking if the event type
	 * routes online meetings through a configured provider.
	 */
	public static function create_for_booking( array $booking, array $event_type ): array|false {
		$provider = self::resolve_provider_for_booking( $booking, $event_type );
		if ( $provider === '' ) {
			return false;
		}

		return match ( $provider ) {
			'zoom'        => MWM_Zoom::create_meeting( $booking, $event_type ),
			'google_meet' => self::create_google_meet( $booking, $event_type ),
			default       => false,
		};
	}

	/**
	 * Clean up provider-specific meeting records.
	 */
	public static function delete_for_booking( array $booking ): void {
		if ( ( $booking['meeting_provider'] ?? '' ) !== 'zoom' ) {
			return;
		}

		$meeting_id = $booking['meeting_data']['zoom_meeting_id'] ?? '';
		if ( $meeting_id ) {
			MWM_Zoom::delete_meeting( $meeting_id );
		}
	}

	/**
	 * Resolve the provider that should be used for this booking.
	 */
	public static function resolve_provider_for_booking( array $booking, array $event_type ): string {
		if ( ( $booking['meeting_type'] ?? '' ) !== 'online' ) {
			return '';
		}

		$provider = sanitize_key( (string) ( $event_type['online_provider'] ?? '' ) );
		$field_id = sanitize_key( (string) ( $event_type['online_routing_field_id'] ?? '' ) );
		$rules    = is_array( $event_type['online_provider_rules'] ?? null ) ? $event_type['online_provider_rules'] : array();

		if ( $field_id !== '' && isset( $booking['field_answers'][ $field_id ] ) ) {
			$answer = $booking['field_answers'][ $field_id ];
			if ( is_array( $answer ) ) {
				$answer = reset( $answer );
			}

			$answer = sanitize_text_field( (string) $answer );
			if ( $answer !== '' && ! empty( $rules[ $answer ] ) ) {
				$provider = sanitize_key( (string) $rules[ $answer ] );
			}
		}

		return self::is_provider_available( $provider ) ? $provider : '';
	}

	private static function create_google_meet( array $booking, array $event_type ): array|false {
		$event = MWM_Google_Calendar::create_event_with_options(
			$booking,
			$event_type,
			array(
				'conference_provider' => 'google_meet',
			)
		);

		if ( ! is_array( $event ) || empty( $event['event_id'] ) ) {
			return false;
		}

		return array(
			'meeting_provider' => 'google_meet',
			'meeting_join_url' => (string) ( $event['meeting_join_url'] ?? '' ),
			'meeting_host_url' => (string) ( $event['meeting_host_url'] ?? '' ),
			'meeting_data'     => (array) ( $event['meeting_data'] ?? array() ),
			'gcal_event_id'    => (string) $event['event_id'],
		);
	}
}
