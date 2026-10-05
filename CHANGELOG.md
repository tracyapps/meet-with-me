# Changelog

All notable changes to Meet With Me are documented here.

## 0.3.0 — 2026-10-03

Professionalization pass (release prep):

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
