<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$new_url = add_query_arg(
	array(
		'page'   => 'mwm-event-types',
		'action' => 'new',
	),
	admin_url( 'admin.php' )
);
?>
<div class="wrap mwm-wrap">

	<h1 class="wp-heading-inline"><?php esc_html_e( 'Meeting Types', 'meet-with-me' ); ?></h1>
	<a href="<?php echo esc_url( $new_url ); ?>" class="page-title-action">
		<?php esc_html_e( 'Add New', 'meet-with-me' ); ?>
	</a>

	<hr class="wp-header-end">

	<?php if ( $notice ) : ?>
		<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible">
			<p><?php echo esc_html( $notice['message'] ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( empty( $event_types ) ) : ?>

		<div class="mwm-empty-state">
			<p><?php esc_html_e( "You haven't created any meeting types yet.", 'meet-with-me' ); ?></p>
			<a href="<?php echo esc_url( $new_url ); ?>" class="button button-primary">
				<?php esc_html_e( 'Create Your First Meeting Type', 'meet-with-me' ); ?>
			</a>
		</div>

	<?php else : ?>

		<table class="wp-list-table widefat fixed striped mwm-event-types-table">
			<thead>
				<tr>
					<th style="width:40px;"></th>
					<th><?php esc_html_e( 'Name', 'meet-with-me' ); ?></th>
					<th style="width:120px;"><?php esc_html_e( 'Duration', 'meet-with-me' ); ?></th>
					<th style="width:130px;"><?php esc_html_e( 'Meeting Format', 'meet-with-me' ); ?></th>
					<th style="width:90px;"><?php esc_html_e( 'Max / Day', 'meet-with-me' ); ?></th>
					<th style="width:90px;"><?php esc_html_e( 'Max / Week', 'meet-with-me' ); ?></th>
					<th style="width:80px;"><?php esc_html_e( 'Status', 'meet-with-me' ); ?></th>
					<th style="width:130px;"><?php esc_html_e( 'Actions', 'meet-with-me' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				foreach ( $event_types as $et ) :
					$edit_url   = add_query_arg(
						array(
							'page'   => 'mwm-event-types',
							'action' => 'edit',
							'id'     => $et['id'],
						),
						admin_url( 'admin.php' )
					);
					$delete_url = wp_nonce_url(
						add_query_arg(
							array(
								'page'   => 'mwm-event-types',
								'action' => 'delete',
								'id'     => $et['id'],
							),
							admin_url( 'admin.php' )
						),
						'mwm_delete_event_type_' . $et['id']
					);

					$duration_label = MWM_Admin_Event_Types::get_durations()[ $et['duration_minutes'] ]
						?? $et['duration_minutes'] . ' ' . __( 'min', 'meet-with-me' );

					$format_labels = array(
						'online'    => __( 'Online', 'meet-with-me' ),
						'in_person' => __( 'In person', 'meet-with-me' ),
						'both'      => __( 'Both', 'meet-with-me' ),
					);
					?>
				<tr>
					<td>
						<span class="mwm-color-dot" style="background:<?php echo esc_attr( $et['color'] ); ?>;"></span>
					</td>
					<td>
						<strong><a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $et['name'] ); ?></a></strong>
						<?php if ( $et['description'] ) : ?>
							<br><span class="description"><?php echo esc_html( wp_trim_words( $et['description'], 12 ) ); ?></span>
						<?php endif; ?>
						<br><code class="mwm-slug"><?php echo esc_html( $et['slug'] ); ?></code>
					</td>
					<td><?php echo esc_html( $duration_label ); ?></td>
					<td><?php echo esc_html( $format_labels[ $et['meeting_type'] ] ?? $et['meeting_type'] ); ?></td>
					<td><?php echo $et['max_per_day'] !== null ? esc_html( $et['max_per_day'] ) : '&mdash;'; ?></td>
					<td><?php echo $et['max_per_week'] !== null ? esc_html( $et['max_per_week'] ) : '&mdash;'; ?></td>
					<td>
						<?php if ( $et['is_active'] ) : ?>
							<span class="mwm-badge mwm-badge--active"><?php esc_html_e( 'Active', 'meet-with-me' ); ?></span>
						<?php else : ?>
							<span class="mwm-badge mwm-badge--inactive"><?php esc_html_e( 'Inactive', 'meet-with-me' ); ?></span>
						<?php endif; ?>
					</td>
					<td>
						<a href="<?php echo esc_url( $edit_url ); ?>" class="button button-small">
							<?php esc_html_e( 'Edit', 'meet-with-me' ); ?>
						</a>
						<a href="<?php echo esc_url( $delete_url ); ?>"
							class="button button-small mwm-delete-btn"
							data-confirm="<?php esc_attr_e( 'Delete this meeting type? This cannot be undone.', 'meet-with-me' ); ?>">
							<?php esc_html_e( 'Delete', 'meet-with-me' ); ?>
						</a>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

	<?php endif; ?>

</div>
