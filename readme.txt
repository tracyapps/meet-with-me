=== Meet With Me ===
Contributors: tracyapps
Tags: booking, appointments, scheduling, calendar, zoom
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.3.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Create meeting types, set availability, and accept appointment bookings directly on your WordPress site.

== Description ==

Meet With Me stores bookings in your WordPress database. Google Calendar and Zoom are optional connections; enabling them shares the meeting details described below with those services.

**Features**

* Unlimited meeting types with per-type duration, buffers, and per-day/week limits
* Custom booking questions (text, paragraph, dropdown, radio, checkboxes)
* Weekly availability, date-specific overrides, and blocked days
* Timezone-aware: availability is defined in your timezone; bookers see theirs
* Booking wizard you can embed anywhere: `[mwm_cards]`, `[mwm_button]`, `[mwm_booking_form]`
* Gutenberg blocks with live previews: Booking Form, Booking Button, and Meeting Type Cards (own “Meet With Me” category)
* Bookers manage their own bookings via a private cancel/reschedule link
* Email notifications (confirmation, admin, cancellation, reschedule) with `.ics` calendar invites
* Optional Google Calendar integration: conflict checking, event write-back, Google Meet links
* Optional Zoom integration: Server-to-Server OAuth, automatic meeting creation
* Style settings: accent color, button style, corner radius, density, surfaces, custom CSS
* Translation-ready (text domain: `meet-with-me`), privacy-policy tooling included

== External Services ==

The plugin makes no Google, Zoom, or relay request until an administrator configures or starts that connection. WordPress sends booking email through your site's mail server or configured SMTP provider.

= Google Calendar and Google Meet =

After an administrator connects a Google account, the plugin exchanges OAuth credentials and tokens with Google, loads calendar names/IDs, and sends selected calendar IDs and date/time ranges to check busy periods. With calendar write-back enabled, booking creation, cancellation, and rescheduling create or delete events. Event creation sends the meeting title, host/booker names and email addresses, meeting time, booking notes, and attendee join link; Google can send invitations to those attendees. Host-only meeting start links are excluded from attendee calendar descriptions.

Service: https://developers.google.com/calendar/api
Privacy: https://policies.google.com/privacy
Terms: https://policies.google.com/terms

= Zoom =

After an administrator configures Zoom Server-to-Server OAuth, testing the connection or creating a Zoom meeting requests an access token using the configured account ID, client ID, and client secret. Creating a booking with Zoom selected sends its title (including the booker's name), notes, start time, and duration to create a meeting. Cancellation and rescheduling can delete the associated meeting. Zoom returns participant/host links and meeting details, which are stored in the site's database.

Service: https://developers.zoom.us/docs/api/
Privacy: https://www.zoom.com/en/trust/privacy/privacy-statement/
Terms: https://www.zoom.com/en/trust/terms/

= Optional plugins.tapps.design Google connection relay =

The one-click connection uses the plugin's shared OAuth client by default (an administrator can still connect their own Google project instead). When an administrator chooses it, https://plugins.tapps.design/connect handles the site's callback URL, temporary connection ticket, Google's authorization code, and OAuth connection tokens/credentials during the handoff. The WordPress site then communicates directly with Google for calendar operations; booking details are not routed through the relay. The advanced own-Google-project flow bypasses this relay.

Relay privacy and software license/warranty terms: https://plugins.tapps.design/privacy/

== Installation ==

1. Upload the `meet-with-me` folder to `/wp-content/plugins/`, or install the zip via Plugins → Add New → Upload Plugin.
2. Activate the plugin through the Plugins screen.
3. Go to **Meet With Me → Settings → General** and set your name, notification email, and timezone.
4. Review your hours under **Settings → Availability**.
5. Create a meeting type under **Meet With Me → Meeting Types**.
6. Embed a booking block or shortcode (`[mwm_cards]`, `[mwm_button]`, `[mwm_booking_form]`) on any page.

Need a walkthrough? See **Settings → Help & Setup** for shortcode reference, Google Calendar and Zoom guides, and troubleshooting.

== Frequently Asked Questions ==

= Who can be booked? =

One host per site — you. Meeting types, availability, and notifications all belong to the site owner.

= Should I use the blocks or the shortcodes? =

Either. Under the “Meet With Me” category in the block editor you will find Booking Form, Booking Button, and Meeting Type Cards blocks. Each renders exactly the same booking UI as its shortcode counterpart (`[mwm_booking_form]`, `[mwm_button]`, `[mwm_cards]`), with a live preview and settings in the block sidebar. Shortcodes remain fully supported.

= Do I need Google Calendar or Zoom? =

No. Both integrations are optional. Without them, bookings are still recorded in your dashboard and confirmed by email. Connect them to block busy times and create online meeting links automatically.

= Are confirmation emails guaranteed to arrive? =

WordPress sends email through your server, which some hosts restrict. Use an SMTP plugin if messages do not arrive, and use the "Send Test Email" button on the Email Templates tab to verify.

= How do bookers cancel or reschedule? =

Every confirmation email contains a private manage link. Bookers can cancel or pick a new time themselves; rescheduling inside your minimum-notice window is blocked automatically.

= What happens to my data if I delete the plugin? =

Uninstalling removes all plugin options, transients, and booking data — including on every site of a multisite network.

== Changelog ==

= 0.3.2 =
* Fixed: theme bullet markers and indents leaking into the booking wizard (time-slot list, any list inside plugin containers) — a scoped CSS guard now strips list styling from page-builder/theme `li` rules.
* UI: roomier clickable targets — time-slot buttons, calendar day cells, month nav buttons, primary/secondary/danger buttons, and the modal close button all gained padding.

= 0.3.1 =
* Fixed: the one-click Google connection now works out of the box on every install — the shared connection client ID ships with the plugin (0.3.0 showed "One-click connection is not configured on this site yet" until a filter or setting supplied it).
* Fixed: the Help & Setup settings tab renders its own page instead of falling back to General.

= 0.3.0 =
* Booking safety: host-wide locking, transactional reschedules, guarded token/status transitions and checked database failures.
* Availability: strict dates and request types, combined meeting buffers, fresh Google verification, and token-protected reschedule availability that excludes the current booking from limits.
* Privacy: host-only meeting links excluded from attendee calendar descriptions; private manage-page headers and external-service disclosures.
* Gutenberg blocks: Booking Form, Booking Button, and Meeting Type Cards (server-side rendered with live editor previews, no build step). Booking styles/scripts and the modal now load only on pages that actually contain booking UI; shortcodes are unchanged.
* Accessibility: modal focus management, focus trap, and background inert; live-region announcements for loading, errors, and results; per-instance element IDs; full-date calendar labels; enlarged close button target.
* Security: constant-time token comparison; transient-based booking rate limiting; duplicate-slot insert protection with a unique index; secrets are no longer echoed back into the admin UI (blank keeps, "Replace secret" updates).
* Style settings: new Settings → Style tab with accent colors, button style/radius, surface mode, density, max width, and scoped custom CSS. Public stylesheet now uses CSS custom properties.
* i18n: all front-end and admin JavaScript strings are translatable; PHP strings centralized and newly translatable; regenerated POT file.
* Admin UX: contextual help tabs, new Help & Setup tab (shortcode reference, Google/Zoom guides, FAQ), Google Client ID validation, Zoom "Test Connection", Zoom failure notices, privacy policy suggestion.
* Fixes: "Refresh My Calendars" nonce mismatch, missing sortable dependency, `isset()` guard on booking limits, `wp_date()` usage, email `lang` attribute, uninstall completeness (multisite loop).
* Release: readme.txt, CHANGELOG.md, phpcs.xml.dist, PHPUnit test scaffold.

= 0.1.0 =
* Initial release.
