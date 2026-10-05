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

	<form method="post" action="" id="mwm-email-settings-form">
		<?php wp_nonce_field( 'mwm_email_settings' ); ?>

		<!-- Sender -->
		<div class="mwm-card">
			<h2><?php esc_html_e( 'Sender Details', 'meet-with-me' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th><label for="from_name"><?php esc_html_e( 'From Name', 'meet-with-me' ); ?></label></th>
					<td>
						<input type="text" id="from_name" name="from_name" class="regular-text"
							value="<?php echo esc_attr( $settings['from_name'] ); ?>">
						<p class="description"><?php esc_html_e( 'Name that appears in the "From" field of all emails.', 'meet-with-me' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="from_email"><?php esc_html_e( 'From Email', 'meet-with-me' ); ?></label></th>
					<td>
						<input type="email" id="from_email" name="from_email" class="regular-text"
							value="<?php echo esc_attr( $settings['from_email'] ); ?>">
					</td>
				</tr>
			</table>
		</div>

		<!-- Variable reference (collapsible) -->
		<details class="mwm-card mwm-vars-card">
			<summary><strong><?php esc_html_e( 'Available Template Variables', 'meet-with-me' ); ?></strong></summary>
			<div class="mwm-vars-grid">
				<?php foreach ( $vars as $token => $desc ) : ?>
					<code class="mwm-var-token" title="<?php echo esc_attr( $desc ); ?>"><?php echo esc_html( $token ); ?></code>
					<span class="mwm-var-desc"><?php echo esc_html( $desc ); ?></span>
				<?php endforeach; ?>
			</div>
		</details>

		<!-- Confirmation email -->
		<div class="mwm-card">
			<h2><?php esc_html_e( 'Booking Confirmation', 'meet-with-me' ); ?></h2>
			<p class="description" style="margin-bottom:16px;">
				<?php esc_html_e( 'Sent to the booker immediately after they book. Includes a .ics calendar invite attachment.', 'meet-with-me' ); ?>
			</p>
			<table class="form-table" role="presentation">
				<tr>
					<th><label for="confirmation_subject"><?php esc_html_e( 'Subject', 'meet-with-me' ); ?></label></th>
					<td><input type="text" id="confirmation_subject" name="confirmation_subject" class="large-text"
							value="<?php echo esc_attr( $settings['confirmation_subject'] ); ?>"></td>
				</tr>
				<tr>
					<th><label for="confirmation_body"><?php esc_html_e( 'Body', 'meet-with-me' ); ?></label></th>
					<td><textarea id="confirmation_body" name="confirmation_body" class="large-text mwm-email-body"
							rows="10"><?php echo esc_textarea( $settings['confirmation_body'] ); ?></textarea></td>
				</tr>
			</table>
		</div>

		<!-- Admin notification -->
		<div class="mwm-card">
			<h2><?php esc_html_e( 'Admin Notification', 'meet-with-me' ); ?></h2>
			<p class="description" style="margin-bottom:16px;">
				<?php esc_html_e( 'Sent to you whenever someone books a meeting.', 'meet-with-me' ); ?>
			</p>
			<table class="form-table" role="presentation">
				<tr>
					<th><label for="admin_subject"><?php esc_html_e( 'Subject', 'meet-with-me' ); ?></label></th>
					<td><input type="text" id="admin_subject" name="admin_subject" class="large-text"
							value="<?php echo esc_attr( $settings['admin_subject'] ); ?>"></td>
				</tr>
				<tr>
					<th><label for="admin_body"><?php esc_html_e( 'Body', 'meet-with-me' ); ?></label></th>
					<td><textarea id="admin_body" name="admin_body" class="large-text mwm-email-body"
							rows="10"><?php echo esc_textarea( $settings['admin_body'] ); ?></textarea></td>
				</tr>
			</table>
		</div>

		<!-- Cancellation email -->
		<div class="mwm-card">
			<h2><?php esc_html_e( 'Cancellation Notice', 'meet-with-me' ); ?></h2>
			<p class="description" style="margin-bottom:16px;">
				<?php esc_html_e( 'Sent to the booker when a booking is cancelled (by you or by them).', 'meet-with-me' ); ?>
			</p>
			<table class="form-table" role="presentation">
				<tr>
					<th><label for="cancellation_subject"><?php esc_html_e( 'Subject', 'meet-with-me' ); ?></label></th>
					<td><input type="text" id="cancellation_subject" name="cancellation_subject" class="large-text"
							value="<?php echo esc_attr( $settings['cancellation_subject'] ); ?>"></td>
				</tr>
				<tr>
					<th><label for="cancellation_body"><?php esc_html_e( 'Body', 'meet-with-me' ); ?></label></th>
					<td><textarea id="cancellation_body" name="cancellation_body" class="large-text mwm-email-body"
							rows="10"><?php echo esc_textarea( $settings['cancellation_body'] ); ?></textarea></td>
				</tr>
			</table>
		</div>

		<!-- Reschedule confirmation email -->
		<div class="mwm-card">
			<h2><?php esc_html_e( 'Reschedule Confirmation', 'meet-with-me' ); ?></h2>
			<p class="description" style="margin-bottom:16px;">
				<?php esc_html_e( 'Sent to the booker when they reschedule. Includes an updated .ics calendar invite attachment.', 'meet-with-me' ); ?>
			</p>
			<table class="form-table" role="presentation">
				<tr>
					<th><label for="reschedule_subject"><?php esc_html_e( 'Subject', 'meet-with-me' ); ?></label></th>
					<td><input type="text" id="reschedule_subject" name="reschedule_subject" class="large-text"
							value="<?php echo esc_attr( $settings['reschedule_subject'] ?? '' ); ?>"></td>
				</tr>
				<tr>
					<th><label for="reschedule_body"><?php esc_html_e( 'Body', 'meet-with-me' ); ?></label></th>
					<td><textarea id="reschedule_body" name="reschedule_body" class="large-text mwm-email-body"
							rows="10"><?php echo esc_textarea( $settings['reschedule_body'] ?? '' ); ?></textarea></td>
				</tr>
			</table>
		</div>

		<div class="mwm-email-actions">
			<button type="submit" name="mwm_save_email" class="button button-primary button-large">
				<?php esc_html_e( 'Save Email Settings', 'meet-with-me' ); ?>
			</button>
			<button type="submit" name="mwm_test_email" class="button button-large"
				onclick="return confirm('<?php esc_attr_e( 'Send a test notification to your admin email?', 'meet-with-me' ); ?>')">
				<?php esc_html_e( 'Send Test Email', 'meet-with-me' ); ?>
			</button>
		</div>

	</form>
</div>
