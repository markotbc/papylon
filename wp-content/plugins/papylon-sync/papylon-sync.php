<?php
/**
 * Plugin Name: Papylon Sync
 * Description: Custom sync job, run by system cron (WP-Cron is disabled).
 * Version:     1.0.0
 * Author:      Papylon
 * Text Domain: papylon-sync
 */

defined('ABSPATH') || exit;

define('PAPYLON_SYNC_VERSION', '1.0.0');
define('PAPYLON_SYNC_PATH', plugin_dir_path(__FILE__));

require_once PAPYLON_SYNC_PATH . 'includes/class-sync.php';

// WP-CLI: `wp papylon sync` (this is what system cron calls)
if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('papylon sync', function () {
        $result = Papylon_Sync::run();
        if (is_wp_error($result)) {
            WP_CLI::error($result->get_error_message());
        }
        WP_CLI::success('Sync finished: ' . wp_json_encode($result));
    });
}

// Hook so other code (or `wp cron event run`) can trigger it too.
add_action('papylon_sync_run', ['Papylon_Sync', 'run']);
