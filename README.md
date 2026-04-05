# studio1119-wordpress-connector

Open-source WordPress plugin shim used by Studio 1119, Inc. to mount its
cloud-hosted product interfaces inside the WordPress admin.

This single codebase produces per-app branded plugin zips:

- **CataSEO for WooCommerce** — AI-powered product descriptions and SEO meta
- **TruSync for WooCommerce** — product and inventory sync

The plugin is a thin shim. It:

1. Registers an admin menu item.
2. Renders a mount `<div>` on the menu page.
3. Enqueues `widget.js` from the configured remote app URL.
4. Exposes a minimal REST bridge (`studio1119/v1`) so the widget can read and
   write canonical SEO fields without needing to know whether Yoast, Rank
   Math, AIOSEO, or standalone mode is active.
5. In standalone mode (no SEO plugin detected), emits a minimal set of meta
   tags on singular product pages so generated content is still visible to
   search engines and social platforms.

All business logic, UI, billing, and account management live in the remote
widget and its backend — not in this plugin. The plugin only needs to be
updated when the shim contract itself changes, which should be rare.

## Repository layout

```
apps.json                 Per-app manifests (name, slug, widget URL, branding)
build/build.sh            Builds a distributable zip for a given app + env
src/plugin.php            Main plugin file (template, tokens substituted at build)
src/readme.txt            WordPress.org plugin directory readme (template)
src/includes/
  class-plugin.php              Bootstrap and option storage
  class-seo-plugin-detector.php Detects Yoast / Rank Math / AIOSEO / standalone
  class-field-mapper.php        Canonical field name → meta key per mode
  class-standalone-head.php     Minimal wp_head output when no SEO plugin is active
  class-admin-page.php          Admin menu, mount div, widget.js enqueue
  class-rest-bridge.php         studio1119/v1 REST endpoints
LICENSE                   GPLv2
```

## Building a plugin zip

```bash
# Production CataSEO build:
./build/build.sh cataseo production 0.1.0

# Staging CataSEO build (points widget at app-staging.cataseo.ai):
./build/build.sh cataseo staging 0.1.0

# Development build for TruSync (points at local ngrok tunnel):
./build/build.sh trusync development 0.1.0
```

Output: `dist/<plugin_slug>-<version>-<env>.zip`

Each zip contains a single-folder WordPress plugin that can be uploaded via
**Plugins > Add New > Upload Plugin** in any WordPress admin.

## Adding a new app

1. Add a new entry under `apps` in `apps.json` with `plugin_name`,
   `plugin_slug`, `text_domain`, `menu_title`, `menu_icon`,
   `root_element_id`, `option_prefix`, `meta_prefix`, `description`, and
   `environments.{production,staging,development}` URLs.
2. Run `./build/build.sh <new-app> <env>`.

No code changes are needed; the template tokens in `src/` cover everything.

## Requirements

The built plugin requires:
- WordPress 6.4+
- PHP 7.4+
- WooCommerce 9.6+ (for the core `product_brand` taxonomy; older WC versions
  are out of scope per the hosted-WooCommerce-first targeting decision — see
  the `docs/specs/woocommerce-specification.md` spec in the main CataSEO repo)

## License

GPLv2 or later. See [LICENSE](LICENSE).

Only the shim code in this repository is GPL. The remote widget, its
backend, and any branded logos or trademarks remain proprietary to
Studio 1119, Inc.
