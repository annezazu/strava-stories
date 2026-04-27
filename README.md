# Strava Stories

A WordPress dashboard widget that surfaces your latest Strava activity inline,
with a carousel through your recent activities and a one-click **Let's blog
it** button that creates a pre-filled WordPress draft.

## What you get

- **Strava embed** — the official Strava iframe is used when the API returns
  an `embed_token`; a styled fallback card is used otherwise.
- **Stats** — type, distance, moving time, average pace/speed, elevation gain.
  Distances and pace switch between metric and imperial based on the site
  locale (`en_US` → imperial, otherwise metric).
- **Description excerpt** — the first ~280 chars of the activity description.
- **Carousel** — left / right arrows (and `←` / `→` keys when the widget has
  focus) page through the last 10 activities.
- **Let's blog it** — creates a draft post with the activity title, a stats
  list, the description, and a link back to Strava, then sends you to the
  block editor.

## Setup

### 1. Install and activate

Drop the plugin folder into `wp-content/plugins/strava-stories/` and activate
**Strava Stories** from the Plugins screen. You'll see a new top-level
**Strava Stories** menu item in the admin sidebar.

### 2. Register a Strava API app (admin, one time per site)

1. Visit <https://www.strava.com/settings/api>.
2. Click **Create & Manage Your App**.
3. Set the **Authorization Callback Domain** to your site's host (no scheme,
   no path) — for example `example.com`.
4. After creating the app, copy the **Client ID** and **Client Secret**.

### 3. Save credentials in WordPress (admin)

1. Go to **Strava Stories** in the admin menu.
2. Paste the Client ID and Client Secret. Save.
3. The page shows the exact callback URL the plugin uses; you don't have to
   enter the URL on Strava's side, only the domain (step 2.3 above).

### 4. Connect your Strava account (any user)

1. On the same **Strava Stories** page, click **Connect Strava**.
2. Approve the `read,activity:read` scopes on Strava's authorize screen.
3. You'll be redirected back to the settings page with a success notice.

### 5. View the widget

Open the WordPress dashboard. The **Strava Stories** widget loads your most
recent activity automatically.

## Permissions

- The widget and the **Let's blog it** action require `edit_posts`.
- Saving site-wide OAuth credentials requires `manage_options`.
- Each user connects their own Strava account; tokens are stored per-user in
  user meta and never shared with other site users.

## Data flow

- **Dashboard load** — one `GET /strava-stories/v1/activities` request fetches
  the 10 most recent activities live from Strava. Nothing is cached server
  side.
- **Let's blog it** — server re-fetches activities, matches the chosen ID,
  and creates the draft. The client never gets to choose post content.
- **Token refresh** — automatic when within 60 seconds of expiry, using the
  refresh token Strava issues at connect time.

## File map

```
strava-stories/
├── strava-stories.php                       — bootstrap, autoloader, hooks
├── uninstall.php                            — wipe option + user tokens
├── assets/
│   ├── strava-stories.css                   — widget + admin styles
│   └── strava-stories.js                    — carousel + blog handler
└── includes/
    ├── class-strava-stories-client.php      — OAuth + Strava API
    ├── class-strava-stories-admin.php       — settings page
    ├── class-strava-stories-rest.php        — REST endpoints + presenter
    └── class-strava-stories-widget.php      — dashboard widget shell
```

## License

GPL-2.0-or-later, matching WordPress core.
