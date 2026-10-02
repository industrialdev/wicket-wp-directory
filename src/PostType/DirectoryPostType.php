<?php

declare(strict_types=1);

namespace Wicket\Directory\PostType;

/**
 * The `wicket_directory` post type and its config post meta.
 *
 * A directory is an admin-only record: it has no frontend URL and is placed on
 * pages with the Wicket Directory block. Every capability maps to
 * manage_options, so only administrators see the Directories menu.
 */
final class DirectoryPostType
{
    /**
     * Post type key.
     */
    public const POST_TYPE = 'wicket_directory';

    /**
     * Post meta key that holds the whole directory config array.
     */
    public const META_KEY = '_wicket_directory_config';

    /**
     * Capability required to view, edit and configure directories.
     */
    public const CAPABILITY = 'manage_options';

    /**
     * Hook into WordPress.
     *
     * @return void
     */
    public function register(): void
    {
        add_action('init', [$this, 'register_post_type']);
        add_action('init', [$this, 'register_meta']);
        add_filter('use_block_editor_for_post_type', [$this, 'disable_block_editor'], 10, 2);
        add_action('add_meta_boxes_' . self::POST_TYPE, [$this, 'remove_custom_fields_box']);
        add_filter('post_updated_messages', [$this, 'updated_messages']);
    }

    /**
     * Register the post type.
     *
     * @return void
     */
    public function register_post_type(): void
    {
        $labels = [
            'name'                  => _x('Directories', 'post type general name', 'wicket-directory'),
            'singular_name'         => _x('Directory', 'post type singular name', 'wicket-directory'),
            'menu_name'             => _x('Directories', 'admin menu', 'wicket-directory'),
            'name_admin_bar'        => _x('Directory', 'add new on admin bar', 'wicket-directory'),
            'add_new'               => __('Add New Directory', 'wicket-directory'),
            'add_new_item'          => __('Add New Directory', 'wicket-directory'),
            'new_item'              => __('New Directory', 'wicket-directory'),
            'edit_item'             => __('Edit Directory', 'wicket-directory'),
            'view_item'             => __('View Directory', 'wicket-directory'),
            'all_items'             => __('All Directories', 'wicket-directory'),
            'search_items'          => __('Search Directories', 'wicket-directory'),
            'not_found'             => __('No directories found.', 'wicket-directory'),
            'not_found_in_trash'    => __('No directories found in Trash.', 'wicket-directory'),
            'filter_items_list'     => __('Filter directories list', 'wicket-directory'),
            'items_list_navigation' => __('Directories list navigation', 'wicket-directory'),
            'items_list'            => __('Directories list', 'wicket-directory'),
            'item_published'        => __('Directory published.', 'wicket-directory'),
            'item_updated'          => __('Directory updated.', 'wicket-directory'),
        ];

        register_post_type(self::POST_TYPE, [
            'labels'              => $labels,
            'description'         => __('Public directories of individuals or organizations from the Wicket member data platform.', 'wicket-directory'),
            'public'              => false,
            'publicly_queryable'  => false,
            'exclude_from_search' => true,
            'show_ui'             => true,
            'show_in_menu'        => true,
            'show_in_nav_menus'   => false,
            'show_in_admin_bar'   => false,
            'show_in_rest'        => true,
            'menu_icon'           => 'dashicons-groups',
            'hierarchical'        => false,
            'has_archive'         => false,
            'rewrite'             => false,
            'query_var'           => false,
            'can_export'          => true,
            'delete_with_user'    => false,
            // custom-fields is required for `meta` to appear in REST responses.
            'supports'            => ['title', 'revisions', 'custom-fields'],
            'map_meta_cap'        => true,
            'capabilities'        => $this->capabilities(),
        ]);
    }

    /**
     * Map every primitive post capability to manage_options.
     *
     * Meta capabilities (edit_post, delete_post, read_post) are left to
     * map_meta_cap(), which resolves them through these primitives.
     *
     * @return array<string, string>
     */
    private function capabilities(): array
    {
        $primitives = [
            'edit_posts',
            'edit_others_posts',
            'edit_private_posts',
            'edit_published_posts',
            'publish_posts',
            'read_private_posts',
            'delete_posts',
            'delete_others_posts',
            'delete_private_posts',
            'delete_published_posts',
            'create_posts',
        ];

        return array_fill_keys($primitives, self::CAPABILITY);
    }

    /**
     * Register the config post meta.
     *
     * The config is exposed only in the REST `edit` context. That context needs
     * the post type's edit_posts capability (manage_options), so the config is
     * never part of a public response even for published directories.
     *
     * @return void
     */
    public function register_meta(): void
    {
        register_post_meta(self::POST_TYPE, self::META_KEY, [
            'type'              => 'object',
            'description'       => __('Directory configuration.', 'wicket-directory'),
            'single'            => true,
            'revisions_enabled' => true,
            'sanitize_callback' => [$this, 'sanitize_meta'],
            'auth_callback'     => [$this, 'can_edit_meta'],
            'show_in_rest'      => [
                'schema' => $this->rest_schema(),
            ],
        ]);
    }

    /**
     * REST schema for the config meta.
     *
     * Keeps the top-level keys of the config shape (see the MVP plan,
     * "Directory config"). Nested values are left open here; DirectoryConfig
     * owns their validation. Core defaults additionalProperties to false on
     * every object schema, so nested objects must opt in explicitly or REST
     * strips their contents.
     *
     * @return array<string, mixed>
     */
    private function rest_schema(): array
    {
        $open_object = [
            'type'                 => 'object',
            'additionalProperties' => true,
        ];

        return [
            'type'                 => 'object',
            'context'              => ['edit'],
            'additionalProperties' => false,
            'properties'           => [
                'type'          => [
                    'type' => 'string',
                    'enum' => ['individual', 'organization'],
                ],
                'eligibility'   => $open_object,
                'card'          => $open_object,
                'facets'        => [
                    'type'  => 'array',
                    'items' => $open_object,
                ],
                'cache_version' => [
                    'type'    => 'integer',
                    'minimum' => 0,
                ],
            ],
        ];
    }

    /**
     * Sanitize the config before it is stored.
     *
     * @param mixed $value Raw meta value.
     *
     * @return array<string, mixed>
     */
    public function sanitize_meta(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * Whether a user may add, update or delete the config meta.
     *
     * @param bool   $allowed  Whether the user can add the meta. Default false.
     * @param string $meta_key The meta key.
     * @param int    $post_id  Post ID.
     * @param int    $user_id  User ID.
     *
     * @return bool
     */
    public function can_edit_meta(bool $allowed, string $meta_key, int $post_id, int $user_id): bool
    {
        return user_can($user_id, self::CAPABILITY);
    }

    /**
     * Use the classic edit screen for directories.
     *
     * @param bool   $use_block_editor Whether the post type can be edited with the block editor.
     * @param string $post_type        The post type being checked.
     *
     * @return bool
     */
    public function disable_block_editor(bool $use_block_editor, string $post_type): bool
    {
        return $post_type === self::POST_TYPE ? false : $use_block_editor;
    }

    /**
     * Remove the generic Custom Fields meta box that custom-fields support adds.
     *
     * The config is edited through the directory meta boxes only.
     *
     * @return void
     */
    public function remove_custom_fields_box(): void
    {
        remove_meta_box('postcustom', self::POST_TYPE, 'normal');
    }

    /**
     * Directory-specific messages on the classic edit screen.
     *
     * Directories have no frontend URL, so no message links to the post.
     *
     * @param array<string, array<int, string>> $messages Messages keyed by post type.
     *
     * @return array<string, array<int, string>>
     */
    public function updated_messages(array $messages): array
    {
        $post = get_post();
        $revision = isset($_GET['revision']) ? absint(wp_unslash($_GET['revision'])) : 0;

        $messages[self::POST_TYPE] = [
            0  => '',
            1  => __('Directory updated.', 'wicket-directory'),
            2  => __('Custom field updated.', 'wicket-directory'),
            3  => __('Custom field deleted.', 'wicket-directory'),
            4  => __('Directory updated.', 'wicket-directory'),
            5  => $revision
                /* translators: %s: Date and time of the revision. */
                ? sprintf(__('Directory restored to revision from %s.', 'wicket-directory'), wp_post_revision_title($revision, false))
                : '',
            6  => __('Directory published.', 'wicket-directory'),
            7  => __('Directory saved.', 'wicket-directory'),
            8  => __('Directory submitted.', 'wicket-directory'),
            9  => $post
                /* translators: %s: Scheduled publish date. */
                ? sprintf(__('Directory scheduled for: %s.', 'wicket-directory'), '<strong>' . esc_html(wp_date(__('M j, Y @ H:i', 'wicket-directory'), get_post_timestamp($post) ?: null)) . '</strong>')
                : '',
            10 => __('Directory draft updated.', 'wicket-directory'),
        ];

        return $messages;
    }
}
