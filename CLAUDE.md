# studio1119-wordpress-connector — Claude Code Instructions

## Project Overview

Multi-app WordPress/WooCommerce connector plugin. A single PHP codebase produces per-app plugin zips (CataSEO, TruSync, etc.) via a build step that substitutes `{{APP_*}}` tokens from `apps.json`. The plugin provides:

- Admin page with iframe widget pointing to the remote SaaS app
- REST bridge (`studio1119/v1`) for reading/writing SEO meta through whichever SEO plugin is active
- OAuth widget token verification
- SEO meta change notifications (outbound to the SaaS backend)
- Taxonomy change notifications (categories + brands)

## Architecture

### Multi-App Build System

- `apps.json` — defines per-app config (plugin name, slug, text domain, menu title, widget URLs per environment)
- `build/build.sh <app> <env> [version]` — substitutes tokens in `src/` and produces a zip at `dist/`
- `test-sites/install-connector.sh <app> <env> [version]` — builds + installs into all local WP test sites
- Use `/deploy-wp-connector` skill in Claude Code to build and deploy

### Four-Mode SEO Field Mapping

The connector detects which SEO plugin is active and maps canonical field names to the correct storage:

| Canonical Field | Yoast | Rank Math | AIOSEO | Standalone |
|----------------|-------|-----------|--------|------------|
| `page_title` | `_yoast_wpseo_title` | `rank_math_title` | `aioseo_posts.title` | `{prefix}_title` |
| `meta_description` | `_yoast_wpseo_metadesc` | `rank_math_description` | `aioseo_posts.description` | `{prefix}_description` |
| `og_title` | `_yoast_wpseo_opengraph-title` | `rank_math_facebook_title` | `aioseo_posts.og_title` | `{prefix}_og_title` |
| `og_description` | `_yoast_wpseo_opengraph-description` | `rank_math_facebook_description` | `aioseo_posts.og_description` | `{prefix}_og_description` |
| `meta_keywords` | `_yoast_wpseo_metakeywords` | `rank_math_focus_keyword` | `aioseo_posts.keyphrases` (JSON) | `{prefix}_keywords` |

**AIOSEO is special**: It stores data in its own `wp_aioseo_posts` table, NOT in `post_meta`. The REST bridge handles this with direct DB queries. The `keyphrases` column uses JSON format: `{"focus":{"keyphrase":"...","score":0,"analysis":[]},"additional":[]}`.

### WooCommerce REST Auth for Custom Namespace

WooCommerce only authenticates consumer key/secret for its own `wc/` namespace. Our `studio1119/v1` endpoints need a filter to tell WC to authenticate them too:

```php
add_filter('woocommerce_rest_is_request_to_rest_api', function($is_rest) {
    if (!empty($_SERVER['REQUEST_URI']) && false !== strpos($_SERVER['REQUEST_URI'], 'studio1119/v1')) {
        return true;
    }
    return $is_rest;
});
```

Without this, server-to-server calls with consumer key/secret get 401 Unauthorized.

### Webhook / Notification Architecture

The plugin sends outbound notifications to the SaaS backend:

| Notifier | WordPress Hooks | Callback URL | Purpose |
|----------|----------------|--------------|---------|
| `SEO_Meta_Notifier` | `updated_post_meta`, `added_post_meta` | `/api/woocommerce/webhooks/seo-meta` | SEO field changes by external plugins |
| `Taxonomy_Notifier` | `created_product_cat`, `edited_product_cat`, `delete_product_cat`, `created_product_brand`, `edited_product_brand`, `delete_product_brand` | `/api/woocommerce/webhooks/taxonomy` | Category/brand CRUD |

Both use `wp_remote_post` with `blocking => false` (fire-and-forget) and batch changes within a request via the `shutdown` hook.

WooCommerce's own product webhooks (`product.created/updated/deleted/restored`) are registered separately via the WC REST API during OAuth callback.

### Widget Auth Flow

1. Admin page loads → generates a one-time token via `Widget_Auth::generate_token()`
2. Token is passed to the iframe widget URL
3. SaaS backend calls back to `studio1119/v1/verify-token` to validate
4. Verification uses WC consumer key/secret Basic Auth (server-to-server)

## Local Test Sites

Four WordPress + WooCommerce instances, each with a different SEO plugin:

| Slug | Port | SEO Plugin | DB |
|------|------|------------|------|
| yoast | 8081 | Yoast SEO | wp_test_yoast |
| rankmath | 8082 | Rank Math | wp_test_rankmath |
| aioseo | 8083 | All in One SEO | wp_test_aioseo |
| standalone | 8084 | None | wp_test_standalone |

### Managing Test Sites

```bash
# Start all sites
./test-sites/start.sh

# Stop all sites
./test-sites/stop.sh

# Build + install connector to all sites
./test-sites/install-connector.sh cataseo development 0.1.0

# Seed products (no images — add manually)
./test-sites/seed.sh

# Reset a site (drop + recreate DB, reinstall WP)
./test-sites/reset.sh
```

### ngrok Tunnels

Test sites need ngrok tunnels for HTTPS (WC consumer key auth requires HTTPS) and for the SaaS backend to call back. Domains are pre-configured in `apps.json` and `ngrok.yml`:

- `studio1119-wp-yoast.ngrok-free.app` → localhost:8081
- `studio1119-wp-rankmath.ngrok-free.app` → localhost:8082
- `studio1119-wp-aioseo.ngrok-free.app` → localhost:8083
- `studio1119-wp-standalone.ngrok-free.app` → localhost:8084

**Hobbyist plan limit**: 3 simultaneous tunnels. Rotate between sites as needed (CataSEO dev tunnel always takes one slot).

### HTTPS Behind ngrok

Each test site's `wp-config.php` needs this block to prevent redirect loops:

```php
if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
    $_SERVER['HTTPS'] = 'on';
}
```

And the site URL must be updated to the ngrok URL:

```bash
wp --path=test-sites/sites/<slug> option update siteurl "https://studio1119-wp-<slug>.ngrok-free.app"
wp --path=test-sites/sites/<slug> option update home "https://studio1119-wp-<slug>.ngrok-free.app"
```

### ngrok Interstitial Bypass

ngrok free tier shows a browser interstitial on first visit. Server-to-server requests (like WC OAuth callback) get blocked by this. The plugin adds `ngrok-skip-browser-warning: 1` to outbound HTTP requests when the widget URL contains `ngrok-free.app`.

### PHP Server Workers

Test sites run with `PHP_CLI_SERVER_WORKERS=4` to avoid deadlocks when the SaaS backend calls back to the WP site during a request that originated from the same site (e.g., OAuth callback → webhook registration).

## Common Commands

```bash
# Build for a specific app + environment
./build/build.sh cataseo development 0.1.0
./build/build.sh cataseo staging 0.1.0
./build/build.sh cataseo production 0.1.0

# Build + deploy to all test sites
./test-sites/install-connector.sh cataseo development 0.1.0

# Run PHP linting
vendor/bin/phpcs

# Auto-fix formatting
vendor/bin/phpcbf

# Run tests
vendor/bin/phpunit
```

## Key Files

| File | Purpose |
|------|---------|
| `src/plugin.php` | Main plugin file with `{{APP_*}}` tokens, require statements |
| `src/includes/class-plugin.php` | Bootstrap: HPOS compat, SEO detection, WC auth filter, ngrok header |
| `src/includes/class-rest-bridge.php` | REST endpoints for SEO meta read/write + AIOSEO table handling |
| `src/includes/class-field-mapper.php` | Canonical field → plugin-specific meta key mapping |
| `src/includes/class-seo-plugin-detector.php` | Detects active SEO plugin (yoast/rankmath/aioseo/standalone) |
| `src/includes/class-admin-page.php` | WP admin menu page + iframe widget mount |
| `src/includes/class-widget-auth.php` | One-time token generation + WC API key auth check |
| `src/includes/class-seo-meta-notifier.php` | Outbound notifications on SEO meta changes |
| `src/includes/class-taxonomy-notifier.php` | Outbound notifications on category/brand changes |
| `src/includes/class-standalone-head.php` | Injects SEO meta tags when no SEO plugin is active |
| `apps.json` | Per-app configuration (names, slugs, widget URLs) |
| `build/build.sh` | Token substitution build script |
| `test-sites/sites.conf` | Test site manifest (slug, port, db, seo plugin) |

## Gotchas

- **ORM metadata cache**: If you add a column to an entity in the SaaS app, you must run `npm run db:cache:generate` in the SaaS repo. The generated `.mikro-orm/metadata.json` tells the ORM which columns to load. Missing columns are silently `undefined` at runtime.
- **WC webhook secrets**: When registering webhooks via the WC REST API, pass `secret: consumerSecret` so the HMAC signature matches what our webhook handler verifies against `store.consumerSecret`. If old webhooks have stale secrets, delete and re-register them.
- **Webhook echo**: When the SaaS app writes to a WC product, WC fires a `product.updated` webhook back. The SaaS app uses a Redis-backed echo guard (`wc:own-write:{storeUrl}:{productId}` with 30s TTL) to skip these.
- **WC brands are a taxonomy**: Not a first-class WC entity. The `products/brands` API endpoint is provided by the WooCommerce Brands extension. Brand updates go to `products/brands/{id}`, not `products/categories/{id}`.
- **Unique email constraint**: WC OAuth doesn't provide a user email. The SaaS app fetches `admin_email` from the connector's `seo/status` endpoint. Falls back to `admin@{hostname}` to satisfy the unique constraint on `users.email`.
- **Test site seed data has no images**: The `export-seed-products.ts` script explicitly skips images. Add product images manually through WP Admin for image/alt-text testing.
