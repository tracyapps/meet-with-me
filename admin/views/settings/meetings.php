<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
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

	<div class="mwm-card">
		<h2><?php esc_html_e( 'Provider Status', 'meet-with-me' ); ?></h2>
		<p class="description" style="margin-bottom: 16px;">
			<?php esc_html_e( 'Connect the providers you want to offer for online bookings. Event types can then pick one provider by default or route bookings based on a custom dropdown or radio field.', 'meet-with-me' ); ?>
		</p>
		<ul style="margin:0; padding-left:18px;">
			<li><?php echo $zoom_ready ? esc_html__( 'Zoom is ready.', 'meet-with-me' ) : esc_html__( 'Zoom is not configured yet.', 'meet-with-me' ); ?></li>
			<li><?php echo $google_meet_ready ? esc_html__( 'Google Meet is ready through your Google Calendar write-back connection.', 'meet-with-me' ) : esc_html__( 'Google Meet needs an active Google Calendar connection plus a write-back calendar.', 'meet-with-me' ); ?></li>
		</ul>
	</div>

	<form method="post" action="">
		<?php wp_nonce_field( 'mwm_meeting_settings' ); ?>

		<div class="mwm-card">
			<h2><?php esc_html_e( 'Zoom', 'meet-with-me' ); ?></h2>
			<p class="description" style="margin-bottom:10px;">
				<?php esc_html_e( 'Use a Zoom Server-to-Server OAuth app so the plugin can create meetings without asking you to log in each time.', 'meet-with-me' ); ?>
				<a href="<?php echo esc_url( $help_url ); ?>"><?php esc_html_e( 'Full Zoom setup guide →', 'meet-with-me' ); ?></a>
			</p>
			<ol class="mwm-setup-steps description">
				<li>
					<?php esc_html_e( 'Sign in and create a Server-to-Server OAuth app in the Zoom Marketplace.', 'meet-with-me' ); ?>
					<a href="https://marketplace.zoom.us/develop/create" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open Zoom Marketplace ↗', 'meet-with-me' ); ?></a>
				</li>
				<li><?php esc_html_e( 'Copy the app’s Account ID, Client ID, and Client Secret into the fields below.', 'meet-with-me' ); ?></li>
				<li><?php esc_html_e( 'Add the scopes needed to create meetings, then activate the app in Zoom.', 'meet-with-me' ); ?></li>
				<li><?php esc_html_e( 'Come back here and use “Test Connection” to confirm everything works.', 'meet-with-me' ); ?></li>
			</ol>

			<table class="form-table" role="presentation">
				<tr>
					<th><label for="zoom_account_id"><?php esc_html_e( 'Account ID', 'meet-with-me' ); ?></label></th>
					<td><input type="text" id="zoom_account_id" name="zoom_account_id" class="regular-text" value="<?php echo esc_attr( $settings['zoom_account_id'] ); ?>"></td>
				</tr>
				<tr>
					<th><label for="zoom_client_id"><?php esc_html_e( 'Client ID', 'meet-with-me' ); ?></label></th>
					<td><input type="text" id="zoom_client_id" name="zoom_client_id" class="regular-text" value="<?php echo esc_attr( $settings['zoom_client_id'] ); ?>"></td>
				</tr>
				<tr>
					<th><label for="zoom_client_secret"><?php esc_html_e( 'Client Secret', 'meet-with-me' ); ?></label></th>
					<td>
						<?php // The stored secret is never echoed back into the page: leave blank to keep it. ?>
						<input type="password" id="zoom_client_secret" name="zoom_client_secret" class="regular-text" value=""
							placeholder="<?php echo $has_zoom_secret ? esc_attr( '••••••••••••' ) : esc_attr__( 'Your client secret', 'meet-with-me' ); ?>"
							autocomplete="new-password" <?php disabled( $has_zoom_secret ); ?>>
						<?php if ( $has_zoom_secret ) : ?>
							<label style="margin-left:8px;">
								<input type="checkbox" class="mwm-replace-secret-toggle" data-target="#zoom_client_secret"> <?php esc_html_e( 'Replace secret', 'meet-with-me' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'A secret is saved. Tick “Replace secret” to enter a new one — saved secrets are never shown again.', 'meet-with-me' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th><label for="zoom_user_id"><?php esc_html_e( 'Host User ID or Email', 'meet-with-me' ); ?></label></th>
					<td>
						<input type="text" id="zoom_user_id" name="zoom_user_id" class="regular-text" value="<?php echo esc_attr( $settings['zoom_user_id'] ); ?>" placeholder="me">
						<p class="description"><?php esc_html_e( 'The Zoom user who should own newly created meetings.', 'meet-with-me' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="zoom_default_password"><?php esc_html_e( 'Default Meeting Passcode', 'meet-with-me' ); ?></label></th>
					<td>
						<input type="text" id="zoom_default_password" name="zoom_default_password" class="regular-text" value="<?php echo esc_attr( $settings['zoom_default_password'] ); ?>">
						<p class="description"><?php esc_html_e( 'Optional. Leave blank to let Zoom generate one using your account defaults.', 'meet-with-me' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Defaults', 'meet-with-me' ); ?></th>
					<td>
						<label style="display:block; margin-bottom:8px;">
							<input type="checkbox" name="zoom_waiting_room" value="1" <?php checked( ! empty( $settings['zoom_waiting_room'] ) ); ?>>
							<?php esc_html_e( 'Enable Zoom waiting room by default', 'meet-with-me' ); ?>
						</label>
						<label style="display:block;">
							<input type="checkbox" name="zoom_join_before_host" value="1" <?php checked( ! empty( $settings['zoom_join_before_host'] ) ); ?>>
							<?php esc_html_e( 'Allow joining before the host by default', 'meet-with-me' ); ?>
						</label>
					</td>
				</tr>
			</table>

			<p class="mwm-test-zoom-wrap">
				<button type="button" id="mwm-test-zoom" class="button"><?php esc_html_e( 'Test Connection', 'meet-with-me' ); ?></button>
				<span id="mwm-test-zoom-result" class="mwm-test-result" role="status" aria-live="polite"></span>
			</p>
		</div>

		<div class="mwm-card">
			<h2><?php esc_html_e( 'Google Meet', 'meet-with-me' ); ?></h2>
			<p class="description" style="margin-bottom:10px;">
				<?php esc_html_e( 'Google Meet links are created through your Google Calendar write-back connection.', 'meet-with-me' ); ?>
			</p>
			<p>
				<a href="<?php echo esc_url( $google_tab_url ); ?>" class="button">
					<?php esc_html_e( 'Open Google Calendar Settings', 'meet-with-me' ); ?>
				</a>
			</p>
		</div>

		<p class="submit">
			<button type="submit" name="mwm_save_meetings" class="button button-primary">
				<?php esc_html_e( 'Save Online Meeting Settings', 'meet-with-me' ); ?>
			</button>
		</p>
	</form>
</div>
