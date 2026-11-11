<?php

declare(strict_types=1);

namespace Wicket\Directory\Api;

use Wicket\Directory\Data\EnumLabels;
use Wicket\Directory\PostType\DirectoryPostType;
use WP_Post;

/**
 * Clears a directory's cached API responses when the directory is saved or deleted.
 *
 * DirectoryRepository's cache keys already change on every save (cache_version,
 * the stored config and post_modified_gmt are in the hash), so a saved
 * directory never reads an old page. This class deletes those old entries
 * instead of leaving them to expire, and clears the shared label transients
 * too, so an admin who saves a directory also sees enum, org-type and
 * group-type labels renamed in the MDP.
 *
 * Runs for every save of a `wicket_directory` post: the edit screen, REST,
 * Quick Edit, trash and restore, and code.
 *
 * - Database-backed transients: one DELETE per prefix removes the values and
 *   their timeouts.
 * - A persistent object cache can't be searched by prefix. The directory's
 *   entries are then left to expire (the new keys already skip them), and the
 *   label transient is deleted by name for each known language.
 */
final class DirectoryCache
{
    /**
     * Runs after DirectoryMetaBoxes::save() (priority 10) has stored the config.
     */
    public const SAVE_PRIORITY = 20;

    /**
     * Hook into post saves and deletes.
     *
     * @return void
     */
    public function register(): void
    {
        add_action('save_post_' . DirectoryPostType::POST_TYPE, [$this, 'purge'], self::SAVE_PRIORITY);
        add_action('before_delete_post', [$this, 'on_delete'], 10, 2);
    }

    /**
     * Runs on before_delete_post, for every post type.
     *
     * @param int          $post_id The post being deleted.
     * @param WP_Post|null $post    The post (WordPress 5.5+ passes it).
     *
     * @return void
     */
    public function on_delete(int $post_id, ?WP_Post $post = null): void
    {
        $type = $post !== null ? $post->post_type : get_post_type($post_id);

        if ($type === DirectoryPostType::POST_TYPE) {
            $this->purge($post_id);
        }
    }

    /**
     * Delete one directory's cached results and the shared label transients.
     *
     * @param int $directory_id The directory post ID.
     *
     * @return void
     */
    public function purge(int $directory_id): void
    {
        if ($directory_id <= 0) {
            return;
        }

        if (!wp_using_ext_object_cache()) {
            self::delete_stored(DirectoryRepository::transient_prefix($directory_id));
            self::delete_stored(EnumLabels::transient_prefix());

            return;
        }

        foreach (self::languages() as $lang) {
            delete_transient(EnumLabels::transient_name($lang));
        }
    }

    /**
     * Delete every database transient (value and timeout) whose name starts with a prefix.
     *
     * @param string $prefix Transient name prefix.
     *
     * @return void
     */
    private static function delete_stored(string $prefix): void
    {
        global $wpdb;

        if (!is_object($wpdb)) {
            return;
        }

        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            $wpdb->esc_like('_transient_' . $prefix) . '%',
            $wpdb->esc_like('_transient_timeout_' . $prefix) . '%',
        ));
    }

    /**
     * Languages the label transient may exist for: the current one, English,
     * and every active WPML language.
     *
     * @return list<string>
     */
    private static function languages(): array
    {
        $languages = ['en'];

        if (function_exists('wicket_get_current_language')) {
            $languages[] = (string) wicket_get_current_language();
        }

        $active = apply_filters('wpml_active_languages', null, ['skip_missing' => 0]);

        if (is_array($active)) {
            foreach (array_keys($active) as $code) {
                $languages[] = (string) $code;
            }
        }

        return array_values(array_unique(array_filter($languages, static fn (string $lang): bool => $lang !== '')));
    }
}
