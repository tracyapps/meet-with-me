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

	<form method="post" action="">
		<?php wp_nonce_field( 'mwm_settings_general' ); ?>

		<table class="form-table" role="presentation">

			<tr>
				<th scope="row">
					<label for="admin_name"><?php esc_html_e( 'Your Name', 'meet-with-me' ); ?></label>
				</th>
				<td>
					<input type="text" id="admin_name" name="admin_name" class="regular-text"
						value="<?php echo esc_attr( $settings['admin_name'] ); ?>">
					<p class="description"><?php esc_html_e( 'Used in email sign-offs sent to people who book with you.', 'meet-with-me' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="admin_email"><?php esc_html_e( 'Notification Email', 'meet-with-me' ); ?></label>
				</th>
				<td>
					<input type="email" id="admin_email" name="admin_email" class="regular-text"
						value="<?php echo esc_attr( $settings['admin_email'] ); ?>">
					<p class="description"><?php esc_html_e( 'Where new booking notifications are sent.', 'meet-with-me' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="timezone"><?php esc_html_e( 'Your Timezone', 'meet-with-me' ); ?></label>
				</th>
				<td>
					<select id="timezone" name="timezone">
						<?php foreach ( $timezones as $tz ) : ?>
							<option value="<?php echo esc_attr( $tz ); ?>"
								<?php selected( $settings['timezone'], $tz ); ?>>
								<?php echo esc_html( $tz ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'All availability windows are set in this timezone. Bookers see times converted to their own timezone automatically.', 'meet-with-me' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="min_notice_hours"><?php esc_html_e( 'Minimum Notice Required', 'meet-with-me' ); ?></label>
				</th>
				<td>
					<input type="number" id="min_notice_hours" name="min_notice_hours"
						value="<?php echo esc_attr( $settings['min_notice_hours'] ); ?>"
						min="0" max="720" class="small-text"> <?php esc_html_e( 'hours', 'meet-with-me' ); ?>
					<p class="description"><?php esc_html_e( 'Minimum amount of notice before a meeting. For example, 24 means no one can book anything happening within the next 24 hours.', 'meet-with-me' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="max_advance_days"><?php esc_html_e( 'Maximum Advance Booking', 'meet-with-me' ); ?></label>
				</th>
				<td>
					<input type="number" id="max_advance_days" name="max_advance_days"
						value="<?php echo esc_attr( $settings['max_advance_days'] ); ?>"
						min="1" max="730" class="small-text"> <?php esc_html_e( 'days in advance', 'meet-with-me' ); ?>
					<p class="description"><?php esc_html_e( 'How far into the future people can book. 60 days means the calendar shows up to 60 days from today.', 'meet-with-me' ); ?></p>
				</td>
			</tr>

		</table>

		<p class="submit">
			<button type="submit" name="mwm_save_general" class="button button-primary">
				<?php esc_html_e( 'Save Settings', 'meet-with-me' ); ?>
			</button>
		</p>

	</form>
</div>
