<?php

/**
 * Plugin Name: Wicket Directory
 * Plugin URI: https://github.com/industrialdev/wicket-wp-directory
 * Description: Configurable public directories of Individuals and Organizations from the Wicket member data platform, placed with the Wicket Directory block.
 * Version: 0.1.0
 * Author: Wicket Inc.
 * Author URI: https://wicket.io
 * Requires at least: 6.6
 * Requires PHP: 8.3
 * Requires Plugins: wicket-wp-base-plugin
 * Text Domain: wicket-directory
 * Domain Path: /languages
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html.
 */

declare(strict_types=1);

// No direct access
defined('ABSPATH') || exit;

// Define plugin constants
define('WICKET_DIRECTORY_VERSION', get_file_data(__FILE__, ['Version' => 'Version'])['Version']);
define('WICKET_DIRECTORY_FILE', __FILE__);
define('WICKET_DIRECTORY_PATH', plugin_dir_path(__FILE__));
define('WICKET_DIRECTORY_URL', plugin_dir_url(__FILE__));
define('WICKET_DIRECTORY_BASENAME', plugin_basename(__FILE__));

// Load Composer autoloader
if (is_readable(WICKET_DIRECTORY_PATH . 'vendor/autoload.php')) {
    require_once WICKET_DIRECTORY_PATH . 'vendor/autoload.php';
}

/**
 * Check if Wicket Base Plugin is active.
 *
 * Looks for the base plugin's global Wicket() accessor, which exists as soon as
 * its main file is loaded. This works for single-site and network activation
 * and does not depend on the plugin's folder name.
 *
 * @return bool
 */
function wicket_directory_is_base_plugin_active(): bool
{
    return function_exists('Wicket');
}

/**
 * Display admin notice if required plugins are not active.
 *
 * @return void
 */
function wicket_directory_missing_dependencies_notice(): void
{
    if (!current_user_can('activate_plugins')) {
        return;
    }

    ?>
    <div class="notice notice-error">
        <p>
            <?php
            echo wp_kses_post(
                __('<strong>Wicket Directory</strong> requires the Wicket Base plugin to be installed and activated.', 'wicket-directory')
            );
    ?>
        </p>
    </div>
    <?php
}

/*
 * Initialize the plugin
 *
 * Runs after the base plugin's own setup (plugins_loaded, priority 99), so its
 * helpers such as wicket_api_client() are available.
 *
 * @return void
 */
add_action(
    'plugins_loaded',
    function () {
        if (!wicket_directory_is_base_plugin_active()) {
            add_action('admin_notices', 'wicket_directory_missing_dependencies_notice');

            return;
        }

        if (!class_exists(Wicket\Directory\Plugin::class)) {
            return;
        }

        Wicket\Directory\Plugin::get_instance()->plugin_setup();
    },
    100
);

/**
 * Plugin activation hook.
 *
 * @return void
 */
function wicket_directory_activate(): void
{
    // Check PHP version
    if (version_compare(PHP_VERSION, '8.3', '<')) {
        deactivate_plugins(WICKET_DIRECTORY_BASENAME);
        wp_die(
            esc_html__('Wicket Directory requires PHP 8.3 or higher. Please upgrade your PHP version.', 'wicket-directory'),
            esc_html__('Plugin Activation Error', 'wicket-directory'),
            ['back_link' => true]
        );
    }

    // Check for required plugins
    if (!wicket_directory_is_base_plugin_active()) {
        deactivate_plugins(WICKET_DIRECTORY_BASENAME);
        wp_die(
            esc_html__('Wicket Directory requires the Wicket Base plugin to be installed and activated.', 'wicket-directory'),
            esc_html__('Plugin Activation Error', 'wicket-directory'),
            ['back_link' => true]
        );
    }
}

register_activation_hook(__FILE__, 'wicket_directory_activate');
