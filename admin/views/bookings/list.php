<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$base_url = add_query_arg( array( 'page' => 'meet-with-me' ), admin_url( 'admin.php' ) );

$status_tabs = array(
	''            => sprintf( '%s <span class="count">(%d)</span>', __( 'All', 'meet-with-me' ), $status_counts['total'] ),
	'confirmed'   => sprintf( '%s <span class="count">(%d)</span>', __( 'Confirmed', 'meet-with-me' ), $status_counts['confirmed'] ),
	'cancelled'   => sprintf( '%s <span class="count">(%d)</span>', __( 'Cancelled', 'meet-with-me' ), $status_counts['cancelled'] ),
	'rescheduled' => sprintf( '%s <span class="count">(%d)</span>', __( 'Rescheduled', 'meet-with-me' ), $status_counts['rescheduled'] ),
);
?>
<div class="wrap mwm-wrap">

	<h1><?php esc_html_e( 'Bookings', 'meet-with-me' ); ?></h1>

	<?php if ( $notice ) : ?>
		<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible">
			<p><?php echo esc_html( $notice['message'] ); ?></p>
		</div>
	<?php endif; ?>

	<!-- Status tabs -->
	<ul class="subsubsub">
		<?php
		$tab_keys = array_keys( $status_tabs ); foreach ( $status_tabs as $tab_status => $tab_label ) :
			$url     = $tab_status ? add_query_arg( 'booking_status', $tab_status, $base_url ) : $base_url;
			$active  = $tab_status === $status;
			$is_last = $tab_status === end( $tab_keys );
			?>
			<li>
				<a href="<?php echo esc_url( $url ); ?>" <?php echo $active ? 'class="current"' : ''; ?>>
					<?php echo wp_kses( $tab_label, array( 'span' => array( 'class' => array() ) ) ); ?>
				</a><?php echo $is_last ? '' : ' |'; ?>
			</li>
		<?php endforeach; ?>
	</ul>

	<!-- Search form -->
	<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="mwm-search-form">
		<input type="hidden" name="page" value="meet-with-me">
		<?php if ( $status ) : ?>
			<input type="hidden" name="booking_status" value="<?php echo esc_attr( $status ); ?>">
		<?php endif; ?>
		<p class="search-box">
			<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>"
				placeholder="<?php esc_attr_e( 'Search by name or email…', 'meet-with-me' ); ?>"
				class="regular-text">
			<button type="submit" class="button"><?php esc_html_e( 'Search', 'meet-with-me' ); ?></button>
			<?php if ( $search ) : ?>
				<a href="<?php echo esc_url( $status ? add_query_arg( 'booking_status', $status, $base_url ) : $base_url ); ?>"
					class="button"><?php esc_html_e( 'Clear', 'meet-with-me' ); ?></a>
			<?php endif; ?>
		</p>
	</form>

	<?php if ( empty( $bookings ) ) : ?>
		<div class="mwm-empty-state">
			<p>
			<?php
			echo $search
				? esc_html__( 'No bookings matched your search.', 'meet-with-me' )
				: esc_html__( 'No bookings yet.', 'meet-with-me' );
			?>
			</p>
		</div>
	<?php else : ?>

		<table class="wp-list-table widefat fixed striped mwm-bookings-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Booker', 'meet-with-me' ); ?></th>
					<th><?php esc_html_e( 'Meeting Type', 'meet-with-me' ); ?></th>
					<th><?php esc_html_e( 'Date & Time', 'meet-with-me' ); ?></th>
					<th style="width:110px;"><?php esc_html_e( 'Format', 'meet-with-me' ); ?></th>
					<th style="width:100px;"><?php esc_html_e( 'Status', 'meet-with-me' ); ?></th>
					<th style="width:80px;"><?php esc_html_e( 'Actions', 'meet-with-me' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				foreach ( $bookings as $booking ) :
					$view_url      = add_query_arg(
						array(
							'page'   => 'meet-with-me',
							'action' => 'view',
							'id'     => $booking['id'],
						),
						admin_url( 'admin.php' )
					);
					$date_label    = MWM_Booking::format_datetime( $booking['start_datetime'], $admin_tz, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
					$format_labels = array(
						'online'    => __( 'Online', 'meet-with-me' ),
						'in_person' => __( 'In person', 'meet-with-me' ),
					);
					?>
				<tr>
					<td>
						<strong><a href="<?php echo esc_url( $view_url ); ?>"><?php echo esc_html( $booking['booker_name'] ); ?></a></strong>
						<br><span class="description"><?php echo esc_html( $booking['booker_email'] ); ?></span>
					</td>
					<td>
						<span class="mwm-color-dot" style="background:<?php echo esc_attr( $booking['event_type_color'] ?? '#3b82f6' ); ?>;"></span>
						<?php echo esc_html( $booking['event_type_name'] ?? '—' ); ?>
					</td>
					<td><?php echo esc_html( $date_label ); ?></td>
					<td><?php echo esc_html( $format_labels[ $booking['meeting_type'] ] ?? $booking['meeting_type'] ); ?></td>
					<td><?php echo mwm_booking_status_badge( $booking['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- returns pre-escaped HTML ?></td>
					<td>
						<a href="<?php echo esc_url( $view_url ); ?>" class="button button-small">
							<?php esc_html_e( 'View', 'meet-with-me' ); ?>
						</a>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<!-- Pagination -->
		<?php
		if ( $total_pages > 1 ) :
			$pagination_args = array( 'page' => 'meet-with-me' );
			if ( $status ) {
				$pagination_args['booking_status'] = $status;
			}
			if ( $search ) {
				$pagination_args['s'] = $search;
			}
			?>
		<div class="tablenav bottom">
			<div class="tablenav-pages">
				<span class="displaying-num">
					<?php
					printf(
						/* translators: %s = number of bookings */
						esc_html( _n( '%s item', '%s items', $total, 'meet-with-me' ) ),
						number_format_i18n( $total ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- returns a formatted number
					);
					?>
				</span>
				<span class="pagination-links">
					<?php if ( $page_num > 1 ) : ?>
						<a class="prev-page button" href="<?php echo esc_url( add_query_arg( array_merge( $pagination_args, array( 'paged' => $page_num - 1 ) ), admin_url( 'admin.php' ) ) ); ?>">‹</a>
					<?php endif; ?>
					<span class="paging-input">
						<?php echo esc_html( $page_num ); ?> / <?php echo esc_html( $total_pages ); ?>
					</span>
					<?php if ( $page_num < $total_pages ) : ?>
						<a class="next-page button" href="<?php echo esc_url( add_query_arg( array_merge( $pagination_args, array( 'paged' => $page_num + 1 ) ), admin_url( 'admin.php' ) ) ); ?>">›</a>
					<?php endif; ?>
				</span>
			</div>
		</div>
		<?php endif; ?>

	<?php endif; ?>

</div>
