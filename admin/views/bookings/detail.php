<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$list_url   = add_query_arg( array( 'page' => 'meet-with-me' ), admin_url( 'admin.php' ) );
$cancel_url = wp_nonce_url(
	add_query_arg(
		array(
			'page'   => 'meet-with-me',
			'action' => 'cancel',
			'id'     => $booking['id'],
		),
		admin_url( 'admin.php' )
	),
	'mwm_cancel_booking_' . $booking['id']
);

$admin_date_label  = MWM_Booking::format_datetime( $booking['start_datetime'], $admin_tz );
$booker_date_label = MWM_Booking::format_datetime( $booking['start_datetime'], $booking['booker_timezone'] );
$is_upcoming       = strtotime( $booking['start_datetime'] ) > time();
$can_cancel        = $booking['status'] === 'confirmed';

$format_labels = array(
	'online'    => __( 'Online', 'meet-with-me' ),
	'in_person' => __( 'In person', 'meet-with-me' ),
);
?>
<div class="wrap mwm-wrap">

	<h1>
		<a href="<?php echo esc_url( $list_url ); ?>" class="mwm-back-link">← <?php esc_html_e( 'Bookings', 'meet-with-me' ); ?></a>
		<?php echo esc_html( $booking['booker_name'] ); ?>
	</h1>

	<?php if ( $notice ) : ?>
		<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible">
			<p><?php echo esc_html( $notice['message'] ); ?></p>
		</div>
	<?php endif; ?>

	<div class="mwm-edit-layout">

		<!-- Main column -->
		<div class="mwm-edit-main">

			<!-- Booking Details -->
			<div class="mwm-card">
				<h2><?php esc_html_e( 'Booking Details', 'meet-with-me' ); ?></h2>

				<table class="mwm-detail-table">
					<tr>
						<th><?php esc_html_e( 'Meeting Type', 'meet-with-me' ); ?></th>
						<td>
							<?php if ( $event_type ) : ?>
								<span class="mwm-color-dot" style="background:<?php echo esc_attr( $event_type['color'] ); ?>;"></span>
								<?php echo esc_html( $event_type['name'] ); ?>
								<span class="description">(<?php echo esc_html( $event_type['duration_minutes'] ); ?> min)</span>
							<?php else : ?>
								<span class="description"><?php esc_html_e( 'Deleted meeting type', 'meet-with-me' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Date & Time', 'meet-with-me' ); ?></th>
						<td>
							<strong><?php echo esc_html( $admin_date_label ); ?></strong>
							<span class="description"><?php echo esc_html( $admin_tz ); ?></span>
							<?php if ( $booking['booker_timezone'] !== $admin_tz ) : ?>
								<br>
								<span class="description">
									<?php echo esc_html( $booker_date_label ); ?>
									(<?php echo esc_html( $booking['booker_timezone'] ); ?> — booker's time)
								</span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Format', 'meet-with-me' ); ?></th>
						<td><?php echo esc_html( $format_labels[ $booking['meeting_type'] ] ?? $booking['meeting_type'] ); ?></td>
					</tr>
					<?php if ( ! empty( $booking['meeting_provider'] ) || ! empty( $booking['meeting_join_url'] ) ) : ?>
					<tr>
						<th><?php esc_html_e( 'Online Meeting', 'meet-with-me' ); ?></th>
						<td>
							<?php if ( ! empty( $booking['meeting_provider'] ) ) : ?>
								<strong><?php echo esc_html( MWM_Online_Meetings::get_provider_label( (string) $booking['meeting_provider'] ) ); ?></strong><br>
							<?php endif; ?>
							<?php if ( ! empty( $booking['meeting_join_url'] ) ) : ?>
								<a href="<?php echo esc_url( $booking['meeting_join_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $booking['meeting_join_url'] ); ?></a>
							<?php else : ?>
								<span class="description"><?php esc_html_e( 'Meeting link not available yet.', 'meet-with-me' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
					<?php endif; ?>
					<tr>
						<th><?php esc_html_e( 'Booked', 'meet-with-me' ); ?></th>
						<td><?php echo esc_html( MWM_Booking::format_datetime( $booking['created_at'], $admin_tz ) ); ?></td>
					</tr>
				</table>
			</div>

			<!-- Booker Info -->
			<div class="mwm-card">
				<h2><?php esc_html_e( 'Booker Information', 'meet-with-me' ); ?></h2>

				<table class="mwm-detail-table">
					<tr>
						<th><?php esc_html_e( 'Name', 'meet-with-me' ); ?></th>
						<td><?php echo esc_html( $booking['booker_name'] ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Email', 'meet-with-me' ); ?></th>
						<td><a href="mailto:<?php echo esc_attr( $booking['booker_email'] ); ?>"><?php echo esc_html( $booking['booker_email'] ); ?></a></td>
					</tr>
					<?php if ( $booking['booker_phone'] ) : ?>
					<tr>
						<th><?php esc_html_e( 'Phone', 'meet-with-me' ); ?></th>
						<td><?php echo esc_html( $booking['booker_phone'] ); ?></td>
					</tr>
					<?php endif; ?>
					<?php if ( $booking['booker_notes'] ) : ?>
					<tr>
						<th><?php esc_html_e( 'Notes', 'meet-with-me' ); ?></th>
						<td><?php echo nl2br( esc_html( $booking['booker_notes'] ) ); ?></td>
					</tr>
					<?php endif; ?>
				</table>
			</div>

			<!-- Custom field answers -->
			<?php if ( ! empty( $booking['field_answers'] ) && $event_type && ! empty( $event_type['fields'] ) ) : ?>
			<div class="mwm-card">
				<h2><?php esc_html_e( 'Question Responses', 'meet-with-me' ); ?></h2>
				<table class="mwm-detail-table">
					<?php
					foreach ( $event_type['fields'] as $field ) :
						$answer = $booking['field_answers'][ $field['id'] ] ?? null;
						if ( $answer === null ) {
							continue;
						}
						$display = is_array( $answer ) ? implode( ', ', $answer ) : $answer;
						?>
					<tr>
						<th><?php echo esc_html( $field['label'] ); ?></th>
						<td><?php echo $display ? nl2br( esc_html( $display ) ) : '<span class="description">—</span>'; ?></td>
					</tr>
					<?php endforeach; ?>
				</table>
			</div>
			<?php endif; ?>

			<!-- Admin Notes -->
			<div class="mwm-card">
				<h2><?php esc_html_e( 'Admin Notes', 'meet-with-me' ); ?></h2>
				<p class="description" style="margin-bottom:12px;">
					<?php esc_html_e( 'Internal notes — not visible to the booker.', 'meet-with-me' ); ?>
				</p>
				<form method="post" action="">
					<?php wp_nonce_field( 'mwm_booking_notes_' . $booking['id'] ); ?>
					<input type="hidden" name="booking_id" value="<?php echo esc_attr( $booking['id'] ); ?>">
					<textarea name="admin_notes" rows="4" class="large-text"><?php echo esc_textarea( $booking['admin_notes'] ?? '' ); ?></textarea>
					<p class="submit">
						<button type="submit" name="mwm_save_admin_notes" class="button button-primary">
							<?php esc_html_e( 'Save Notes', 'meet-with-me' ); ?>
						</button>
					</p>
				</form>
			</div>

		</div><!-- .mwm-edit-main -->

		<!-- Sidebar -->
		<div class="mwm-edit-sidebar">

			<div class="mwm-card">
				<h2><?php esc_html_e( 'Status', 'meet-with-me' ); ?></h2>
				<div style="margin-bottom:16px; font-size:1.05em;">
					<?php echo mwm_booking_status_badge( $booking['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- returns pre-escaped HTML ?>
					<?php if ( $is_upcoming && $booking['status'] === 'confirmed' ) : ?>
						<span class="description" style="margin-left:6px;"><?php esc_html_e( 'Upcoming', 'meet-with-me' ); ?></span>
					<?php endif; ?>
				</div>

				<?php if ( $can_cancel ) : ?>
				<a href="<?php echo esc_url( $cancel_url ); ?>"
					class="button button-link-delete mwm-delete-btn"
					data-confirm="<?php esc_attr_e( 'Cancel this booking? This cannot be undone. A cancellation email will be sent to the booker.', 'meet-with-me' ); ?>">
					<?php esc_html_e( 'Cancel Booking', 'meet-with-me' ); ?>
				</a>
				<?php endif; ?>
			</div>

			<div class="mwm-card">
				<h2><?php esc_html_e( 'Booker\'s Manage Link', 'meet-with-me' ); ?></h2>
				<p class="description" style="margin-bottom:8px;">
					<?php esc_html_e( 'This is the private link sent to the booker to cancel or reschedule.', 'meet-with-me' ); ?>
				</p>
				<code class="mwm-shortcode" data-copy tabindex="0" role="button" style="font-size:0.75em; word-break:break-all;">
					<?php echo esc_html( add_query_arg( array( 'mwm_token' => $booking['cancel_token'] ), home_url( '/' ) ) ); ?>
				</code>
				<p class="description" style="margin-top:8px;">
					<?php esc_html_e( 'Click to copy.', 'meet-with-me' ); ?>
				</p>
			</div>

		</div><!-- .mwm-edit-sidebar -->

	</div><!-- .mwm-edit-layout -->

</div>
