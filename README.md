# BV Instagram Feed

Lightweight WordPress Instagram grid: server-rendered, cached, no front-end JS. Uses Facebook Graph API (Instagram Business/Creator).

## Install

**As a plugin:** Clone or copy this folder into `wp-content/plugins/bv-instagram-feed/`, then activate under Plugins.

**Local (symlink):** For Local by Flywheel, symlink this repo into the site’s `wp-content/plugins` so edits here run immediately. See [LOCAL_SETUP.md](LOCAL_SETUP.md).

**From a theme:** Require the main file from your theme’s `functions.php`:
```php
$bv_ig = get_template_directory() . '/path/to/bv-instagram-feed/bv-instagram-feed.php';
if ( file_exists( $bv_ig ) ) {
	require_once $bv_ig;
}
```

## Setup

- **Token & IG ID:** Settings → BV Instagram Feed, or set constants `BV_IG_TOKEN` and `BV_IG_USER_ID` (e.g. in wp-config or a loaded file). Constants override saved options.
- **Token:** Long-lived Instagram user access token (from Meta app → Instagram API with Instagram Login). Stored token is auto-refreshed daily.
- **IG ID:** Instagram Business Account numeric ID (from Meta app → Instagram API setup, under the connected account).

## Usage

- Shortcode: `[bv_instagram_grid limit="12" cols="4" size="m"]` (limit 1–20, cols 2–6, size: m|t|l|full). Images proxied, resized, cached as WebP under `wp-content/cache/bv-instagram-feed/`.
- Verify: `GET /wp-json/bv/v1/instagram-verify` (admin or local host). Returns `ok`, `ig_user_id`, `media_count` or error step.

## Rate limits

Meta app-level limit: **200 × number of users per hour** (all tokens share the app quota). This plugin uses:

- **Media:** 1 call per cache miss; default cache 30 min → at most ~2 media calls/hour per limit bucket.
- **IG user id:** 1 call when uncached, then 24h transient → negligible.
- **Token refresh:** 1 call/day (only when token is stored in Settings).

So normal use stays well under the limit. On high-traffic sites, increase cache via the filter (e.g. 1 hour) to reduce calls.

## For developers

- **Filter `bv_instagram_access_token`:** Override the token.
- **Filter `bv_instagram_media_cache_seconds`:** Media cache TTL (default 30 min). Set to 0 to use default. Use a larger value (e.g. `HOUR_IN_SECONDS`) to reduce API calls under rate limits.
- **Shortcode `size`:** default `m` (306px). Images are proxied, resized to the size (t=150, m=306, l=640, full=1080), converted to WebP (or JPEG if unsupported), cached under `wp-content/cache/bv-instagram-feed/`, then served same-origin. Reduces payload and improves LCP; your CDN can cache the proxy URL.
- Uninstall: Deleting the plugin removes options and transients.

## License

Use as needed for Boardwalk Vintage; adapt for other projects as desired.
