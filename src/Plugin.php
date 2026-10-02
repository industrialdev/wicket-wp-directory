<?php

declare(strict_types=1);

namespace Wicket\Directory;

/**
 * Main plugin class.
 *
 * Boots the directory components once the base plugin is available.
 */
final class Plugin
{
    /**
     * Text domain for all plugin strings.
     */
    public const TEXT_DOMAIN = 'wicket-directory';

    /**
     * Singleton instance.
     *
     * @var self|null
     */
    private static ?self $instance = null;

    /**
     * Whether plugin_setup() has already run.
     *
     * @var bool
     */
    private bool $booted = false;

    /**
     * Use get_instance().
     */
    private function __construct() {}

    /**
     * Access this plugin's working instance.
     *
     * @return self
     */
    public static function get_instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Regular plugin setup. Runs on plugins_loaded.
     *
     * @return void
     */
    public function plugin_setup(): void
    {
        if ($this->booted) {
            return;
        }

        $this->booted = true;

        load_plugin_textdomain(
            self::TEXT_DOMAIN,
            false,
            dirname(WICKET_DIRECTORY_BASENAME) . '/languages'
        );
    }
}
