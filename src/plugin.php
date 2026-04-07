<?php
/**
 * Plugin Name: {{APP_PLUGIN_NAME}}
 * Plugin URI:  {{APP_WIDGET_URL}}
 * Description: {{APP_DESCRIPTION}}
 * Version:     {{APP_VERSION}}
 * Author:      Studio 1119, Inc.
 * Author URI:  https://studio1119.com
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Text Domain: {{APP_TEXT_DOMAIN}}
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * WC requires at least: 9.6
 * WC tested up to: 10.6
 *
 * @package Studio1119\Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( '{{APP_CONST_PREFIX}}_VERSION', '{{APP_VERSION}}' );
define( '{{APP_CONST_PREFIX}}_PLUGIN_FILE', __FILE__ );
define( '{{APP_CONST_PREFIX}}_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( '{{APP_CONST_PREFIX}}_WIDGET_URL', '{{APP_WIDGET_URL}}' );
define( '{{APP_CONST_PREFIX}}_ROOT_ID', '{{APP_ROOT_ELEMENT_ID}}' );
define( '{{APP_CONST_PREFIX}}_OPTION_PREFIX', '{{APP_OPTION_PREFIX}}' );
define( '{{APP_CONST_PREFIX}}_META_PREFIX', '{{APP_META_PREFIX}}' );
define( '{{APP_CONST_PREFIX}}_TEXT_DOMAIN', '{{APP_TEXT_DOMAIN}}' );
define( '{{APP_CONST_PREFIX}}_MENU_TITLE', '{{APP_MENU_TITLE}}' );
define( '{{APP_CONST_PREFIX}}_MENU_ICON', '{{APP_MENU_ICON}}' );

require_once __DIR__ . '/includes/class-seo-plugin-detector.php';
require_once __DIR__ . '/includes/class-field-mapper.php';
require_once __DIR__ . '/includes/class-standalone-head.php';
require_once __DIR__ . '/includes/class-widget-auth.php';
require_once __DIR__ . '/includes/class-admin-page.php';
require_once __DIR__ . '/includes/class-rest-bridge.php';
require_once __DIR__ . '/includes/class-seo-meta-notifier.php';
require_once __DIR__ . '/includes/class-plugin.php';

register_activation_hook( __FILE__, array( '\Studio1119\Connector\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\Studio1119\Connector\Plugin', 'deactivate' ) );
register_uninstall_hook( __FILE__, array( '\Studio1119\Connector\Plugin', 'uninstall' ) );

add_action( 'plugins_loaded', array( '\Studio1119\Connector\Plugin', 'boot' ) );
