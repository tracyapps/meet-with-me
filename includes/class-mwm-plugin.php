<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core plugin loader. Bootstraps all components.
 */
class MWM_Plugin {

	private static ?MWM_Plugin $instance = null;

	private function __construct() {
		$this->load_dependencies();
		$this->init_hooks();
	}

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function load_dependencies(): void {
		require_once MWM_PLUGIN_DIR . 'includes/class-mwm-event-type.php';
		require_once MWM_PLUGIN_DIR . 'includes/class-mwm-availability.php';
		require_once MWM_PLUGIN_DIR . 'includes/class-mwm-booking.php';
		require_once MWM_PLUGIN_DIR . 'api/class-mwm-rest.php';
		require_once MWM_PLUGIN_DIR . 'includes/class-mwm-google-calendar.php';
		require_once MWM_PLUGIN_DIR . 'includes/class-mwm-zoom.php';
		require_once MWM_PLUGIN_DIR . 'includes/class-mwm-online-meetings.php';
		require_once MWM_PLUGIN_DIR . 'includes/class-mwm-ics.php';
		require_once MWM_PLUGIN_DIR . 'includes/class-mwm-email.php';
		require_once MWM_PLUGIN_DIR . 'includes/class-mwm-blocks.php';

		if ( is_admin() ) {
			require_once MWM_PLUGIN_DIR . 'admin/class-mwm-admin.php';
		} else {
			require_once MWM_PLUGIN_DIR . 'public/class-mwm-public.php';
		}
	}

	private function init_hooks(): void {
		add_action( 'init', array( $this, 'maybe_upgrade_db' ), 1 );
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_action( 'admin_init', array( $this, 'add_privacy_policy_content' ) );
		add_action( 'mwm_booking_confirmed', array( $this, 'on_booking_confirmed' ), 10, 2 );
		add_action( 'mwm_booking_cancelled', array( $this, 'on_booking_cancelled' ), 10, 2 );
		add_action( 'mwm_booking_rescheduled', array( $this, 'on_booking_rescheduled' ), 10, 3 );

		if ( is_admin() ) {
			( new MWM_Admin() )->init();
		} else {
			( new MWM_Public() )->init();
		}

		// Blocks must be registered in both admin and front-end contexts.
		MWM_Blocks::init();
	}

	public function on_booking_confirmed( array $booking, array $event_type ): void {
		$booking = $this->maybe_prepare_online_meeting( $booking, $event_type );
		$booking = $this->maybe_sync_google_calendar( $booking, $event_type );

		$this->safe_side_effect(
			'booking confirmation email',
			function () use ( $booking, $event_type ) {
				( new MWM_Email() )->send_confirmation( $booking, $event_type );
			}
		);

		$this->safe_side_effect(
			'admin booking notification',
			function () use ( $booking, $event_type ) {
				( new MWM_Email() )->send_admin_notification( $booking, $event_type );
			}
		);
	}

	public function on_booking_cancelled( array $booking, array $event_type ): void {
		$this->safe_side_effect(
			'booking cancellation email',
			function () use ( $booking, $event_type ) {
				( new MWM_Email() )->send_cancellation( $booking, $event_type );
			}
		);

		$this->safe_side_effect(
			'online meeting cleanup',
			function () use ( $booking ) {
				MWM_Online_Meetings::delete_for_booking( $booking );
			}
		);

		if ( MWM_Google_Calendar::is_connected() && ! empty( $booking['gcal_event_id'] ) ) {
			$this->safe_side_effect(
				'Google Calendar delete event',
				function () use ( $booking ) {
					MWM_Google_Calendar::delete_event( $booking['gcal_event_id'] );
				}
			);
		}
	}

	public function on_booking_rescheduled( array $new_booking, array $old_booking, array $event_type ): void {
		$this->safe_side_effect(
			'online meeting cleanup',
			function () use ( $old_booking ) {
				MWM_Online_Meetings::delete_for_booking( $old_booking );
			}
		);

		if ( MWM_Google_Calendar::is_connected() ) {
			$this->safe_side_effect(
				'Google Calendar reschedule sync',
				function () use ( $new_booking, $old_booking, $event_type ) {
					if ( ! empty( $old_booking['gcal_event_id'] ) ) {
						MWM_Google_Calendar::delete_event( $old_booking['gcal_event_id'] );
					}
				}
			);
		}

		$new_booking = $this->maybe_prepare_online_meeting( $new_booking, $event_type );
		$new_booking = $this->maybe_sync_google_calendar( $new_booking, $event_type );

		$this->safe_side_effect(
			'booking reschedule email',
			function () use ( $new_booking, $event_type ) {
				( new MWM_Email() )->send_reschedule_confirmation( $new_booking, $event_type );
			}
		);
	}

	public function register_rest_routes(): void {
		( new MWM_REST() )->register_routes();
	}

	/**
	 * Suggest privacy policy text for the stored booking data.
	 */
	public function add_privacy_policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content  = '<p>' . esc_html__( 'Meet With Me stores booking information submitted through its booking forms: the booker’s name, email address, optional phone number and notes, answers to any custom booking questions, the chosen meeting time, and the booker’s timezone.', 'meet-with-me' ) . '</p>';
		$content .= '<p>' . esc_html__( 'This information is used solely to schedule and manage the requested meeting, to send booking notifications, and to manage cancellations and reschedules. It is stored in the site’s WordPress database and retained until an administrator deletes the booking or uninstalls the plugin.', 'meet-with-me' ) . '</p>';
		$content .= '<p>' . esc_html__( 'If Google Calendar or Zoom integrations are enabled, meeting details are shared with those services as needed to create calendar events and online meeting links. Bookers can request deletion of their data by contacting the site owner.', 'meet-with-me' ) . '</p>';

		wp_add_privacy_policy_content( 'Meet With Me', wp_kses_post( $content ) );
	}

	public function load_textdomain(): void {
		load_plugin_textdomain(
			'meet-with-me',
			false,
			dirname( MWM_PLUGIN_BASENAME ) . '/languages'
		);
	}

	private function safe_side_effect( string $label, callable $callback ): void {
		try {
			$callback();
		} catch ( \Throwable $e ) {
			error_log( '[Meet With Me] ' . $label . ' failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- deliberate failure log
		}
	}

	private function maybe_prepare_online_meeting( array $booking, array $event_type ): array {
		$updates = null;

		$this->safe_side_effect(
			'online meeting provisioning',
			function () use ( $booking, $event_type, &$updates ) {
				$updates = MWM_Online_Meetings::create_for_booking( $booking, $event_type );
				if ( is_array( $updates ) && ! empty( $updates ) ) {
					MWM_Booking::update( $booking['id'], $updates );
				}
			}
		);

		if ( is_array( $updates ) && ! empty( $updates ) ) {
			$fresh = MWM_Booking::get( $booking['id'] );
			if ( $fresh ) {
				return $fresh;
			}
		}

		return $booking;
	}

	private function maybe_sync_google_calendar( array $booking, array $event_type ): array {
		if ( ! MWM_Google_Calendar::is_connected() || ! empty( $booking['gcal_event_id'] ) ) {
			return $booking;
		}

		$gcal_id = false;
		$this->safe_side_effect(
			'Google Calendar create event',
			function () use ( $booking, $event_type, &$gcal_id ) {
				$gcal_id = MWM_Google_Calendar::create_event( $booking, $event_type );
				if ( $gcal_id ) {
					MWM_Booking::update( $booking['id'], array( 'gcal_event_id' => $gcal_id ) );
				}
			}
		);

		if ( $gcal_id ) {
			$fresh = MWM_Booking::get( $booking['id'] );
			if ( $fresh ) {
				return $fresh;
			}
		}

		return $booking;
	}

	/**
	 * Run DB upgrades when plugin version changes.
	 */
	public function maybe_upgrade_db(): void {
		$installed = get_option( 'mwm_db_version', '0' );
		if ( version_compare( $installed, MWM_VERSION, '<' ) ) {
			MWM_Install::create_tables();
			MWM_Install::set_default_options();
			update_option( 'mwm_db_version', MWM_VERSION );
		}
	}
}

/**
 * Render the settings tab navigation.
 *
 * @param string $current Active tab key.
 */
function mwm_admin_tabs( string $current ): void { // phpcs:ignore Universal.Files.SeparateFunctionsFromOO.Mixed -- shared template helpers
	$tabs = array(
		'general'      => __( 'General', 'meet-with-me' ),
		'availability' => __( 'Availability', 'meet-with-me' ),
		'google'       => __( 'Google Calendar', 'meet-with-me' ),
		'meetings'     => __( 'Online Meetings', 'meet-with-me' ),
		'email'        => __( 'Email Templates', 'meet-with-me' ),
		'style'        => __( 'Style', 'meet-with-me' ),
		'help'         => __( 'Help & Setup', 'meet-with-me' ),
	);
	echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__( 'Settings tabs', 'meet-with-me' ) . '">';
	foreach ( $tabs as $key => $label ) {
		$url   = add_query_arg(
			array(
				'page' => 'mwm-settings',
				'tab'  => $key,
			),
			admin_url( 'admin.php' )
		);
		$class = 'nav-tab' . ( $current === $key ? ' nav-tab-active' : '' );
		printf(
			'<a href="%s" class="%s"%s>%s</a>',
			esc_url( $url ),
			esc_attr( $class ),
			$current === $key ? ' aria-current="page"' : '',
			esc_html( $label )
		);
	}
	echo '</nav>';
}

/**
 * Return an HTML status badge for a booking status string.
 *
 * @param string $status 'confirmed'|'cancelled'|'rescheduled'
 * @return string  Safe HTML string.
 */
function mwm_booking_status_badge( string $status ): string {
	$map   = array(
		'confirmed'   => array(
			'class' => 'mwm-badge--active',
			'label' => __( 'Confirmed', 'meet-with-me' ),
		),
		'cancelled'   => array(
			'class' => 'mwm-badge--cancelled',
			'label' => __( 'Cancelled', 'meet-with-me' ),
		),
		'rescheduled' => array(
			'class' => 'mwm-badge--rescheduled',
			'label' => __( 'Rescheduled', 'meet-with-me' ),
		),
	);
	$entry = $map[ $status ] ?? array(
		'class' => '',
		'label' => ucfirst( $status ),
	);
	return sprintf(
		'<span class="mwm-badge %s">%s</span>',
		esc_attr( $entry['class'] ),
		esc_html( $entry['label'] )
	);
}
