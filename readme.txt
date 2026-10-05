=== Meet With Me ===
Contributors: tracyapps
Tags: booking, appointments, scheduling, calendar, zoom, google-calendar
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A flexible appointment booking plugin. Create meeting types, set your availability, and let people book time with you — directly from your WordPress site.

== Description ==

Meet With Me does appointment scheduling the WordPress way: no external service required, your data stays in your own site.

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

Every confirmation email contains a private manage link. Bookers can cancel or pick a new time themselves; changes inside your minimum-notice window are blocked automatically.

= What happens to my data if I delete the plugin? =

Uninstalling removes all plugin options, transients, and booking data — including on every site of a multisite network.

== Changelog ==

= 0.3.0 =
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
