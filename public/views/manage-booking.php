<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$tz          = $booking['booker_timezone'] ?: 'UTC';
$start_local = MWM_Booking::format_datetime(
	$booking['start_datetime'],
	$tz,
	get_option( 'date_format' ) . ' \a\t ' . get_option( 'time_format' )
);

$admin_start_local = MWM_Booking::format_datetime(
	$booking['start_datetime'],
	$admin_tz,
	get_option( 'date_format' ) . ' \a\t ' . get_option( 'time_format' )
);

// Data passed to JS
$js_data = wp_json_encode(
	array(
		'id'               => $booking['id'],
		'cancel_token'     => $booking['cancel_token'],
		'reschedule_token' => $booking['reschedule_token'],
		'event_type_slug'  => $event_type['slug'],
		'event_type_name'  => $event_type['name'],
		'start_local'      => $start_local,
		'status'           => $booking['status'],
		'can_reschedule'   => $can_reschedule,
		'too_close'        => $too_close,
	)
);
?>
<div class="mwm-manage" data-booking="<?php echo esc_attr( $js_data ); ?>">

	<div class="mwm-manage__header">
		<div class="mwm-dot" style="background:<?php echo esc_attr( $event_type['color'] ); ?>"></div>
		<h2 class="mwm-manage__event-name"><?php echo esc_html( $event_type['name'] ); ?></h2>
	</div>

	<div class="mwm-manage__details">
		<div class="mwm-manage__row">
			<span class="mwm-manage__icon" aria-hidden="true">&#128197;</span>
			<span><?php echo esc_html( $start_local ); ?> (<?php echo esc_html( $tz ); ?>)</span>
		</div>
		<?php if ( $admin_tz !== $tz ) : ?>
		<div class="mwm-manage__row mwm-manage__row--secondary">
			<span class="mwm-manage__icon" aria-hidden="true">&#127760;</span>
			<span><?php echo esc_html( $admin_start_local ); ?> (<?php echo esc_html( $admin_tz ); ?>)</span>
		</div>
		<?php endif; ?>
		<div class="mwm-manage__row">
			<span class="mwm-manage__icon" aria-hidden="true">&#128205;</span>
			<span><?php echo esc_html( MWM_Booking::format_label( (string) $booking['meeting_type'] ) ); ?></span>
		</div>
		<div class="mwm-manage__row">
			<span class="mwm-manage__icon" aria-hidden="true">&#9900;</span>
			<span><?php echo esc_html( $event_type['duration_minutes'] ); ?> <?php esc_html_e( 'minutes', 'meet-with-me' ); ?></span>
		</div>
	</div>

	<!-- JS renders the actions here -->
	<div class="mwm-manage__actions-wrap" id="mwm-manage-actions">
		<?php if ( $booking['status'] !== 'confirmed' ) : ?>
			<p class="mwm-manage__notice">
				<?php
				if ( $booking['status'] === 'cancelled' ) {
					esc_html_e( 'This booking has already been cancelled.', 'meet-with-me' );
				} else {
					esc_html_e( 'This booking has been rescheduled.', 'meet-with-me' );
				}
				?>
			</p>
		<?php elseif ( $too_close ) : ?>
			<p class="mwm-manage__notice">
				<?php
				printf(
					/* translators: %d = number of hours */
					esc_html__( 'Changes cannot be made within %d hours of the meeting.', 'meet-with-me' ),
					(int) MWM_Settings::get( 'min_notice_hours' )
				);
				?>
			</p>
			<p><a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="mwm-btn-secondary"><?php esc_html_e( 'Book a new time', 'meet-with-me' ); ?></a></p>
		<?php else : ?>
			<div class="mwm-manage__btns">
				<button class="mwm-btn-danger" data-a="start-cancel"><?php esc_html_e( 'Cancel this booking', 'meet-with-me' ); ?></button>
				<?php if ( $can_reschedule ) : ?>
					<button class="mwm-btn-secondary" data-a="start-reschedule"><?php esc_html_e( 'Reschedule', 'meet-with-me' ); ?></button>
				<?php endif; ?>
			</div>
		<?php endif; ?>
	</div>

	<!-- Reschedule wizard placeholder (JS fills this) -->
	<div class="mwm-manage__reschedule-wrap" hidden></div>

</div>
