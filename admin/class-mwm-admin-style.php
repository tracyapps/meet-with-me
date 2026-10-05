<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin controller for the Style (appearance) settings tab.
 */
class MWM_Admin_Style {

	public function dispatch(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'meet-with-me' ) );
		}

		$this->render();
	}

	/**
	 * Run write actions on the load-{page} hook, before WordPress renders the
	 * admin header — redirects and JSON responses go out header-clean.
	 */
	public function handle_actions(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'meet-with-me' ) );
		}

		if ( isset( $_POST['mwm_save_style'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified inside the handler
			$this->handle_save();
		}
	}

	private function handle_save(): void {
		check_admin_referer( 'mwm_style_settings' );

		$button_styles = array( 'outline', 'filled', 'link' );
		$surface_modes = array( 'theme', 'light', 'dark' );
		$densities     = array( 'comfortable', 'compact' );

		$button_style = sanitize_key( wp_unslash( $_POST['button_style'] ?? 'outline' ) );
		$surface_mode = sanitize_key( wp_unslash( $_POST['surface_mode'] ?? 'theme' ) );
		$density      = sanitize_key( wp_unslash( $_POST['density'] ?? 'comfortable' ) );

		MWM_Settings::set_group(
			array(
				'accent_color'      => sanitize_hex_color( wp_unslash( $_POST['accent_color'] ?? '' ) ) ?: '',
				'accent_text_color' => sanitize_hex_color( wp_unslash( $_POST['accent_text_color'] ?? '' ) ) ?: '',
				'button_style'      => in_array( $button_style, $button_styles, true ) ? $button_style : 'outline',
				'button_radius'     => $this->sanitize_radius( wp_unslash( $_POST['button_radius'] ?? '' ) ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- plugin sanitizer
				'surface_mode'      => in_array( $surface_mode, $surface_modes, true ) ? $surface_mode : 'theme',
				'density'           => in_array( $density, $densities, true ) ? $density : 'comfortable',
				'max_width'         => max( 320, min( 1200, absint( wp_unslash( $_POST['max_width'] ?? 560 ) ) ) ),
				'custom_css'        => $this->sanitize_custom_css( wp_unslash( $_POST['custom_css'] ?? '' ) ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- plugin sanitizer
			),
			'style'
		);

		MWM_Settings::flush_cache( 'style' );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'         => 'mwm-settings',
					'tab'          => 'style',
					'style_status' => 'saved',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Allowed radius values: '' (default), '0'–'24', or 'pill'.
	 */
	private function sanitize_radius( mixed $value ): string {
		$value = is_string( $value ) ? sanitize_key( $value ) : '';

		if ( $value === 'pill' ) {
			return 'pill';
		}

		if ( $value !== '' && preg_match( '/^(?:[0-9]|1[0-9]|2[0-4])$/', $value ) ) {
			return (string) (int) $value;
		}

		return '';
	}

	/**
	 * Light sanitisation of admin-provided CSS. Output only rides along with
	 * this plugin's stylesheet (see MWM_Public::build_style_css()).
	 */
	private function sanitize_custom_css( mixed $css ): string {
		$css = wp_strip_all_tags( wp_unslash( (string) $css ) );
		$css = preg_replace( '/@import\s+[^;]+;?/i', '', $css );
		$css = preg_replace( '/expression\s*\(/i', '', $css );
		$css = preg_replace( '/javascript\s*:/i', '', $css );
		$css = preg_replace( '/behavior\s*:/i', '', $css );

		return trim( mb_substr( $css, 0, 20000 ) );
	}

	private function render(): void {
		$current_tab = 'style';
		$notice      = $this->get_notice();
		$settings    = MWM_Settings::get_all( 'style' );
		require MWM_PLUGIN_DIR . 'admin/views/settings/style.php';
	}

	private function get_notice(): ?array {
		$status = sanitize_key( wp_unslash( $_GET['style_status'] ?? '' ) );
		if ( ! $status ) {
			return null;
		}
		return match ( $status ) {
			'saved' => array(
				'type'    => 'success',
				'message' => __( 'Style settings saved.', 'meet-with-me' ),
			),
			default => null,
		};
	}
}
