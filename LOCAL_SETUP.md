# Local setup (shopboardwalkvintage.local)

## Symlink so Local runs the repo code

Local site folder is **boardwalk** (`Users/boardwalk/Local Sites/boardwalk`). Use a symlink so the code you edit in this repo is what runs in Local.

```bash
LOCAL_PLUGINS_DIR="/Users/boardwalk/Local Sites/boardwalk/app/public/wp-content/plugins"
PLUGIN_REPO="/Users/boardwalk/development/bv-instagram-feed"

# If the plugin folder already exists as a copy (not a symlink), remove it first
rm -rf "$LOCAL_PLUGINS_DIR/bv-instagram-feed"

# Create symlink (Local will load plugin from repo)
ln -s "$PLUGIN_REPO" "$LOCAL_PLUGINS_DIR/bv-instagram-feed"
```

Check: `ls -la "$LOCAL_PLUGINS_DIR/bv-instagram-feed"` should show `bv-instagram-feed -> /Users/boardwalk/development/bv-instagram-feed`.

Then in WordPress: **Plugins** → activate **BV Instagram Feed**. Configure token and IG ID under **Settings → BV Instagram Feed**.

## Verify

1. **Verify endpoint**  
   Open: `http://shopboardwalkvintage.local/wp-json/bv/v1/instagram-verify`  
   (Logged in as admin or on `.local`.) You should see JSON with `ok: true`, `ig_user_id`, `media_count`, and `test_image_url` (proxy.php URL).

2. **Test one image**  
   Open the `test_image_url` from the verify response (or  
   `http://shopboardwalkvintage.local/wp-content/plugins/bv-instagram-feed/proxy.php?bv_ig_limit=12&bv_ig_size=m&bv_ig_index=0`).  
   The image should load (same-origin proxy; full-size from Instagram).

3. **Homepage**  
   Open `http://shopboardwalkvintage.local/` and confirm the Instagram grid appears and images load.
