<?php

declare(strict_types=1);

namespace Wicket\Directory\Admin;

use Wicket\Directory\Config\DirectoryConfig;
use Wicket\Directory\PostType\DirectoryPostType;

/**
 * Type and Status columns on All Directories.
 *
 * Both go right after the title. There is no shortcode column: directories are
 * placed with the block. Title and Date stay WordPress's own, sortable as usual.
 * Only a published directory renders on the site, so every other status says
 * so under its label. A directory marked as running custom code (Developer
 * tab) gets a Custom Code label under its type.
 */
final class ListColumns
{
    /**
     * Type column key.
     */
    public const TYPE = 'wicket_directory_type';

    /**
     * Status column key.
     */
    public const STATUS = 'wicket_directory_status';

    /**
     * Hook into WordPress.
     *
     * @return void
     */
    public function register(): void
    {
        add_filter('manage_' . DirectoryPostType::POST_TYPE . '_posts_columns', [$this, 'columns']);
        add_action('manage_' . DirectoryPostType::POST_TYPE . '_posts_custom_column', [$this, 'render_column'], 10, 2);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_style']);
    }

    /**
     * Load the admin styles (for the Custom Code label) on All Directories only.
     *
     * @param string $hook_suffix The current admin page.
     *
     * @return void
     */
    public function enqueue_style(string $hook_suffix): void
    {
        if ($hook_suffix !== 'edit.php') {
            return;
        }

        $screen = get_current_screen();

        if ($screen === null || $screen->post_type !== DirectoryPostType::POST_TYPE) {
            return;
        }

        wp_enqueue_style(DirectoryMetaBoxes::STYLE_HANDLE, WICKET_DIRECTORY_URL . 'assets/css/admin.css', [], WICKET_DIRECTORY_VERSION);
    }

    /**
     * Add the columns after the title (or at the end when there's no title column).
     *
     * @param array<string, string> $columns Column key => header.
     *
     * @return array<string, string>
     */
    public function columns(array $columns): array
    {
        $added = [
            self::TYPE   => __('Type', 'wicket-directory'),
            self::STATUS => __('Status', 'wicket-directory'),
        ];

        $position = array_search('title', array_keys($columns), true);

        if ($position === false) {
            return $columns + $added;
        }

        return array_slice($columns, 0, $position + 1, true) + $added + array_slice($columns, $position + 1, null, true);
    }

    /**
     * Print a cell of one of the columns.
     *
     * @param string $column  Column key.
     * @param int    $post_id Directory post ID.
     *
     * @return void
     */
    public function render_column(string $column, int $post_id): void
    {
        if ($column === self::TYPE) {
            $config = DirectoryConfig::from_post($post_id);

            echo $config !== null ? esc_html($config->type->label()) : '—';

            if ($config?->has_custom_code()) {
                echo '<br><span class="wicket-directory-pill">' . esc_html__('Custom Code', 'wicket-directory') . '</span>';
            }

            return;
        }

        if ($column !== self::STATUS) {
            return;
        }

        $status = (string) get_post_status($post_id);
        $object = get_post_status_object($status);

        echo esc_html($object !== null ? (string) $object->label : $status);

        if ($status !== 'publish') {
            echo '<br><span class="description">' . esc_html__('Not shown on the site', 'wicket-directory') . '</span>';
        }
    }
}
