<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$general_url = add_query_arg(
	array(
		'page' => 'mwm-settings',
		'tab'  => 'general',
	),
	admin_url( 'admin.php' )
);
$avail_url   = add_query_arg(
	array(
		'page' => 'mwm-settings',
		'tab'  => 'availability',
	),
	admin_url( 'admin.php' )
);
$types_url   = add_query_arg( array( 'page' => 'mwm-event-types' ), admin_url( 'admin.php' ) );
$google_url  = add_query_arg(
	array(
		'page' => 'mwm-settings',
		'tab'  => 'google',
	),
	admin_url( 'admin.php' )
);
$zoom_url    = add_query_arg(
	array(
		'page' => 'mwm-settings',
		'tab'  => 'meetings',
	),
	admin_url( 'admin.php' )
);
$style_url   = add_query_arg(
	array(
		'page' => 'mwm-settings',
		'tab'  => 'style',
	),
	admin_url( 'admin.php' )
);
?>
<div class="wrap mwm-settings-wrap">

	<h1><?php esc_html_e( 'Meet With Me — Settings', 'meet-with-me' ); ?></h1>

	<?php mwm_admin_tabs( $current_tab ); ?>

	<!-- ===== QUICK START ===== -->
	<div class="mwm-card" id="mwm-help-quickstart">
		<h2><?php esc_html_e( 'Quick Start', 'meet-with-me' ); ?></h2>
		<ol class="mwm-help-steps">
			<li>
				<?php
				printf(
					/* translators: %s = link to the General settings tab */
					esc_html__( 'Set your name, notification email, and timezone under %s, then tune your weekly hours under Default Availability.', 'meet-with-me' ),
					'<a href="' . esc_url( $general_url ) . '">' . esc_html__( 'General', 'meet-with-me' ) . '</a>'
				);
				?>
			</li>
			<li>
				<?php
				printf(
					/* translators: %s = link to the Meeting Types screen */
					esc_html__( 'Create at least one %s — for example “30 min intro call”. Each type has its own duration, format, and booking rules.', 'meet-with-me' ),
					'<a href="' . esc_url( $types_url ) . '">' . esc_html__( 'Meeting Type', 'meet-with-me' ) . '</a>'
				);
				?>
			</li>
			<li><?php esc_html_e( 'Embed the booking UI on any page or post — use a Meet With Me block or a shortcode. See the reference below.', 'meet-with-me' ); ?></li>
		</ol>
		<p class="description"><?php esc_html_e( 'Optional extras: connect Google Calendar for conflict checking and Meet links, connect Zoom for Zoom meetings, and adjust colors under Style.', 'meet-with-me' ); ?></p>
	</div>

	<!-- ===== BLOCKS ===== -->
	<div class="mwm-card" id="mwm-help-blocks">
		<h2><?php esc_html_e( 'Booking Blocks (Gutenberg)', 'meet-with-me' ); ?></h2>
		<p class="description" style="margin-bottom:12px;">
			<?php esc_html_e( 'Find three blocks under the “Meet With Me” category in the block editor. They render the same booking UI as the shortcodes below — use whichever fits your workflow.', 'meet-with-me' ); ?>
		</p>
		<table class="widefat striped mwm-help-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Block', 'meet-with-me' ); ?></th>
					<th><?php esc_html_e( 'Equivalent shortcode', 'meet-with-me' ); ?></th>
					<th><?php esc_html_e( 'Settings', 'meet-with-me' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td><?php esc_html_e( 'Booking Form', 'meet-with-me' ); ?></td>
					<td><code>[mwm_booking_form]</code></td>
					<td><?php esc_html_e( 'Meeting type (optional), accent color override.', 'meet-with-me' ); ?></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Booking Button', 'meet-with-me' ); ?></td>
					<td><code>[mwm_button]</code></td>
					<td><?php esc_html_e( 'Button text, meeting type, accent color override.', 'meet-with-me' ); ?></td>
				</tr>
				<tr>
					<td><?php esc_html_e( 'Meeting Type Cards', 'meet-with-me' ); ?></td>
					<td><code>[mwm_cards]</code></td>
					<td><?php esc_html_e( 'Meeting types, columns (1–6), show descriptions, accent color override.', 'meet-with-me' ); ?></td>
				</tr>
			</tbody>
		</table>
		<p class="description"><?php esc_html_e( 'The blocks show a live preview in the editor; settings live in the block sidebar. If no meeting types exist yet, a notice links you to the Meeting Types screen. Pages with booking UI load the booking styles and scripts automatically — pages without it do not.', 'meet-with-me' ); ?></p>
	</div>

	<!-- ===== SHORTCODES ===== -->
	<div class="mwm-card" id="mwm-help-shortcodes">
		<h2><?php esc_html_e( 'Shortcode Reference', 'meet-with-me' ); ?></h2>
		<p class="description" style="margin-bottom:12px;">
			<?php esc_html_e( 'Each shortcode mirrors a block above. Shortcodes are handy for widgets, legacy content, and page builders.', 'meet-with-me' ); ?>
		</p>

		<h3><code>[mwm_cards]</code></h3>
		<p class="description"><?php esc_html_e( 'A grid of all your active meeting types, each with a “Book Now” button that opens the booking wizard.', 'meet-with-me' ); ?></p>
		<table class="widefat striped mwm-help-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Attribute', 'meet-with-me' ); ?></th>
					<th><?php esc_html_e( 'Default', 'meet-with-me' ); ?></th>
					<th><?php esc_html_e( 'Description', 'meet-with-me' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr><td><code>event_types</code></td><td><?php esc_html_e( 'all active', 'meet-with-me' ); ?></td><td><?php esc_html_e( 'Comma-separated slugs to show only certain meeting types.', 'meet-with-me' ); ?></td></tr>
				<tr><td><code>columns</code></td><td><code>3</code></td><td><?php esc_html_e( 'Grid columns (1–6).', 'meet-with-me' ); ?></td></tr>
				<tr><td><code>show_description</code></td><td><code>true</code></td><td><?php esc_html_e( 'Set to false to hide the description text.', 'meet-with-me' ); ?></td></tr>
				<tr><td><code>accent</code></td><td><?php esc_html_e( '(style setting)', 'meet-with-me' ); ?></td><td><?php esc_html_e( 'Optional hex color to override the accent for this instance only, e.g. accent="#2563eb".', 'meet-with-me' ); ?></td></tr>
			</tbody>
		</table>

		<h3><code>[mwm_button]</code></h3>
		<p class="description"><?php esc_html_e( 'A single button that opens the booking wizard in a modal. Great for headers and sidebars.', 'meet-with-me' ); ?></p>
		<table class="widefat striped mwm-help-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Attribute', 'meet-with-me' ); ?></th>
					<th><?php esc_html_e( 'Default', 'meet-with-me' ); ?></th>
					<th><?php esc_html_e( 'Description', 'meet-with-me' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr><td><code>event_type</code></td><td><?php esc_html_e( '(required)', 'meet-with-me' ); ?></td><td><?php esc_html_e( 'Slug of the meeting type to book.', 'meet-with-me' ); ?></td></tr>
				<tr><td><code>label</code></td><td><code>Book a time</code></td><td><?php esc_html_e( 'Button text.', 'meet-with-me' ); ?></td></tr>
				<tr><td><code>class</code></td><td><?php esc_html_e( '(empty)', 'meet-with-me' ); ?></td><td><?php esc_html_e( 'Extra CSS class(es) for the button.', 'meet-with-me' ); ?></td></tr>
				<tr><td><code>accent</code></td><td><?php esc_html_e( '(style setting)', 'meet-with-me' ); ?></td><td><?php esc_html_e( 'Optional hex color to override the accent for this instance only.', 'meet-with-me' ); ?></td></tr>
			</tbody>
		</table>

		<h3><code>[mwm_booking_form]</code></h3>
		<p class="description"><?php esc_html_e( 'The full booking wizard rendered inline — ideal for a dedicated booking page.', 'meet-with-me' ); ?></p>
		<table class="widefat striped mwm-help-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Attribute', 'meet-with-me' ); ?></th>
					<th><?php esc_html_e( 'Default', 'meet-with-me' ); ?></th>
					<th><?php esc_html_e( 'Description', 'meet-with-me' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr><td><code>event_type</code></td><td><?php esc_html_e( '(optional)', 'meet-with-me' ); ?></td><td><?php esc_html_e( 'Pre-select a meeting type; omit to let the visitor choose.', 'meet-with-me' ); ?></td></tr>
				<tr><td><code>accent</code></td><td><?php esc_html_e( '(style setting)', 'meet-with-me' ); ?></td><td><?php esc_html_e( 'Optional hex color to override the accent for this instance only.', 'meet-with-me' ); ?></td></tr>
			</tbody>
		</table>
	</div>

	<!-- ===== GOOGLE ===== -->
	<div class="mwm-card" id="mwm-help-google">
		<h2><?php esc_html_e( 'Google Calendar Setup', 'meet-with-me' ); ?></h2>
		<p class="description" style="margin-bottom:12px;">
			<?php esc_html_e( 'Connect Google Calendar to check busy times before offering slots and to write confirmed bookings into a calendar. Google Meet links come along for free when you write bookings to Google Calendar.', 'meet-with-me' ); ?>
		</p>
		<ol class="mwm-help-steps">
			<li>
				<?php
				printf(
					/* translators: %s = link to Google Cloud Console */
					esc_html__( 'Create a project in the %s and enable the “Google Calendar API”.', 'meet-with-me' ),
					'<a href="https://console.cloud.google.com/" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Google Cloud Console ↗', 'meet-with-me' ) . '</a>'
				);
				?>
			</li>
			<li><?php esc_html_e( 'Create an OAuth “Web application” credential and add the plugin’s Authorised Redirect URI (shown on the Google Calendar tab) to your OAuth client.', 'meet-with-me' ); ?></li>
			<li>
				<?php
				printf(
					/* translators: %s = link to the Google Calendar settings tab */
					esc_html__( 'Paste the Client ID and Client Secret into %s and save.', 'meet-with-me' ),
					'<a href="' . esc_url( $google_url ) . '">' . esc_html__( 'Settings → Google Calendar', 'meet-with-me' ) . '</a>'
				);
				?>
			</li>
			<li><?php esc_html_e( 'Click “Connect with Google”, approve access, then pick the calendars to check for conflicts and the calendar to write bookings into.', 'meet-with-me' ); ?></li>
		</ol>
		<p class="description"><?php esc_html_e( 'If bookings stop syncing, open the Google Calendar tab — it shows your connection status and lets you reconnect. Saving new credentials automatically clears the old tokens.', 'meet-with-me' ); ?></p>
	</div>

	<!-- ===== ZOOM ===== -->
	<div class="mwm-card" id="mwm-help-zoom">
		<h2><?php esc_html_e( 'Zoom Setup', 'meet-with-me' ); ?></h2>
		<p class="description" style="margin-bottom:12px;">
			<?php esc_html_e( 'A Zoom Server-to-Server OAuth app lets the plugin create Zoom meetings on your behalf. No per-booking logins required.', 'meet-with-me' ); ?>
		</p>
		<ol class="mwm-help-steps">
			<li>
				<?php
				printf(
					/* translators: %s = link to Zoom Marketplace */
					esc_html__( 'Sign in at the %s with your Zoom account.', 'meet-with-me' ),
					'<a href="https://marketplace.zoom.us/develop/create" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Zoom Marketplace ↗', 'meet-with-me' ) . '</a>'
				);
				?>
			</li>
			<li><?php esc_html_e( 'Choose “Build App” → “Server-to-Server OAuth”. Give it a name and create it.', 'meet-with-me' ); ?></li>
			<li><?php esc_html_e( 'Copy the app’s Account ID, Client ID, and Client Secret. These are the three values the plugin needs — the Secret is like a password, store it safely.', 'meet-with-me' ); ?></li>
			<li>
				<?php
				printf(
					/* translators: %s = link to the Online Meetings settings tab */
					esc_html__( 'Activate the app in Zoom, then paste the values into %s and use “Test Connection”.', 'meet-with-me' ),
					'<a href="' . esc_url( $zoom_url ) . '">' . esc_html__( 'Settings → Online Meetings', 'meet-with-me' ) . '</a>'
				);
				?>
			</li>
		</ol>
		<p class="description"><?php esc_html_e( 'Meeting types can then use Zoom as their default provider, or route based on a custom dropdown/radio question (e.g. work vs. personal).', 'meet-with-me' ); ?></p>
	</div>

	<!-- ===== FAQ ===== -->
	<div class="mwm-card" id="mwm-help-faq">
		<h2><?php esc_html_e( 'Troubleshooting & FAQ', 'meet-with-me' ); ?></h2>

		<details class="mwm-help-faq">
			<summary><?php esc_html_e( 'Confirmation emails are not arriving', 'meet-with-me' ); ?></summary>
			<p><?php esc_html_e( 'WordPress sends email through your server, which many hosts block or mark as spam. Install an SMTP plugin, and use “Send Test Email” on the Email Templates tab to verify delivery. Also check the spam folder of the booker’s mailbox.', 'meet-with-me' ); ?></p>
		</details>

		<details class="mwm-help-faq">
			<summary><?php esc_html_e( 'Times look wrong for some bookers', 'meet-with-me' ); ?></summary>
			<p><?php esc_html_e( 'All availability is defined in the timezone set on the General tab. Bookers automatically see times converted to their own timezone. If your times look shifted, double-check the General tab timezone first.', 'meet-with-me' ); ?></p>
		</details>

		<details class="mwm-help-faq">
			<summary><?php esc_html_e( '“Refresh My Calendars” does nothing', 'meet-with-me' ); ?></summary>
			<p><?php esc_html_e( 'That button re-fetches your calendar list from Google. If it fails, make sure you are still connected on the Google Calendar tab, then try again. Reconnecting Google fixes most calendar-list problems.', 'meet-with-me' ); ?></p>
		</details>

		<details class="mwm-help-faq">
			<summary><?php esc_html_e( 'A booking has no online meeting link', 'meet-with-me' ); ?></summary>
			<p><?php esc_html_e( 'Online meeting links are created after the booking is saved. If Zoom fails, an admin notice appears here in the dashboard with the reason. For Google Meet, the booking must be written to a Google Calendar — check the write-back setting.', 'meet-with-me' ); ?></p>
		</details>

		<details class="mwm-help-faq">
			<summary><?php esc_html_e( 'How do bookers cancel or reschedule?', 'meet-with-me' ); ?></summary>
			<p><?php esc_html_e( 'Every confirmation email includes a private manage link for the booker. You can also copy that link from any booking’s detail screen. Changes within your minimum-notice window are blocked automatically.', 'meet-with-me' ); ?></p>
		</details>

		<details class="mwm-help-faq">
			<summary><?php esc_html_e( 'Can I change the colors and buttons?', 'meet-with-me' ); ?></summary>
			<p>
				<?php
				printf(
					/* translators: %s = link to the Style settings tab */
					esc_html__( 'Yes — see %s for accent color, button style, corner radius, surface mode, density, and custom CSS.', 'meet-with-me' ),
					'<a href="' . esc_url( $style_url ) . '">' . esc_html__( 'Settings → Style', 'meet-with-me' ) . '</a>'
				);
				?>
			</p>
		</details>

		<details class="mwm-help-faq">
			<summary><?php esc_html_e( 'What personal data does the plugin store?', 'meet-with-me' ); ?></summary>
			<p><?php esc_html_e( 'Bookings store the booker’s name, email, optional phone and notes, any answers to your custom questions, and the meeting time/timezone. Data is kept in your WordPress database until you delete it, and is removed completely if you uninstall the plugin. See the Privacy Policy guide under Settings → Privacy for a ready-made draft.', 'meet-with-me' ); ?></p>
		</details>
	</div>

</div>
