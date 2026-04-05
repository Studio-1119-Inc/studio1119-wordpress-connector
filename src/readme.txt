=== {{APP_PLUGIN_NAME}} ===
Contributors: studio1119
Tags: woocommerce, seo, ai, product descriptions, meta tags
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: {{APP_VERSION}}
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html

{{APP_DESCRIPTION}}

== Description ==

{{APP_PLUGIN_NAME}} connects your WooCommerce store to the {{APP_MENU_TITLE}} cloud service for AI-powered product descriptions and SEO meta generation.

The plugin itself is a thin shim: it adds a {{APP_MENU_TITLE}} menu item to your WordPress admin and loads the {{APP_MENU_TITLE}} interface inside it. All product optimization, billing, and account management happens on {{APP_WIDGET_URL}}.

= Works alongside your existing SEO plugin =

* Yoast SEO — writes into Yoast's meta fields
* Rank Math — writes into Rank Math's meta fields
* All in One SEO — writes into AIOSEO's meta fields
* None of the above — ships a minimal standalone meta output layer so your generated content is still visible to search engines and social media

The plugin detects which SEO plugin you have active and automatically writes content to the right place. If you later install or switch SEO plugins, it adapts on the next admin page load.

= What this plugin does NOT do =

This plugin does not provide sitemaps, schema.org markup, canonical URL management, redirects, content analysis, or any other general-purpose SEO infrastructure. If you need those, install Yoast SEO, Rank Math, or All in One SEO alongside this plugin — we coexist cleanly.

== Installation ==

1. Upload the plugin zip through **Plugins > Add New > Upload Plugin**, or install from the WordPress.org directory.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Click the **{{APP_MENU_TITLE}}** menu item in the admin sidebar.
4. Sign in or create an account to start optimizing your products.

== Frequently Asked Questions ==

= Do I need Yoast / Rank Math / All in One SEO? =

No. If you have one of them, we use it. If you don't, we output the essential meta tags ourselves.

= Where is my content stored? =

Your generated content is stored in your WordPress database as standard post meta — the same place any SEO plugin stores its fields. If you uninstall this plugin, your content stays.

= Does this plugin slow down my site? =

The plugin only loads code on WooCommerce product pages (to output meta tags in standalone mode) and inside the {{APP_MENU_TITLE}} admin page (to load the interface). It does nothing on the front end otherwise.

== Changelog ==

= {{APP_VERSION}} =
* Initial release.

== Upgrade Notice ==

= {{APP_VERSION}} =
Initial release.
