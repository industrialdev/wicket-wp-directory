<?php
/**
 * The Card meta box: what each card in the directory shows.
 *
 * Only the saved type's settings are printed. While the Directory box's type
 * radio differs from the saved type, `assets/js/admin.js` swaps them for the
 * `data-wd-type-changed` note, because saving a type change resets the card.
 *
 * The save handler replaces the whole card section with what this box posts,
 * so every card key has a control here. Boolean checkboxes post a hidden "0"
 * first; data-field pickers post `"schema_key.field"` (`''` for none).
 *
 * @var array{
 *     field: string,
 *     config: Wicket\Directory\Config\DirectoryConfig,
 *     toggles: array<string, array{label: string, description: string}>,
 *     refs: array<string, array{label: string, description: string, selected: string, choices: array<string, array<string, string>>}>,
 *     tag_chips: array<string, array<string, string>>,
 *     tag_values: list<string>,
 *     schemas_failed: bool,
 *     contacts: array<string, array{label: string, types: array<string, string>}>,
 *     resource_types_failed: bool,
 *     address_formats: array<string, string>,
 * } $args
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$card = $args['config']->card;
$name = $args['field'] . '[card]';
?>
<div class="wicket-directory-card-settings">
    <p class="description" data-wd-type-changed hidden><?php esc_html_e('Save the directory to set up the card for the new type.', 'wicket-directory'); ?></p>
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
        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row"><?php esc_html_e('Show', 'wicket-directory'); ?></th>
                    <td>
                        <fieldset>
                            <legend class="screen-reader-text"><?php esc_html_e('Show', 'wicket-directory'); ?></legend>
                            <?php foreach ($args['toggles'] as $key => $toggle) : ?>
                                <input type="hidden" name="<?php echo esc_attr($name . '[' . $key . ']'); ?>" value="0">
                                <label>
                                    <input type="checkbox" name="<?php echo esc_attr($name . '[' . $key . ']'); ?>" value="1" <?php checked($card[$key]); ?>>
                                    <?php echo esc_html($toggle['label']); ?>
                                </label>
                                <?php if ($toggle['description'] !== '') : ?>
                                    <span class="description"><?php echo esc_html($toggle['description']); ?></span>
                                <?php endif; ?>
                                <br>
                            <?php endforeach; ?>
                        </fieldset>
                    </td>
                </tr>
                <?php foreach ($args['refs'] as $key => $ref) : ?>
                    <tr>
                        <th scope="row">
                            <label for="wicket-directory-card-<?php echo esc_attr($key); ?>"><?php echo esc_html($ref['label']); ?></label>
                        </th>
                        <td>
                            <select id="wicket-directory-card-<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($name . '[' . $key . ']'); ?>">
                                <option value="" <?php selected($ref['selected'], ''); ?>><?php esc_html_e('None', 'wicket-directory'); ?></option>
                                <?php foreach ($ref['choices'] as $group => $choices) : ?>
                                    <optgroup label="<?php echo esc_attr($group); ?>">
                                        <?php foreach ($choices as $value => $label) : ?>
                                            <option value="<?php echo esc_attr($value); ?>" <?php selected($ref['selected'], $value); ?>><?php echo esc_html($label); ?></option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php echo esc_html($ref['description']); ?></p>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <tr>
                    <th scope="row"><?php esc_html_e('Tags', 'wicket-directory'); ?></th>
                    <td>
                        <?php if ($args['tag_chips'] !== []) : ?>
                            <fieldset class="wp-tab-panel">
                                <legend class="screen-reader-text"><?php esc_html_e('Tags', 'wicket-directory'); ?></legend>
                                <?php foreach ($args['tag_chips'] as $group => $choices) : ?>
                                    <strong><?php echo esc_html($group); ?></strong><br>
                                    <?php foreach ($choices as $value => $label) : ?>
                                        <label>
                                            <input type="checkbox" name="<?php echo esc_attr($name); ?>[tag_chips][]" value="<?php echo esc_attr($value); ?>" <?php checked(in_array($value, $args['tag_values'], true)); ?>>
                                            <?php echo esc_html($label); ?>
                                        </label><br>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>
                            </fieldset>
                        <?php elseif (!$args['schemas_failed']) : ?>
                            <p><?php esc_html_e('No data fields of this type were found.', 'wicket-directory'); ?></p>
                        <?php endif; ?>
                        <p class="description"><?php esc_html_e('Data fields shown as tags under the name. Each value is a tag; choice fields show their labels.', 'wicket-directory'); ?></p>
                    </td>
                </tr>
                <?php foreach ($args['contacts'] as $key => $contact) : ?>
                    <?php
                    $rule = $card[$key];
                    $id = 'wicket-directory-card-' . $key;
                    $rule_name = $name . '[' . $key . ']';
                    ?>
                    <tr>
                        <th scope="row"><?php echo esc_html($contact['label']); ?></th>
                        <td>
                            <fieldset>
                                <legend class="screen-reader-text"><?php echo esc_html($contact['label']); ?></legend>
                                <input type="hidden" name="<?php echo esc_attr($rule_name); ?>[show]" value="0">
                                <label>
                                    <input type="checkbox" name="<?php echo esc_attr($rule_name); ?>[show]" value="1" <?php checked($rule['show']); ?>>
                                    <?php esc_html_e('Show on the card', 'wicket-directory'); ?>
                                </label>
                                <p>
                                    <label for="<?php echo esc_attr($id); ?>-type"><?php esc_html_e('Type', 'wicket-directory'); ?></label>
                                    <select id="<?php echo esc_attr($id); ?>-type" name="<?php echo esc_attr($rule_name); ?>[type]">
                                        <option value="any" <?php selected($rule['type'], 'any'); ?>><?php esc_html_e('Any', 'wicket-directory'); ?></option>
                                        <?php foreach ($contact['types'] as $slug => $label) : ?>
                                            <option value="<?php echo esc_attr($slug); ?>" <?php selected($rule['type'], $slug); ?>><?php echo esc_html($label); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </p>
                                <input type="hidden" name="<?php echo esc_attr($rule_name); ?>[only_directory]" value="0">
                                <label>
                                    <input type="checkbox" name="<?php echo esc_attr($rule_name); ?>[only_directory]" value="1" <?php checked($rule['only_directory']); ?>>
                                    <?php esc_html_e('Only show if flagged "Show in Directory"', 'wicket-directory'); ?>
                                </label><br>
                                <?php if ($key !== 'website') : ?>
                                    <input type="hidden" name="<?php echo esc_attr($rule_name); ?>[only_primary]" value="0">
                                    <label>
                                        <input type="checkbox" name="<?php echo esc_attr($rule_name); ?>[only_primary]" value="1" <?php checked($rule['only_primary']); ?>>
                                        <?php esc_html_e('Only show if primary', 'wicket-directory'); ?>
                                    </label><br>
                                <?php endif; ?>
                            </fieldset>
                            <p class="description">
                                <?php if ($key === 'website') : ?>
                                    <?php esc_html_e('With the box unticked, the card shows the first website of this type flagged "Show in Directory", else the first website of this type. Ticked, it shows every website of this type flagged "Show in Directory", or none. Websites have no primary flag.', 'wicket-directory'); ?>
                                <?php else : ?>
                                    <?php esc_html_e('With both boxes unticked, the card shows the first record of this type flagged "Show in Directory", else the primary one, else nothing. With a box ticked, it shows every record of this type that has the ticked flags (both, when both are ticked), and nothing when none do.', 'wicket-directory'); ?>
                                <?php endif; ?>
                            </p>
                            <?php if ($key === 'address') : ?>
                                <fieldset>
                                    <legend><?php esc_html_e('Address format', 'wicket-directory'); ?></legend>
                                    <?php foreach ($args['address_formats'] as $format => $label) : ?>
                                        <label>
                                            <input type="radio" name="<?php echo esc_attr($name); ?>[address_format]" value="<?php echo esc_attr($format); ?>" <?php checked($card['address_format'], $format); ?>>
                                            <?php echo esc_html($label); ?>
                                        </label><br>
                                    <?php endforeach; ?>
                                </fieldset>
                            <?php endif; ?>
                            <?php if ($key === 'website') : ?>
                                <p>
                                    <label for="<?php echo esc_attr($id); ?>-label"><?php esc_html_e('Button label', 'wicket-directory'); ?></label><br>
                                    <input type="text" id="<?php echo esc_attr($id); ?>-label" class="regular-text" name="<?php echo esc_attr($name); ?>[website_label]" value="<?php echo esc_attr($card['website_label']); ?>" placeholder="<?php esc_attr_e('Visit Website', 'wicket-directory'); ?>">
                                </p>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
