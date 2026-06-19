<?php
/**
 * PHPUnit bootstrap for the Studio 1119 WordPress Connector plugin.
 *
 * Sets up Brain Monkey and loads the plugin classes in a mock WordPress
 * environment so that unit tests can exercise field mapping, SEO detection,
 * and meta change notification without a live WordPress installation.
 *
 * Source files contain build-time template variables (e.g. {{APP_NAMESPACE}}).
 * This bootstrap substitutes them with test values, writes the processed
 * files to a temp directory, and loads from there.
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
define( 'TESTAPP_APP_TYPE', 'seo' );
define( 'TESTAPP_MENU_TITLE', 'TestApp' );
define( 'TESTAPP_TEXT_DOMAIN', 'testapp' );

// Template variable substitutions for loading source files.
$template_replacements = array(
	'{{APP_NAMESPACE}}'             => 'Studio1119\\Connector',
	'{{APP_OPTION_PREFIX}}'         => 'testapp',
	'{{APP_CONST_PREFIX}}'          => 'TESTAPP',
	'{{APP_MENU_TITLE}}'            => 'TestApp',
	'{{APP_VERSION}}'               => '1.0.0-test',
	'{{APP_TEXT_DOMAIN}}'           => 'testapp',
	'{{APP_PLUGIN_NAME}}'           => 'Test App',
	'{{APP_META_PREFIX}}'           => '_testmeta',
	'{{APP_TYPE}}'                  => 'seo',
	'{{APP_WIDGET_URL}}'            => 'https://app.cataseo.ai',
	'{{APP_DOCS_URL}}'              => 'https://docs.example.com',
	'{{APP_MENU_ICON}}'             => 'dashicons-admin-generic',
	'{{APP_ROOT_ELEMENT_ID}}'       => 'testapp-root',
	'{{APP_DESCRIPTION}}'           => 'Test plugin.',
	'{{APP_CONNECT_HEADING}}'       => 'Connect',
	'{{APP_CONNECT_DESCRIPTION}}'   => 'Connect your store.',
	'{{APP_CONNECT_DISCLAIMER}}'    => '',
	'{{APP_CONNECTED_DESCRIPTION}}' => 'Connected.',
);

$test_build_dir = sys_get_temp_dir() . '/wp-connector-test-' . getmypid();
if ( ! is_dir( $test_build_dir ) ) {
	mkdir( $test_build_dir, 0755, true );
}

// Load plugin class files (order matters -- mirrors plugin.php).
$source_files = array(
	'class-seo-plugin-detector.php',
	'class-field-mapper.php',
	'class-standalone-head.php',
	'class-widget-auth.php',
	'class-admin-page.php',
	'class-rest-bridge.php',
	'class-seo-meta-notifier.php',
	'class-taxonomy-notifier.php',
	'class-plugin.php',
);

foreach ( $source_files as $file ) {
	$src  = __DIR__ . '/../src/includes/' . $file;
	$dest = $test_build_dir . '/' . $file;
	$code = file_get_contents( $src );
	$code = str_replace(
		array_keys( $template_replacements ),
		array_values( $template_replacements ),
		$code
	);
	file_put_contents( $dest, $code );
	require_once $dest;
}
