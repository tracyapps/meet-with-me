<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$day_labels = array(
	0 => __( 'Sunday', 'meet-with-me' ),
	1 => __( 'Monday', 'meet-with-me' ),
	2 => __( 'Tuesday', 'meet-with-me' ),
	3 => __( 'Wednesday', 'meet-with-me' ),
	4 => __( 'Thursday', 'meet-with-me' ),
	5 => __( 'Friday', 'meet-with-me' ),
	6 => __( 'Saturday', 'meet-with-me' ),
);

// Display Mon–Sun order for schedule
$schedule_order = array( 1, 2, 3, 4, 5, 6, 0 );

$base_url = add_query_arg(
	array(
		'page' => 'mwm-settings',
		'tab'  => 'availability',
	),
	admin_url( 'admin.php' )
);
?>
<div class="wrap mwm-settings-wrap">

	<h1><?php esc_html_e( 'Meet With Me — Settings', 'meet-with-me' ); ?></h1>

	<?php mwm_admin_tabs( $current_tab ); ?>

	<?php if ( $notice ) : ?>
		<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible">
			<p><?php echo esc_html( $notice['message'] ); ?></p>
		</div>
	<?php endif; ?>

	<!-- ===== WEEKLY SCHEDULE ===== -->
	<div class="mwm-card mwm-availability-card">
		<h2><?php esc_html_e( 'Default Weekly Hours', 'meet-with-me' ); ?></h2>
		<p class="description" style="margin-bottom:20px;">
			<?php esc_html_e( 'Set the days and times you are generally available for meetings. All times are in your configured timezone.', 'meet-with-me' ); ?>
			<strong><?php echo esc_html( MWM_Settings::get( 'timezone' ) ); ?></strong>
			— <a href="
			<?php
			echo esc_url(
				add_query_arg(
					array(
						'page' => 'mwm-settings',
						'tab'  => 'general',
					),
					admin_url( 'admin.php' )
				)
			);
			?>
			"><?php esc_html_e( 'change', 'meet-with-me' ); ?></a>
		</p>

		<form method="post" action="">
			<?php wp_nonce_field( 'mwm_availability_schedule', '_mwm_schedule_nonce' ); ?>

			<div class="mwm-schedule-grid">
				<?php
				foreach ( $schedule_order as $dow ) :
					$rule    = $schedule[ $dow ];
					$enabled = $rule ? $rule['is_available'] : false;
					$start   = $rule['start'] ?? '09:00';
					$end     = $rule['end'] ?? '17:00';
					?>
				<div class="mwm-schedule-row <?php echo $enabled ? 'is-enabled' : ''; ?>">
					<label class="mwm-schedule-day-toggle">
						<input type="checkbox" class="mwm-day-toggle"
							name="days[<?php echo esc_attr( $dow ); ?>][enabled]"
							value="1" <?php checked( $enabled ); ?>>
						<span class="mwm-day-label"><?php echo esc_html( $day_labels[ $dow ] ); ?></span>
					</label>

					<div class="mwm-schedule-times <?php echo $enabled ? '' : 'mwm-times-disabled'; ?>">
						<input type="time" class="mwm-time-input"
							name="days[<?php echo esc_attr( $dow ); ?>][start]"
							value="<?php echo esc_attr( $start ); ?>"
							aria-label="<?php echo esc_attr( sprintf( /* translators: %s = day of the week */ __( '%s start time', 'meet-with-me' ), $day_labels[ $dow ] ) ); ?>"
							<?php echo $enabled ? '' : 'disabled'; ?>>
						<span class="mwm-time-sep">–</span>
						<input type="time" class="mwm-time-input"
							name="days[<?php echo esc_attr( $dow ); ?>][end]"
							value="<?php echo esc_attr( $end ); ?>"
							aria-label="<?php echo esc_attr( sprintf( /* translators: %s = day of the week */ __( '%s end time', 'meet-with-me' ), $day_labels[ $dow ] ) ); ?>"
							<?php echo $enabled ? '' : 'disabled'; ?>>
					</div>

					<span class="mwm-schedule-off-label <?php echo $enabled ? 'mwm-hidden' : ''; ?>">
						<?php esc_html_e( 'Off', 'meet-with-me' ); ?>
					</span>
				</div>
				<?php endforeach; ?>
			</div>

			<p class="submit">
				<button type="submit" name="mwm_save_schedule" class="button button-primary">
					<?php esc_html_e( 'Save Default Hours', 'meet-with-me' ); ?>
				</button>
			</p>
		</form>
	</div>

	<!-- ===== DATE OVERRIDES ===== -->
	<div class="mwm-card mwm-availability-card">
		<h2><?php esc_html_e( 'Date-Specific Overrides', 'meet-with-me' ); ?></h2>
		<p class="description" style="margin-bottom:16px;">
			<?php esc_html_e( 'Override your hours for a specific date — set custom hours or mark the day as unavailable. Overrides take priority over your weekly schedule.', 'meet-with-me' ); ?>
		</p>

		<?php if ( ! empty( $overrides ) ) : ?>
		<table class="wp-list-table widefat fixed striped mwm-avail-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Date', 'meet-with-me' ); ?></th>
					<th><?php esc_html_e( 'Status', 'meet-with-me' ); ?></th>
					<th><?php esc_html_e( 'Hours', 'meet-with-me' ); ?></th>
					<th style="width:80px;"></th>
				</tr>
			</thead>
			<tbody>
				<?php
				foreach ( $overrides as $o ) :
					$delete_url = wp_nonce_url(
						add_query_arg(
							array(
								'avail_action' => 'delete_override',
								'id'           => $o['id'],
							),
							$base_url
						),
						'mwm_delete_override_' . $o['id']
					);
					$available  = (bool) $o['is_available'];
					?>
				<tr>
					<td><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $o['override_date'] ) ) ); ?></td>
					<td>
						<?php if ( $available ) : ?>
							<span class="mwm-badge mwm-badge--active"><?php esc_html_e( 'Custom hours', 'meet-with-me' ); ?></span>
						<?php else : ?>
							<span class="mwm-badge mwm-badge--inactive"><?php esc_html_e( 'Unavailable', 'meet-with-me' ); ?></span>
						<?php endif; ?>
					</td>
					<td>
						<?php
						if ( $available ) :
							echo esc_html( substr( $o['start_time'], 0, 5 ) . ' – ' . substr( $o['end_time'], 0, 5 ) );
						else :
							echo '—';
						endif;
						?>
					</td>
					<td>
						<a href="<?php echo esc_url( $delete_url ); ?>"
							class="button button-small mwm-delete-btn"
							data-confirm="<?php esc_attr_e( 'Remove this override?', 'meet-with-me' ); ?>">
							<?php esc_html_e( 'Remove', 'meet-with-me' ); ?>
						</a>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>

		<div class="mwm-add-row-form" style="margin-top:16px;">
			<button type="button" class="button mwm-toggle-form" data-target="mwm-override-form">
				+ <?php esc_html_e( 'Add Override', 'meet-with-me' ); ?>
			</button>

			<form method="post" action="" id="mwm-override-form" class="mwm-inline-form" style="display:none;">
				<?php wp_nonce_field( 'mwm_add_override', '_mwm_override_nonce' ); ?>

				<div class="mwm-inline-form-fields">
					<div class="mwm-inline-field">
						<label for="override_date"><?php esc_html_e( 'Date', 'meet-with-me' ); ?></label>
						<input type="date" id="override_date" name="override_date"
							min="<?php echo esc_attr( wp_date( 'Y-m-d' ) ); ?>" required>
					</div>

					<div class="mwm-inline-field">
						<label><?php esc_html_e( 'Availability', 'meet-with-me' ); ?></label>
						<div class="mwm-radio-group mwm-radio-inline" role="radiogroup" aria-label="<?php esc_attr_e( 'Availability', 'meet-with-me' ); ?>">
							<label class="mwm-radio-label">
								<input type="radio" name="override_available" value="1"
									id="override-avail-yes" checked class="mwm-override-avail-toggle">
								<?php esc_html_e( 'Custom hours', 'meet-with-me' ); ?>
							</label>
							<label class="mwm-radio-label">
								<input type="radio" name="override_available" value="0"
									class="mwm-override-avail-toggle">
								<?php esc_html_e( 'Unavailable all day', 'meet-with-me' ); ?>
							</label>
						</div>
					</div>

					<div class="mwm-inline-field" id="override-time-fields">
						<label for="override_start"><?php esc_html_e( 'Hours', 'meet-with-me' ); ?></label>
						<input type="time" id="override_start" name="override_start" value="09:00" class="mwm-time-input"
							aria-label="<?php esc_attr_e( 'Override start time', 'meet-with-me' ); ?>">
						<span class="mwm-time-sep">–</span>
						<input type="time" name="override_end" value="17:00" class="mwm-time-input"
							aria-label="<?php esc_attr_e( 'Override end time', 'meet-with-me' ); ?>">
					</div>
				</div>

				<div class="mwm-inline-form-actions">
					<button type="submit" name="mwm_add_override" class="button button-primary">
						<?php esc_html_e( 'Add Override', 'meet-with-me' ); ?>
					</button>
					<button type="button" class="button mwm-toggle-form" data-target="mwm-override-form">
						<?php esc_html_e( 'Cancel', 'meet-with-me' ); ?>
					</button>
				</div>
			</form>
		</div>
	</div>

	<!-- ===== BLOCKED DATES ===== -->
	<div class="mwm-card mwm-availability-card">
		<h2><?php esc_html_e( 'Days Off', 'meet-with-me' ); ?></h2>
		<p class="description" style="margin-bottom:16px;">
			<?php esc_html_e( 'Block out specific dates entirely — vacations, holidays, anything. No bookings will be accepted on these days.', 'meet-with-me' ); ?>
		</p>

		<?php if ( ! empty( $blocked_dates ) ) : ?>
		<table class="wp-list-table widefat fixed striped mwm-avail-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Date', 'meet-with-me' ); ?></th>
					<th><?php esc_html_e( 'Reason', 'meet-with-me' ); ?></th>
					<th style="width:80px;"></th>
				</tr>
			</thead>
			<tbody>
				<?php
				foreach ( $blocked_dates as $bd ) :
					$delete_url = wp_nonce_url(
						add_query_arg(
							array(
								'avail_action' => 'delete_blocked',
								'id'           => $bd['id'],
							),
							$base_url
						),
						'mwm_delete_blocked_' . $bd['id']
					);
					?>
				<tr>
					<td><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $bd['blocked_date'] ) ) ); ?></td>
					<td><?php echo $bd['reason'] ? esc_html( $bd['reason'] ) : '<span style="color:#999">—</span>'; ?></td>
					<td>
						<a href="<?php echo esc_url( $delete_url ); ?>"
							class="button button-small mwm-delete-btn"
							data-confirm="<?php esc_attr_e( 'Remove this blocked date?', 'meet-with-me' ); ?>">
							<?php esc_html_e( 'Remove', 'meet-with-me' ); ?>
						</a>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>

		<div class="mwm-add-row-form" style="margin-top:16px;">
			<button type="button" class="button mwm-toggle-form" data-target="mwm-blocked-form">
				+ <?php esc_html_e( 'Add a Day Off', 'meet-with-me' ); ?>
			</button>

			<form method="post" action="" id="mwm-blocked-form" class="mwm-inline-form" style="display:none;">
				<?php wp_nonce_field( 'mwm_add_blocked', '_mwm_blocked_nonce' ); ?>

				<div class="mwm-inline-form-fields">
					<div class="mwm-inline-field">
						<label for="blocked_date"><?php esc_html_e( 'Date', 'meet-with-me' ); ?></label>
						<input type="date" id="blocked_date" name="blocked_date"
							min="<?php echo esc_attr( wp_date( 'Y-m-d' ) ); ?>" required>
					</div>
					<div class="mwm-inline-field">
						<label for="blocked_reason"><?php esc_html_e( 'Reason', 'meet-with-me' ); ?> <span class="description">(optional)</span></label>
						<input type="text" id="blocked_reason" name="blocked_reason"
							class="regular-text" placeholder="<?php esc_attr_e( 'e.g. Holiday, Vacation', 'meet-with-me' ); ?>">
					</div>
				</div>

				<div class="mwm-inline-form-actions">
					<button type="submit" name="mwm_add_blocked" class="button button-primary">
						<?php esc_html_e( 'Block This Day', 'meet-with-me' ); ?>
					</button>
					<button type="button" class="button mwm-toggle-form" data-target="mwm-blocked-form">
						<?php esc_html_e( 'Cancel', 'meet-with-me' ); ?>
					</button>
				</div>
			</form>
		</div>
	</div>

</div>
