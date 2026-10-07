<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles plugin activation, deactivation, and database schema creation.
 */
class MWM_Install {

	public static function activate(): void {
		self::create_tables();
		self::set_default_options();
		flush_rewrite_rules();
		update_option( 'mwm_db_version', MWM_VERSION );
	}

	public static function deactivate(): void {
		flush_rewrite_rules();
	}

	public static function create_tables(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta(
			"CREATE TABLE {$wpdb->prefix}mwm_event_types (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			name varchar(255) NOT NULL,
			slug varchar(255) NOT NULL,
			description text DEFAULT NULL,
			duration_minutes int(11) NOT NULL DEFAULT 30,
			meeting_type enum('online','in_person','both') NOT NULL DEFAULT 'both',
			online_provider varchar(50) NOT NULL DEFAULT '',
			online_routing_field_id varchar(100) DEFAULT NULL,
			online_provider_rules longtext DEFAULT NULL,
			buffer_before int(11) NOT NULL DEFAULT 0,
			buffer_after int(11) NOT NULL DEFAULT 0,
			max_per_day int(11) DEFAULT NULL,
			max_per_week int(11) DEFAULT NULL,
			color varchar(7) NOT NULL DEFAULT '#3b82f6',
			fields longtext DEFAULT NULL,
			availability_override longtext DEFAULT NULL,
			is_active tinyint(1) NOT NULL DEFAULT 1,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug)
		) $charset_collate;"
		);

		dbDelta(
			"CREATE TABLE {$wpdb->prefix}mwm_bookings (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			event_type_id bigint(20) UNSIGNED NOT NULL,
			booker_name varchar(255) NOT NULL,
			booker_email varchar(255) NOT NULL,
			booker_phone varchar(50) DEFAULT NULL,
			booker_notes text DEFAULT NULL,
			field_answers longtext DEFAULT NULL,
			start_datetime datetime NOT NULL,
			end_datetime datetime NOT NULL,
			booker_timezone varchar(100) NOT NULL DEFAULT 'UTC',
			meeting_type enum('online','in_person') NOT NULL DEFAULT 'online',
			meeting_provider varchar(50) NOT NULL DEFAULT '',
			meeting_join_url text DEFAULT NULL,
			meeting_host_url text DEFAULT NULL,
			meeting_data longtext DEFAULT NULL,
			status enum('confirmed','cancelled','rescheduled') NOT NULL DEFAULT 'confirmed',
			cancel_token varchar(64) NOT NULL,
			reschedule_token varchar(64) NOT NULL,
			gcal_event_id varchar(255) DEFAULT NULL,
			admin_notes text DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY cancel_token (cancel_token),
			UNIQUE KEY reschedule_token (reschedule_token),
			UNIQUE KEY slot_datetime (event_type_id, start_datetime),
			KEY event_type_id (event_type_id),
			KEY start_datetime (start_datetime),
			KEY status (status)
		) ENGINE=InnoDB $charset_collate;"
		);

		dbDelta(
			"CREATE TABLE {$wpdb->prefix}mwm_availability_rules (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			rule_type enum('weekly','override') NOT NULL DEFAULT 'weekly',
			day_of_week tinyint(1) DEFAULT NULL,
			override_date date DEFAULT NULL,
			start_time time NOT NULL,
			end_time time NOT NULL,
			is_available tinyint(1) NOT NULL DEFAULT 1,
			PRIMARY KEY  (id),
			KEY rule_type (rule_type),
			KEY day_of_week (day_of_week),
			KEY override_date (override_date)
		) $charset_collate;"
		);

		dbDelta(
			"CREATE TABLE {$wpdb->prefix}mwm_blocked_dates (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			blocked_date date NOT NULL,
			reason varchar(255) DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY blocked_date (blocked_date)
		) $charset_collate;"
		);
	}

	public static function set_default_options(): void {
		if ( false === get_option( 'mwm_general' ) ) {
			$wp_tz = get_option( 'timezone_string' );
			if ( ! $wp_tz ) {
				$offset = (float) get_option( 'gmt_offset', 0 );
				$wp_tz  = timezone_name_from_abbr( '', (int) ( $offset * 3600 ), false ) ?: 'UTC';
			}
			update_option(
				'mwm_general',
				array(
					'timezone'         => $wp_tz,
					'admin_name'       => get_option( 'blogname' ),
					'admin_email'      => get_option( 'admin_email' ),
					'min_notice_hours' => 24,
					'max_advance_days' => 60,
				)
			);
		}

		if ( false === get_option( 'mwm_google' ) ) {
			update_option(
				'mwm_google',
				array(
					'client_id'              => '',
					'client_secret'          => '',
					'access_token'           => '',
					'refresh_token'          => '',
					'token_expiry'           => 0,
					'calendar_ids'           => array(),
					'write_back_calendar_id' => '',
				)
			);
		}

		if ( false === get_option( 'mwm_meetings' ) ) {
			update_option(
				'mwm_meetings',
				array(
					'zoom_account_id'       => '',
					'zoom_client_id'        => '',
					'zoom_client_secret'    => '',
					'zoom_user_id'          => 'me',
					'zoom_default_password' => '',
					'zoom_waiting_room'     => 0,
					'zoom_join_before_host' => 0,
				)
			);
		}

		if ( false === get_option( 'mwm_style' ) ) {
			update_option(
				'mwm_style',
				array(
					'accent_color'      => '',
					'accent_text_color' => '',
					'button_style'      => 'outline',
					'button_radius'     => '',
					'surface_mode'      => 'theme',
					'density'           => 'comfortable',
					'max_width'         => 560,
					'custom_css'        => '',
				)
			);
		}

		if ( false === get_option( 'mwm_email' ) ) {
			update_option(
				'mwm_email',
				array(
					'from_name'            => get_option( 'blogname' ),
					'from_email'           => get_option( 'admin_email' ),
					'confirmation_subject' => 'Your booking is confirmed — {event_name} on {date}',
					'confirmation_body'    => self::default_email( 'confirmation' ),
					'admin_subject'        => 'New booking: {booker_name} — {event_name} on {date}',
					'admin_body'           => self::default_email( 'admin' ),
					'cancellation_subject' => 'Booking cancelled — {event_name} on {date}',
					'cancellation_body'    => self::default_email( 'cancellation' ),
					'reschedule_subject'   => 'Your booking has been rescheduled — {event_name} on {date}',
					'reschedule_body'      => self::default_email( 'reschedule' ),
				)
			);
		}

		self::seed_default_availability();
	}

	private static function seed_default_availability(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'mwm_availability_rules';

		if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) > 0 ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is an internal table name
			return;
		}

		foreach ( array( 1, 2, 3, 4, 5 ) as $day ) {
			$wpdb->insert(
				$table,
				array(
					'rule_type'    => 'weekly',
					'day_of_week'  => $day,
					'start_time'   => '09:00:00',
					'end_time'     => '17:00:00',
					'is_available' => 1,
				)
			);
		}
	}

	private static function default_email( string $type ): string {
		return match ( $type ) {
			'confirmation' => "Hi {booker_name},\n\nYour booking is confirmed!\n\n📅 {event_name}\n🕐 {date} at {time} ({timezone})\n📍 {meeting_type_label}\n{meeting_details}\n\nNeed to cancel or reschedule?\n{manage_url}\n\nSee you then!\n{admin_name}",
			'admin'        => "New booking received:\n\n📅 {event_name}\n👤 {booker_name} ({booker_email})\n🕐 {date} at {time} ({booker_timezone})\n📍 {meeting_type_label}\n{meeting_details}\n\n{field_answers}\n\nView booking in dashboard:\n{admin_booking_url}",
			'cancellation' => "Hi {booker_name},\n\nYour booking has been cancelled.\n\n📅 {event_name}\n🕐 {date} at {time} ({timezone})\n\nWant to book a new time?\n{booking_url}\n\n{admin_name}",
			'reschedule'   => "Hi {booker_name},\n\nYour booking has been rescheduled.\n\n📅 {event_name}\n🕐 {date} at {time} ({timezone})\n📍 {meeting_type_label}\n{meeting_details}\n\nNeed to make another change?\n{manage_url}\n\nSee you then!\n{admin_name}",
			default        => '',
		};
	}
}
