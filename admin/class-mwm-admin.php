<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the WP Admin menu and dispatches to page controllers.
 */
class MWM_Admin {

	public function init(): void {
		add_action( 'admin_menu', array( $this, 'register_menus' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'current_screen', array( $this, 'add_help_tabs' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_zoom_error_notice' ) );
		add_action( 'admin_init', array( $this, 'maybe_dismiss_zoom_error' ) );
		add_action( 'wp_ajax_mwm_test_zoom', array( $this, 'ajax_test_zoom' ) );
	}

	public function register_menus(): void {
		add_menu_page(
			__( 'Meet With Me', 'meet-with-me' ),
			__( 'Meet With Me', 'meet-with-me' ),
			'manage_options',
			'meet-with-me',
			array( $this, 'page_bookings' ),
			'dashicons-calendar-alt',
			30
		);

		add_submenu_page(
			'meet-with-me',
			__( 'Bookings', 'meet-with-me' ),
			__( 'Bookings', 'meet-with-me' ),
			'manage_options',
			'meet-with-me',
			array( $this, 'page_bookings' )
		);

		add_submenu_page(
			'meet-with-me',
			__( 'Meeting Types', 'meet-with-me' ),
			__( 'Meeting Types', 'meet-with-me' ),
			'manage_options',
			'mwm-event-types',
			array( $this, 'page_event_types' )
		);

		add_submenu_page(
			'meet-with-me',
			__( 'Settings', 'meet-with-me' ),
			__( 'Settings', 'meet-with-me' ),
			'manage_options',
			'mwm-settings',
			array( $this, 'page_settings' )
		);
	}

	public function page_bookings(): void {
		require_once MWM_PLUGIN_DIR . 'includes/class-mwm-booking.php';
		require_once MWM_PLUGIN_DIR . 'admin/class-mwm-admin-bookings.php';
		( new MWM_Admin_Bookings() )->dispatch();
	}

	public function page_event_types(): void {
		require_once MWM_PLUGIN_DIR . 'admin/class-mwm-admin-event-types.php';
		( new MWM_Admin_Event_Types() )->dispatch();
	}

	public function page_settings(): void {
		$tab          = sanitize_key( wp_unslash( $_GET['tab'] ?? 'general' ) );
		$allowed_tabs = array( 'general', 'availability', 'google', 'meetings', 'email', 'style', 'help' );
		if ( ! in_array( $tab, $allowed_tabs, true ) ) {
			$tab = 'general';
		}

		if ( $tab === 'general' ) {
			require_once MWM_PLUGIN_DIR . 'admin/class-mwm-admin-general.php';
			( new MWM_Admin_General() )->dispatch();
			return;
		}

		if ( $tab === 'availability' ) {
			require_once MWM_PLUGIN_DIR . 'includes/class-mwm-availability.php';
			require_once MWM_PLUGIN_DIR . 'admin/class-mwm-admin-availability.php';
			( new MWM_Admin_Availability() )->dispatch();
			return;
		}

		if ( $tab === 'google' ) {
			require_once MWM_PLUGIN_DIR . 'includes/class-mwm-google-calendar.php';
			require_once MWM_PLUGIN_DIR . 'admin/class-mwm-admin-google.php';
			( new MWM_Admin_Google() )->dispatch();
			return;
		}

		if ( $tab === 'meetings' ) {
			require_once MWM_PLUGIN_DIR . 'admin/class-mwm-admin-meetings.php';
			( new MWM_Admin_Meetings() )->dispatch();
			return;
		}

		if ( $tab === 'email' ) {
			require_once MWM_PLUGIN_DIR . 'includes/class-mwm-email.php';
			require_once MWM_PLUGIN_DIR . 'admin/class-mwm-admin-email.php';
			( new MWM_Admin_Email() )->dispatch();
			return;
		}

		if ( $tab === 'style' ) {
			require_once MWM_PLUGIN_DIR . 'admin/class-mwm-admin-style.php';
			( new MWM_Admin_Style() )->dispatch();
			return;
		}

		if ( $tab === 'help' ) {
			$current_tab = 'help';
			require MWM_PLUGIN_DIR . 'admin/views/settings/help.php';
			return;
		}

		require_once MWM_PLUGIN_DIR . "admin/views/settings/{$tab}.php";
	}

	public function enqueue_assets( string $hook ): void {
		if ( ! str_contains( $hook, 'meet-with-me' ) && ! str_contains( $hook, 'mwm-' ) ) {
			return;
		}

		wp_enqueue_style(
			'mwm-admin',
			MWM_PLUGIN_URL . 'admin/assets/css/admin.css',
			array(),
			MWM_VERSION
		);

		// WP color pickers are only needed on the Style tab.
		$tab          = sanitize_key( wp_unslash( $_GET['tab'] ?? '' ) );
		$is_style_tab = str_contains( $hook, 'mwm-settings' ) && $tab === 'style';
		if ( $is_style_tab ) {
			wp_enqueue_style( 'wp-color-picker' );
		}

		$script_deps = array( 'jquery', 'jquery-ui-sortable' );
		if ( $is_style_tab ) {
			$script_deps[] = 'wp-color-picker';
		}

		wp_enqueue_script(
			'mwm-admin',
			MWM_PLUGIN_URL . 'admin/assets/js/admin.js',
			$script_deps,
			MWM_VERSION,
			true
		);

		wp_localize_script(
			'mwm-admin',
			'mwmAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'mwm_admin' ),
				'strings' => array(
					'areYouSure'       => __( 'Are you sure?', 'meet-with-me' ),
					'copied'           => __( 'Copied!', 'meet-with-me' ),
					'copiedAnnounce'   => __( 'Copied to clipboard.', 'meet-with-me' ),
					'copyFallback'     => __( 'Text selected — press Ctrl/Cmd+C to copy.', 'meet-with-me' ),
					'loading'          => __( 'Loading…', 'meet-with-me' ),
					'refreshCalendars' => __( 'Refresh My Calendars', 'meet-with-me' ),
					'calsFailed'       => __( 'Could not load calendars.', 'meet-with-me' ),
					'requestFailed'    => __( 'Request failed. Please try again.', 'meet-with-me' ),
					'noCals'           => __( 'No calendars were returned from Google.', 'meet-with-me' ),
					'readOnly'         => __( 'Read only', 'meet-with-me' ),
					'primarySuffix'    => __( '(primary)', 'meet-with-me' ),
					'noWriteBack'      => __( 'Do not create Google Calendar events', 'meet-with-me' ),
					'selectRouting'    => __( 'Select a routing field above to map specific answers to connected providers.', 'meet-with-me' ),
					'noRouting'        => __( 'No conditional routing', 'meet-with-me' ),
					'useDefault'       => __( 'Use default provider', 'meet-with-me' ),
					'optionText'       => __( 'Option text', 'meet-with-me' ),
					'removeOption'     => __( 'Remove option', 'meet-with-me' ),
					'moveUp'           => __( 'Move question up', 'meet-with-me' ),
					'moveDown'         => __( 'Move question down', 'meet-with-me' ),
					'testing'          => __( 'Testing…', 'meet-with-me' ),
					'testConnection'   => __( 'Test Connection', 'meet-with-me' ),
				),
			)
		);
	}

	/**
	 * Contextual help tabs for the plugin's admin screens.
	 */
	public function add_help_tabs(): void {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		$ours = array(
			'toplevel_page_meet-with-me',
			'meet-with-me_page_mwm-event-types',
			'meet-with-me_page_mwm-settings',
		);

		if ( ! in_array( $screen->id, $ours, true ) ) {
			return;
		}

		$help_url = add_query_arg(
			array(
				'page' => 'mwm-settings',
				'tab'  => 'help',
			),
			admin_url( 'admin.php' )
		);

		if ( $screen->id === 'toplevel_page_meet-with-me' ) {
			$screen->add_help_tab(
				array(
					'id'      => 'mwm-help-bookings',
					'title'   => __( 'Bookings', 'meet-with-me' ),
					'content' =>
						'<p>' . esc_html__( 'Every booking made through your booking forms appears on this screen. Use the status links to filter, and the search box to find a booker by name or email.', 'meet-with-me' ) . '</p>' .
						'<p>' . esc_html__( 'Click a booker to open the booking details, where you can add private notes, cancel the booking, or copy the booker’s private cancel/reschedule link.', 'meet-with-me' ) . '</p>',
				)
			);
		}

		if ( $screen->id === 'meet-with-me_page_mwm-event-types' ) {
			$screen->add_help_tab(
				array(
					'id'      => 'mwm-help-event-types',
					'title'   => __( 'Meeting Types', 'meet-with-me' ),
					'content' =>
						'<p>' . esc_html__( 'Meeting types define what people can book: duration, format, buffers, booking limits, and any custom questions.', 'meet-with-me' ) . '</p>' .
						'<p>' . esc_html__( 'Embed them anywhere with the shortcodes shown in the sidebar of each meeting type, e.g. [mwm_cards], [mwm_button event_type="your-slug"], or [mwm_booking_form event_type="your-slug"].', 'meet-with-me' ) . '</p>',
				)
			);
		}

		if ( $screen->id === 'meet-with-me_page_mwm-settings' ) {
			$screen->add_help_tab(
				array(
					'id'      => 'mwm-help-settings',
					'title'   => __( 'Settings', 'meet-with-me' ),
					'content' =>
						'<p>' . esc_html__( 'General sets your name, notification email, timezone, and booking windows. Availability controls your weekly hours, date overrides, and days off.', 'meet-with-me' ) . '</p>' .
						'<p>' . esc_html__( 'Google Calendar and Online Meetings connect external services. Style controls the front-end appearance. Email Templates lets you customise every notification.', 'meet-with-me' ) . '</p>' .
						'<p>' . sprintf(
							/* translators: %s = link to the Help & Setup tab */
							esc_html__( 'Need a walkthrough? See %s.', 'meet-with-me' ),
							'<a href="' . esc_url( $help_url ) . '">' . esc_html__( 'Help & Setup', 'meet-with-me' ) . '</a>'
						) . '</p>',
				)
			);
		}

		$screen->set_help_sidebar(
			'<p><strong>' . esc_html__( 'Meet With Me', 'meet-with-me' ) . '</strong></p>' .
			'<p><a href="' . esc_url( $help_url ) . '">' . esc_html__( 'Full help & setup →', 'meet-with-me' ) . '</a></p>'
		);
	}

	/**
	 * Surface the most recent Zoom booking failure as an admin notice on MWM screens.
	 */
	public function maybe_show_zoom_error_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || ( ! str_contains( $screen->id, 'meet-with-me' ) && ! str_contains( $screen->id, 'mwm-' ) ) ) {
			return;
		}

		$error = get_transient( 'mwm_zoom_last_error' );
		if ( ! is_array( $error ) || empty( $error['reason'] ) ) {
			return;
		}

		$dismiss_url = wp_nonce_url( add_query_arg( 'mwm_dismiss_zoom_error', '1' ), 'mwm_dismiss_zoom_error' );

		printf(
			'<div class="notice notice-warning"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
			esc_html(
				sprintf(
				/* translators: 1: booking ID, 2: error reason */
					__( 'A Zoom meeting could not be created for booking #%1$d: %2$s', 'meet-with-me' ),
					(int) ( $error['booking_id'] ?? 0 ),
					(string) $error['reason']
				)
			),
			esc_url( $dismiss_url ),
			esc_html__( 'Dismiss', 'meet-with-me' )
		);
	}

	/**
	 * Persistently dismiss the Zoom error notice.
	 */
	public function maybe_dismiss_zoom_error(): void {
		if ( ! isset( $_GET['mwm_dismiss_zoom_error'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		check_admin_referer( 'mwm_dismiss_zoom_error' );
		delete_transient( 'mwm_zoom_last_error' );

		wp_safe_redirect( remove_query_arg( array( 'mwm_dismiss_zoom_error', '_wpnonce' ) ) );
		exit;
	}

	/**
	 * AJAX handler: test the stored Zoom credentials.
	 */
	public function ajax_test_zoom(): void {
		check_ajax_referer( 'mwm_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'meet-with-me' ) ), 403 );
		}

		if ( ! class_exists( 'MWM_Zoom' ) ) {
			require_once MWM_PLUGIN_DIR . 'includes/class-mwm-zoom.php';
		}

		$result = MWM_Zoom::test_connection();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => __( 'Connected to Zoom successfully.', 'meet-with-me' ) ) );
	}
}
