<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// Variables available: $et (event type array), $show_description (bool)
?>
<div class="mwm-card" style="border-top: 3px solid <?php echo esc_attr( $et['color'] ); ?>">
	<h3 class="mwm-card__name"><?php echo esc_html( $et['name'] ); ?></h3>
	<p class="mwm-card__meta">
		<?php echo esc_html( $et['duration_minutes'] ); ?> <?php esc_html_e( 'min', 'meet-with-me' ); ?>
		<?php if ( $et['meeting_type'] !== 'both' ) : ?>
			&middot; <?php echo esc_html( MWM_Booking::format_label( (string) $et['meeting_type'] ) ); ?>
		<?php endif; ?>
	</p>
	<?php if ( $show_description && $et['description'] ) : ?>
		<p class="mwm-card__description"><?php echo esc_html( $et['description'] ); ?></p>
	<?php endif; ?>
	<div class="mwm-card__cta">
		<button class="mwm-booking-button mwm-button" data-event-type="<?php echo esc_attr( $et['slug'] ); ?>" aria-haspopup="dialog">
			<?php esc_html_e( 'Book Now', 'meet-with-me' ); ?>
		</button>
	</div>
</div>
