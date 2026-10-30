# test-sites — local WordPress + WooCommerce test harness

Four isolated WordPress sites for exercising the connector plugin against
every supported SEO plugin configuration.

| Port | Site | SEO plugin |
|------|------|------------|
| 8081 | yoast | Yoast SEO |
| 8082 | rankmath | Rank Math |
| 8083 | aioseo | All in One SEO |
| 8084 | standalone | none (exercises the shim's own `wp_head` output) |

All four share one local `mariadb` instance (via `brew services`) with one
database per site. PHP is the Homebrew build. No Docker.

## One-time prerequisites

```bash
brew install php mariadb wp-cli jq
brew services start mariadb
```

## Bring up the sites

```bash
# 1. Install WP + WC + the SEO plugin per site, create databases, import products:
./setup.sh

# 2. Start the 4 dev servers (backgrounded, PIDs written to .pids):
./start.sh

# 3. Install / reinstall the connector plugin across all 4 sites:
./install-connector.sh cataseo development 0.1.0
```

Then open:
- http://localhost:8081/wp-admin (Yoast)
- http://localhost:8082/wp-admin (Rank Math)
- http://localhost:8083/wp-admin (AIOSEO)
- http://localhost:8084/wp-admin (standalone)

Credentials are written to `test-sites/.credentials` on first setup
(gitignored). Default username is `admin`.

## Stop / tear down

```bash
./stop.sh            # stops the dev servers, keeps data
./reset.sh           # stops servers, drops all 4 DBs, deletes ./sites/
```

## Seeding products

`setup.sh` calls `seed.sh` automatically on first install. To re-seed without
a full reset:

```bash
./seed.sh
```

The seed data comes from a JSON file exported by the main CataSEO repo:

```bash
# In bigcommerce-aiseo:
npx tsx scripts/export-seed-products.ts
# Writes to /tmp/cataseo-seed-products.json (default location)
```

`seed.sh` reads from `$SEED_JSON` (defaults to
`/tmp/cataseo-seed-products.json`) and creates products in every site via
`wp wc product create`.

## Webhooks to a local receiver

`setup.sh` installs `mu-plugins/allow-loopback-webhooks.php` into every site.
Without it, a WooCommerce webhook pointed at `http://localhost:<port>/...`
never arrives — and says nothing about why. WC delivers with
`wp_safe_remote_request()`, which rejects loopback hosts *and* any port
outside 80/443/8080; both rejections leave the Action Scheduler job
`completed` and the webhook's `failure_count` empty.

A webhook pointed at a public URL (an ngrok tunnel, say) needs none of this.
Neither do the connector's own notifications — they use `wp_remote_post()`,
which does not validate the URL.

Delivery is queued, and WP-Cron does not run on this harness, so nothing
leaves until you drain the queue:

```bash
wp --path=sites/yoast action-scheduler run
```

Drain *between* steps when you are measuring a sequence. Action Scheduler
collapses identical pending actions and WooCommerce builds the payload at
delivery time, so three changes to one order inside one undrained window
arrive as a single delivery carrying only the final state.

## Notes

- `wp server` is PHP's built-in single-request web server. Fine for manual
  testing, not load testing.
- Images are not seeded — the source dev store has none. Featured-image
  testing belongs to a separate pass.
- `wp_options` for each site is configured with `home` and `siteurl` set to
  `http://localhost:808X` so links resolve correctly inside each admin.
