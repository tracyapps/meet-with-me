<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles all frontend output: asset enqueue, shortcodes, modal footer injection,
 * and the manage-booking page (?mwm_token=).
 */
class MWM_Public {

	/**
	 * Whether the current request renders booking UI (shortcode or block).
	 *
	 * Set by the shortcode renderers and by the block render callbacks; drives
	 * the conditional asset loading and the footer modal.
	 *
	 * @var bool
	 */
	public static bool $needs_assets = false;

	/** Shortcodes that render booking UI and need the front-end assets. */
	private const SHORTCODE_TAGS = array( 'mwm_booking_form', 'mwm_button', 'mwm_cards' );

	/** Blocks that render booking UI and need the front-end assets. */
	private const BLOCK_TAGS = array( 'meet-with-me/booking-form', 'meet-with-me/button', 'meet-with-me/cards' );

	public function init(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_assets' ) );
		add_action( 'wp_footer', array( $this, 'render_modal' ) );
		add_action( 'template_redirect', array( $this, 'maybe_render_manage_page' ) );
		add_action( 'template_redirect', array( $this, 'maybe_serve_ics' ) );
		add_shortcode( 'mwm_booking_form', array( $this, 'shortcode_booking_form' ) );
		add_shortcode( 'mwm_button', array( $this, 'shortcode_button' ) );
		add_shortcode( 'mwm_cards', array( $this, 'shortcode_cards' ) );
	}

	/**
	 * Intercept ?mwm_token=<token> requests and render the manage-booking page.
	 */
	public function maybe_render_manage_page(): void {
		$token = isset( $_GET['mwm_token'] ) ? sanitize_text_field( wp_unslash( $_GET['mwm_token'] ) ) : '';
		if ( ! $token ) {
			return;
		}

		$booking = MWM_Booking::get_by_token( $token, 'cancel' );
		if ( ! $booking ) {
			// Invalid token — let WP handle it normally (404 or homepage)
			return;
		}

		$event_type = MWM_Event_Type::get( $booking['event_type_id'] );
		if ( ! $event_type ) {
			return;
		}

		// Manage links are bearer capabilities. Keep their page and tokens private.
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();
		header( 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0', true );
		header( 'Referrer-Policy: no-referrer', true );
		header( 'X-Robots-Tag: noindex, nofollow, noarchive', true );

		$admin_tz    = MWM_Settings::get( 'timezone' ) ?: 'UTC';
		$min_notice  = (int) MWM_Settings::get( 'min_notice_hours' );
		$now_utc     = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		$meeting_utc = new DateTimeImmutable( $booking['start_datetime'], new DateTimeZone( 'UTC' ) );
		$hours_until = ( $meeting_utc->getTimestamp() - $now_utc->getTimestamp() ) / 3600;

		$too_close      = $booking['status'] === 'confirmed' && $hours_until < $min_notice;
		$can_reschedule = $booking['status'] === 'confirmed' && ! $too_close;

		$site_name  = get_option( 'blogname' );
		$page_title = sprintf(
			/* translators: %s = meeting type name */
			__( 'Manage Booking — %s', 'meet-with-me' ),
			$event_type['name']
		);

		// Render full HTML page
		$this->enqueue_assets();

		status_header( 200 );
		header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );

		// Use get_header / get_footer so the page inherits the active theme
		ob_start();
		require MWM_PLUGIN_DIR . 'public/views/manage-booking.php';
		$content = ob_get_clean();

		// Buffer the full page through the theme
		add_filter(
			'document_title_parts',
			function ( $parts ) use ( $page_title ) {
				$parts['title'] = $page_title;
				return $parts;
			}
		);

		get_header();
		echo '<div class="mwm-manage-page">';
		echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- assembled from an escaped template
		echo '</div>';
		get_footer();
		exit;
	}

	/**
	 * Intercept ?mwm_ics=<token> requests and serve the booking as an .ics
	 * download (confirmation screen "Download .ics" button).
	 */
	public function maybe_serve_ics(): void {
		$token = isset( $_GET['mwm_ics'] ) ? sanitize_text_field( wp_unslash( $_GET['mwm_ics'] ) ) : '';
		if ( ! $token ) {
			return;
		}

		$result = self::ics_response( $token );
		if ( is_wp_error( $result ) ) {
			wp_die(
				esc_html( $result->get_error_message() ),
				'',
				array( 'response' => 404 )
			);
		}

		// The URL embeds a bearer token: never cache, never index, never refer.
		nocache_headers();
		header( 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0', true );
		header( 'Referrer-Policy: no-referrer', true );
		header( 'X-Robots-Tag: noindex, nofollow, noarchive', true );
		header( 'Content-Type: text/calendar; charset=' . get_option( 'blog_charset' ), true );
		header( 'Content-Disposition: attachment; filename="' . $result['filename'] . '"', true );
		header( 'Content-Length: ' . strlen( $result['body'] ), true );

		echo $result['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ICS body from MWM_ICS::generate, already escaped for the iCalendar format
		exit;
	}

	/**
	 * Build the ICS payload for a booking's calendar-download link.
	 *
	 * Testable core of maybe_serve_ics(): validates the token shape, resolves
	 * the booking (confirmed bookings only — cancelled/rescheduled rows would
	 * hand out a stale event), and reuses the email-attachment generator.
	 *
	 * @param string $token Booking cancel token from the mwm_ics link.
	 * @return array{body:string,filename:string}|WP_Error
	 */
	public static function ics_response( string $token ): array|WP_Error {
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
			return new WP_Error( 'mwm_ics_invalid_token', __( 'Invalid calendar link.', 'meet-with-me' ) );
		}

		$booking = MWM_Booking::get_by_token( $token, 'cancel' );
		if ( ! $booking || 'confirmed' !== (string) $booking['status'] ) {
			return new WP_Error( 'mwm_ics_not_found', __( 'Booking not found.', 'meet-with-me' ) );
		}

		$event_type = MWM_Event_Type::get( (int) $booking['event_type_id'] );
		if ( ! $event_type ) {
			return new WP_Error( 'mwm_ics_not_found', __( 'Booking not found.', 'meet-with-me' ) );
		}

		return array(
			'body'     => MWM_ICS::generate( $booking, $event_type ),
			'filename' => 'meet-with-me-booking-' . (int) $booking['id'] . '.ics',
		);
	}

	/**
	 * Enqueue assets only when the request is likely to render booking UI.
	 *
	 * Cheap main-query detection covers the common case (shortcodes or blocks
	 * in the queried post). Placements it cannot see — widgets, template parts,
	 * synced patterns — are handled by the late-enqueue path in ensure_assets().
	 */
	public function maybe_enqueue_assets(): void {
		/**
		 * Filters whether the front-end booking assets load on this request.
		 *
		 * @param bool $should_load Whether to enqueue the CSS/JS.
		 */
		$should_load = (bool) apply_filters(
			'mwm_load_assets',
			self::$needs_assets || $this->main_query_has_booking_ui()
		);

		if ( $should_load ) {
			$this->enqueue_assets();
		}
	}

	/**
	 * Mark this request as rendering booking UI and load the assets.
	 *
	 * Called from the shortcode renderers and the block render callbacks.
	 * Safe to call multiple times. When content renders after wp_enqueue_scripts
	 * (the usual case) the assets are enqueued late and print with the footer.
	 */
	public static function ensure_assets(): void {
		self::$needs_assets = true;

		// Editor previews (REST), feeds and admin requests never print the
		// front-end queue, so only the flag matters there.
		if ( is_admin() || is_feed() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		( new self() )->enqueue_assets(); // Idempotent.
	}

	/**
	 * Register (once) the shared front-end stylesheet handle and its inline
	 * design tokens. Runs on init so the block.json `style` references resolve
	 * in both the editor and the front end.
	 */
	public static function register_front_style(): void {
		if ( wp_style_is( 'meet-with-me', 'registered' ) ) {
			return;
		}

		wp_register_style(
			'meet-with-me',
			MWM_PLUGIN_URL . 'public/assets/css/meet-with-me.css',
			array(),
			MWM_VERSION
		);

		$style_css = self::build_style_css();
		if ( $style_css !== '' ) {
			wp_add_inline_style( 'meet-with-me', $style_css );
		}
	}

	/**
	 * Cheap detection: does the queried post contain a booking shortcode or block?
	 */
	private function main_query_has_booking_ui(): bool {
		if ( ! is_singular() ) {
			return false;
		}

		$post = get_queried_object();
		if ( ! $post instanceof WP_Post ) {
			return false;
		}

		$content = (string) $post->post_content;

		foreach ( self::SHORTCODE_TAGS as $shortcode ) {
			if ( has_shortcode( $content, $shortcode ) ) {
				return true;
			}
		}

		foreach ( self::BLOCK_TAGS as $block_name ) {
			if ( has_block( $block_name, $post ) ) {
				return true;
			}
		}

		return false;
	}

	public function enqueue_assets(): void {
		// Idempotent: shortcodes, blocks and the manage page can all call this.
		if ( wp_script_is( 'meet-with-me', 'enqueued' ) ) {
			return;
		}

		self::register_front_style();
		wp_enqueue_style( 'meet-with-me' );

		wp_enqueue_script(
			'meet-with-me',
			MWM_PLUGIN_URL . 'public/assets/js/meet-with-me.js',
			array(),
			MWM_VERSION,
			true
		);

		wp_localize_script(
			'meet-with-me',
			'mwmData',
			array(
				'restUrl'        => esc_url_raw( rest_url( 'mwm/v1' ) ),
				'siteUrl'        => esc_url_raw( home_url( '/' ) ),
				'maxAdvanceDays' => max( 1, (int) MWM_Settings::get( 'max_advance_days' ) ),
				'strings'        => $this->get_script_strings(),
			)
		);
	}

	/**
	 * [mwm_booking_form event_type="slug" accent="#hex"]
	 * Renders the full inline booking wizard.
	 */
	public function shortcode_booking_form( $atts ): string {
		$atts = is_array( $atts ) ? $atts : array(); // WP < 6.5 passes '' for attribute-less shortcodes.
		self::ensure_assets();

		$atts   = shortcode_atts(
			array(
				'event_type' => '',
				'accent'     => '',
			),
			$atts,
			'mwm_booking_form'
		);
		$slug   = sanitize_key( $atts['event_type'] );
		$accent = $this->sanitize_accent( $atts['accent'] );
		ob_start();
		require MWM_PLUGIN_DIR . 'public/views/booking-wizard.php';
		return ob_get_clean();
	}

	/**
	 * [mwm_button event_type="slug" label="Book a time" class="" accent="#hex"]
	 * Renders a button that opens the booking wizard in a modal.
	 */
	public function shortcode_button( $atts ): string {
		$atts = is_array( $atts ) ? $atts : array(); // WP < 6.5 passes '' for attribute-less shortcodes.
		self::ensure_assets();

		$atts = shortcode_atts(
			array(
				'event_type' => '',
				'label'      => __( 'Book a time', 'meet-with-me' ),
				'class'      => '',
				'accent'     => '',
			),
			$atts,
			'mwm_button'
		);

		$accent = $this->sanitize_accent( $atts['accent'] );
		$style  = $accent !== '' ? ' style="--mwm-accent:' . esc_attr( $accent ) . ';"' : '';

		return sprintf(
			'<button class="mwm-booking-button %s" data-event-type="%s"%s aria-haspopup="dialog">%s</button>',
			esc_attr( $atts['class'] ),
			esc_attr( sanitize_key( $atts['event_type'] ) ),
			$style,
			esc_html( $atts['label'] )
		);
	}

	/**
	 * [mwm_cards event_types="" columns="3" show_description="true" accent="#hex"]
	 * Renders a grid of event type cards, each with a "Book Now" button.
	 */
	public function shortcode_cards( $atts ): string {
		$atts = is_array( $atts ) ? $atts : array(); // WP < 6.5 passes '' for attribute-less shortcodes.

		$atts = shortcode_atts(
			array(
				'event_types'      => '',
				'columns'          => '3',
				'show_description' => 'true',
				'accent'           => '',
			),
			$atts,
			'mwm_cards'
		);

		$all_types = MWM_Event_Type::get_all( true );

		if ( ! empty( $atts['event_types'] ) ) {
			$slugs     = array_map( 'trim', explode( ',', $atts['event_types'] ) );
			$all_types = array_filter( $all_types, fn( $et ) => in_array( $et['slug'], $slugs, true ) );
		}

		if ( empty( $all_types ) ) {
			return '';
		}

		self::ensure_assets();

		$columns          = max( 1, min( 6, (int) $atts['columns'] ) );
		$show_description = $atts['show_description'] !== 'false';
		$accent           = $this->sanitize_accent( $atts['accent'] );
		$wrapper_style    = '--mwm-columns:' . $columns . ( $accent !== '' ? ';--mwm-accent:' . $accent : '' );

		ob_start();
		?>
		<div class="mwm-cards" style="<?php echo esc_attr( $wrapper_style ); ?>">
			<?php
			foreach ( $all_types as $et ) :
				require MWM_PLUGIN_DIR . 'public/views/event-type-card.php';
			endforeach;
			?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Output the shared modal container in the footer (once per page).
	 */
	public function render_modal(): void {
		if ( ! self::$needs_assets ) {
			return;
		}
		?>
		<div id="mwm-modal" class="mwm-modal" hidden aria-modal="true" role="dialog" aria-label="<?php esc_attr_e( 'Book a meeting', 'meet-with-me' ); ?>">
			<div class="mwm-modal__overlay"></div>
			<div class="mwm-modal__content">
				<button class="mwm-modal__close" aria-label="<?php esc_attr_e( 'Close', 'meet-with-me' ); ?>">✕</button>
				<div class="mwm-booking-wizard mwm-booking-wizard--modal" data-event-type="">
					<div class="mwm-booking-wizard__inner"></div>
				</div>
			</div>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Style settings → CSS custom properties
	// -------------------------------------------------------------------------

	/**
	 * Build the inline CSS block from the Style settings.
	 *
	 * Output is attached to this plugin's stylesheet only (via
	 * wp_add_inline_style) and printed wherever the stylesheet is used —
	 * front end and block editor previews alike.
	 */
	public static function build_style_css(): string {
		$style = MWM_Settings::get_all( 'style' );
		$decls = array();

		$accent = $style['accent_color'] ? sanitize_hex_color( (string) $style['accent_color'] ) : '';
		if ( $accent ) {
			$decls['--mwm-accent']          = $accent;
			$contrast                       = $style['accent_text_color'] ? sanitize_hex_color( (string) $style['accent_text_color'] ) : '';
			$decls['--mwm-accent-contrast'] = $contrast ?: '#ffffff';
		}

		if ( $style['button_radius'] === 'pill' ) {
			$decls['--mwm-radius']     = '999px';
			$decls['--mwm-btn-radius'] = '999px';
		} elseif ( is_string( $style['button_radius'] ) && preg_match( '/^(?:[0-9]|1[0-9]|2[0-4])$/', $style['button_radius'] ) ) {
			$decls['--mwm-radius']     = (int) $style['button_radius'] . 'px';
			$decls['--mwm-btn-radius'] = (int) $style['button_radius'] . 'px';
		}

		if ( $style['surface_mode'] === 'light' ) {
			$decls['--mwm-surface']          = '#ffffff';
			$decls['--mwm-surface-contrast'] = '#1d2327';
		} elseif ( $style['surface_mode'] === 'dark' ) {
			$decls['--mwm-surface']          = '#1e1e1e';
			$decls['--mwm-surface-contrast'] = '#f0f0f1';
			$decls['--mwm-surface-muted']    = 'rgba(255,255,255,0.14)';
			$decls['--mwm-overlay']          = 'rgba(0,0,0,0.7)';
		}

		if ( $style['density'] === 'compact' ) {
			$decls['--mwm-space'] = '0.75';
		}

		$max_width = (int) $style['max_width'];
		if ( $max_width >= 320 && $max_width <= 1200 && $max_width !== 560 ) {
			$decls['--mwm-wrap-width'] = $max_width . 'px';
		}

		$css = '';
		if ( ! empty( $decls ) ) {
			$css .= ":root {\n";
			foreach ( $decls as $prop => $value ) {
				$css .= "\t{$prop}: {$value};\n";
			}
			$css .= "}\n";
		}

		if ( $style['button_style'] === 'filled' ) {
			$css .= ".mwm-btn-primary,\n.mwm-btn-danger {\n\tbackground: var(--mwm-accent, currentColor);\n\tborder-color: var(--mwm-accent, currentColor);\n\tcolor: var(--mwm-accent-contrast, #fff);\n}\n";
		} elseif ( $style['button_style'] === 'link' ) {
			$css .= ".mwm-btn-primary,\n.mwm-btn-secondary,\n.mwm-btn-danger {\n\tbackground: none;\n\tborder-color: transparent;\n\tpadding-left: 0;\n\tpadding-right: 0;\n\ttext-decoration: underline;\n\tcolor: var(--mwm-accent, inherit);\n}\n";
		}

		$custom = trim( (string) $style['custom_css'] );
		if ( $custom !== '' ) {
			$css .= "\n" . $custom . "\n";
		}

		return trim( $css );
	}

	/**
	 * All front-end strings used by meet-with-me.js.
	 *
	 * Populated server-side through __()/wptexturize-free literals so PHP
	 * translation files (PO/MO) drive the JS UI with no build step.
	 *
	 * @return array<string, mixed>
	 */
	private function get_script_strings(): array {
		return array(
			'loading'             => __( 'Loading…', 'meet-with-me' ),
			'requestFailed'       => __( 'Request failed. Please try again.', 'meet-with-me' ),
			'tryAgain'            => __( 'Try Again', 'meet-with-me' ),
			'back'                => __( '← Back', 'meet-with-me' ),
			'stepDate'            => __( 'Choose a date', 'meet-with-me' ),
			'stepTime'            => __( 'Choose a time', 'meet-with-me' ),
			'stepDetails'         => __( 'Your details', 'meet-with-me' ),
			/* translators: 1: step number, 2: step title */
			'stepAnnounce'        => __( 'Step %1$s of 3: %2$s', 'meet-with-me' ),
			'noMeetingTypes'      => __( 'No meeting types available.', 'meet-with-me' ),
			'chooseTypeTitle'     => __( 'What type of meeting?', 'meet-with-me' ),
			'minShort'            => __( 'min', 'meet-with-me' ),
			'online'              => __( 'Online', 'meet-with-me' ),
			'inPerson'            => __( 'In person', 'meet-with-me' ),
			'onlineOrInPerson'    => __( 'Online or in person', 'meet-with-me' ),
			'reschedule'          => __( 'Reschedule', 'meet-with-me' ),
			'months'              => array(
				__( 'January', 'meet-with-me' ),
				__( 'February', 'meet-with-me' ),
				__( 'March', 'meet-with-me' ),
				__( 'April', 'meet-with-me' ),
				__( 'May', 'meet-with-me' ),
				__( 'June', 'meet-with-me' ),
				__( 'July', 'meet-with-me' ),
				__( 'August', 'meet-with-me' ),
				__( 'September', 'meet-with-me' ),
				__( 'October', 'meet-with-me' ),
				__( 'November', 'meet-with-me' ),
				__( 'December', 'meet-with-me' ),
			),
			'dayHeaders'          => array(
				__( 'Mo', 'meet-with-me' ),
				__( 'Tu', 'meet-with-me' ),
				__( 'We', 'meet-with-me' ),
				__( 'Th', 'meet-with-me' ),
				__( 'Fr', 'meet-with-me' ),
				__( 'Sa', 'meet-with-me' ),
				__( 'Su', 'meet-with-me' ),
			),
			'prevMonth'           => __( 'Previous month', 'meet-with-me' ),
			'nextMonth'           => __( 'Next month', 'meet-with-me' ),
			'today'               => __( 'Today', 'meet-with-me' ),
			'backToToday'         => __( 'Back to Today', 'meet-with-me' ),
			'chooseMonth'         => __( 'Choose a month', 'meet-with-me' ),
			'yearLabel'           => __( 'Year', 'meet-with-me' ),
			'pickerNote'          => __( 'Choose a month in the active booking window, or use the arrows to preview months outside it.', 'meet-with-me' ),
			/* translators: %s = date */
			'pastMonth'           => __( 'You’re viewing a past month. New meetings can only be booked from %s onward.', 'meet-with-me' ),
			/* translators: %s = date */
			'futureMonth'         => __( 'You’re viewing beyond the current booking window. New meetings can be booked through %s.', 'meet-with-me' ),
			/* translators: 1: month name, 2: year */
			'noDatesInMonth'      => __( 'No bookable dates are available in %1$s %2$s. Try another month.', 'meet-with-me' ),
			'unavailable'         => __( 'unavailable', 'meet-with-me' ),
			'noTimes'             => __( 'No times available on this day. Please go back and pick another date.', 'meet-with-me' ),
			'availableTimes'      => __( 'Available Times', 'meet-with-me' ),
			'loadingDates'        => __( 'Loading available dates…', 'meet-with-me' ),
			'loadingTimes'        => __( 'Loading available times…', 'meet-with-me' ),
			/* translators: %s = number of dates */
			'datesAvailable'      => __( '%s dates available.', 'meet-with-me' ),
			/* translators: %s = number of time slots */
			'timesAvailable'      => __( '%s times available.', 'meet-with-me' ),
			'name'                => __( 'Name', 'meet-with-me' ),
			'namePlaceholder'     => __( 'Your full name', 'meet-with-me' ),
			'email'               => __( 'Email', 'meet-with-me' ),
			'emailPlaceholder'    => __( 'you@example.com', 'meet-with-me' ),
			'phone'               => __( 'Phone', 'meet-with-me' ),
			'optional'            => __( '(optional)', 'meet-with-me' ),
			'howToMeet'           => __( 'How would you like to meet?', 'meet-with-me' ),
			'anythingElse'        => __( 'Anything else?', 'meet-with-me' ),
			'notesPlaceholder'    => __( 'Notes or context for our meeting…', 'meet-with-me' ),
			'confirmBooking'      => __( 'Confirm Booking', 'meet-with-me' ),
			'confirming'          => __( 'Confirming…', 'meet-with-me' ),
			'choosePlaceholder'   => __( '— Choose —', 'meet-with-me' ),
			'invalidNameEmail'    => __( 'Please enter a valid name and email address.', 'meet-with-me' ),
			'chooseMeetType'      => __( 'Please choose how you would like to meet.', 'meet-with-me' ),
			/* translators: %s = question label */
			'pleaseAnswer'        => __( 'Please answer: %s', 'meet-with-me' ),
			'genericError'        => __( 'Something went wrong. Please try again.', 'meet-with-me' ),
			'booked'              => __( 'You’re booked!', 'meet-with-me' ),
			/* translators: %s = email address */
			'confirmationSent'    => __( 'A confirmation email is heading to %s.', 'meet-with-me' ),
			'openMeetingLink'     => __( 'Open meeting link', 'meet-with-me' ),
			'needManage'          => __( 'Need to cancel or reschedule?', 'meet-with-me' ),
			'manageBooking'       => __( 'Manage Booking', 'meet-with-me' ),
			'bookingProgress'     => __( 'Booking progress', 'meet-with-me' ),
			'summarySelected'     => __( 'Your selection', 'meet-with-me' ),
			'summaryPickTime'     => __( 'Pick a time below', 'meet-with-me' ),
			'addToGoogleCalendar' => __( 'Add to Google Calendar', 'meet-with-me' ),
			'downloadIcs'         => __( 'Download .ics', 'meet-with-me' ),
			'copyDetails'         => __( 'Copy details', 'meet-with-me' ),
			'detailsCopied'       => __( 'Booking details copied.', 'meet-with-me' ),
			'copyFailed'          => __( 'Could not copy to clipboard.', 'meet-with-me' ),
			'shareBooking'        => __( 'Share', 'meet-with-me' ),
			/* translators: %s = manage booking URL */
			'manageAt'            => __( 'Manage: %s', 'meet-with-me' ),
			'cancelThis'          => __( 'Cancel this booking', 'meet-with-me' ),
			'cancelConfirm'       => __( 'Cancel this booking? This cannot be undone.', 'meet-with-me' ),
			'yesCancel'           => __( 'Yes, Cancel It', 'meet-with-me' ),
			'goBack'              => __( 'Go Back', 'meet-with-me' ),
			'cancelling'          => __( 'Cancelling…', 'meet-with-me' ),
			'bookingCancelled'    => __( 'Booking cancelled', 'meet-with-me' ),
			/* translators: 1: meeting name, 2: date/time */
			'cancelledBody'       => __( 'Your %1$s on %2$s has been cancelled.', 'meet-with-me' ),
			'bookNewTime'         => __( 'Book a New Time', 'meet-with-me' ),
			'backToBooking'       => __( '← Back to booking', 'meet-with-me' ),
			'chooseNewTime'       => __( 'Choose a new time', 'meet-with-me' ),
			/* translators: %s = time slot label */
			'newTime'             => __( 'New time: %s', 'meet-with-me' ),
			'confirmNewTime'      => __( 'Confirm New Time', 'meet-with-me' ),
			'rescheduling'        => __( 'Rescheduling…', 'meet-with-me' ),
			'bookingRescheduled'  => __( 'Booking rescheduled!', 'meet-with-me' ),
			/* translators: 1: meeting name, 2: date/time */
			'rescheduledBody'     => __( 'Your %1$s is now scheduled for %2$s.', 'meet-with-me' ),
			'confirmationOnWay'   => __( 'A confirmation email is on its way.', 'meet-with-me' ),
		);
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Validate an optional per-instance accent override.
	 *
	 * @return string  Empty string when unset/invalid, hex color otherwise.
	 */
	private function sanitize_accent( string $value ): string {
		$hex = sanitize_hex_color( $value );
		return $hex ?: '';
	}
}
