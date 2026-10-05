<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$radius_options = array(
	''     => __( 'Theme default', 'meet-with-me' ),
	'0'    => '0 px',
	'2'    => '2 px',
	'4'    => '4 px',
	'6'    => '6 px',
	'8'    => '8 px',
	'10'   => '10 px',
	'12'   => '12 px',
	'14'   => '14 px',
	'16'   => '16 px',
	'18'   => '18 px',
	'20'   => '20 px',
	'22'   => '22 px',
	'24'   => '24 px',
	'pill' => __( 'Pill (fully rounded)', 'meet-with-me' ),
);

// Static preview styles built from the saved values.
$preview_style = '';
if ( ! empty( $settings['accent_color'] ) ) {
	$preview_style .= '--mwm-accent:' . $settings['accent_color'] . ';';
	$preview_style .= '--mwm-accent-contrast:' . ( $settings['accent_text_color'] ?: '#ffffff' ) . ';';
}
if ( $settings['button_radius'] === 'pill' ) {
	$preview_style .= '--mwm-radius:999px;--mwm-btn-radius:999px;';
} elseif ( $settings['button_radius'] !== '' ) {
	$preview_style .= '--mwm-radius:' . (int) $settings['button_radius'] . 'px;--mwm-btn-radius:' . (int) $settings['button_radius'] . 'px;';
}
if ( $settings['density'] === 'compact' ) {
	$preview_style .= '--mwm-space:0.75;';
}
$preview_classes = 'mwm-style-preview mwm-style-preview--' . sanitize_html_class( $settings['button_style'] );
?>
<div class="wrap mwm-settings-wrap">

	<h1><?php esc_html_e( 'Meet With Me — Settings', 'meet-with-me' ); ?></h1>

	<?php mwm_admin_tabs( $current_tab ); ?>

	<?php if ( $notice ) : ?>
		<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible">
			<p><?php echo esc_html( $notice['message'] ); ?></p>
		</div>
	<?php endif; ?>

	<form method="post" action="" id="mwm-style-form">

		<div class="mwm-edit-layout">

			<!-- Main column -->
			<div class="mwm-edit-main">
				<?php wp_nonce_field( 'mwm_style_settings' ); ?>

				<div class="mwm-card">
					<h2><?php esc_html_e( 'Colors', 'meet-with-me' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th><label for="accent_color"><?php esc_html_e( 'Accent Color', 'meet-with-me' ); ?></label></th>
							<td>
								<input type="text" id="accent_color" name="accent_color" class="mwm-color-input"
									value="<?php echo esc_attr( $settings['accent_color'] ); ?>"
									data-default-color="#3b82f6">
								<p class="description"><?php esc_html_e( 'Used for filled buttons, progress fills, and selected states. Leave empty to inherit your theme’s text color.', 'meet-with-me' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><label for="accent_text_color"><?php esc_html_e( 'Accent Text Color', 'meet-with-me' ); ?></label></th>
							<td>
								<input type="text" id="accent_text_color" name="accent_text_color" class="mwm-color-input"
									value="<?php echo esc_attr( $settings['accent_text_color'] ); ?>"
									data-default-color="#ffffff">
								<p class="description"><?php esc_html_e( 'Text color used on top of the accent color (filled buttons). Leave empty to use white automatically.', 'meet-with-me' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<div class="mwm-card">
					<h2><?php esc_html_e( 'Buttons & Layout', 'meet-with-me' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th><label for="button_style"><?php esc_html_e( 'Button Style', 'meet-with-me' ); ?></label></th>
							<td>
								<select id="button_style" name="button_style">
									<option value="outline" <?php selected( $settings['button_style'], 'outline' ); ?>><?php esc_html_e( 'Outline (theme default)', 'meet-with-me' ); ?></option>
									<option value="filled" <?php selected( $settings['button_style'], 'filled' ); ?>><?php esc_html_e( 'Filled with accent color', 'meet-with-me' ); ?></option>
									<option value="link" <?php selected( $settings['button_style'], 'link' ); ?>><?php esc_html_e( 'Text links', 'meet-with-me' ); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th><label for="button_radius"><?php esc_html_e( 'Corner Radius', 'meet-with-me' ); ?></label></th>
							<td>
								<select id="button_radius" name="button_radius">
									<?php foreach ( $radius_options as $value => $label ) : ?>
										<option value="<?php echo esc_attr( $value ); ?>" <?php selected( (string) $settings['button_radius'], (string) $value ); ?>>
											<?php echo esc_html( $label ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'Rounded corners for buttons and form fields.', 'meet-with-me' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><label for="surface_mode"><?php esc_html_e( 'Surfaces', 'meet-with-me' ); ?></label></th>
							<td>
								<select id="surface_mode" name="surface_mode">
									<option value="theme" <?php selected( $settings['surface_mode'], 'theme' ); ?>><?php esc_html_e( 'Adapt to the visitor’s system (recommended)', 'meet-with-me' ); ?></option>
									<option value="light" <?php selected( $settings['surface_mode'], 'light' ); ?>><?php esc_html_e( 'Always light', 'meet-with-me' ); ?></option>
									<option value="dark" <?php selected( $settings['surface_mode'], 'dark' ); ?>><?php esc_html_e( 'Always dark', 'meet-with-me' ); ?></option>
								</select>
								<p class="description"><?php esc_html_e( 'Controls the modal and calendar overlay surfaces so they stay readable on light or dark themes.', 'meet-with-me' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><label for="density"><?php esc_html_e( 'Density', 'meet-with-me' ); ?></label></th>
							<td>
								<select id="density" name="density">
									<option value="comfortable" <?php selected( $settings['density'], 'comfortable' ); ?>><?php esc_html_e( 'Comfortable', 'meet-with-me' ); ?></option>
									<option value="compact" <?php selected( $settings['density'], 'compact' ); ?>><?php esc_html_e( 'Compact', 'meet-with-me' ); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th><label for="max_width"><?php esc_html_e( 'Max Width', 'meet-with-me' ); ?></label></th>
							<td>
								<input type="number" id="max_width" name="max_width"
									value="<?php echo esc_attr( $settings['max_width'] ); ?>"
									min="320" max="1200" step="10" class="small-text"> px
								<p class="description"><?php esc_html_e( 'Maximum width of the booking wizard and manage-booking page.', 'meet-with-me' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<div class="mwm-card">
					<h2><?php esc_html_e( 'Custom CSS', 'meet-with-me' ); ?></h2>
					<p class="description" style="margin-bottom:12px;">
						<?php esc_html_e( 'Extra CSS applied after the plugin stylesheet. Prefix selectors with .mwm- to keep changes scoped to Meet With Me.', 'meet-with-me' ); ?>
					</p>
					<textarea id="custom_css" name="custom_css" class="large-text code mwm-email-body" rows="8"
						placeholder=".mwm-btn-primary { letter-spacing: 0.02em; }"><?php echo esc_textarea( $settings['custom_css'] ); ?></textarea>
				</div>
			</div>

			<!-- Sidebar: preview + save -->
			<div class="mwm-edit-sidebar">

				<div class="mwm-card">
					<h2><?php esc_html_e( 'Preview', 'meet-with-me' ); ?></h2>
					<p class="description" style="margin-bottom:12px;">
						<?php esc_html_e( 'Saved settings preview. Save changes to refresh it.', 'meet-with-me' ); ?>
					</p>
					<div class="<?php echo esc_attr( $preview_classes ); ?>" style="<?php echo esc_attr( $preview_style ); ?>">
						<div class="mwm-style-preview__row">
							<span class="mwm-style-preview__btn mwm-style-preview__btn--primary"><?php esc_html_e( 'Confirm Booking', 'meet-with-me' ); ?></span>
							<span class="mwm-style-preview__btn mwm-style-preview__btn--secondary"><?php esc_html_e( 'Manage Booking', 'meet-with-me' ); ?></span>
						</div>
						<div class="mwm-style-preview__bar"><span></span></div>
						<p class="mwm-style-preview__muted"><?php esc_html_e( 'Muted helper text sample', 'meet-with-me' ); ?></p>
					</div>
				</div>

				<div class="mwm-card">
					<p class="submit" style="margin:0; padding:0;">
						<button type="submit" name="mwm_save_style" class="button button-primary button-large">
							<?php esc_html_e( 'Save Style Settings', 'meet-with-me' ); ?>
						</button>
					</p>
				</div>

			</div>

		</div>

	</form>
</div>
