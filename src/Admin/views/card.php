<?php
/**
 * The Card View tab: what each card in the directory shows.
 *
 * One checklist in `groups` order, with a divider between groups. A ticked
 * item reveals its options indented below it (`data-wd-reveal`); without
 * `assets/js/admin.js` every item's options are shown.
 *
 * - Toggles are plain checkboxes.
 * - Data-field references (`data-wd-item="ref"`) have a checkbox that isn't
 *   posted; it's ticked when a field is chosen. Unticking it disables the
 *   field picker, so the reference isn't posted and saves as off. Choosing
 *   "Choose a field…" turns it off too.
 * - Contact rules (views/contact-rule.php) reveal their type, flags and hint.
 *   The script shows the hint (`data-wd-hint`) that matches the ticked flags.
 * - An organization card's Organization Contact (`org_contact`) reveals its
 *   source, relationship types, what each contact shows, and an email and a
 *   phone contact rule of its own, nested. The script shows the hint of the
 *   chosen source (`data-wd-mode`).
 *
 * Only the saved type's settings are printed. While the type select differs
 * from the saved type, the script swaps them for the `data-wd-type-changed`
 * note, because saving a type change resets the card.
 *
 * The save handler replaces the whole card section with what this tab posts,
 * so every card key has a control here. Boolean checkboxes post a hidden "0"
 * first; data-field pickers post `"schema_key.field"` (`''` for none).
 *
 * A group directory prints it twice: for `card`, and for its Group Member
 * card (`section` `member_card`, with its own `id_prefix`, `label` and
 * `intro`).
 *
 * @var array{
 *     field: string,
 *     config: Wicket\Directory\Config\DirectoryConfig,
 *     card?: array<string, mixed>,
 *     section?: string,
 *     id_prefix?: string,
 *     label?: string,
 *     intro?: string,
 *     groups: list<list<string>>,
 *     toggles: array<string, array{label: string, description: string}>,
 *     refs: array<string, array{label: string, description: string, selected: string, choices: array<string, array<string, string>>}>,
 *     tag_chips: array<string, array<string, string>>,
 *     tag_values: list<string>,
 *     schemas_failed: bool,
 *     contacts: array<string, array{label: string, noun: string, types: array<string, string>}>,
 *     resource_types_failed: bool,
 *     address_formats: array<string, string>,
 *     org_contact: array{sources: array<string, string>, types: array<string, string>, hints: array<string, string>, toggles: array<string, string>, records: array<string, array{label: string, noun: string, types: array<string, string>}>}|null,
 * } $args
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$card = $args['card'] ?? $args['config']->card;
$name = $args['field'] . '[' . ($args['section'] ?? 'card') . ']';
$id_prefix = $args['id_prefix'] ?? 'wicket-directory-card-';

$chip_picker = static function (array $args): void {
    include __DIR__ . '/chip-picker.php';
};

$contact_rule = static function (array $args): void {
    include __DIR__ . '/contact-rule.php';
};

/**
 * The hint for each combination of a contact rule's flags.
 *
 * @param string $key  Contact field.
 * @param string $noun The field's records, lower case.
 *
 * @return array<string, string> Flag state => hint.
 */
$hints = static function (string $key, string $noun): array {
    if ($key === 'website') {
        return [
            'none'      => __('Shows the first matching website flagged "Show in Directory", else the first matching website. Websites have no primary flag.', 'wicket-directory'),
            'directory' => __('Shows every matching website flagged "Show in Directory", and nothing when none is. Websites have no primary flag.', 'wicket-directory'),
        ];
    }

    return [
        /* translators: %s: Contact record in lower case, e.g. "email". */
        'none'      => sprintf(__('Shows the first matching %s flagged "Show in Directory", else the primary one, else nothing.', 'wicket-directory'), $noun),
        /* translators: %s: Contact record in lower case, e.g. "email". */
        'directory' => sprintf(__('Shows every matching %s flagged "Show in Directory", and nothing when none is.', 'wicket-directory'), $noun),
        /* translators: %s: Contact record in lower case, e.g. "email". */
        'primary'   => sprintf(__('Shows every matching primary %s, and nothing when none is.', 'wicket-directory'), $noun),
        /* translators: %s: Contact record in lower case, e.g. "email". */
        'both'      => sprintf(__('Shows every matching %s that is both primary and flagged "Show in Directory", and nothing when none is.', 'wicket-directory'), $noun),
    ];
};

/**
 * The flag state of a contact rule, as a `data-wd-hint` key.
 *
 * @param array{only_directory: bool, only_primary: bool} $rule Contact rule.
 */
$hint_state = static fn (array $rule): string => match (true) {
    $rule['only_directory'] && $rule['only_primary'] => 'both',
    $rule['only_directory']                          => 'directory',
    $rule['only_primary']                            => 'primary',
    default                                          => 'none',
};
?>
<div class="wicket-directory-card-settings">
    <p class="wd-help" data-wd-type-changed hidden><?php esc_html_e('Save the directory to set up the card for the new type.', 'wicket-directory'); ?></p>
    <div data-wd-type-unchanged>
        <?php if ($args['schemas_failed']) : ?>
            <div class="notice notice-error inline">
                <p><?php esc_html_e('Data fields couldn\'t be loaded from Wicket. Saved data fields are kept.', 'wicket-directory'); ?></p>
            </div>
        <?php endif; ?>
        <?php if ($args['resource_types_failed']) : ?>
            <div class="notice notice-error inline">
                <p><?php esc_html_e('Contact types couldn\'t be loaded from Wicket. Saved types are kept.', 'wicket-directory'); ?></p>
            </div>
        <?php endif; ?>
        <p class="wd-panel__label"><?php echo esc_html($args['label'] ?? __('Card details', 'wicket-directory')); ?></p>
        <p class="wd-help wd-help--intro"><?php echo esc_html($args['intro'] ?? __('Choose what each card shows.', 'wicket-directory')); ?></p>
        <?php foreach ($args['groups'] as $index => $group) : ?>
            <?php if ($index > 0) : ?>
                <hr class="wd-divider">
            <?php endif; ?>
            <?php foreach ($group as $key) : ?>
                <?php $id = $id_prefix . $key; ?>

                <?php if (isset($args['toggles'][$key])) : ?>
                    <?php $toggle = $args['toggles'][$key]; ?>
                    <div class="wd-item">
                        <input type="hidden" name="<?php echo esc_attr($name . '[' . $key . ']'); ?>" value="0">
                        <label class="wd-check">
                            <input type="checkbox" name="<?php echo esc_attr($name . '[' . $key . ']'); ?>" value="1" <?php checked($card[$key]); ?>>
                            <?php echo esc_html($toggle['label']); ?>
                        </label>
                        <?php if ($toggle['description'] !== '') : ?>
                            <p class="wd-help wd-indent"><?php echo esc_html($toggle['description']); ?></p>
                        <?php endif; ?>
                    </div>

                <?php elseif (isset($args['refs'][$key])) : ?>
                    <?php $ref = $args['refs'][$key]; ?>
                    <div class="wd-item" data-wd-item="ref">
                        <label class="wd-check">
                            <input type="checkbox" data-wd-reveal-toggle <?php checked($ref['selected'] !== ''); ?>>
                            <?php echo esc_html($ref['label']); ?>
                        </label>
                        <div class="wd-indent" data-wd-reveal>
                            <label class="wd-sublabel" for="<?php echo esc_attr($id); ?>"><?php esc_html_e('Data field', 'wicket-directory'); ?></label>
                            <select id="<?php echo esc_attr($id); ?>" class="wd-field" name="<?php echo esc_attr($name . '[' . $key . ']'); ?>">
                                <option value="" <?php selected($ref['selected'], ''); ?>><?php esc_html_e('Choose a field…', 'wicket-directory'); ?></option>
                                <?php foreach ($ref['choices'] as $group_label => $choices) : ?>
                                    <optgroup label="<?php echo esc_attr((string) $group_label); ?>">
                                        <?php foreach ($choices as $value => $label) : ?>
                                            <option value="<?php echo esc_attr((string) $value); ?>" <?php selected($ref['selected'], (string) $value); ?>><?php echo esc_html($label); ?></option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endforeach; ?>
                            </select>
                            <p class="wd-help"><?php echo esc_html($ref['description']); ?></p>
                        </div>
                    </div>

                <?php elseif ($key === 'tag_chips') : ?>
                    <div class="wd-item">
                        <p class="wd-sublabel"><?php esc_html_e('Tags', 'wicket-directory'); ?></p>
                        <?php if ($args['tag_chips'] !== []) : ?>
                            <?php
                            $chip_picker([
                                'name'     => $name . '[tag_chips][]',
                                'label'    => __('Tags', 'wicket-directory'),
                                'choices'  => $args['tag_chips'],
                                'selected' => $args['tag_values'],
                            ]);
                            ?>
                        <?php elseif (!$args['schemas_failed']) : ?>
                            <p><?php esc_html_e('No data fields of this type were found.', 'wicket-directory'); ?></p>
                        <?php endif; ?>
                        <p class="wd-help"><?php esc_html_e('Data fields shown as tags under the name. Each value is a tag; choice fields show their labels.', 'wicket-directory'); ?></p>
                    </div>

                <?php elseif (isset($args['contacts'][$key])) : ?>
                    <?php
                    $contact = $args['contacts'][$key];
                    $contact_rule([
                        'key'             => $key,
                        'name'            => $name . '[' . $key . ']',
                        'id'              => $id,
                        'label'           => $contact['label'],
                        /* translators: %s: Contact field, e.g. "Email". */
                        'type_label'      => sprintf(__('%s type', 'wicket-directory'), $contact['label']),
                        'types'           => $contact['types'],
                        'rule'            => $card[$key],
                        'hints'           => $hints($key, $contact['noun']),
                        'state'           => $hint_state($card[$key]),
                        'card_name'       => $name,
                        'address_format'  => $card['address_format'],
                        'address_formats' => $args['address_formats'],
                        'website_label'   => $card['website_label'],
                    ]);
                    ?>

                <?php elseif ($key === 'org_contact' && ($args['org_contact'] ?? null) !== null) : ?>
                    <?php
                    $org_contact = $card['org_contact'];
                    $org_name = $name . '[org_contact]';
                    $org_id = $id_prefix . 'org-contact';
                    ?>
                    <div class="wd-item" data-wd-item="org-contact">
                        <input type="hidden" name="<?php echo esc_attr($org_name); ?>[show]" value="0">
                        <label class="wd-check">
                            <input type="checkbox" name="<?php echo esc_attr($org_name); ?>[show]" value="1" data-wd-reveal-toggle <?php checked($org_contact['show']); ?>>
                            <?php esc_html_e('Organization Contact', 'wicket-directory'); ?>
                        </label>
                        <p class="wd-help wd-indent"><?php esc_html_e('People to contact about the organization, in their own column. Hidden on a card when nobody qualifies.', 'wicket-directory'); ?></p>
                        <div class="wd-indent" data-wd-reveal>
                            <fieldset>
                                <legend class="wd-sublabel"><?php esc_html_e('Show', 'wicket-directory'); ?></legend>
                                <?php foreach ($args['org_contact']['sources'] as $source => $label) : ?>
                                    <label class="wd-check">
                                        <input type="radio" name="<?php echo esc_attr($org_name); ?>[source]" value="<?php echo esc_attr($source); ?>" data-wd-mode <?php checked($org_contact['source'], $source); ?>>
                                        <?php echo esc_html($label); ?>
                                    </label>
                                <?php endforeach; ?>
                            </fieldset>
                            <p class="wd-sublabel"><?php esc_html_e('Relationship types', 'wicket-directory'); ?></p>
                            <?php if ($args['org_contact']['types'] !== []) : ?>
                                <?php
                                $chip_picker([
                                    'name'     => $org_name . '[relationship_types][]',
                                    'label'    => __('Relationship types', 'wicket-directory'),
                                    'choices'  => ['' => $args['org_contact']['types']],
                                    'selected' => $org_contact['relationship_types'],
                                ]);
                                ?>
                            <?php elseif (!$args['resource_types_failed']) : ?>
                                <p><?php esc_html_e('No relationship types were found.', 'wicket-directory'); ?></p>
                            <?php endif; ?>
                            <?php foreach ($args['org_contact']['hints'] as $hint => $text) : ?>
                                <p class="wd-help" data-wd-hint="<?php echo esc_attr($hint); ?>" <?php echo $hint === $org_contact['source'] ? '' : 'hidden'; ?>><?php echo esc_html($text); ?></p>
                            <?php endforeach; ?>
                            <p class="wd-sublabel"><?php esc_html_e('Each contact shows', 'wicket-directory'); ?></p>
                            <?php foreach ($args['org_contact']['toggles'] as $toggle => $label) : ?>
                                <input type="hidden" name="<?php echo esc_attr($org_name . '[' . $toggle . ']'); ?>" value="0">
                                <label class="wd-check">
                                    <input type="checkbox" name="<?php echo esc_attr($org_name . '[' . $toggle . ']'); ?>" value="1" <?php checked($org_contact[$toggle]); ?>>
                                    <?php echo esc_html($label); ?>
                                </label>
                            <?php endforeach; ?>
                            <?php foreach ($args['org_contact']['records'] as $record => $contact) : ?>
                                <?php
                                $contact_rule([
                                    'key'             => $record,
                                    'name'            => $org_name . '[' . $record . ']',
                                    'id'              => $org_id . '-' . $record,
                                    'label'           => $contact['label'],
                                    /* translators: %s: Contact field, e.g. "Email". */
                                    'type_label'      => sprintf(__('%s type', 'wicket-directory'), $contact['label']),
                                    'types'           => $contact['types'],
                                    'rule'            => $org_contact[$record],
                                    'hints'           => $hints($record, $contact['noun']),
                                    'state'           => $hint_state($org_contact[$record]),
                                    'card_name'       => $name,
                                    'address_format'  => $card['address_format'],
                                    'address_formats' => [],
                                    'website_label'   => '',
                                ]);
                                ?>
                            <?php endforeach; ?>
                            <p class="wd-help"><?php esc_html_e('A contact\'s email and phone are their own records, picked with the rules above. Adds one or two API requests per page of results.', 'wicket-directory'); ?></p>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </div>
</div>
