<?php
/**
 * Plugin Name: Meet With Me
 * Plugin URI:  https://github.com/tracyapps/meet-with-me
 * Description: A flexible appointment booking plugin. Create meeting types, set your availability, connect Google Calendar, and let people book time with you — directly from your WordPress site.
 * Version:     0.4.0
 * Author:      Tracy Apps
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: meet-with-me
 * Domain Path: /languages
 * Requires PHP: 8.1
 * Requires at least: 6.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MWM_VERSION', '0.4.0' );
define( 'MWM_PLUGIN_FILE', __FILE__ );
define( 'MWM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MWM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MWM_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once MWM_PLUGIN_DIR . 'includes/class-mwm-install.php';
require_once MWM_PLUGIN_DIR . 'includes/class-mwm-settings.php';
require_once MWM_PLUGIN_DIR . 'includes/class-mwm-plugin.php';

register_activation_hook( __FILE__, array( 'MWM_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'MWM_Install', 'deactivate' ) );

function mwm(): MWM_Plugin {
	return MWM_Plugin::instance();
}

mwm();
