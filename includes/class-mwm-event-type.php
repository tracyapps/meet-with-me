<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Event Type data layer.
 *
 * All methods are static. DB table: {prefix}mwm_event_types
 *
 * The `fields` column stores a JSON array of custom field definitions:
 * [
 *   {
 *     "id": "uuid4-short",
 *     "label": "Where should we meet?",
 *     "type": "select",        // text | textarea | select | radio | checkbox
 *     "placeholder": "",
 *     "required": true,
 *     "options": ["Coffee shop", "My office"],
 *     "order": 0
 *   },
 *   ...
 * ]
 */
class MWM_Event_Type {

	/**
	 * Get a single event type by ID.
	 *
	 * @return array|null Row as associative array, or null if not found.
	 */
	public static function get( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}mwm_event_types WHERE id = %d", $id ),
			ARRAY_A
		);
		return $row ? self::parse( $row ) : null;
	}

	/**
	 * Get all event types, optionally filtered to active only.
	 *
	 * @return array[] Array of parsed row arrays.
	 */
	public static function get_all( bool $active_only = false ): array {
		global $wpdb;
		$where = $active_only ? 'WHERE is_active = 1' : '';
		$rows  = $wpdb->get_results(
			"SELECT * FROM {$wpdb->prefix}mwm_event_types {$where} ORDER BY name ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is a literal fragment
			ARRAY_A
		);
		return array_map( array( self::class, 'parse' ), $rows ?: array() );
	}

	/**
	 * Get a single event type by slug.
	 *
	 * @return array|null
	 */
	public static function get_by_slug( string $slug ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}mwm_event_types WHERE slug = %s", $slug ),
			ARRAY_A
		);
		return $row ? self::parse( $row ) : null;
	}

	/**
	 * Create a new event type.
	 *
	 * @param array $data Unsanitized input data.
	 * @return int|false New row ID, or false on failure.
	 */
	public static function create( array $data ): int|false {
		global $wpdb;
		$clean  = self::sanitize( $data );
		$result = $wpdb->insert(
			$wpdb->prefix . 'mwm_event_types',
			$clean,
			self::db_formats( $clean )
		);
		return $result ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Update an existing event type.
	 *
	 * @param int   $id   Row ID.
	 * @param array $data Unsanitized input data.
	 * @return bool
	 */
	public static function update( int $id, array $data ): bool {
		global $wpdb;
		$clean  = self::sanitize( $data );
		$result = $wpdb->update(
			$wpdb->prefix . 'mwm_event_types',
			$clean,
			array( 'id' => $id ),
			self::db_formats( $clean ),
			array( '%d' )
		);
		return $result !== false;
	}

	/**
	 * Delete an event type by ID.
	 *
	 * @return bool
	 */
	public static function delete( int $id ): bool {
		global $wpdb;
		return (bool) $wpdb->delete(
			$wpdb->prefix . 'mwm_event_types',
			array( 'id' => $id ),
			array( '%d' )
		);
	}

	/**
	 * Check whether a slug is already taken (optionally excluding a specific ID).
	 */
	public static function slug_exists( string $slug, int $exclude_id = 0 ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}mwm_event_types WHERE slug = %s AND id != %d",
				$slug,
				$exclude_id
			)
		);
	}

	/**
	 * Generate a URL-safe slug from a name. Appends -2, -3, etc. if taken.
	 */
	public static function generate_slug( string $name, int $exclude_id = 0 ): string {
		$base = sanitize_title( $name );
		$slug = $base;
		$i    = 2;
		while ( self::slug_exists( $slug, $exclude_id ) ) {
			$slug = $base . '-' . ( $i++ );
		}
		return $slug;
	}

	/**
	 * Return the parsed `fields` JSON array for an event type.
	 *
	 * @return array[] Array of field definition arrays.
	 */
	public static function get_fields( int $id ): array {
		$row = self::get( $id );
		return $row ? ( $row['fields'] ?? array() ) : array();
	}

	// -------------------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------------------

	/**
	 * Parse a raw DB row: decode JSON fields, cast types.
	 */
	private static function parse( array $row ): array {
		$row['id']                    = (int) $row['id'];
		$row['duration_minutes']      = (int) $row['duration_minutes'];
		$row['buffer_before']         = (int) $row['buffer_before'];
		$row['buffer_after']          = (int) $row['buffer_after'];
		$row['max_per_day']           = $row['max_per_day'] !== null ? (int) $row['max_per_day'] : null;
		$row['max_per_week']          = $row['max_per_week'] !== null ? (int) $row['max_per_week'] : null;
		$row['is_active']             = (bool) $row['is_active'];
		$row['fields']                = ! empty( $row['fields'] ) ? json_decode( $row['fields'], true ) : array();
		$row['online_provider_rules'] = ! empty( $row['online_provider_rules'] )
			? json_decode( $row['online_provider_rules'], true ) : array();
		return $row;
	}

	/**
	 * Sanitize and normalize input data before writing to DB.
	 */
	private static function sanitize( array $data ): array {
		$clean = array();

		if ( isset( $data['name'] ) ) {
			$clean['name'] = sanitize_text_field( $data['name'] );
		}
		if ( isset( $data['slug'] ) ) {
			$clean['slug'] = sanitize_title( $data['slug'] );
		}
		if ( isset( $data['description'] ) ) {
			$clean['description'] = sanitize_textarea_field( $data['description'] );
		}
		if ( isset( $data['duration_minutes'] ) ) {
			$clean['duration_minutes'] = max( 5, (int) $data['duration_minutes'] );
		}
		if ( isset( $data['meeting_type'] ) ) {
			$clean['meeting_type'] = in_array( $data['meeting_type'], array( 'online', 'in_person', 'both' ), true )
				? $data['meeting_type'] : 'both';
		}
		if ( array_key_exists( 'online_provider', $data ) ) {
			$clean['online_provider'] = sanitize_key( (string) $data['online_provider'] );
		}
		if ( array_key_exists( 'online_routing_field_id', $data ) ) {
			$clean['online_routing_field_id'] = sanitize_key( (string) $data['online_routing_field_id'] );
		}
		if ( array_key_exists( 'online_provider_rules', $data ) ) {
			$clean['online_provider_rules'] = self::sanitize_provider_rules( $data['online_provider_rules'] );
		}
		if ( isset( $data['buffer_before'] ) ) {
			$clean['buffer_before'] = max( 0, (int) $data['buffer_before'] );
		}
		if ( isset( $data['buffer_after'] ) ) {
			$clean['buffer_after'] = max( 0, (int) $data['buffer_after'] );
		}
		if ( array_key_exists( 'max_per_day', $data ) ) {
			$clean['max_per_day'] = ( $data['max_per_day'] === '' || $data['max_per_day'] === null )
				? null : max( 1, (int) $data['max_per_day'] );
		}
		if ( array_key_exists( 'max_per_week', $data ) ) {
			$clean['max_per_week'] = ( $data['max_per_week'] === '' || $data['max_per_week'] === null )
				? null : max( 1, (int) $data['max_per_week'] );
		}
		if ( isset( $data['color'] ) ) {
			$color          = sanitize_hex_color( $data['color'] );
			$clean['color'] = $color ?: '#3b82f6';
		}
		if ( isset( $data['fields'] ) ) {
			$clean['fields'] = self::sanitize_fields( $data['fields'] );
		}
		if ( isset( $data['is_active'] ) ) {
			$clean['is_active'] = (int) (bool) $data['is_active'];
		}

		return $clean;
	}

	/**
	 * Sanitize the custom fields JSON before storing.
	 */
	private static function sanitize_fields( mixed $fields_input ): string {
		if ( is_string( $fields_input ) ) {
			$fields_input = json_decode( wp_unslash( $fields_input ), true );
		}
		if ( ! is_array( $fields_input ) ) {
			return '[]';
		}

		$clean   = array();
		$allowed = array( 'text', 'textarea', 'select', 'radio', 'checkbox' );

		foreach ( $fields_input as $i => $field ) {
			if ( ! is_array( $field ) || empty( $field['label'] ) ) {
				continue;
			}

			$type    = in_array( $field['type'] ?? '', $allowed, true ) ? $field['type'] : 'text';
			$options = array();

			if ( in_array( $type, array( 'select', 'radio', 'checkbox' ), true ) && ! empty( $field['options'] ) ) {
				foreach ( (array) $field['options'] as $opt ) {
					$opt = sanitize_text_field( $opt );
					if ( $opt !== '' ) {
						$options[] = $opt;
					}
				}
			}

			$clean[] = array(
				'id'          => sanitize_key( $field['id'] ?? uniqid( 'f', false ) ),
				'label'       => sanitize_text_field( $field['label'] ),
				'type'        => $type,
				'placeholder' => sanitize_text_field( $field['placeholder'] ?? '' ),
				'required'    => (bool) ( $field['required'] ?? false ),
				'options'     => $options,
				'order'       => (int) ( $field['order'] ?? $i ),
			);
		}

		usort( $clean, fn( $a, $b ) => $a['order'] <=> $b['order'] );

		return wp_json_encode( $clean );
	}

	/**
	 * Sanitize the provider routing rules JSON before storing.
	 */
	private static function sanitize_provider_rules( mixed $rules_input ): string {
		if ( is_string( $rules_input ) ) {
			$decoded     = json_decode( wp_unslash( $rules_input ), true );
			$rules_input = is_array( $decoded ) ? $decoded : array();
		}

		if ( ! is_array( $rules_input ) ) {
			return '{}';
		}

		$clean = array();
		foreach ( $rules_input as $option => $provider ) {
			$option = sanitize_text_field( (string) $option );
			if ( $option === '' ) {
				continue;
			}

			$provider = sanitize_key( (string) $provider );
			if ( $provider === '' ) {
				continue;
			}

			$clean[ $option ] = $provider;
		}

		return wp_json_encode( $clean );
	}

	/**
	 * Return wpdb format strings for the given data array.
	 */
	private static function db_formats( array $data ): array {
		$int_fields = array( 'duration_minutes', 'buffer_before', 'buffer_after', 'max_per_day', 'max_per_week', 'is_active' );
		$formats    = array();
		foreach ( $data as $key => $value ) {
			if ( in_array( $key, $int_fields, true ) ) {
				$formats[] = $value === null ? null : '%d';
			} else {
				$formats[] = '%s';
			}
		}
		return $formats;
	}
}
