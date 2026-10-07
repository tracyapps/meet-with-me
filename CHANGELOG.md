# Changelog

All notable changes to Meet With Me are documented here.

## 0.4.0 — 2026-10-06

Booking wizard UX foundation (first release of the design program):

- **Persistent selection panel** — "Your selection" card (accent border, event type + duration, weekday date, chosen time with the booker's timezone) renders in the same position on the time, details, and confirmation steps. Previously the chosen date was only the time-step heading and vanished on the details form — the exact "I can't verify my selection before submitting" problem. Step titles are now stable step names ("Choose a time", "Your details"); focus targets unchanged.
- **Stepper rework** — the old back + thin progress bar + event-name row is now a quiet top bar (small muted back link and event-type label) over a distinct three-step stepper with inline-SVG icons (calendar grid, clock, form card), accent-filled done dots with checks, an accent ring on the current step, and connecting segments. All-done state renders on the confirmation. Labels hide under 520px.
- **Confirmation actions** — new action row: Add to Google Calendar (template URL built from the booking's UTC start/end and timezone), Download .ics, Copy details (clipboard + polite announcement), and Share where `navigator.share` exists. Redundant date/event rows were dropped from the details block since the selection panel above now carries them.
- **Sparkle burst** — eight decorative four-point stars animate outward from the confirmation check mark (pure CSS, staggered, `prefers-reduced-motion` disables it).
- **ICS download endpoint** — `?mwm_ics=<cancel token>` serves the booking as a `text/calendar` attachment via `MWM_Public::ics_response()`: 64-hex token shape validated, confirmed bookings only (cancelled/rescheduled rows refuse), no-store/no-referrer/noindex headers matching the manage page. The REST confirmation payload gained `start_utc`, `end_utc`, and `ics_url`.
- **Fixed the block-editor accent picker crash** — changing the accent color on the Booking Form / Button / Cards blocks crashed the block ("This block has encountered an error and cannot be previewed") on WordPress 7.x: the hand-written editor scripts used the deprecated ColorPicker props (`disableAlpha` + object-payload `onChange`), which WP routes through a back-compat adapter that throws the moment a color changes unless the legacy `onChangeComplete` prop is present. All three blocks now use the current API (`enableAlpha: false`); `colorToHex()` still normalizes both payload shapes. Verified in a live editor: slider and hex changes commit the attribute without crashing and the ServerSideRender preview live-updates with the accent.
- **Tests** — new `MWM_ICS_Download_Test` (confirmed booking serves VCALENDAR, cancelled refuses, malformed/unknown tokens rejected, payload carries UTC times + ICS URL).
- Verified end-to-end in a browser harness against the real JS/CSS with a stubbed REST layer: all five steps, focus management, hostile-theme bullet leak contained, copy announcement, sparkle animations.

## 0.3.2 — 2026-10-06

First theme-compatibility fix from live testing:

- **Stray bullets fixed** — the time-slot grid (and any list the plugin renders) could pick up bullet markers and indents from theme/page-builder `ul`/`li` styles, because the reset only covered the list container and not the `li` children (a direct `li` rule always beats an inherited value). Added a theme-leak guard scoped to `.mwm-` containers: `list-style`/`margin`/`padding` resets plus `content: none` on `li` pseudo-elements, `!important` so theme selectors lose regardless of enqueue order.
- **Roomier click targets** — bumped padding on time-slot buttons, calendar day cells, calendar/month nav buttons, primary/secondary/danger buttons, and the modal close button (now 28×28 minimum) for easier, more confident tapping. Values will be revisited per style bundle in the upcoming booking-UI design pass.

## 0.3.1 — 2026-10-06

First bugfix release after launch:

- **Fixed one-click Google connect on fresh installs** — 0.3.0 shipped without the shared connection client ID, so every new install showed "One-click connection is not configured on this site yet" (only the dev site had it via a stored setting). The public client ID now ships in the plugin; the client secret stays on the plugins.tapps.design relay, and the `relay_client_id` setting / `mwm_google_relay_client_id` filter remain as overrides.
- **Fixed the Help & Setup settings tab** — the tab controller whitelist omitted `help`, so clicking the tab silently fell through to the General tab. The router now hands `help` to its static view as designed.

## 0.3.0 — 2026-10-06

Professionalization pass (release prep):

- **Booking safety** — serialize public booking changes across meeting types with a host-wide MySQL advisory lock; require InnoDB and transact reschedules; check every state transition before committing or firing hooks; guard cancellation and provider writes with the original token/status; reject stale manage tokens after a slot is reused.
- **Availability correctness** — fail closed on database and Google Calendar read failures; refresh calendar busy data before booking mutations; validate real dates and scalar request fields; compare the combined buffers of both meetings; exclude the current booking from reschedule limits using a private token-protected availability request.
- **Privacy and packaging** — exclude host-only meeting links from attendee calendar descriptions; send private/no-store, no-referrer and indexing exclusions on manage pages; document optional external services and hosting requirements; include the GPL license and operator README in clean distribution ZIPs.
- **Regression coverage** — add public booking lifecycle, SQL failure/rollback, lock contention, combined-buffer, private reschedule availability and Google payload/failure tests.
- **Wizard lifecycle** — hide reschedule confirmation until a time is selected, clear abandoned selections, and remove wizard listeners/live regions when closing or leaving a flow; ignore late responses after teardown.
- **Admin accessibility** — associate internal notes and custom duration inputs with labels, improve Delete button contrast, enlarge question reorder/remove controls, and keep the meeting editor within mobile viewport bounds.
- **Accessibility (WCAG 2.2 AA)** — modal focus management (save/restore trigger, focus trap, background `inert`), wizard step focus and live-region announcements, persistent polite/assertive live regions, correct slots/calendar semantics (no more misuse of `listbox`/`dialog` roles), full-date accessible names on calendar days, per-instance DOM IDs to avoid duplicates, keyboard-accessible field reordering in the admin, keyboard-operable copy-to-clipboard with announcements, larger modal close target, `prefers-reduced-motion` support, tunable muted-text contrast.
- **Security & correctness** — fixed the broken "Refresh My Calendars" nonce; stored secrets are never re-rendered into admin HTML (blank keeps the existing secret; explicit "Replace secret" flow); transient-based rate limiting on public bookings (filterable); constant-time token comparison (`hash_equals`) for cancel/reschedule; duplicate-slot protection (unique index + duplicate-key handling, 409 instead of 500); `jquery-ui-sortable` registered so drag-reorder works; complete uninstall (multisite-aware, removes all options/transients); assorted PHP notices and `wp_date()` fixes.
- **Rebooking freed slots** — a cancelled/rescheduled slot is bookable again: the duplicate-key path now reactivates the single existing slot row in place with the new booking's details and freshly generated cancel/reschedule tokens (no stale manage links; true races against a still-confirmed occupant still return `409`).
- **Review polish** — month navigation/Retry restore keyboard focus after re-render; server errors focus the alert region and stale `aria-invalid` is cleared; unavailable calendar days carry real (visually hidden) text; admin settings tabs expose `aria-current`; clipboard fallback paths announce guidance; slot JSON is attribute-escaped; inline add-form Cancel returns focus to its opener; modal wizard listeners are torn down on close.
- **Style settings** — new Settings → Style tab: accent color, accent text color, button style (outline/filled/link), corner radius (including pill), surface mode (adaptive/light/dark), density, max width, and scoped custom CSS. Public stylesheet refactored to CSS custom properties with current-look fallbacks and dark-theme-safe surfaces. Optional per-instance `accent` shortcode attribute.
- **Internationalization** — all JavaScript UI strings (front-end + admin) are translatable via localized string objects; previously hardcoded PHP strings (durations, email variables, format labels, provider labels, email/ICS/calendar labels) now use the text domain; POT regenerated.
- **Admin UX** — contextual help tabs; new Help & Setup tab with quick start, blocks + shortcode reference, Google Calendar guide, Zoom guide, and FAQ; Google Client ID format validation; connection status details; "Test Connection" for Zoom; admin notices when Zoom meeting creation fails; privacy-policy suggestion via `wp_add_privacy_policy_content()`; unified settings-save pattern.
- **Gutenberg blocks** — Booking Form, Booking Button, and Meeting Type Cards blocks (own “Meet With Me” category, server-side rendered with live editor previews, no build step); conditional asset loading so booking CSS/JS and the footer modal only load on pages that contain booking UI; shortcodes unchanged.
- **Release hygiene** — version unified at 0.3.0; `readme.txt` (WordPress.org format); `CHANGELOG.md`; `phpcs.xml.dist`; PHPUnit test scaffold (`composer.json`, `phpunit.xml.dist`, `tests/`, `bin/install-wp-tests.sh`); `.distignore` for release packaging.
- **Docs** — README updated (style settings, shortcodes with `accent`, accurate behavior notes); in-admin Help mirrors setup docs.

## 0.1.0

Initial release: meeting types, availability engine (weekly hours, overrides, blocked days), multi-step booking wizard, shortcodes (`[mwm_cards]`, `[mwm_button]`, `[mwm_booking_form]`), booker cancel/reschedule flow, email notifications with `.ics` attachments, Google Calendar integration, and the WP admin interface.
