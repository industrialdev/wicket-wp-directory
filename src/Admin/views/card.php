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
 * - Contact rules reveal their type, flags and hint. The script shows the hint
 *   (`data-wd-hint`) that matches the ticked flags.
 *
 * Only the saved type's settings are printed. While the type select differs
 * from the saved type, the script swaps them for the `data-wd-type-changed`
 * note, because saving a type change resets the card.
 *
 * The save handler replaces the whole card section with what this tab posts,
 * so every card key has a control here. Boolean checkboxes post a hidden "0"
 * first; data-field pickers post `"schema_key.field"` (`''` for none).
 *
 * @var array{
 *     field: string,
 *     config: Wicket\Directory\Config\DirectoryConfig,
 *     groups: list<list<string>>,
 *     toggles: array<string, array{label: string, description: string}>,
 *     refs: array<string, array{label: string, description: string, selected: string, choices: array<string, array<string, string>>}>,
 *     tag_chips: array<string, array<string, string>>,
 *     tag_values: list<string>,
 *     schemas_failed: bool,
 *     contacts: array<string, array{label: string, noun: string, types: array<string, string>}>,
 *     resource_types_failed: bool,
 *     address_formats: array<string, string>,
 * } $args
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$card = $args['config']->card;
$name = $args['field'] . '[card]';

$chip_picker = static function (array $args): void {
    include __DIR__ . '/chip-picker.php';
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
        <p class="wd-panel__label"><?php esc_html_e('Card details', 'wicket-directory'); ?></p>
        <p class="wd-help wd-help--intro"><?php esc_html_e('Choose what each card shows.', 'wicket-directory'); ?></p>
        <?php foreach ($args['groups'] as $index => $group) : ?>
            <?php if ($index > 0) : ?>
                <hr class="wd-divider">
            <?php endif; ?>
            <?php foreach ($group as $key) : ?>
                <?php $id = 'wicket-directory-card-' . $key; ?>

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
                    $rule = $card[$key];
                    $rule_name = $name . '[' . $key . ']';
                    $state = $hint_state($rule);
                    /* translators: %s: Contact field, e.g. "Email". */
                    $type_label = sprintf(__('%s type', 'wicket-directory'), $contact['label']);
                    ?>
                    <div class="wd-item" data-wd-item="contact">
                        <input type="hidden" name="<?php echo esc_attr($rule_name); ?>[show]" value="0">
                        <label class="wd-check">
                            <input type="checkbox" name="<?php echo esc_attr($rule_name); ?>[show]" value="1" data-wd-reveal-toggle <?php checked($rule['show']); ?>>
                            <?php echo esc_html($contact['label']); ?>
                        </label>
                        <div class="wd-indent" data-wd-reveal>
                            <?php if ($key === 'address') : ?>
                                <fieldset>
                                    <legend class="wd-sublabel"><?php esc_html_e('Format', 'wicket-directory'); ?></legend>
                                    <?php foreach ($args['address_formats'] as $format => $label) : ?>
                                        <label class="wd-check">
                                            <input type="radio" name="<?php echo esc_attr($name); ?>[address_format]" value="<?php echo esc_attr($format); ?>" <?php checked($card['address_format'], $format); ?>>
                                            <?php echo esc_html($label); ?>
                                        </label>
                                    <?php endforeach; ?>
                                </fieldset>
                            <?php endif; ?>
                            <label class="wd-sublabel" for="<?php echo esc_attr($id); ?>-type"><?php echo esc_html($type_label); ?></label>
                            <select id="<?php echo esc_attr($id); ?>-type" class="wd-field" name="<?php echo esc_attr($rule_name); ?>[type]">
                                <option value="any" <?php selected($rule['type'], 'any'); ?>><?php esc_html_e('Any type', 'wicket-directory'); ?></option>
                                <?php foreach ($contact['types'] as $slug => $label) : ?>
                                    <option value="<?php echo esc_attr((string) $slug); ?>" <?php selected($rule['type'], (string) $slug); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="hidden" name="<?php echo esc_attr($rule_name); ?>[only_directory]" value="0">
                            <label class="wd-check">
                                <input type="checkbox" name="<?php echo esc_attr($rule_name); ?>[only_directory]" value="1" data-wd-flag="directory" <?php checked($rule['only_directory']); ?>>
                                <?php esc_html_e('Only show if flagged "Show in Directory"', 'wicket-directory'); ?>
                            </label>
                            <?php if ($key !== 'website') : ?>
                                <input type="hidden" name="<?php echo esc_attr($rule_name); ?>[only_primary]" value="0">
                                <label class="wd-check">
                                    <input type="checkbox" name="<?php echo esc_attr($rule_name); ?>[only_primary]" value="1" data-wd-flag="primary" <?php checked($rule['only_primary']); ?>>
                                    <?php esc_html_e('Only show if primary', 'wicket-directory'); ?>
                                </label>
                            <?php endif; ?>
                            <?php foreach ($hints($key, $contact['noun']) as $hint => $text) : ?>
                                <p class="wd-help" data-wd-hint="<?php echo esc_attr($hint); ?>" <?php echo $hint === $state ? '' : 'hidden'; ?>><?php echo esc_html($text); ?></p>
                            <?php endforeach; ?>
                            <?php if ($key === 'website') : ?>
                                <label class="wd-sublabel" for="<?php echo esc_attr($id); ?>-label"><?php esc_html_e('Button label', 'wicket-directory'); ?></label>
                                <input type="text" id="<?php echo esc_attr($id); ?>-label" class="wd-field" name="<?php echo esc_attr($name); ?>[website_label]" value="<?php echo esc_attr($card['website_label']); ?>" placeholder="<?php esc_attr_e('Visit Website', 'wicket-directory'); ?>">
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </div>
</div>
