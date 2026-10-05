<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings registry with in-request caching.
 *
 * Usage:
 *   MWM_Settings::get( 'timezone' );                      // from 'general' group
 *   MWM_Settings::get( 'client_id', 'google' );
 *   MWM_Settings::set( 'min_notice_hours', 48 );
 *   MWM_Settings::set_group( [ 'from_name' => 'Tracy' ], 'email' );
 */
class MWM_Settings {

	private static array $cache = array();

	private static array $defaults = array(
		'general'  => array(
			'timezone'         => 'UTC',
			'admin_name'       => '',
			'admin_email'      => '',
			'min_notice_hours' => 24,
			'max_advance_days' => 60,
		),
		'google'   => array(
			'client_id'              => '',
			'client_secret'          => '',
			'access_token'           => '',
			'refresh_token'          => '',
			'token_expiry'           => 0,
			'calendar_ids'           => array(),
			'write_back_calendar_id' => '',
		),
		'meetings' => array(
			'zoom_account_id'       => '',
			'zoom_client_id'        => '',
			'zoom_client_secret'    => '',
			'zoom_user_id'          => 'me',
			'zoom_default_password' => '',
			'zoom_waiting_room'     => 0,
			'zoom_join_before_host' => 0,
		),
		'email'    => array(
			'from_name'            => '',
			'from_email'           => '',
			'confirmation_subject' => '',
			'confirmation_body'    => '',
			'admin_subject'        => '',
			'admin_body'           => '',
			'cancellation_subject' => '',
			'cancellation_body'    => '',
			'reschedule_subject'   => '',
			'reschedule_body'      => '',
		),
		'style'    => array(
			'accent_color'      => '',
			'accent_text_color' => '',
			'button_style'      => 'outline',
			'button_radius'     => '',
			'surface_mode'      => 'theme',
			'density'           => 'comfortable',
			'max_width'         => 560,
			'custom_css'        => '',
		),
	);

	/**
	 * Get a single setting value.
	 */
	public static function get( string $key, string $group = 'general' ): mixed {
		$all = self::get_all( $group );
		return $all[ $key ] ?? ( self::$defaults[ $group ][ $key ] ?? null );
	}

	/**
	 * Get all settings for a group, merged with defaults.
	 */
	public static function get_all( string $group = 'general' ): array {
		if ( ! isset( self::$cache[ $group ] ) ) {
			$stored                = get_option( "mwm_{$group}", array() );
			self::$cache[ $group ] = array_merge(
				self::$defaults[ $group ] ?? array(),
				is_array( $stored ) ? $stored : array()
			);
		}
		return self::$cache[ $group ];
	}

	/**
	 * Set a single setting value.
	 */
	public static function set( string $key, mixed $value, string $group = 'general' ): bool {
		$all                   = self::get_all( $group );
		$all[ $key ]           = $value;
		self::$cache[ $group ] = $all;
		return (bool) update_option( "mwm_{$group}", $all );
	}

	/**
	 * Merge and save an array of values into a group.
	 */
	public static function set_group( array $values, string $group = 'general' ): bool {
		$merged                = array_merge( self::get_all( $group ), $values );
		self::$cache[ $group ] = $merged;
		return (bool) update_option( "mwm_{$group}", $merged );
	}

	/**
	 * Clear the in-request cache for one or all groups.
	 */
	public static function flush_cache( ?string $group = null ): void {
		if ( $group ) {
			unset( self::$cache[ $group ] );
		} else {
			self::$cache = array();
		}
	}
}
