# Meet With Me

A flexible appointment booking plugin for WordPress. Create meeting types, set your availability, connect Google Calendar, and let people book time with you — directly from your site.

**Requires:** PHP 8.1+, WordPress 6.4+
**Version:** 0.3.0

> Gutenberg blocks — Booking Form, Booking Button, and Meeting Type Cards — are available in the **Meet With Me** block category. Shortcodes remain fully supported and unchanged.

---

## Table of Contents

1. [Installation](#installation)
2. [Quick Start](#quick-start)
3. [Shortcodes](#shortcodes)
4. [Gutenberg Blocks](#gutenberg-blocks)
5. [Settings Reference](#settings-reference)
6. [Database Schema](#database-schema)
7. [REST API](#rest-api)
8. [Action Hooks](#action-hooks)
9. [Filters](#filters)
10. [PHP API](#php-api)
11. [CSS Customization](#css-customization)
12. [Google Calendar Setup](#google-calendar-setup)

---

## Installation

1. Drop the `meet-with-me/` folder into `wp-content/plugins/`.
2. Activate via **Plugins → Installed Plugins**.
3. Go to **Meet With Me → Settings** and set your timezone, name, and email.
4. Go to **Meet With Me → Meeting Types** and create at least one meeting type.
5. Embed a booking block or shortcode wherever you want people to book.

On activation the plugin creates four database tables (prefixed with `{$wpdb->prefix}mwm_`) and seeds a default Mon–Fri 9 am–5 pm weekly schedule.

---

## Quick Start

```php
// Display all active meeting types as cards
[mwm_cards]

// Single "Book a time" button → opens wizard in a modal
[mwm_button event_type="30-min-intro-call"]

// Inline full booking wizard embedded on a page
[mwm_booking_form event_type="30-min-intro-call"]
```

Bookers receive a confirmation email with a `.ics` calendar attachment and a link to cancel or reschedule their booking.

---

## Shortcodes

### `[mwm_cards]`

Renders a responsive grid of all active meeting types. Each card has a "Book Now" button that opens the booking wizard in a modal.

| Attribute | Default | Description |
|---|---|---|
| `event_types` | *(all active)* | Comma-separated slugs to limit which types appear, e.g. `event_types="call,deep-dive"` |
| `columns` | `3` | Grid columns (1–6). Collapses to 1 on small screens. |
| `show_description` | `true` | Set to `false` to hide the meeting type description. |
| `accent` | *(style setting)* | Optional hex color that overrides the accent color for this instance only, e.g. `accent="#2563eb"`. |

```
[mwm_cards columns="2" event_types="call,workshop"]
```

---

### `[mwm_button]`

Renders a single button. Clicking it opens the booking wizard for the specified event type in a modal overlay. Good for headers, nav bars, and sidebars.

| Attribute | Default | Description |
|---|---|---|
| `event_type` | *(required)* | The meeting type slug. |
| `label` | `Book a time` | Button text. |
| `class` | *(empty)* | Extra CSS class(es) added to the button element. |
| `accent` | *(style setting)* | Optional hex color that overrides the accent color for this instance only. |

```
[mwm_button event_type="consultation" label="Book a free consultation" class="btn-primary"]
```

---

### `[mwm_booking_form]`

Renders the full booking wizard inline on the page (no modal). Use this when you want a dedicated booking page.

| Attribute | Default | Description |
|---|---|---|
| `event_type` | *(optional)* | Slug of a specific meeting type. If omitted the wizard starts at the event-type chooser. |
| `accent` | *(style setting)* | Optional hex color that overrides the accent color for this instance only. |

```
[mwm_booking_form event_type="deep-dive"]
```

---

## Gutenberg Blocks

The plugin ships three server-rendered blocks in their own **Meet With Me** block category. Each renders exactly the same booking UI as its shortcode counterpart:

| Block | Equivalent shortcode | Key settings |
|---|---|---|
| **Booking Form** (`meet-with-me/booking-form`) | `[mwm_booking_form]` | Meeting type (optional), accent color override |
| **Booking Button** (`meet-with-me/button`) | `[mwm_button]` | Button text, meeting type, accent color override |
| **Meeting Type Cards** (`meet-with-me/cards`) | `[mwm_cards]` | Meeting types, columns (1–6), show descriptions, accent color override |

Blocks show a live server-side preview in the editor, and the front end loads the booking CSS/JS (and the modal) only on pages that actually contain booking UI. Shortcodes keep working unchanged — pick whichever fits your workflow.

Block settings map 1:1 to the shortcode attributes:

| Block setting | Shortcode attribute | Notes |
|---|---|---|
| Meeting type | `eventType` / `eventTypes` | Dropdown built from your active meeting types; falls back to typing slugs when none exist yet |
| Accent color | `accentColor` | Optional per-instance hex override; defaults to **Settings → Style** |
| Columns | `columns` | Cards block, 1–6 (default 3) |
| Show descriptions | `showDescription` | Cards block, default on |

> Dev notes: blocks are registered from `blocks/<name>/block.json` with hand-written editor scripts (no build step — `wp.*` globals only). The `mwm_load_assets` filter can force front-end asset loading on or off for a request.

---

## Settings Reference

All settings are stored in WordPress options. Access them via the admin menu at **Meet With Me → Settings**, or programmatically via `MWM_Settings`.

### General (`mwm_general`)

| Key | Description |
|---|---|
| `timezone` | IANA timezone string for the host (e.g. `America/Vancouver`). All availability rules are evaluated in this timezone. |
| `admin_name` | Your name — used in email templates as `{admin_name}`. |
| `admin_email` | Email address that receives new booking notifications. |
| `min_notice_hours` | Minimum hours' notice required before a booking. Bookers cannot book or reschedule within this window. Default: `24`. |
| `max_advance_days` | How many days in advance someone can book. Default: `60`. |

### Availability (`mwm_availability_rules` table)

Managed under **Settings → Availability**. Two rule types:

- **Weekly rules** — repeat every week for a given day. Day `0` = Sunday, `1` = Monday … `6` = Saturday.
- **Override rules** — apply to a specific date, overriding the weekly rule for that day.

Additionally, **Blocked Dates** (`mwm_blocked_dates` table) mark entire days as unavailable regardless of other rules.

### Google Calendar (`mwm_google`)

| Key | Description |
|---|---|
| `client_id` | OAuth 2.0 client ID from Google Cloud Console. |
| `client_secret` | OAuth 2.0 client secret. |
| `access_token` | Auto-managed. Stored after OAuth flow completes. |
| `refresh_token` | Auto-managed. Used to renew expired access tokens. |
| `calendar_ids` | Array of calendar IDs to check for busy/available times. |
| `write_back_calendar_id` | Calendar ID where confirmed bookings are written as events. |

See [Google Calendar Setup](#google-calendar-setup) for the full OAuth flow.

### Email Templates (`mwm_email`)

| Key | Description |
|---|---|
| `from_name` | "From" name on all outgoing emails. |
| `from_email` | "From" address on all outgoing emails. |
| `confirmation_subject` / `confirmation_body` | Sent to the booker on new booking (includes `.ics` attachment). |
| `admin_subject` / `admin_body` | Sent to `admin_email` on every new booking. |
| `cancellation_subject` / `cancellation_body` | Sent to the booker when their booking is cancelled. |
| `reschedule_subject` / `reschedule_body` | Sent to the booker when they reschedule (includes updated `.ics`). |

#### Template variables

All email bodies support these placeholders:

| Variable | Value |
|---|---|
| `{booker_name}` | Booker's full name |
| `{booker_email}` | Booker's email address |
| `{event_name}` | Meeting type name |
| `{duration}` | Duration in minutes |
| `{date}` | Meeting date in the booker's timezone |
| `{time}` | Meeting time in the booker's timezone |
| `{timezone}` | Booker's timezone string |
| `{meeting_type_label}` | `"Online"` or `"In person"` |
| `{manage_url}` | Unique URL for the booker to cancel or reschedule |
| `{booking_url}` | Home URL (link to book again) |
| `{admin_name}` | Your name from General settings |
| `{admin_booking_url}` | Direct link to the booking in WP admin |
| `{field_answers}` | Formatted custom field responses |
| `{site_name}` | WordPress site title |

### Style (`mwm_style`)

| Key | Description |
|---|---|
| `accent_color` | Hex color for filled buttons, progress fills, and selected states. Empty = inherit theme text color. |
| `accent_text_color` | Text color on top of the accent color. Empty = white automatically. |
| `button_style` | `outline` \| `filled` \| `link`. |
| `button_radius` | `''` (theme default), `0`–`24`, or `pill`. |
| `surface_mode` | `theme` (adaptive) \| `light` \| `dark` — drives modal and overlay surfaces. |
| `density` | `comfortable` \| `compact`. |
| `max_width` | Max width in px (320–1200). Default `560`. |
| `custom_css` | Extra CSS output after the plugin stylesheet. |

---

## Database Schema

### `{prefix}mwm_event_types`

Stores meeting type definitions.

| Column | Type | Notes |
|---|---|---|
| `id` | `bigint UNSIGNED` | Primary key |
| `name` | `varchar(255)` | Display name |
| `slug` | `varchar(255)` | URL-safe identifier, unique |
| `description` | `text` | Optional description shown in cards/wizard |
| `duration_minutes` | `int` | Meeting length |
| `meeting_type` | `enum('online','in_person','both')` | |
| `buffer_before` | `int` | Minutes blocked before the meeting |
| `buffer_after` | `int` | Minutes blocked after the meeting |
| `max_per_day` | `int` | Max bookings of this type per day (`NULL` = unlimited) |
| `max_per_week` | `int` | Max bookings of this type per week (`NULL` = unlimited) |
| `color` | `varchar(7)` | Hex color for UI accents, e.g. `#3b82f6` |
| `fields` | `longtext` | JSON array of custom field definitions |
| `is_active` | `tinyint(1)` | `1` = visible and bookable |
| `created_at` | `datetime` | |

#### Custom fields JSON structure

Each entry in `fields` is an object:

```json
{
  "id": "unique-key",
  "label": "Field label",
  "type": "text|textarea|select|radio|checkbox",
  "required": true,
  "placeholder": "Optional placeholder text",
  "options": ["Option A", "Option B"]
}
```

`options` is only used for `select`, `radio`, and `checkbox` types.

---

### `{prefix}mwm_bookings`

| Column | Type | Notes |
|---|---|---|
| `id` | `bigint UNSIGNED` | Primary key |
| `event_type_id` | `bigint UNSIGNED` | FK → `mwm_event_types.id` |
| `booker_name` | `varchar(255)` | |
| `booker_email` | `varchar(255)` | |
| `booker_phone` | `varchar(50)` | Optional |
| `booker_notes` | `text` | Optional notes from booker |
| `field_answers` | `longtext` | JSON object: `{ "field-id": "answer" }` |
| `start_datetime` | `datetime` | UTC |
| `end_datetime` | `datetime` | UTC |
| `booker_timezone` | `varchar(100)` | IANA timezone of the booker at booking time |
| `meeting_type` | `enum('online','in_person')` | |
| `status` | `enum('confirmed','cancelled','rescheduled')` | |
| `cancel_token` | `varchar(64)` | 64-char hex token, unique |
| `reschedule_token` | `varchar(64)` | 64-char hex token, unique |
| `gcal_event_id` | `varchar(255)` | Google Calendar event ID, if written back |
| `admin_notes` | `text` | Private notes added from WP admin |
| `created_at` | `datetime` | |
| `updated_at` | `datetime` | Auto-updated on change |

All datetimes in this table are **UTC**. Use `MWM_Booking::format_datetime()` to display in a specific timezone.

> **Slot reuse (booking, cancel, reschedule):** `start_datetime` is unique per `event_type_id` **across all statuses** — one row per slot, ever. Cancel and reschedule keep their row (status change only), so when a freed slot is booked again, the existing row is **reactivated in place**: the new booking's details and times replace the old ones and both `cancel_token`/`reschedule_token` are regenerated (the previous booker's links can never manage the new booking). A duplicate against a still-`confirmed` occupant (true concurrent race) is rejected with `409`, so the unique index keeps its race protection.

---

### `{prefix}mwm_availability_rules`

| Column | Type | Notes |
|---|---|---|
| `id` | `bigint UNSIGNED` | |
| `rule_type` | `enum('weekly','override')` | |
| `day_of_week` | `tinyint(1)` | `0`=Sun … `6`=Sat. Only for `weekly` rules. |
| `override_date` | `date` | Only for `override` rules. |
| `start_time` | `time` | In admin timezone |
| `end_time` | `time` | In admin timezone |
| `is_available` | `tinyint(1)` | `0` = marks day as unavailable (no bookings) |

---

### `{prefix}mwm_blocked_dates`

| Column | Type | Notes |
|---|---|---|
| `id` | `bigint UNSIGNED` | |
| `blocked_date` | `date` | Unique |
| `reason` | `varchar(255)` | Optional label (vacation, holiday, etc.) |

---

## REST API

Base URL: `/wp-json/mwm/v1`

All endpoints are public (no authentication required for read/create). Token validation is used for cancel and reschedule actions.

---

### `GET /event-types`

Returns all active meeting types.

**Response `200`:**
```json
[
  {
    "id": 1,
    "name": "30-min Intro Call",
    "slug": "30-min-intro-call",
    "description": "A quick introduction call.",
    "duration_minutes": 30,
    "meeting_type": "online",
    "color": "#3b82f6",
    "fields": []
  }
]
```

---

### `GET /availability/month`

Returns dates in a calendar month that have at least one available slot.

**Query params:**

| Param | Required | Description |
|---|---|---|
| `event_type` | yes | Meeting type slug |
| `year` | yes | Four-digit year |
| `month` | yes | Month number (1–12) |
| `tz` | no | Booker's IANA timezone (default: `UTC`) |

**Response `200`:**
```json
{
  "year": 2026,
  "month": 4,
  "available_dates": ["2026-04-07", "2026-04-08", "2026-04-09"]
}
```

---

### `GET /availability/slots`

Returns available time slots for a specific date.

**Query params:**

| Param | Required | Description |
|---|---|---|
| `event_type` | yes | Meeting type slug |
| `date` | yes | `YYYY-MM-DD` |
| `tz` | no | Booker's IANA timezone (default: `UTC`) |

**Response `200`:**
```json
{
  "date": "2026-04-07",
  "timezone": "America/Vancouver",
  "slots": [
    {
      "start_utc": "2026-04-07 16:00:00",
      "end_utc":   "2026-04-07 16:30:00",
      "start_local": "9:00 AM",
      "start_iso": "2026-04-07T16:00:00+00:00"
    }
  ]
}
```

---

### `POST /bookings`

Create a new booking.

**Request body (JSON):**
```json
{
  "event_type":   "30-min-intro-call",
  "start_utc":    "2026-04-07 16:00:00",
  "end_utc":      "2026-04-07 16:30:00",
  "timezone":     "America/Vancouver",
  "booker_name":  "Jane Smith",
  "booker_email": "jane@example.com",
  "booker_phone": "+1 555 000 0000",
  "booker_notes": "Looking forward to it!",
  "meeting_type": "online",
  "field_answers": { "topic": "Product demo" },
  "mwm_hp": ""
}
```

`mwm_hp` is a honeypot field — must be empty. Non-empty submissions are silently discarded.

**Response `201`:**
```json
{
  "success": true,
  "booking": {
    "id": 42,
    "booker_name": "Jane Smith",
    "booker_email": "jane@example.com",
    "event_type_name": "30-min Intro Call",
    "start_local": "April 7, 2026 at 9:00 am",
    "timezone": "America/Vancouver",
    "meeting_type_label": "Online",
    "manage_url": "https://example.com/?mwm_token=abc123..."
  }
}
```

**Error responses:** `400` missing/invalid fields, `404` event type not found, `409` slot no longer available, `500` DB failure.

---

### `POST /bookings/{id}/cancel`

Cancel an existing booking using its cancel token.

**Request body:**
```json
{ "cancel_token": "64-char-hex-token" }
```

**Response `200`:** `{ "success": true }`

**Error responses:** `400` missing token, `403` invalid token, `409` booking not confirmed.

After cancellation the `mwm_booking_cancelled` action fires, which sends a cancellation email and removes the Google Calendar event.

---

### `POST /bookings/{id}/reschedule`

Reschedule a confirmed booking to a new time slot.

**Request body:**
```json
{
  "reschedule_token": "64-char-hex-token",
  "start_utc":  "2026-04-10 16:00:00",
  "end_utc":    "2026-04-10 16:30:00",
  "timezone":   "America/Vancouver"
}
```

**Response `201`:**
```json
{
  "success": true,
  "booking": {
    "id": 43,
    "event_type_name": "30-min Intro Call",
    "start_local": "April 10, 2026 at 9:00 am",
    "timezone": "America/Vancouver"
  }
}
```

**Error responses:** `400` missing fields, `403` invalid token, `409` too close to meeting / slot unavailable, `404` event type not found, `500` DB failure.

The original booking is marked `rescheduled`. A new confirmed booking is created inheriting all booker details. The `mwm_booking_rescheduled` action fires.

---

## Action Hooks

### `mwm_booking_confirmed`

Fires immediately after a new booking is saved and before the HTTP response is sent.

```php
do_action( 'mwm_booking_confirmed', array $booking, array $event_type );
```

Default behavior (in `MWM_Plugin`): sends confirmation email + admin notification, writes event to Google Calendar.

---

### `mwm_booking_cancelled`

Fires after a booking is cancelled — either from the admin dashboard or by the booker via the manage-booking page.

```php
do_action( 'mwm_booking_cancelled', array $booking, array $event_type );
```

Default behavior: sends cancellation email to the booker, deletes the Google Calendar event.

---

### `mwm_booking_rescheduled`

Fires after a booking is rescheduled. `$new_booking` is the freshly-created confirmed booking. `$old_booking` is the original, now marked `rescheduled`.

```php
do_action( 'mwm_booking_rescheduled', array $new_booking, array $old_booking, array $event_type );
```

Default behavior: sends reschedule confirmation email with updated `.ics` to the booker, deletes old Google Calendar event, creates new one.

---

## Filters

### `mwm_email`

Filter any outgoing email before it is sent via `wp_mail()`.

```php
add_filter( 'mwm_email', function( array $email ): array {
    // $email keys: to, subject, html_body, headers, attachments
    $email['subject'] = '[Site Name] ' . $email['subject'];
    return $email;
} );
```

### `mwm_booking_rate_limit_hourly` / `mwm_booking_rate_limit_burst`

Adjust the transient-based rate limits applied to public booking submissions (per client IP address). Defaults: 5 attempts per hour and 3 attempts per 5 minutes. Return `0` to disable a limit.

```php
// Allow more bookings per hour from each visitor.
add_filter( 'mwm_booking_rate_limit_hourly', fn() => 10 );
```

### `mwm_email_accent_color`

Filter the accent (link) color used inside HTML notification emails. Defaults to the Style setting's accent color, or `#2563eb`.

```php
add_filter( 'mwm_email_accent_color', fn() => '#0f766e' );
```

---

## PHP API

### `MWM_Settings`

```php
// Get a single value (defaults to 'general' group)
MWM_Settings::get( 'timezone' );
MWM_Settings::get( 'client_id', 'google' );

// Get all values for a group (merged with defaults)
MWM_Settings::get_all( 'email' );

// Set a single value
MWM_Settings::set( 'min_notice_hours', 48 );
MWM_Settings::set( 'from_name', 'Tracy', 'email' );

// Merge and save multiple values at once
MWM_Settings::set_group( [ 'from_name' => 'Tracy', 'from_email' => 'tracy@example.com' ], 'email' );
```

---

### `MWM_Booking`

```php
// Fetch by ID
$booking = MWM_Booking::get( 42 );

// Fetch by cancel or reschedule token
$booking = MWM_Booking::get_by_token( $token, 'cancel' );
$booking = MWM_Booking::get_by_token( $token, 'reschedule' );

// Paginated list with optional filters
$bookings = MWM_Booking::get_all([
    'status'   => 'confirmed',  // 'confirmed'|'cancelled'|'rescheduled'|'' for all
    'search'   => 'jane',       // searches name + email
    'per_page' => 20,
    'page'     => 1,
    'orderby'  => 'start_datetime', // or 'created_at'
    'order'    => 'DESC',
]);

// Count with the same filters
$total = MWM_Booking::count([ 'status' => 'confirmed' ]);

// Counts grouped by status
$counts = MWM_Booking::count_by_status();
// Returns: [ 'confirmed' => N, 'cancelled' => N, 'rescheduled' => N, 'total' => N ]

// Create — tokens are auto-generated
$id = MWM_Booking::create([
    'event_type_id'   => 1,
    'booker_name'     => 'Jane Smith',
    'booker_email'    => 'jane@example.com',
    'start_datetime'  => '2026-04-07 16:00:00', // UTC
    'end_datetime'    => '2026-04-07 16:30:00',
    'booker_timezone' => 'America/Vancouver',
    'meeting_type'    => 'online',
    'status'          => 'confirmed',
]);

// Update arbitrary fields
MWM_Booking::update( 42, [ 'admin_notes' => 'Confirmed via phone.' ] );

// Cancel (sets status to 'cancelled')
MWM_Booking::cancel( 42 );

// Format a UTC datetime for display in a specific timezone
$display = MWM_Booking::format_datetime(
    $booking['start_datetime'],  // 'Y-m-d H:i:s' UTC
    'America/Vancouver',
    'D, M j \a\t g:i A'          // PHP date format; omit to use WP date+time format
);
```

---

### `MWM_Event_Type`

```php
// Fetch by ID
$et = MWM_Event_Type::get( 1 );

// Fetch by slug
$et = MWM_Event_Type::get_by_slug( '30-min-intro-call' );

// All event types; pass true to return only active ones
$types = MWM_Event_Type::get_all( true );

// Create
$id = MWM_Event_Type::create([
    'name'             => '30-min Intro Call',
    'slug'             => '30-min-intro-call',
    'duration_minutes' => 30,
    'meeting_type'     => 'online',
    'color'            => '#3b82f6',
    'is_active'        => 1,
]);

// Update
MWM_Event_Type::update( 1, [ 'max_per_day' => 3 ] );

// Delete (removes the row and its stored answers permanently)
MWM_Event_Type::delete( 1 );
```

---

### `MWM_Availability`

```php
// All bookable slots for a specific date
$slots = MWM_Availability::get_slots_for_date( '2026-04-07', $event_type, 'America/Vancouver' );
// Returns: [[ 'start_utc', 'end_utc', 'start_local', 'start_iso' ], ...]

// Which dates in a month have at least one slot
$dates = MWM_Availability::get_available_dates_for_month( 2026, 4, $event_type, 'America/Vancouver' );
// Returns: ['2026-04-07', '2026-04-08', ...]

// Verify a specific slot before booking
$ok = MWM_Availability::is_slot_available( $start_utc, $end_utc, $event_type );
// Pass $exclude_booking_id to ignore a specific booking (used during reschedule)
$ok = MWM_Availability::is_slot_available( $start_utc, $end_utc, $event_type, $old_booking_id );

// Read the weekly schedule
$schedule = MWM_Availability::get_weekly_schedule();
// Returns: array<int, ['id','start','end','is_available']|null> indexed 0(Sun)–6(Sat)

// Date-specific overrides
$overrides = MWM_Availability::get_overrides();

// Entire-day blocks
$blocked = MWM_Availability::get_blocked_dates();
```

---

### `MWM_Google_Calendar`

```php
// Check whether OAuth is connected
if ( MWM_Google_Calendar::is_connected() ) { ... }

// Get busy periods for a date (returns array of ['start' => DT, 'end' => DT] in UTC)
$busy = MWM_Google_Calendar::get_busy_periods_for_date( '2026-04-07', $admin_tz );

// Write a booking to Google Calendar; returns the event ID or null
$gcal_id = MWM_Google_Calendar::create_event( $booking, $event_type );

// Delete an event
MWM_Google_Calendar::delete_event( $gcal_event_id );
```

---

### `MWM_Email`

```php
$email = new MWM_Email();
$email->send_confirmation( $booking, $event_type );           // with .ics attachment
$email->send_admin_notification( $booking, $event_type );
$email->send_cancellation( $booking, $event_type );
$email->send_reschedule_confirmation( $new_booking, $event_type ); // with .ics attachment
```

---

### `MWM_ICS`

Generates RFC 5545-compliant `.ics` files for calendar invite attachments.

```php
// Write a temp file and return its path (delete after sending)
$path = MWM_ICS::temp_file( $booking, $event_type );
// ...send email with $path as attachment...
@unlink( $path );
```

---

## Style & CSS Customization

The plugin ships with structural, theme-friendly CSS (`border: 1px solid currentColor` everywhere) plus a full styling layer built on CSS custom properties. Class names use the `.mwm-` prefix.

### Style settings

**Meet With Me → Settings → Style** controls the front-end appearance without touching code:

| Setting | Options |
|---|---|
| Accent Color | Hex color, or empty to inherit your theme's text color. Used for filled buttons, progress fills, and selected states. |
| Accent Text Color | Text color on top of the accent. Empty = white automatically. |
| Button Style | `outline` (theme default), `filled` (accent background), or `link` (text links). |
| Corner Radius | Theme default, 0–24 px, or pill. |
| Surfaces | `theme` adapts modal/calendar surfaces to the visitor's system (light/dark), or force light/dark. |
| Density | `comfortable` or `compact`. |
| Max Width | Max width of the wizard and manage-booking page in px (320–1200). |
| Custom CSS | Extra CSS loaded after the plugin stylesheet; prefix selectors with `.mwm-`. |

Instance-level accent overrides are supported on every shortcode: `[mwm_button event_type="consultation" accent="#2563eb"]`, `[mwm_cards accent="#2563eb"]`, `[mwm_booking_form event_type="deep-dive" accent="#2563eb"]`.

### CSS custom properties

Every tunable value is exposed as a custom property with a current-look fallback, so themes can override them safely:

```css
:root {
  --mwm-accent: #2563eb;           /* buttons, progress, selected states */
  --mwm-accent-contrast: #fff;     /* text on accent-filled elements */
  --mwm-surface: #fff;             /* modal / overlay card surface */
  --mwm-surface-contrast: #1d2327; /* text on that surface */
  --mwm-surface-muted: rgba(0,0,0,.1); /* tracks, spinner */
  --mwm-overlay: rgba(0,0,0,.55);  /* modal backdrop */
  --mwm-border: currentColor;
  --mwm-radius: 4px;               /* buttons/inputs */
  --mwm-btn-radius: 4px;           /* buttons specifically */
  --mwm-radius-lg: 8px;            /* cards/modal */
  --mwm-space: 1;                  /* density multiplier */
  --mwm-muted-opacity: 0.75;       /* muted helper text contrast knob */
  --mwm-wrap-width: 560px;
}
```

Common overrides:

```css
/* Brand the primary button */
.mwm-btn-primary {
    background: var(--your-brand-color);
    color: #fff;
    border-color: var(--your-brand-color);
}

/* Danger button (cancel) */
.mwm-btn-danger {
    color: #dc2626;
    border-color: #dc2626;
}

/* Modal backdrop opacity */
.mwm-modal__overlay {
    background: rgba(0, 0, 0, 0.6);
}
```

Unset custom properties fall back to the original structural look, and surfaces (`.mwm-modal__content`, calendar overlays) adapt to light/dark automatically in `theme` mode. The full stylesheet also honours `prefers-reduced-motion`.

---

## Google Calendar Setup

1. Go to [Google Cloud Console](https://console.cloud.google.com/) → create a project.
2. Enable the **Google Calendar API** for the project.
3. Create **OAuth 2.0 credentials** (type: Web Application).
4. Under "Authorized redirect URIs" add:
   ```
   https://yoursite.com/wp-admin/admin.php?page=mwm-settings&tab=google&mwm_oauth_callback=1
   ```
5. Copy the **Client ID** and **Client Secret** into **Meet With Me → Settings → Google Calendar**.
6. Click **Connect with Google** and complete the OAuth flow.
7. After connecting, select which calendars to check for busy times and which calendar to write confirmed bookings into.

To disconnect: click **Disconnect** in the Google Calendar settings tab. This clears the stored tokens.

---

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for the full history. Current release: **0.3.0**.
