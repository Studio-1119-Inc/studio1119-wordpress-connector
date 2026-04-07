<?php
/**
 * PHPUnit bootstrap for the Studio 1119 WordPress Connector plugin.
 *
 * Sets up Brain Monkey and loads the plugin classes in a mock WordPress
 * environment so that unit tests can exercise field mapping, SEO detection,
 * and meta change notification without a live WordPress installation.
 *
 * @package Studio1119\Connector\Tests
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Define ABSPATH so the plugin files don't call exit().
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/wordpress/' );
}

// Define per-app constants so Plugin::const_prefix() discovers 'TESTAPP'
// and Plugin::const_value() resolves correctly.
define( 'TESTAPP_VERSION', '1.0.0-test' );
define( 'TESTAPP_PLUGIN_FILE', '/tmp/test-plugin.php' );
define( 'TESTAPP_META_PREFIX', '_testmeta' );
define( 'TESTAPP_WIDGET_URL', 'https://app.cataseo.ai' );
define( 'TESTAPP_OPTION_PREFIX', 'testapp' );

// Load plugin class files (order matters — mirrors plugin.php).
require_once __DIR__ . '/../src/includes/class-seo-plugin-detector.php';
require_once __DIR__ . '/../src/includes/class-field-mapper.php';
require_once __DIR__ . '/../src/includes/class-standalone-head.php';
require_once __DIR__ . '/../src/includes/class-admin-page.php';
require_once __DIR__ . '/../src/includes/class-rest-bridge.php';
require_once __DIR__ . '/../src/includes/class-seo-meta-notifier.php';
require_once __DIR__ . '/../src/includes/class-plugin.php';
