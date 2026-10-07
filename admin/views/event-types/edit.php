<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$list_url   = add_query_arg( array( 'page' => 'mwm-event-types' ), admin_url( 'admin.php' ) );
$page_title = $is_new
	? __( 'Add Meeting Type', 'meet-with-me' )
	: __( 'Edit Meeting Type', 'meet-with-me' );

// Defaults for new
$et = array_merge(
	array(
		'id'                      => 0,
		'name'                    => '',
		'slug'                    => '',
		'description'             => '',
		'duration_minutes'        => 30,
		'meeting_type'            => 'both',
		'online_provider'         => '',
		'online_routing_field_id' => '',
		'online_provider_rules'   => array(),
		'buffer_before'           => 0,
		'buffer_after'            => 0,
		'max_per_day'             => '',
		'max_per_week'            => '',
		'color'                   => '#3b82f6',
		'fields'                  => array(),
		'availability_override'   => null,
		'is_active'               => true,
	),
	$event_type ?? array()
);

// Is the duration a custom (non-standard) value?
$is_custom_duration     = ! array_key_exists( $et['duration_minutes'], $durations );
$fields_json            = wp_json_encode( $et['fields'] ?: array() );
$provider_json          = wp_json_encode( $provider_choices );
$routing_fields         = MWM_Online_Meetings::get_routing_fields( $et );
$selected_route_options = array();

foreach ( $routing_fields as $routing_field ) {
	if ( $routing_field['id'] === $et['online_routing_field_id'] ) {
		$selected_route_options = $routing_field['options'];
		break;
	}
}

// Automation mode is derived from the stored provider/routing data:
// no provider = off, provider + routing field = conditional, provider = always.
$automation_mode = ( $et['online_provider'] === '' ) ? 'none'
	: ( ( $et['online_routing_field_id'] !== '' ) ? 'conditional' : 'always' );

// Availability: the type's override (weekly shape) and the global defaults.
$type_weekly = array();
if ( ! empty( $et['availability_override']['weekly'] ) && is_array( $et['availability_override']['weekly'] ) ) {
	$type_weekly = $et['availability_override']['weekly'];
}
$availability_mode = empty( $type_weekly ) ? 'default' : 'custom';

// Seed the custom grid from the override when set, otherwise from the global
// defaults so "Custom hours" starts from something sensible.
$custom_seed = array();
foreach ( array( 1, 2, 3, 4, 5, 6, 0 ) as $dow ) {
	$seed = $type_weekly[ $dow ] ?? null;
	if ( ! is_array( $seed ) && isset( $global_weekly[ $dow ] ) && is_array( $global_weekly[ $dow ] ) ) {
		$seed = array(
			'enabled' => $global_weekly[ $dow ]['is_available'],
			'start'   => $global_weekly[ $dow ]['start'],
			'end'     => $global_weekly[ $dow ]['end'],
		);
	}
	if ( is_array( $seed ) ) {
		$custom_seed[ $dow ] = $seed;
	}
}

$day_names = array(
	__( 'Monday', 'meet-with-me' ),
	__( 'Tuesday', 'meet-with-me' ),
	__( 'Wednesday', 'meet-with-me' ),
	__( 'Thursday', 'meet-with-me' ),
	__( 'Friday', 'meet-with-me' ),
	__( 'Saturday', 'meet-with-me' ),
	__( 'Sunday', 'meet-with-me' ),
);

/**
 * Open a collapsible card. Closed state is managed by JS + localStorage.
 */
$card_open  = function ( string $key, string $title ): void {
	printf(
		'<div class="mwm-card mwm-collapsible" data-mwm-section="%1$s"><h2 class="mwm-card__title"><span class="mwm-card-drag-handle" title="%3$s" aria-hidden="true">&#x2807;</span><button type="button" class="mwm-card-toggle" aria-expanded="true"><span>%2$s</span><span class="mwm-card-toggle__arrow" aria-hidden="true"></span></button></h2><div class="mwm-card__body">',
		esc_attr( $key ),
		esc_html( $title ),
		esc_attr__( 'Drag to reorder', 'meet-with-me' )
	);
};
$card_close = function (): void {
	echo '</div></div>';
};
?>
<div class="wrap mwm-wrap">

	<h1>
		<a href="<?php echo esc_url( $list_url ); ?>" class="mwm-back-link">&larr; <?php esc_html_e( 'Meeting Types', 'meet-with-me' ); ?></a>
		<?php echo esc_html( $page_title ); ?>
	</h1>

	<?php if ( $notice ) : ?>
		<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible">
			<p><?php echo esc_html( $notice['message'] ); ?></p>
		</div>
	<?php endif; ?>

	<form method="post" action="" id="mwm-event-type-form" data-autosave="<?php echo $is_new ? 'off' : 'on'; ?>">
		<?php wp_nonce_field( 'mwm_save_event_type' ); ?>
		<input type="hidden" name="event_type_id" id="mwm-event-type-id" value="<?php echo esc_attr( $et['id'] ); ?>">
		<input type="hidden" name="mwm_fields" id="mwm-fields-json" value="<?php echo esc_attr( $fields_json ); ?>">
		<input type="hidden" name="availability_override" id="mwm-availability-json" value="<?php echo esc_attr( wp_json_encode( $et['availability_override'] ?? null ) ); ?>">

		<div class="mwm-edit-layout">

			<!-- Main column -->
			<div class="mwm-edit-main">

				<?php $card_open( 'basics', __( 'Basics', 'meet-with-me' ) ); ?>

					<div class="mwm-field-row">
						<label for="mwm-name"><?php esc_html_e( 'Name', 'meet-with-me' ); ?> <span class="required">*</span></label>
						<input type="text" id="mwm-name" name="name" class="large-text"
							value="<?php echo esc_attr( $et['name'] ); ?>" required
							placeholder="<?php esc_attr_e( 'e.g. 30-Minute Intro Call', 'meet-with-me' ); ?>">
					</div>

					<div class="mwm-field-row">
						<label for="mwm-slug"><?php esc_html_e( 'Slug', 'meet-with-me' ); ?></label>
						<div class="mwm-slug-field">
							<input type="text" id="mwm-slug" name="slug" class="regular-text"
								value="<?php echo esc_attr( $et['slug'] ); ?>"
								placeholder="<?php esc_attr_e( 'auto-generated', 'meet-with-me' ); ?>">
						</div>
						<p class="description"><?php esc_html_e( 'Used in shortcodes: [mwm_button event_type="your-slug"]', 'meet-with-me' ); ?></p>
					</div>

					<div class="mwm-field-row">
						<label for="mwm-description"><?php esc_html_e( 'Description', 'meet-with-me' ); ?></label>
						<textarea id="mwm-description" name="description" class="large-text" rows="3"
							placeholder="<?php esc_attr_e( 'Shown on the booking card. Briefly describe what this meeting is for.', 'meet-with-me' ); ?>"><?php echo esc_textarea( $et['description'] ); ?></textarea>
					</div>

				<?php $card_close(); ?>

				<?php $card_open( 'duration-format', __( 'Duration & Format', 'meet-with-me' ) ); ?>

					<div class="mwm-field-row">
						<label for="mwm-duration"><?php esc_html_e( 'Duration', 'meet-with-me' ); ?></label>
						<div class="mwm-duration-field">
							<select id="mwm-duration" name="duration_minutes">
								<?php foreach ( $durations as $mins => $label ) : ?>
									<option value="<?php echo esc_attr( $mins ); ?>"
										<?php selected( ! $is_custom_duration && $et['duration_minutes'] === $mins ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
								<option value="0" <?php selected( $is_custom_duration ); ?>><?php esc_html_e( 'Custom&hellip;', 'meet-with-me' ); ?></option>
							</select>
							<span id="mwm-duration-custom-wrap" <?php echo $is_custom_duration ? '' : 'style="display:none"'; ?>>
								<input type="number" id="mwm-duration-custom" name="duration_custom"
									aria-label="<?php esc_attr_e( 'Custom duration in minutes', 'meet-with-me' ); ?>"
									value="<?php echo $is_custom_duration ? esc_attr( $et['duration_minutes'] ) : ''; ?>"
									min="5" max="480" class="small-text">
								<?php esc_html_e( 'minutes', 'meet-with-me' ); ?>
							</span>
						</div>
					</div>

					<div class="mwm-field-row">
						<strong id="mwm-meeting-format-label" class="mwm-radio-group-label"><?php esc_html_e( 'Meeting Format', 'meet-with-me' ); ?></strong>
						<div class="mwm-radio-group" role="radiogroup" aria-labelledby="mwm-meeting-format-label">
							<?php
							$formats = array(
								'online'    => __( 'Online only', 'meet-with-me' ),
								'in_person' => __( 'In person only', 'meet-with-me' ),
								'both'      => __( 'Let the booker choose', 'meet-with-me' ),
							);
							foreach ( $formats as $val => $label ) :
								?>
								<label class="mwm-radio-label">
									<input type="radio" name="meeting_type" value="<?php echo esc_attr( $val ); ?>"
										<?php checked( $et['meeting_type'], $val ); ?>>
									<?php echo esc_html( $label ); ?>
								</label>
							<?php endforeach; ?>
						</div>
					</div>

					<div class="mwm-field-row mwm-field-row--inline">
						<div>
							<label for="mwm-buffer-before"><?php esc_html_e( 'Buffer Before', 'meet-with-me' ); ?></label>
							<input type="number" id="mwm-buffer-before" name="buffer_before"
								value="<?php echo esc_attr( $et['buffer_before'] ); ?>"
								min="0" max="120" class="small-text">
							<?php esc_html_e( 'min', 'meet-with-me' ); ?>
							<p class="description"><?php esc_html_e( 'Padding before each meeting.', 'meet-with-me' ); ?></p>
						</div>
						<div>
							<label for="mwm-buffer-after"><?php esc_html_e( 'Buffer After', 'meet-with-me' ); ?></label>
							<input type="number" id="mwm-buffer-after" name="buffer_after"
								value="<?php echo esc_attr( $et['buffer_after'] ); ?>"
								min="0" max="120" class="small-text">
							<?php esc_html_e( 'min', 'meet-with-me' ); ?>
							<p class="description"><?php esc_html_e( 'Padding after each meeting.', 'meet-with-me' ); ?></p>
						</div>
					</div>

				<?php $card_close(); ?>

				<?php $card_open( 'limits', __( 'Booking Limits', 'meet-with-me' ) ); ?>
					<p class="description" style="margin-bottom:16px;"><?php esc_html_e( 'Leave blank for no limit.', 'meet-with-me' ); ?></p>

					<div class="mwm-field-row mwm-field-row--inline">
						<div>
							<label for="mwm-max-day"><?php esc_html_e( 'Max per Day', 'meet-with-me' ); ?></label>
							<input type="number" id="mwm-max-day" name="max_per_day"
								value="<?php echo esc_attr( $et['max_per_day'] ?? '' ); ?>"
								min="1" placeholder="&mdash;" class="small-text">
						</div>
						<div>
							<label for="mwm-max-week"><?php esc_html_e( 'Max per Week', 'meet-with-me' ); ?></label>
							<input type="number" id="mwm-max-week" name="max_per_week"
								value="<?php echo esc_attr( $et['max_per_week'] ?? '' ); ?>"
								min="1" placeholder="&mdash;" class="small-text">
						</div>
					</div>
				<?php $card_close(); ?>

				<?php $card_open( 'availability', __( 'Availability', 'meet-with-me' ) ); ?>
					<div class="mwm-field-row">
						<strong id="mwm-availability-mode-label" class="mwm-radio-group-label"><?php esc_html_e( 'Schedule', 'meet-with-me' ); ?></strong>
						<div class="mwm-availability-mode" role="radiogroup" aria-labelledby="mwm-availability-mode-label">
							<label class="mwm-radio-label">
								<input type="radio" name="availability_mode" value="default" <?php checked( $availability_mode, 'default' ); ?>>
								<?php esc_html_e( 'Use default hours', 'meet-with-me' ); ?>
							</label>
							<label class="mwm-radio-label">
								<input type="radio" name="availability_mode" value="custom" <?php checked( $availability_mode, 'custom' ); ?>>
								<?php esc_html_e( 'Custom hours for this type', 'meet-with-me' ); ?>
							</label>
						</div>
						<p class="description"><?php esc_html_e( 'Default hours come from Settings → Default Availability. Date overrides and days off always apply.', 'meet-with-me' ); ?></p>
					</div>

					<div id="mwm-availability-ghost" class="mwm-schedule-grid mwm-schedule-grid--ghost">
						<?php
						$gi = 0;
						foreach ( array( 1, 2, 3, 4, 5, 6, 0 ) as $dow ) :
							$rule  = $global_weekly[ $dow ] ?? null;
							$on    = $rule && $rule['is_available'];
							$times = $on ? $rule['start'] . ' &ndash; ' . $rule['end'] : '&mdash;';
							?>
							<div class="mwm-schedule-row <?php echo $on ? 'is-enabled' : ''; ?>">
								<span class="mwm-schedule-day-label"><?php echo esc_html( $day_names[ $gi ] ); ?></span>
								<span class="mwm-schedule-ghost-times"><?php echo $on ? esc_html( $rule['start'] ) . ' &ndash; ' . esc_html( $rule['end'] ) : esc_html__( 'Off', 'meet-with-me' ); ?></span>
							</div>
							<?php
							++$gi;
						endforeach;
						?>
						<p class="description"><?php esc_html_e( 'Defaults — shared by every meeting type without custom hours.', 'meet-with-me' ); ?></p>
					</div>

					<div id="mwm-availability-custom" class="mwm-schedule-grid" <?php echo $availability_mode === 'custom' ? '' : 'style="display:none"'; ?>>
						<?php
						$ci = 0;
						foreach ( array( 1, 2, 3, 4, 5, 6, 0 ) as $dow ) :
							$day = $custom_seed[ $dow ] ?? array(
								'enabled' => false,
								'start'   => '09:00',
								'end'     => '17:00',
							);
							?>
							<div class="mwm-schedule-row<?php echo ! empty( $day['enabled'] ) ? ' is-enabled' : ''; ?>" data-dow="<?php echo esc_attr( $dow ); ?>">
								<label class="mwm-schedule-day-toggle">
									<input type="checkbox" class="mwm-day-toggle" name="type_days[<?php echo esc_attr( $dow ); ?>][enabled]" value="1" <?php checked( ! empty( $day['enabled'] ) ); ?>>
									<span class="mwm-schedule-day-label"><?php echo esc_html( $day_names[ $ci ] ); ?></span>
								</label>
								<div class="mwm-schedule-times <?php echo empty( $day['enabled'] ) ? 'mwm-times-disabled' : ''; ?>">
									<input type="time" class="mwm-time-input" name="type_days[<?php echo esc_attr( $dow ); ?>][start]"
										value="<?php echo esc_attr( $day['start'] ); ?>" <?php disabled( empty( $day['enabled'] ) ); ?>>
									&ndash;
									<input type="time" class="mwm-time-input" name="type_days[<?php echo esc_attr( $dow ); ?>][end]"
										value="<?php echo esc_attr( $day['end'] ); ?>" <?php disabled( empty( $day['enabled'] ) ); ?>>
								</div>
								<span class="mwm-schedule-off-label <?php echo empty( $day['enabled'] ) ? '' : 'mwm-hidden'; ?>"><?php esc_html_e( 'Off', 'meet-with-me' ); ?></span>
							</div>
							<?php
							++$ci;
						endforeach;
						?>
					</div>
				<?php $card_close(); ?>

				<?php $card_open( 'questions', __( 'Questions for the Booker', 'meet-with-me' ) ); ?>
					<p class="description" style="margin-bottom:16px;">
						<?php esc_html_e( 'Add questions that appear on the booking form for this meeting type. Drag to reorder, or use the up/down buttons.', 'meet-with-me' ); ?>
					</p>

					<div id="mwm-fields-list" class="mwm-fields-list">
						<!-- Rows injected by JS from mwm_fields_json -->
					</div>

					<button type="button" id="mwm-add-field" class="button">
						+ <?php esc_html_e( 'Add a Question', 'meet-with-me' ); ?>
					</button>
				<?php $card_close(); ?>

				<?php $card_open( 'automation', __( 'Online Meeting Automation', 'meet-with-me' ) ); ?>
					<p class="description" style="margin-bottom:16px;">
						<?php esc_html_e( 'Applies to online bookings. Route bookers to a connected provider — optionally based on one of your questions above.', 'meet-with-me' ); ?>
					</p>

					<div id="mwm-automation-card" data-current-format="<?php echo esc_attr( $et['meeting_type'] ); ?>">
						<?php if ( empty( $provider_choices ) ) : ?>
							<p class="description">
								<?php esc_html_e( 'No online meeting providers are configured yet. Set up Zoom or Google Meet in Settings → Online Meetings, then come back here.', 'meet-with-me' ); ?>
							</p>
						<?php else : ?>
						<div class="mwm-field-row" id="mwm-automation-mode-row">
							<label id="mwm-automation-mode-label"><?php esc_html_e( 'Automatically create an online meeting', 'meet-with-me' ); ?></label>
							<div class="mwm-segmented" role="radiogroup" aria-labelledby="mwm-automation-mode-label">
								<?php
								$modes = array(
									'none'        => __( 'No', 'meet-with-me' ),
									'always'      => __( 'Yes', 'meet-with-me' ),
									'conditional' => __( 'Conditional', 'meet-with-me' ),
								);
								foreach ( $modes as $mode_key => $mode_label ) :
									?>
									<label class="mwm-segmented__item">
										<input type="radio" name="automation_mode" value="<?php echo esc_attr( $mode_key ); ?>"
											id="mwm-automation-mode-<?php echo esc_attr( $mode_key ); ?>"
											<?php checked( $automation_mode, $mode_key ); ?>>
										<span><?php echo esc_html( $mode_label ); ?></span>
									</label>
								<?php endforeach; ?>
							</div>
						</div>

						<div class="mwm-field-row mwm-automation-provider" id="mwm-automation-provider-row">
							<label for="mwm-online-provider"><?php esc_html_e( 'Provider', 'meet-with-me' ); ?></label>
							<select id="mwm-online-provider" name="online_provider" class="regular-text">
								<option value=""><?php esc_html_e( 'Choose a provider&hellip;', 'meet-with-me' ); ?></option>
								<?php foreach ( $provider_choices as $provider_key => $provider_label ) : ?>
									<option value="<?php echo esc_attr( $provider_key ); ?>" <?php selected( $et['online_provider'], $provider_key ); ?>>
										<?php echo esc_html( $provider_label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Connected providers only. Disconnecting a provider later leaves bookings intact.', 'meet-with-me' ); ?></p>
						</div>

						<div class="mwm-field-row mwm-automation-routing" id="mwm-automation-routing-row">
							<label for="mwm-online-routing-field"><?php esc_html_e( 'Route By', 'meet-with-me' ); ?></label>
							<select id="mwm-online-routing-field" name="online_routing_field_id" class="regular-text">
								<option value=""><?php esc_html_e( 'No conditional routing', 'meet-with-me' ); ?></option>
								<?php foreach ( $routing_fields as $routing_field ) : ?>
									<option value="<?php echo esc_attr( $routing_field['id'] ); ?>" <?php selected( $et['online_routing_field_id'], $routing_field['id'] ); ?>>
										<?php echo esc_html( $routing_field['label'] ); ?> (<?php echo esc_html( $routing_field['type'] ); ?>)
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Choose a dropdown or radio question if certain answers should use a different provider.', 'meet-with-me' ); ?></p>
						</div>

						<div class="mwm-field-row mwm-automation-routing" id="mwm-automation-rules-row">
							<label><?php esc_html_e( 'Routing Rules', 'meet-with-me' ); ?></label>
							<div
								id="mwm-online-provider-rules"
								data-provider-choices="<?php echo esc_attr( $provider_json ); ?>"
								data-routing-fields="<?php echo esc_attr( wp_json_encode( $routing_fields ) ); ?>"
							>
								<?php if ( empty( $selected_route_options ) ) : ?>
									<p class="description"><?php esc_html_e( 'Select a routing field above to map specific answers to connected providers.', 'meet-with-me' ); ?></p>
								<?php else : ?>
									<?php foreach ( $selected_route_options as $option ) : ?>
										<div class="mwm-provider-rule-row">
											<span class="mwm-provider-rule-label"><?php echo esc_html( $option ); ?></span>
											<select name="online_provider_rules[<?php echo esc_attr( $option ); ?>]">
												<option value=""><?php esc_html_e( 'Use default provider', 'meet-with-me' ); ?></option>
												<?php foreach ( $provider_choices as $provider_key => $provider_label ) : ?>
													<option value="<?php echo esc_attr( $provider_key ); ?>" <?php selected( $et['online_provider_rules'][ $option ] ?? '', $provider_key ); ?>>
														<?php echo esc_html( $provider_label ); ?>
													</option>
												<?php endforeach; ?>
											</select>
										</div>
									<?php endforeach; ?>
								<?php endif; ?>
							</div>
						</div>
						<?php endif; ?>
					</div>
				<?php $card_close(); ?>

			</div><!-- .mwm-edit-main -->

			<!-- Sidebar -->
			<div class="mwm-edit-sidebar">

				<div class="mwm-card mwm-card--sticky" id="mwm-publish-card">
					<h2><span class="mwm-card-drag-handle" title="<?php esc_attr_e( 'Drag to reorder', 'meet-with-me' ); ?>" aria-hidden="true">&#x2807;</span><?php esc_html_e( 'Publish', 'meet-with-me' ); ?></h2>

					<div class="mwm-field-row">
						<label class="mwm-toggle-label">
							<input type="checkbox" name="is_active" value="1"
								<?php checked( $et['is_active'] ); ?>>
							<?php esc_html_e( 'Active (visible to bookers)', 'meet-with-me' ); ?>
						</label>
					</div>

					<div class="mwm-field-row">
						<label for="mwm-color"><?php esc_html_e( 'Color', 'meet-with-me' ); ?></label>
						<input type="color" id="mwm-color" name="color"
							value="<?php echo esc_attr( $et['color'] ); ?>">
						<p class="description"><?php esc_html_e( 'Used on booking cards.', 'meet-with-me' ); ?></p>
					</div>

					<span id="mwm-autosave-status" class="mwm-autosave-status" role="status" aria-live="polite"></span>

					<div class="mwm-sidebar-actions">
						<button type="submit" name="mwm_save_event_type" class="button button-primary button-large">
							<?php echo $is_new ? esc_html__( 'Create Meeting Type', 'meet-with-me' ) : esc_html__( 'Save Changes', 'meet-with-me' ); ?>
						</button>
						<a href="<?php echo esc_url( $list_url ); ?>" class="button button-large">
							<?php esc_html_e( 'Back to list', 'meet-with-me' ); ?>
						</a>
					</div>
				</div>

				<?php if ( ! $is_new ) : ?>
				<div class="mwm-card mwm-card--shortcodes" id="mwm-shortcodes-card">
					<h2><span class="mwm-card-drag-handle" title="<?php esc_attr_e( 'Drag to reorder', 'meet-with-me' ); ?>" aria-hidden="true">&#x2807;</span><?php esc_html_e( 'Shortcodes', 'meet-with-me' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Copy and paste these into any page or widget.', 'meet-with-me' ); ?></p>

					<div class="mwm-shortcode-copy">
						<label><?php esc_html_e( 'Button', 'meet-with-me' ); ?></label>
						<code class="mwm-shortcode" data-copy tabindex="0" role="button">[mwm_button event_type="<?php echo esc_attr( $et['slug'] ); ?>"]</code>
					</div>
					<div class="mwm-shortcode-copy">
						<label><?php esc_html_e( 'Full booking form', 'meet-with-me' ); ?></label>
						<code class="mwm-shortcode" data-copy tabindex="0" role="button">[mwm_booking_form event_type="<?php echo esc_attr( $et['slug'] ); ?>"]</code>
					</div>
				</div>
				<?php endif; ?>

				<div class="mwm-card mwm-preview-card" id="mwm-preview-card">
					<h2>
						<span class="mwm-card-drag-handle" title="<?php esc_attr_e( 'Drag to reorder', 'meet-with-me' ); ?>" aria-hidden="true">&#x2807;</span>
						<?php esc_html_e( 'Preview', 'meet-with-me' ); ?>
						<button type="button" class="button-link mwm-preview-move" id="mwm-preview-move"
							title="<?php esc_attr_e( 'Move preview to the main column or back to the sidebar', 'meet-with-me' ); ?>">
							&#8661; <span class="screen-reader-text"><?php esc_html_e( 'Move preview', 'meet-with-me' ); ?></span>
						</button>
					</h2>
					<div class="mwm-preview-filters" role="group" aria-label="<?php esc_attr_e( 'Preview meeting format', 'meet-with-me' ); ?>">
						<button type="button" class="mwm-preview-filter is-active" data-preview-format="all"><?php esc_html_e( 'All', 'meet-with-me' ); ?></button>
						<button type="button" class="mwm-preview-filter" data-preview-format="online"><?php esc_html_e( 'Online', 'meet-with-me' ); ?></button>
						<button type="button" class="mwm-preview-filter" data-preview-format="in_person"><?php esc_html_e( 'In person', 'meet-with-me' ); ?></button>
					</div>
					<div id="mwm-live-preview" class="mwm-live-preview" aria-hidden="true"></div>
					<p class="description"><?php esc_html_e( 'Live preview — updates as you edit. Not clickable.', 'meet-with-me' ); ?></p>
				</div>

			</div><!-- .mwm-edit-sidebar -->

		</div><!-- .mwm-edit-layout -->

	</form>

</div>

<!-- Field row template (hidden, cloned by JS) -->
<script type="text/html" id="mwm-field-row-tpl">
<div class="mwm-field-row-item" data-field-id="__ID__">
	<div class="mwm-field-row-handle" title="<?php esc_attr_e( 'Drag to reorder', 'meet-with-me' ); ?>" aria-hidden="true">&#x2807;</div>
	<div class="mwm-field-row-body">
		<div class="mwm-field-row-top">
			<input type="text" class="mwm-fl-label regular-text" aria-label="<?php esc_attr_e( 'Question label', 'meet-with-me' ); ?>" placeholder="<?php esc_attr_e( 'Question label, e.g. Where should we meet?', 'meet-with-me' ); ?>">
			<select class="mwm-fl-type" aria-label="<?php esc_attr_e( 'Question type', 'meet-with-me' ); ?>">
				<option value="text"><?php esc_html_e( 'Short text', 'meet-with-me' ); ?></option>
				<option value="textarea"><?php esc_html_e( 'Long text', 'meet-with-me' ); ?></option>
				<option value="select"><?php esc_html_e( 'Dropdown', 'meet-with-me' ); ?></option>
				<option value="radio"><?php esc_html_e( 'Radio (pick one)', 'meet-with-me' ); ?></option>
				<option value="checkbox"><?php esc_html_e( 'Checkboxes (pick multiple)', 'meet-with-me' ); ?></option>
			</select>
			<label class="mwm-fl-required-wrap">
				<input type="checkbox" class="mwm-fl-required">
				<?php esc_html_e( 'Required', 'meet-with-me' ); ?>
			</label>
			<span class="mwm-fl-display-for" data-display-for-wrap>
				<span class="screen-reader-text"><?php esc_html_e( 'Display this question for:', 'meet-with-me' ); ?></span>
				<label class="mwm-display-chip" data-mwm-display-chip="online">
					<input type="checkbox" class="mwm-fl-show-online">
					<span><?php esc_html_e( 'Online', 'meet-with-me' ); ?></span>
				</label>
				<label class="mwm-display-chip" data-mwm-display-chip="in_person">
					<input type="checkbox" class="mwm-fl-show-in-person">
					<span><?php esc_html_e( 'In person', 'meet-with-me' ); ?></span>
				</label>
			</span>
			<button type="button" class="mwm-field-row-up button-link" aria-label="<?php esc_attr_e( 'Move question up', 'meet-with-me' ); ?>" title="<?php esc_attr_e( 'Move question up', 'meet-with-me' ); ?>">&#9650;</button>
			<button type="button" class="mwm-field-row-down button-link" aria-label="<?php esc_attr_e( 'Move question down', 'meet-with-me' ); ?>" title="<?php esc_attr_e( 'Move question down', 'meet-with-me' ); ?>">&#9660;</button>
			<button type="button" class="mwm-fl-remove button-link-delete" aria-label="<?php esc_attr_e( 'Remove question', 'meet-with-me' ); ?>" title="<?php esc_attr_e( 'Remove question', 'meet-with-me' ); ?>">&#x2715;</button>
		</div>
		<div class="mwm-field-row-placeholder" style="display:none;">
			<input type="text" class="mwm-fl-placeholder regular-text"
				aria-label="<?php esc_attr_e( 'Placeholder text', 'meet-with-me' ); ?>"
				placeholder="<?php esc_attr_e( 'Placeholder text (optional)', 'meet-with-me' ); ?>">
		</div>
		<div class="mwm-field-row-options" style="display:none;">
			<div class="mwm-options-config">
				<label>
					<span class="screen-reader-text"><?php esc_html_e( 'Option layout', 'meet-with-me' ); ?></span>
					<select class="mwm-fl-layout" aria-label="<?php esc_attr_e( 'Option layout', 'meet-with-me' ); ?>">
						<option value="stacked"><?php esc_html_e( 'Stacked', 'meet-with-me' ); ?></option>
						<option value="inline"><?php esc_html_e( 'Inline', 'meet-with-me' ); ?></option>
						<option value="columns"><?php esc_html_e( 'Columns', 'meet-with-me' ); ?></option>
					</select>
				</label>
			</div>
			<div class="mwm-options-list"></div>
			<button type="button" class="mwm-fl-add-option button-link">+ <?php esc_html_e( 'Add option', 'meet-with-me' ); ?></button>
		</div>
	</div>
</div>
</script>
