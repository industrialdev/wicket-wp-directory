<?php

declare(strict_types=1);

namespace Wicket\Directory\Admin;

use Wicket\Directory\Config\DirectoryConfig;
use Wicket\Directory\Config\DirectoryType;
use Wicket\Directory\Data\ContactResolver;
use Wicket\Directory\PostType\DirectoryPostType;
use Wicket\Directory\Query\RequestParams;
use WP_Post;

/**
 * Meta boxes on the classic directory edit screen, and their save handler.
 *
 * Every box posts its part of the config under one field
 * (`wicket_directory[...]`). The save handler lays the posted sections over the
 * stored config, so a section with no box keeps its stored value, and runs the
 * result through DirectoryConfig::sanitize() with the stored config as
 * `$previous`. That resets the type's settings on a type change and bumps
 * cache_version.
 *
 * Views live in `src/Admin/views/` and aren't theme-overridable.
 */
final class DirectoryMetaBoxes
{
    /**
     * Name of the posted config array.
     */
    public const FIELD = 'wicket_directory';

    /**
     * Nonce action for the save handler.
     */
    public const NONCE_ACTION = 'wicket_directory_save';

    /**
     * Name of the nonce field.
     */
    public const NONCE_FIELD = '_wicket_directory_nonce';

    /**
     * Config sections the meta boxes post. Each one replaces the stored
     * section as a whole, so every field of a section must be in its box.
     */
    public const SECTIONS = ['type', 'eligibility', 'card', 'facets'];

    /**
     * Script handle for the edit screen.
     */
    public const SCRIPT_HANDLE = 'wicket-directory-admin';

    /**
     * Membership tier choices.
     */
    private MembershipTiers $tiers;

    /**
     * Organization and contact record types.
     */
    private ResourceTypes $resource_types;

    /**
     * Data-field choices.
     */
    private SchemaFields $schema_fields;

    /**
     * Each data source is loaded once, however many boxes use it. Tests pass
     * ones with fake loaders.
     *
     * @param MembershipTiers|null $tiers          Tier choices.
     * @param ResourceTypes|null   $resource_types Organization and contact record types.
     * @param SchemaFields|null    $schema_fields  Data-field choices.
     */
    public function __construct(?MembershipTiers $tiers = null, ?ResourceTypes $resource_types = null, ?SchemaFields $schema_fields = null)
    {
        $this->tiers = $tiers ?? new MembershipTiers();
        $this->resource_types = $resource_types ?? new ResourceTypes();
        $this->schema_fields = $schema_fields ?? new SchemaFields();
    }

    /**
     * Hook into WordPress.
     *
     * @return void
     */
    public function register(): void
    {
        add_action('add_meta_boxes_' . DirectoryPostType::POST_TYPE, [$this, 'add_meta_boxes']);
        add_action('save_post_' . DirectoryPostType::POST_TYPE, [$this, 'save'], 10, 2);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    /**
     * Add the meta boxes.
     *
     * @return void
     */
    public function add_meta_boxes(): void
    {
        add_meta_box(
            'wicket-directory-settings',
            __('Directory', 'wicket-directory'),
            [$this, 'render_directory_box'],
            DirectoryPostType::POST_TYPE,
            'normal',
            'high'
        );

        add_meta_box(
            'wicket-directory-card',
            __('Card', 'wicket-directory'),
            [$this, 'render_card_box'],
            DirectoryPostType::POST_TYPE,
            'normal',
            'high'
        );

        add_meta_box(
            'wicket-directory-filters',
            __('Filters', 'wicket-directory'),
            [$this, 'render_filters_box'],
            DirectoryPostType::POST_TYPE,
            'normal',
            'high'
        );
    }

    /**
     * Print the Directory box: type and eligibility.
     *
     * Tiers are listed for the stored type only. A type change clears them on
     * save, so the box asks the admin to save before choosing new ones.
     * Selections that the MDP no longer lists (or that couldn't be loaded) are
     * still shown, checked, so saving doesn't drop them silently.
     *
     * @param WP_Post $post Directory being edited.
     *
     * @return void
     */
    public function render_directory_box(WP_Post $post): void
    {
        $config = DirectoryConfig::from_post($post) ?? DirectoryConfig::defaults();
        $lang = (string) wicket_get_current_language();

        $tiers = $this->tiers->for_type($config->type, $lang);
        $org_types = $this->resource_types->for('organizations', $lang);

        wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD);

        $this->view('directory', [
            'field'           => self::FIELD,
            'config'          => $config,
            'types'           => DirectoryType::cases(),
            /* translators: %s: Membership tier UUID. */
            'tiers'           => self::with_saved($tiers, $config->eligibility['membership_ids'], __('Unknown tier (%s)', 'wicket-directory')),
            'tiers_failed'    => $this->tiers->failed(),
            /* translators: %s: Organization type slug. */
            'org_types'       => self::with_saved($org_types, $config->eligibility['org_types'], __('Unknown type (%s)', 'wicket-directory')),
            'org_types_empty' => $org_types === [],
        ]);
    }

    /**
     * Print the Card box: what each card shows.
     *
     * Only the saved type's settings are shown. A type change resets the card
     * on save, so the box asks the admin to save first. As in the Directory
     * box, saved data fields and contact types the MDP no longer lists are
     * still offered, selected.
     *
     * @param WP_Post $post Directory being edited.
     *
     * @return void
     */
    public function render_card_box(WP_Post $post): void
    {
        $config = DirectoryConfig::from_post($post) ?? DirectoryConfig::defaults();
        $card = $config->card;
        $lang = (string) wicket_get_current_language();
        $schemas = $this->schema_fields->for_type($config->type, $lang);
        $fields = self::card_fields($config->type);

        $tag_values = array_map(self::ref_value(...), $card['tag_chips']);
        $refs = [];

        foreach ($fields['refs'] as $key => $ref) {
            $selected = self::ref_value($card[$key]);
            $refs[$key] = $ref + ['selected' => $selected, 'choices' => self::ref_choices($schemas, $ref['kinds'], [$selected])];
        }

        $contacts = [];

        foreach (ContactResolver::RELATIONSHIPS as $key => $resource_type) {
            $saved = $card[$key]['type'] === DirectoryConfig::CONTACT_ANY ? [] : [$card[$key]['type']];

            $contacts[$key] = [
                'label' => $fields['contacts'][$key],
                /* translators: %s: Contact record type slug. */
                'types' => self::with_saved($this->resource_types->for($resource_type, $lang), $saved, __('Unknown type (%s)', 'wicket-directory')),
            ];
        }

        $this->view('card', [
            'field'                 => self::FIELD,
            'config'                => $config,
            'toggles'               => $fields['toggles'],
            'refs'                  => $refs,
            'tag_chips'             => self::ref_choices($schemas, [SchemaFields::KIND_TEXT, SchemaFields::KIND_LIST], $tag_values),
            'tag_values'            => $tag_values,
            'schemas_failed'        => $this->schema_fields->failed(),
            'contacts'              => $contacts,
            'resource_types_failed' => $this->resource_types->failed(),
            'address_formats'       => [
                'full'                  => __('Full address', 'wicket-directory'),
                'city_province_country' => __('City, province/state, country', 'wicket-directory'),
                'city_province'         => __('City, province/state', 'wicket-directory'),
            ],
        ]);
    }

    /**
     * Print the Filters box: the facets visitors refine results with.
     *
     * Rows are the configured facets, in order. The add picker offers every
     * data field with an enum (a facet's choices come from it) and, for
     * organization directories, the organization type. Adding, removing and
     * reordering rows is `assets/js/admin.js`; without it, labels can still be
     * edited. A saved facet the picker no longer offers stays, marked: the
     * directory hides it from visitors until it has choices again.
     *
     * @param WP_Post $post Directory being edited.
     *
     * @return void
     */
    public function render_filters_box(WP_Post $post): void
    {
        $config = DirectoryConfig::from_post($post) ?? DirectoryConfig::defaults();
        $lang = (string) wicket_get_current_language();
        $choices = self::facet_choices($config->type, $this->schema_fields->for_type($config->type, $lang));

        $available = [];

        foreach ($choices as $group) {
            $available += $group;
        }

        $rows = [];

        foreach ($config->facets as $facet) {
            $key = RequestParams::facet_key($facet);

            $rows[] = $facet + [
                'key'         => $key,
                'name'        => $available[$key]['name'] ?? $facet['schema_key'] . '.' . $facet['field'],
                'placeholder' => self::facet_placeholder($facet),
                'available'   => isset($available[$key]),
            ];
        }

        $this->view('filters', [
            'field'          => self::FIELD,
            'rows'           => $rows,
            'choices'        => $choices,
            'schemas_failed' => $this->schema_fields->failed(),
        ]);
    }

    /**
     * Save the posted config.
     *
     * Runs on save_post_wicket_directory. Skips autosaves, revisions, requests
     * without a valid nonce (REST, quick edit, code) and users who can't manage
     * directories.
     *
     * @param int     $post_id Post ID.
     * @param WP_Post $post    Post object.
     *
     * @return void
     */
    public function save(int $post_id, WP_Post $post): void
    {
        if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
            return;
        }

        $nonce = isset($_POST[self::NONCE_FIELD]) && is_string($_POST[self::NONCE_FIELD])
            ? sanitize_text_field(wp_unslash($_POST[self::NONCE_FIELD]))
            : '';

        if ($nonce === '' || !wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            return;
        }

        if (!current_user_can(DirectoryPostType::CAPABILITY) || !current_user_can('edit_post', $post_id)) {
            return;
        }

        $posted = isset($_POST[self::FIELD]) && is_array($_POST[self::FIELD]) ? wp_unslash($_POST[self::FIELD]) : [];
        $previous = DirectoryConfig::from_post($post) ?? DirectoryConfig::defaults();

        $config = DirectoryConfig::sanitize(self::merge($previous->to_array(), $posted), $previous);

        update_post_meta($post_id, DirectoryPostType::META_KEY, $config->to_array());
    }

    /**
     * Load the edit screen script on directory edit screens only.
     *
     * @param string $hook_suffix The current admin page.
     *
     * @return void
     */
    public function enqueue_assets(string $hook_suffix): void
    {
        if (!in_array($hook_suffix, ['post.php', 'post-new.php'], true)) {
            return;
        }

        $screen = get_current_screen();

        if ($screen === null || $screen->post_type !== DirectoryPostType::POST_TYPE) {
            return;
        }

        wp_enqueue_script(
            self::SCRIPT_HANDLE,
            WICKET_DIRECTORY_URL . 'assets/js/admin.js',
            [],
            WICKET_DIRECTORY_VERSION,
            ['in_footer' => true]
        );
    }

    /**
     * Lay the posted sections over the stored config.
     *
     * Each section in SECTIONS that was posted replaces the stored one whole;
     * anything else posted is ignored. sanitize() validates the result.
     *
     * @param array<string, mixed> $stored The stored config, as from to_array().
     * @param array<mixed>         $posted The posted `wicket_directory` array.
     *
     * @return array<string, mixed>
     */
    public static function merge(array $stored, array $posted): array
    {
        return array_replace($stored, array_intersect_key($posted, array_flip(self::SECTIONS)));
    }

    /**
     * The card settings of a type, with their admin labels.
     *
     * Keys match DirectoryConfig's card defaults for the type: `toggles` are
     * its boolean keys, `refs` its single data-field references (with the
     * field kinds each accepts), `contacts` its contact rules. tag_chips,
     * address_format and website_label have controls of their own.
     *
     * @param DirectoryType $type Directory type.
     *
     * @return array{
     *     toggles: array<string, array{label: string, description: string}>,
     *     refs: array<string, array{label: string, description: string, kinds: list<string>}>,
     *     contacts: array<string, string>,
     * }
     */
    public static function card_fields(DirectoryType $type): array
    {
        $any = [SchemaFields::KIND_TEXT, SchemaFields::KIND_LIST];
        $image = [
            'label'       => '',
            'description' => __('A data field that holds the image URL.', 'wicket-directory'),
            'kinds'       => [SchemaFields::KIND_TEXT],
        ];
        $eyebrow = [
            'label'       => __('Eyebrow', 'wicket-directory'),
            'description' => __('A data field shown above the name, e.g. a business type.', 'wicket-directory'),
            'kinds'       => $any,
        ];
        $shared = [
            'member_id'       => [
                'label'       => __('Member ID', 'wicket-directory'),
                'description' => '',
            ],
            'membership_tier' => [
                'label'       => __('Membership tier', 'wicket-directory'),
                'description' => __('Adds one API request per page of results.', 'wicket-directory'),
            ],
        ];

        return match ($type) {
            DirectoryType::Individual => [
                'toggles'  => [
                    'salutation'  => ['label' => __('Salutation', 'wicket-directory'), 'description' => ''],
                    'middle_name' => ['label' => __('Middle name', 'wicket-directory'), 'description' => ''],
                    'suffix'      => ['label' => __('Suffix', 'wicket-directory'), 'description' => ''],
                    'job_title'   => ['label' => __('Job title', 'wicket-directory'), 'description' => ''],
                ] + $shared,
                'refs'     => [
                    'post_nominal'  => [
                        'label'       => __('Post-nominal letters', 'wicket-directory'),
                        'description' => __('A data field shown after the name, e.g. "Jane Doe, PhD".', 'wicket-directory'),
                        'kinds'       => $any,
                    ],
                    'profile_image' => ['label' => __('Profile image', 'wicket-directory')] + $image,
                    'eyebrow'       => $eyebrow,
                ],
                'contacts' => self::contact_labels(),
            ],
            DirectoryType::Organization => [
                'toggles'  => [
                    'org_type'    => ['label' => __('Organization type', 'wicket-directory'), 'description' => ''],
                    'description' => [
                        'label'       => __('Description', 'wicket-directory'),
                        'description' => __('Long descriptions get a Show more link.', 'wicket-directory'),
                    ],
                ] + $shared,
                'refs'     => [
                    'logo'    => ['label' => __('Logo', 'wicket-directory')] + $image,
                    'eyebrow' => $eyebrow,
                ],
                'contacts' => self::contact_labels(),
            ],
        };
    }

    /**
     * The facets a directory of this type can add, grouped for the picker.
     *
     * Keys are RequestParams::facet_key(), which is also what makes two
     * facets duplicates.
     *
     * `name` is what the admin sees in the list; `default` is the label a new
     * facet starts with; `placeholder` is the heading visitors see when the
     * label is emptied (see facet_placeholder()).
     *
     * @param DirectoryType $type    Directory type.
     * @param array<string, array{label: string, fields: array<string, array{label: string, kind: string, enum: bool}>}> $schemas From SchemaFields::for_type().
     *
     * @return array<string, array<string, array{source: string, schema_key: string, field: string, name: string, default: string, placeholder: string}>> Group label => facet key => facet.
     */
    public static function facet_choices(DirectoryType $type, array $schemas): array
    {
        $choices = [];

        if ($type === DirectoryType::Organization) {
            $org_type = [
                'source'     => DirectoryConfig::FACET_ORG_TYPE,
                'schema_key' => '',
                'field'      => '',
                'name'       => __('Organization type', 'wicket-directory'),
                'default'    => __('Organization type', 'wicket-directory'),
            ];
            $org_type['placeholder'] = self::facet_placeholder($org_type);
            $choices[__('Organization', 'wicket-directory')][RequestParams::facet_key($org_type)] = $org_type;
        }

        foreach ($schemas as $schema_key => $schema) {
            foreach ($schema['fields'] as $field => $meta) {
                if (!$meta['enum']) {
                    continue;
                }

                $facet = [
                    'source'     => DirectoryConfig::FACET_DATA_FIELD,
                    'schema_key' => $schema_key,
                    'field'      => $field,
                    'name'       => $schema['label'] . ' › ' . $meta['label'],
                    'default'    => $meta['label'],
                ];
                $facet['placeholder'] = self::facet_placeholder($facet);
                $choices[$schema['label']][RequestParams::facet_key($facet)] = $facet;
            }
        }

        return $choices;
    }

    /**
     * The heading visitors see for a facet with an empty label.
     *
     * Matches the fallback in `templates/parts/filters.php`.
     *
     * @param array{source: string, field: string} $facet Facet.
     *
     * @return string
     */
    private static function facet_placeholder(array $facet): string
    {
        return $facet['source'] === DirectoryConfig::FACET_ORG_TYPE
            ? __('Organization type', 'wicket-directory')
            : $facet['field'];
    }

    /**
     * Admin labels of the contact rules.
     *
     * @return array<string, string>
     */
    private static function contact_labels(): array
    {
        return [
            'address' => __('Address', 'wicket-directory'),
            'email'   => __('Email', 'wicket-directory'),
            'phone'   => __('Phone', 'wicket-directory'),
            'website' => __('Website', 'wicket-directory'),
        ];
    }

    /**
     * Picker choices: the fields of the accepted kinds, grouped by schema.
     *
     * Values are `"schema_key.field"`, as DirectoryConfig accepts them. A saved
     * value missing from the schemas is added in an "Unavailable" group.
     *
     * @param array<string, array{label: string, fields: array<string, array{label: string, kind: string, enum: bool}>}> $schemas From SchemaFields::for_type().
     * @param list<string> $kinds Accepted field kinds.
     * @param list<string> $saved Saved values (`''` for none).
     *
     * @return array<string, array<string, string>> Group label => value => label.
     */
    private static function ref_choices(array $schemas, array $kinds, array $saved): array
    {
        $groups = [];
        $known = [];

        foreach ($schemas as $schema_key => $schema) {
            foreach ($schema['fields'] as $field => $meta) {
                if (in_array($meta['kind'], $kinds, true)) {
                    $value = $schema_key . '.' . $field;
                    $groups[$schema['label']][$value] = $meta['label'] === $field ? $field : $meta['label'] . ' (' . $field . ')';
                    $known[$value] = true;
                }
            }
        }

        $missing = [];

        foreach ($saved as $value) {
            if ($value !== '' && !isset($known[$value])) {
                /* translators: %s: Data field reference, "schema_key.field". */
                $missing[$value] = sprintf(__('Unknown field (%s)', 'wicket-directory'), $value);
            }
        }

        if ($missing !== []) {
            $groups[__('Unavailable', 'wicket-directory')] = $missing;
        }

        return $groups;
    }

    /**
     * A data-field reference as a picker value.
     *
     * @param array{schema_key: string, field: string} $ref Data-field reference.
     *
     * @return string `"schema_key.field"`, or `''` when the reference is off.
     */
    private static function ref_value(array $ref): string
    {
        return $ref['schema_key'] === '' ? '' : $ref['schema_key'] . '.' . $ref['field'];
    }

    /**
     * Choices plus any saved value missing from them.
     *
     * @param array<string, string> $choices Value => label.
     * @param list<string>          $saved   Saved values.
     * @param string                $missing Label format for a missing value; `%s` is the value.
     *
     * @return array<string, string>
     */
    private static function with_saved(array $choices, array $saved, string $missing): array
    {
        foreach ($saved as $value) {
            $choices[$value] ??= sprintf($missing, $value);
        }

        return $choices;
    }

    /**
     * Print an admin view.
     *
     * @param string               $name View name, without `.php`.
     * @param array<string, mixed> $args Passed to the view as `$args`.
     *
     * @return void
     */
    private function view(string $name, array $args): void
    {
        // A static closure, so the view sees $args and not $this.
        (static function (string $__file, array $args): void {
            include $__file;
        })(__DIR__ . '/views/' . $name . '.php', $args);
    }
}
