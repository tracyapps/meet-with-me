<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// $slug and $accent are set by the shortcode caller.
$slug   = $slug ?? '';
$accent = $accent ?? '';
?>
<div class="mwm-booking-wizard" data-event-type="<?php echo esc_attr( $slug ); ?>"<?php echo $accent !== '' ? ' style="--mwm-accent:' . esc_attr( $accent ) . ';"' : ''; ?>>
	<div class="mwm-booking-wizard__inner"></div>
</div>
