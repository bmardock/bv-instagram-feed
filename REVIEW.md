# Lead-dev review notes

Things that were improved or are worth watching.

## Done

- **Proxy without DB** – Shortcode passes signed image URL; proxy only reads `wp-config` for `AUTH_KEY`. No PDO, no DB. Single responsibility, easier to reason about and deploy behind CDN.
- **DRY** – `bv_instagram_proxy_url( $image_url, $size )` builds the signed proxy URL; shortcode and verify both use it.
- **Settings** – Saving with an empty token field now clears the stored token (previously it was left unchanged).
- **Fallback** – If `AUTH_KEY` is missing, shortcode falls back to the raw Instagram URL (may fail cross-origin on some hosts; document that AUTH_KEY is required for proxy).

## Watch / optional improvements

1. **Proxy: AUTH_KEY regex** – We parse `wp-config` with a regex. If the site uses a nonstandard `AUTH_KEY` format (e.g. multiline or different quotes), the proxy could 500. Standard WP installs are fine. Optional: support a dedicated `BV_IG_PROXY_SECRET` constant so the proxy doesn’t depend on AUTH_KEY format.

2. **Proxy: URL allowlist** – We only allow `cdninstagram.com`, `fbcdn.net`, `instagram.com`. If Meta add a new CDN host, allowlist may need a quick update. Consider a short comment in proxy.php listing the pattern so it’s easy to extend.

3. **Observability** – On 502/503 the proxy only sends headers and exits; there’s no logging. For production you might add `error_log()` (or your logger) on failure so you can see when token/media/fetch fails.

4. **Rate limiting** – The proxy has no rate limit. Signed URLs restrict to your own images, but someone could still hit many signed URLs and use the server as a resize proxy. If the site is high-traffic or abuse is a concern, consider rate limiting by IP (or at the CDN).

5. **Token in UI** – The token field is type="password" and placeholder shows bullets when set; the value is intentionally not re-displayed. Good. Ensure env/constant override is documented for production (already in description).

6. **Verify permission** – Verify endpoint is allowed for admins or on `.local`/localhost; otherwise 403. Fine for a single-tenant setup; if you ever multi-tenant, restrict further.

Nothing else stands out as a red flag; the rest is straightforward (transients, cron, nonce on save).
