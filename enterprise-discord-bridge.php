<?php
/**
 * Plugin Name: Enterprise Discord Bridge
 * Description: Discord OAuth, login, direct guild membership recovery, messaging center, Discord-auth password reset, and dynamic role sync add-on for the JAS Enterprises portal.
 * Version: 0.4.3
 * Author: OpenAI
 */

if (!defined('ABSPATH')) {
    exit;
}

define('EDB_PLUGIN_FILE', __FILE__);
define('EDB_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('EDB_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once EDB_PLUGIN_DIR . 'includes/update-checker-bootstrap.php';

require_once EDB_PLUGIN_DIR . 'includes/class-edb-plugin.php';

register_activation_hook(__FILE__, array('EDB_Plugin', 'activate'));
register_deactivation_hook(__FILE__, array('EDB_Plugin', 'deactivate'));

add_action('plugins_loaded', function () {
    EDB_Plugin::instance();
});
