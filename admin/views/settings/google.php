<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$disconnect_url     = wp_nonce_url(
	add_query_arg(
		array(
			'page'              => 'mwm-settings',
			'tab'               => 'google',
			'mwm_google_action' => 'disconnect',
		),
		admin_url( 'admin.php' )
	),
	'mwm_google_disconnect'
);
$oauth_url          = $has_credentials ? MWM_Google_Calendar::get_oauth_url() : '#';
$selected_ids       = array_values( array_filter( (array) ( $settings['calendar_ids'] ?? array() ) ) );
$write_back_id      = (string) ( $settings['write_back_calendar_id'] ?? '' );
$has_secret         = ! empty( $settings['client_secret'] );
$help_url           = add_query_arg(
	array(
		'page' => 'mwm-settings',
		'tab'  => 'help',
	),
	admin_url( 'admin.php' )
) . '#mwm-help-google';
$writable_calendars = array();

foreach ( $calendar_list as $calendar ) {
	if ( ! empty( $calendar['writable'] ) ) {
		$writable_calendars[] = $calendar;
	}
}

// Friendly connection details (write-back target + token auto-renewal).
$connected_summary = '';
if ( $is_connected ) {
	if ( $write_back_id ) {
		$summary_name = $write_back_id;
		foreach ( $calendar_list as $calendar ) {
			if ( $calendar['id'] === $write_back_id ) {
				$summary_name = $calendar['summary'];
				break;
			}
		}
		/* translators: %s = calendar name or ID */
		$connected_summary = sprintf( __( 'Writing bookings to: %s', 'meet-with-me' ), $summary_name );
	} else {
		foreach ( $calendar_list as $calendar ) {
			if ( in_array( $calendar['id'], $selected_ids, true ) || ( empty( $selected_ids ) && ! empty( $calendar['primary'] ) ) ) {
				/* translators: %s = calendar name */
				$connected_summary = sprintf( __( 'Checking conflicts on: %s', 'meet-with-me' ), $calendar['summary'] );
				break;
			}
		}
	}

	$token_expiry = (int) ( $settings['token_expiry'] ?? 0 );
	if ( $token_expiry > 0 ) {
		/* translators: %s = date/time */
		$renewal            = sprintf( __( 'Access token refreshes automatically (next renewal by %s)', 'meet-with-me' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $token_expiry ) );
		$connected_summary .= ( $connected_summary ? ' · ' : '' ) . $renewal;
	}
}
?>
<div class="wrap mwm-settings-wrap">

	<h1><?php esc_html_e( 'Meet With Me — Settings', 'meet-with-me' ); ?></h1>

	<?php mwm_admin_tabs( $current_tab ); ?>

	<?php if ( $notice ) : ?>
		<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible">
			<p><?php echo esc_html( $notice['message'] ); ?></p>
		</div>
	<?php endif; ?>

	<!-- Connection status banner -->
	<div class="mwm-card mwm-gcal-status-card">
		<?php if ( $is_connected ) : ?>
			<div class="mwm-gcal-status mwm-gcal-status--connected">
				<span class="mwm-gcal-status__dot"></span>
				<span class="mwm-gcal-status__text">
					<?php esc_html_e( 'Connected to Google Calendar', 'meet-with-me' ); ?>
					<?php if ( $connected_summary ) : ?>
						<span class="mwm-gcal-status__detail"><?php echo esc_html( $connected_summary ); ?></span>
					<?php endif; ?>
				</span>
				<a href="<?php echo esc_url( $disconnect_url ); ?>"
					class="button mwm-gcal-disconnect"
					onclick="return confirm('<?php esc_attr_e( 'Disconnect from Google Calendar? Your existing bookings will not be affected, but new ones will no longer sync.', 'meet-with-me' ); ?>')">
					<?php esc_html_e( 'Disconnect', 'meet-with-me' ); ?>
				</a>
			</div>
		<?php else : ?>
			<div class="mwm-gcal-status mwm-gcal-status--disconnected">
				<span class="mwm-gcal-status__dot"></span>
				<span><?php esc_html_e( 'Not connected', 'meet-with-me' ); ?></span>
			</div>
		<?php endif; ?>
	</div>

	<form method="post" action="" id="mwm-gcal-form">
		<?php wp_nonce_field( 'mwm_google_settings' ); ?>

		<!-- Step 1: GCP Credentials -->
		<div class="mwm-card">
			<h2>
				<span class="mwm-step-num">1</span>
				<?php esc_html_e( 'Google API Credentials', 'meet-with-me' ); ?>
			</h2>
			<p class="description" style="margin-bottom:16px;">
				<?php esc_html_e( 'You need a Google Cloud project with the Calendar API enabled and an OAuth 2.0 client ID. ', 'meet-with-me' ); ?>
				<a href="<?php echo esc_url( $help_url ); ?>">
					<?php esc_html_e( 'Step-by-step setup guide →', 'meet-with-me' ); ?>
				</a>
			</p>

			<div class="mwm-gcal-redirect-uri">
				<label><?php esc_html_e( 'Authorised Redirect URI', 'meet-with-me' ); ?></label>
				<p class="description" style="margin-bottom:6px;"><?php esc_html_e( 'Add this exact URL to your Google OAuth client\'s "Authorised redirect URIs" in Google Cloud Console:', 'meet-with-me' ); ?></p>
				<code class="mwm-shortcode" data-copy tabindex="0" role="button"><?php echo esc_html( $redirect_uri ); ?></code>
			</div>

			<table class="form-table" role="presentation" style="margin-top:16px;">
				<tr>
					<th><label for="client_id"><?php esc_html_e( 'Client ID', 'meet-with-me' ); ?></label></th>
					<td>
						<input type="text" id="client_id" name="client_id" class="large-text"
							value="<?php echo esc_attr( $settings['client_id'] ); ?>"
							placeholder="xxxxxxxxxx.apps.googleusercontent.com">
					</td>
				</tr>
				<tr>
					<th><label for="client_secret"><?php esc_html_e( 'Client Secret', 'meet-with-me' ); ?></label></th>
					<td>
						<?php // The stored secret is never echoed back into the page: leave blank to keep it. ?>
						<input type="password"
							id="client_secret" name="client_secret" class="regular-text"
							value=""
							placeholder="<?php echo $has_secret ? esc_attr( '••••••••••••' ) : esc_attr__( 'Your client secret', 'meet-with-me' ); ?>"
							autocomplete="new-password" <?php disabled( $has_secret ); ?>>
						<?php if ( $has_secret ) : ?>
							<label style="margin-left:8px;">
								<input type="checkbox" class="mwm-replace-secret-toggle" data-target="#client_secret"> <?php esc_html_e( 'Replace secret', 'meet-with-me' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'A secret is saved. Tick “Replace secret” to enter a new one — saved secrets are never shown again.', 'meet-with-me' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
			</table>

			<p class="submit" style="padding-bottom:0;">
				<button type="submit" name="mwm_save_google" class="button button-secondary">
					<?php esc_html_e( 'Save Credentials', 'meet-with-me' ); ?>
				</button>
			</p>
		</div>

		<!-- Step 2: Connect -->
		<div class="mwm-card">
			<h2>
				<span class="mwm-step-num">2</span>
				<?php esc_html_e( 'Connect Your Account', 'meet-with-me' ); ?>
			</h2>

			<?php if ( $is_connected ) : ?>
				<p><?php esc_html_e( 'Your Google account is connected. If you need to re-authorise, disconnect first then reconnect.', 'meet-with-me' ); ?></p>
			<?php elseif ( $has_credentials ) : ?>
				<p class="description" style="margin-bottom:16px;">
					<?php esc_html_e( 'Click below to authorise Meet With Me to read and write your Google Calendar.', 'meet-with-me' ); ?>
				</p>
				<a href="<?php echo esc_url( $oauth_url ); ?>" class="button button-primary mwm-gcal-connect-btn">
					<?php esc_html_e( 'Connect with Google', 'meet-with-me' ); ?>
				</a>
			<?php else : ?>
				<p class="description">
					<?php esc_html_e( 'Save your Client ID and Client Secret above first, then come back here to connect.', 'meet-with-me' ); ?>
				</p>
			<?php endif; ?>
		</div>

		<!-- Step 3: Calendars (only when connected) -->
		<?php if ( $is_connected ) : ?>
		<div class="mwm-card">
			<h2>
				<span class="mwm-step-num">3</span>
				<?php esc_html_e( 'Calendar Configuration', 'meet-with-me' ); ?>
			</h2>

			<!-- Calendars to check for busy times -->
			<div class="mwm-field-row">
				<label><?php esc_html_e( 'Calendars to Check for Conflicts', 'meet-with-me' ); ?></label>
				<p class="description" style="margin-bottom:10px;">
					<?php esc_html_e( 'Meet With Me will treat any busy time on these calendars as unavailable. Your primary calendar is usually your Google email address.', 'meet-with-me' ); ?>
				</p>

				<div id="mwm-calendar-checkboxes">
					<?php if ( ! empty( $calendar_list ) ) : ?>
						<?php foreach ( $calendar_list as $calendar ) : ?>
							<?php
							$is_checked = in_array( $calendar['id'], $selected_ids, true ) || ( empty( $selected_ids ) && ! empty( $calendar['primary'] ) );
							$classes    = 'mwm-calendar-check';
							if ( ! empty( $calendar['primary'] ) ) {
								$classes .= ' mwm-calendar-check--primary';
							}
							?>
							<label class="<?php echo esc_attr( $classes ); ?>">
								<input type="checkbox" name="calendar_ids[]" value="<?php echo esc_attr( $calendar['id'] ); ?>" <?php checked( $is_checked ); ?>>
								<?php echo esc_html( $calendar['summary'] ); ?>
								<?php if ( ! empty( $calendar['primary'] ) ) : ?>
									<em><?php esc_html_e( '(primary)', 'meet-with-me' ); ?></em>
								<?php endif; ?>
								<?php if ( empty( $calendar['writable'] ) ) : ?>
									<small><?php esc_html_e( 'Read only', 'meet-with-me' ); ?></small>
								<?php endif; ?>
							</label>
						<?php endforeach; ?>
					<?php else : ?>
						<p class="description" id="mwm-no-cals-msg"><?php esc_html_e( 'We could not load your calendars just now. Use the refresh button below or reconnect Google if this keeps happening.', 'meet-with-me' ); ?></p>
						<?php foreach ( $selected_ids as $cal_id ) : ?>
							<label class="mwm-calendar-check">
								<input type="checkbox" name="calendar_ids[]" value="<?php echo esc_attr( $cal_id ); ?>" checked>
								<?php echo esc_html( $cal_id ); ?>
							</label>
						<?php endforeach; ?>
					<?php endif; ?>
				</div>

				<button type="button" id="mwm-load-cals-btn" class="button" style="margin-top:10px;">
					<?php esc_html_e( 'Refresh My Calendars', 'meet-with-me' ); ?>
				</button>
				<?php wp_nonce_field( 'mwm_fetch_calendars', 'mwm_fetch_cals_nonce' ); ?>
			</div>

			<!-- Write-back calendar -->
			<div class="mwm-field-row" style="margin-top:20px;">
				<label for="write_back_calendar_id"><?php esc_html_e( 'Add New Bookings To', 'meet-with-me' ); ?></label>
				<p class="description" style="margin-bottom:6px;">
					<?php esc_html_e( 'Choose which calendar confirmed bookings should be written to. Leave blank to disable write-back.', 'meet-with-me' ); ?>
				</p>
				<?php if ( ! empty( $writable_calendars ) ) : ?>
					<select id="write_back_calendar_id" name="write_back_calendar_id" class="regular-text">
						<option value=""><?php esc_html_e( 'Do not create Google Calendar events', 'meet-with-me' ); ?></option>
						<?php foreach ( $writable_calendars as $calendar ) : ?>
							<option value="<?php echo esc_attr( $calendar['id'] ); ?>" <?php selected( $write_back_id, $calendar['id'] ); ?>>
								<?php echo esc_html( $calendar['summary'] ); ?><?php echo ! empty( $calendar['primary'] ) ? esc_html__( ' (primary)', 'meet-with-me' ) : ''; ?>
							</option>
						<?php endforeach; ?>
						<?php if ( $write_back_id && ! array_filter( $writable_calendars, fn( $calendar ) => $calendar['id'] === $write_back_id ) ) : ?>
							<option value="<?php echo esc_attr( $write_back_id ); ?>" selected>
								<?php echo esc_html( $write_back_id ); ?>
							</option>
						<?php endif; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Only calendars you can write to appear here.', 'meet-with-me' ); ?></p>
				<?php else : ?>
					<input type="text" id="write_back_calendar_id" name="write_back_calendar_id"
						class="regular-text"
						value="<?php echo esc_attr( $write_back_id ); ?>"
						placeholder="primary">
					<p class="description"><?php esc_html_e( 'If your calendars could not be loaded, you can still enter a calendar ID manually.', 'meet-with-me' ); ?></p>
				<?php endif; ?>
			</div>

			<p class="submit">
				<button type="submit" name="mwm_save_google" class="button button-primary">
					<?php esc_html_e( 'Save Calendar Settings', 'meet-with-me' ); ?>
				</button>
			</p>
		</div>
		<?php endif; ?>

	</form>
</div>
