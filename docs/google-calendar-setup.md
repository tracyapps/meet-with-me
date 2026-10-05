# Google Calendar Setup

Meet With Me uses Google's OAuth flow, so you still need your own Google Cloud project and OAuth client credentials. That part cannot be skipped if you want to connect a personal Google account securely.

## 1. Create Google credentials

1. Open the Google Cloud Console.
2. Create or choose a project.
3. Enable the Google Calendar API for that project.
4. Create an OAuth 2.0 Web application credential.
5. Add this plugin's redirect URL as an authorised redirect URI:

   `https://your-site.example/wp-admin/admin.php?page=mwm-settings&tab=google&mwm_oauth_callback=1`

6. Copy the Client ID and Client Secret into `Meet With Me -> Settings -> Google Calendar`.

## 2. Connect Google

1. Save the credentials in the plugin.
2. Click `Connect with Google`.
3. Approve access in the Google consent screen.

## 3. Choose calendars

After connecting, the plugin can load your available calendars for you.

1. Tick the calendars that should block availability.
2. Choose the calendar that confirmed bookings should be added to.
3. Save the calendar settings.

If your calendar list does not appear right away, use the `Refresh My Calendars` button.
