<?php
/**
 * The Directory meta box: type and eligibility.
 *
 * Rows that depend on the type carry `data-wd-type`; `assets/js/admin.js`
 * shows them for the selected type. Elements with `data-wd-type-changed` show
 * only while the selected type differs from the saved one, and
 * `data-wd-type-unchanged` ones only while it matches. Without the script the
 * box shows the saved type's rows.
 *
 * The save handler replaces the whole eligibility section with what this box
 * posts, so an unticked list saves as empty. A boolean checkbox needs its
 * hidden "0", because DirectoryConfig keeps the default for a missing one.
 *
 * @var array{
 *     field: string,
 *     config: Wicket\Directory\Config\DirectoryConfig,
 *     types: list<Wicket\Directory\Config\DirectoryType>,
 *     tiers: array<string, string>,
 *     tiers_failed: bool,
 *     org_types: array<string, string>,
 *     org_types_empty: bool,
 * } $args
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$config = $args['config'];
$eligibility = $config->eligibility;
$field = $args['field'];
$saved_type = $config->type->value;
$opt_in = $eligibility['opt_in'] ?? ['schema_key' => '', 'field' => '', 'value' => ''];
$opt_in_value = is_bool($opt_in['value']) ? ($opt_in['value'] ? 'true' : 'false') : $opt_in['value'];
?>
<div class="wicket-directory-settings" data-wicket-directory-settings data-saved-type="<?php echo esc_attr($saved_type); ?>">
    <table class="form-table" role="presentation">
        <tbody>
            <tr>
                <th scope="row"><?php esc_html_e('Type', 'wicket-directory'); ?></th>
                <td>
                    <fieldset>
                        <legend class="screen-reader-text"><?php esc_html_e('Type', 'wicket-directory'); ?></legend>
                        <?php foreach ($args['types'] as $type) : ?>
                            <label>
                                <input type="radio" name="<?php echo esc_attr($field); ?>[type]" value="<?php echo esc_attr($type->value); ?>" <?php checked($saved_type, $type->value); ?>>
                                <?php echo esc_html($type->label()); ?>
                            </label><br>
                        <?php endforeach; ?>
                    </fieldset>
                    <p class="description"><?php esc_html_e('Changing the type resets the card settings, the filters and the membership tiers when you save.', 'wicket-directory'); ?></p>
                    <div class="notice notice-warning inline" data-wd-type-changed hidden>
                        <p><?php esc_html_e('The type has changed. Saving resets the card settings, the filters and the membership tiers to the new type\'s defaults.', 'wicket-directory'); ?></p>
                    </div>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Membership', 'wicket-directory'); ?></th>
                <td>
                    <input type="hidden" name="<?php echo esc_attr($field); ?>[eligibility][require_active_membership]" value="0">
                    <label>
                        <input type="checkbox" name="<?php echo esc_attr($field); ?>[eligibility][require_active_membership]" value="1" <?php checked($eligibility['require_active_membership']); ?>>
                        <?php esc_html_e('Only list active members', 'wicket-directory'); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Membership tiers', 'wicket-directory'); ?></th>
                <td>
                    <div data-wd-type-unchanged>
                        <?php if ($args['tiers_failed']) : ?>
                            <div class="notice notice-error inline">
                                <p><?php esc_html_e('Membership tiers couldn\'t be loaded from Wicket. Saved tiers are kept.', 'wicket-directory'); ?></p>
                            </div>
                        <?php endif; ?>
                        <?php if ($args['tiers'] !== []) : ?>
                            <fieldset class="wp-tab-panel">
                                <legend class="screen-reader-text"><?php esc_html_e('Membership tiers', 'wicket-directory'); ?></legend>
                                <?php foreach ($args['tiers'] as $id => $label) : ?>
                                    <label>
                                        <input type="checkbox" name="<?php echo esc_attr($field); ?>[eligibility][membership_ids][]" value="<?php echo esc_attr($id); ?>" <?php checked(in_array($id, $eligibility['membership_ids'], true)); ?>>
                                        <?php echo esc_html($label); ?>
                                    </label><br>
                                <?php endforeach; ?>
                            </fieldset>
                        <?php elseif (!$args['tiers_failed']) : ?>
                            <p><?php esc_html_e('No membership tiers of this type were found.', 'wicket-directory'); ?></p>
                        <?php endif; ?>
                        <p class="description"><?php esc_html_e('List only members of these tiers. Leave all unticked to list members of any tier.', 'wicket-directory'); ?></p>
                    </div>
                    <p class="description" data-wd-type-changed hidden><?php esc_html_e('Save the directory to choose membership tiers for the new type.', 'wicket-directory'); ?></p>
                </td>
            </tr>
            <tr data-wd-type="organization" <?php echo $saved_type === 'organization' ? '' : 'hidden'; ?>>
                <th scope="row"><?php esc_html_e('Organization types', 'wicket-directory'); ?></th>
                <td>
                    <?php if ($args['org_types'] !== []) : ?>
                        <fieldset class="wp-tab-panel">
                            <legend class="screen-reader-text"><?php esc_html_e('Organization types', 'wicket-directory'); ?></legend>
                            <?php foreach ($args['org_types'] as $slug => $label) : ?>
                                <label>
                                    <input type="checkbox" name="<?php echo esc_attr($field); ?>[eligibility][org_types][]" value="<?php echo esc_attr($slug); ?>" <?php checked(in_array($slug, $eligibility['org_types'], true)); ?>>
                                    <?php echo esc_html($label); ?>
                                </label><br>
                            <?php endforeach; ?>
                        </fieldset>
                    <?php endif; ?>
                    <?php if ($args['org_types_empty']) : ?>
                        <p><?php esc_html_e('No organization types could be loaded from Wicket.', 'wicket-directory'); ?></p>
                    <?php endif; ?>
                    <p class="description"><?php esc_html_e('List only organizations of these types. Leave all unticked to list every type.', 'wicket-directory'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Opt-in data field', 'wicket-directory'); ?></th>
                <td>
                    <fieldset>
                        <legend class="screen-reader-text"><?php esc_html_e('Opt-in data field', 'wicket-directory'); ?></legend>
                        <p>
                            <label for="wicket-directory-opt-in-schema"><?php esc_html_e('Schema key', 'wicket-directory'); ?></label><br>
                            <input type="text" id="wicket-directory-opt-in-schema" class="regular-text code" name="<?php echo esc_attr($field); ?>[eligibility][opt_in][schema_key]" value="<?php echo esc_attr($opt_in['schema_key']); ?>" placeholder="orgmemberdir">
                        </p>
                        <p>
                            <label for="wicket-directory-opt-in-field"><?php esc_html_e('Field', 'wicket-directory'); ?></label><br>
                            <input type="text" id="wicket-directory-opt-in-field" class="regular-text code" name="<?php echo esc_attr($field); ?>[eligibility][opt_in][field]" value="<?php echo esc_attr($opt_in['field']); ?>" placeholder="optin">
                        </p>
                        <p>
                            <label for="wicket-directory-opt-in-value"><?php esc_html_e('Value', 'wicket-directory'); ?></label><br>
                            <input type="text" id="wicket-directory-opt-in-value" class="regular-text code" name="<?php echo esc_attr($field); ?>[eligibility][opt_in][value]" value="<?php echo esc_attr((string) $opt_in_value); ?>" placeholder="true">
                        </p>
                    </fieldset>
                    <p class="description"><?php esc_html_e('List only records whose data field has this value, e.g. schema key "orgmemberdir", field "optin", value "true". Use letters, numbers, "_" and "-" only. Leave any of the three empty to turn the opt-in off.', 'wicket-directory'); ?></p>
                </td>
            </tr>
        </tbody>
    </table>
</div>
