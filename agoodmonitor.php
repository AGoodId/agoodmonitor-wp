<?php
/**
 * Plugin Name: AGoodMonitor
 * Plugin URI: https://github.com/AGoodId/agoodmonitor-wp
 * Description: Skickar WordPress-hälsodata (plugins, tema, core, Site Health) till AGoodMember automatiskt varje timme.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Author: AGoodId
 * Author URI: https://agoodid.se
 * License: GPL-2.0-or-later
 * Text Domain: agoodmonitor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AGOODMONITOR_VERSION', '1.0.0' );
define( 'AGOODMONITOR_DIR', plugin_dir_path( __FILE__ ) );

// GitHub-based auto-updater.
require_once AGOODMONITOR_DIR . 'inc/github-updater.php';
new AGoodMonitor_GitHub_Updater( __FILE__, 'AGoodId/agoodmonitor-wp' );

// Health reporter.
require_once AGOODMONITOR_DIR . 'inc/class-health-reporter.php';
new AGoodMonitor_Health_Reporter();
