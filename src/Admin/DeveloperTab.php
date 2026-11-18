<?php

declare(strict_types=1);

namespace Wicket\Directory\Admin;

use Wicket\Directory\Config\DirectoryConfig;
use WP_Post;

/**
 * What the Developer tab lists: the plugin's hooks, with this directory's
 * slug-scoped names, and the custom fields code registers for it.
 *
 * The tab only shows these. It doesn't write, run or turn off code; the
 * "Custom code" switch and the notes it saves are labels for admins
 * (DirectoryConfig::$developer).
 *
 * @phpstan-type Hook array{name: string, scoped: string|null, kind: string, args: string, description: string}
 * @phpstan-type CustomField array{key: string, label: string, description: string, tag: string}
 */
final class DeveloperTab
{
    /**
     * Filter that registers custom fields for the Developer tab; runs for every directory.
     */
    public const FIELDS_FILTER = 'wicket_directory/fields';

    /**
     * Hook kind: a filter.
     */
    public const FILTER = 'filter';

    /**
     * Hook kind: an action.
     */
    public const ACTION = 'action';

    /**
     * Placeholder for the slug in a scoped hook name, while the directory has none.
     */
    public const SLUG_PLACEHOLDER = '{slug}';

    /**
     * The plugin's hooks, in the order a render runs them.
     *
     * `scoped` is the name of the variant that runs for this directory only
     * (the generic name plus `_{slug}`), with SLUG_PLACEHOLDER when $slug is
     * empty; null for a hook that has no such variant.
     *
     * @param string $slug The directory post's slug (`post_name`); empty before WordPress sets one.
     *
     * @return list<Hook>
     */
    public static function hooks(string $slug): array
    {
        $hooks = [
            [
                'name'        => 'wicket_directory/before_render',
                'scoped'      => true,
                'kind'        => self::ACTION,
                'args'        => 'DirectoryConfig $config, RequestParams $params, WP_Post $directory',
                'description' => __('Runs before the directory queries the API and renders. What it prints comes before the directory.', 'wicket-directory'),
            ],
            [
                'name'        => 'wicket_directory/query_args',
                'scoped'      => true,
                'kind'        => self::FILTER,
                'args'        => 'array $args, DirectoryConfig $config, RequestParams $params',
                'description' => __('Changes the API query before it runs: filter, page, sort and include.', 'wicket-directory'),
            ],
            [
                'name'        => 'wicket_directory/cache_ttl',
                'scoped'      => false,
                'kind'        => self::FILTER,
                'args'        => 'int $ttl, DirectoryConfig $config, WP_Post $directory',
                'description' => __('How long API responses are cached, in seconds (default 600). 0 turns the cache off.', 'wicket-directory'),
            ],
            [
                'name'        => 'wicket_directory/entry',
                'scoped'      => true,
                'kind'        => self::FILTER,
                'args'        => 'array $entry, array $resource, DirectoryConfig $config',
                'description' => __('Changes one result\'s data before its card renders. Put custom data under extra.', 'wicket-directory'),
            ],
            [
                'name'        => 'wicket_directory/card_template',
                'scoped'      => false,
                'kind'        => self::FILTER,
                'args'        => 'string $path, DirectoryConfig $config, WP_Post $directory',
                'description' => __('Swaps the card template file, once per render.', 'wicket-directory'),
            ],
            [
                'name'        => 'wicket_directory/labels_cache_ttl',
                'scoped'      => false,
                'kind'        => self::FILTER,
                'args'        => 'int $ttl, string $lang',
                'description' => __('How long the shared choice and type labels are cached, in seconds (default 3600). Applies to every directory.', 'wicket-directory'),
            ],
            [
                'name'        => self::FIELDS_FILTER,
                'scoped'      => true,
                'kind'        => self::FILTER,
                'args'        => 'array $fields, DirectoryConfig $config, WP_Post $directory',
                'description' => __('Lists the custom fields your code adds, under Registered custom fields on this tab and in the Advance card\'s field picker. Admin only.', 'wicket-directory'),
            ],
        ];

        $suffix = $slug !== '' ? $slug : self::SLUG_PLACEHOLDER;

        return array_map(static fn (array $hook): array => [
            'scoped' => $hook['scoped'] ? $hook['name'] . '_' . $suffix : null,
        ] + $hook, $hooks);
    }

    /**
     * The custom fields code registers for a directory.
     *
     * Runs FIELDS_FILTER, then its `_{slug}` variant. A callback adds fields
     * by key, the key its `wicket_directory/entry` callback uses under
     * `extra`:
     *
     *     $fields['member_since'] = 'Member since';
     *     $fields['chapter'] = ['label' => 'Chapter', 'description' => 'From the chapters API.'];
     *     $fields[] = ['key' => 'region', 'label' => 'Region'];
     *
     * A key may hold letters, digits, `_` and `-` (as a merge tag needs);
     * other entries are dropped, and so is a key listed twice (the first
     * stays). A field without a label is labelled with its
     * key. Each field gets its Advance card merge tag, `{{extra.key}}`.
     *
     * Only the edit screen calls this (the Developer tab, and the Card View's
     * Advance field picker): rendering never does.
     *
     * @param DirectoryConfig $config    The directory's config.
     * @param WP_Post         $directory The directory post.
     *
     * @return list<CustomField> In registration order.
     */
    public static function fields(DirectoryConfig $config, WP_Post $directory): array
    {
        $fields = apply_filters(self::FIELDS_FILTER, [], $config, $directory);

        if ($directory->post_name !== '') {
            $fields = apply_filters(self::FIELDS_FILTER . '_' . $directory->post_name, is_array($fields) ? $fields : [], $config, $directory);
        }

        if (!is_array($fields)) {
            return [];
        }

        $list = [];

        foreach ($fields as $key => $field) {
            if (is_string($field)) {
                $field = ['label' => $field];
            }

            if (!is_array($field)) {
                continue;
            }

            // A list item names its key; a position is no key.
            $key = is_int($key) ? ($field['key'] ?? null) : $key;

            if (!is_string($key) || preg_match('/^[A-Za-z0-9_-]{1,100}$/', $key) !== 1 || isset($list[$key])) {
                continue;
            }

            $label = is_string($field['label'] ?? null) ? sanitize_text_field($field['label']) : '';
            $description = is_string($field['description'] ?? null) ? sanitize_text_field($field['description']) : '';

            $list[$key] = [
                'key'         => $key,
                'label'       => $label !== '' ? $label : $key,
                'description' => $description,
                'tag'         => '{{extra.' . $key . '}}',
            ];
        }

        return array_values($list);
    }
}
