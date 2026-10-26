<?php
/**
 * The Directory panels: type and eligibility.
 *
 * Panels that depend on the type carry `data-wd-type`; `assets/js/admin.js`
 * shows them for the selected type. Elements with `data-wd-type-changed` show
 * only while the selected type differs from the saved one, and
 * `data-wd-type-unchanged` ones only while it matches. Without the script the
 * panels show the saved type's settings.
 *
 * The save handler replaces the whole eligibility section with what these
 * panels post, so an emptied chip picker saves as empty. A boolean checkbox
 * needs its hidden "0", because DirectoryConfig keeps the default for a
 * missing one.
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

$chip_picker = static function (array $args): void {
    include __DIR__ . '/chip-picker.php';
};
?>
<div class="wicket-directory-settings" data-wicket-directory-settings data-saved-type="<?php echo esc_attr($saved_type); ?>">
    <div class="wd-panel">
        <label class="wd-panel__label" for="wicket-directory-type"><?php esc_html_e('Type', 'wicket-directory'); ?></label>
        <select id="wicket-directory-type" class="wd-field" name="<?php echo esc_attr($field); ?>[type]">
            <?php foreach ($args['types'] as $type) : ?>
                <option value="<?php echo esc_attr($type->value); ?>" <?php selected($saved_type, $type->value); ?>><?php echo esc_html($type->label()); ?></option>
            <?php endforeach; ?>
        </select>
        <p class="wd-help"><?php esc_html_e('Changing the type resets the card settings, the filters and the membership tiers when you save.', 'wicket-directory'); ?></p>
        <div class="notice notice-warning inline" data-wd-type-changed hidden>
            <p><?php esc_html_e('The type has changed. Saving resets the card settings, the filters and the membership tiers to the new type\'s defaults.', 'wicket-directory'); ?></p>
        </div>
    </div>

    <div class="wd-panel" data-wd-type="organization" <?php echo $saved_type === 'organization' ? '' : 'hidden'; ?>>
        <p class="wd-panel__label"><?php esc_html_e('Organization types', 'wicket-directory'); ?></p>
        <?php if ($args['org_types'] !== []) : ?>
            <?php
            $chip_picker([
                'name'     => $field . '[eligibility][org_types][]',
                'label'    => __('Organization types', 'wicket-directory'),
                'choices'  => ['' => $args['org_types']],
                'selected' => $eligibility['org_types'],
            ]);
            ?>
        <?php endif; ?>
        <?php if ($args['org_types_empty']) : ?>
            <p><?php esc_html_e('No organization types could be loaded from Wicket.', 'wicket-directory'); ?></p>
        <?php endif; ?>
        <p class="wd-help"><?php esc_html_e('List only organizations of these types. Leave empty to list every type.', 'wicket-directory'); ?></p>
    </div>

    <div class="wd-panel">
        <p class="wd-panel__label"><?php esc_html_e('Membership tiers', 'wicket-directory'); ?></p>
        <div data-wd-type-unchanged>
            <?php if ($args['tiers_failed']) : ?>
                <div class="notice notice-error inline">
                    <p><?php esc_html_e('Membership tiers couldn\'t be loaded from Wicket. Saved tiers are kept.', 'wicket-directory'); ?></p>
                </div>
            <?php endif; ?>
            <?php if ($args['tiers'] !== []) : ?>
                <?php
                $chip_picker([
                    'name'     => $field . '[eligibility][membership_ids][]',
                    'label'    => __('Membership tiers', 'wicket-directory'),
                    'choices'  => ['' => $args['tiers']],
                    'selected' => $eligibility['membership_ids'],
                ]);
                ?>
            <?php elseif (!$args['tiers_failed']) : ?>
                <p><?php esc_html_e('No membership tiers of this type were found.', 'wicket-directory'); ?></p>
            <?php endif; ?>
            <p class="wd-help"><?php esc_html_e('List only members of these tiers. Leave empty to list members of any tier.', 'wicket-directory'); ?></p>
        </div>
        <p class="wd-help" data-wd-type-changed hidden><?php esc_html_e('Save the directory to choose membership tiers for the new type.', 'wicket-directory'); ?></p>
    </div>

    <div class="wd-panel">
        <p class="wd-panel__label"><?php esc_html_e('Membership', 'wicket-directory'); ?></p>
        <input type="hidden" name="<?php echo esc_attr($field); ?>[eligibility][require_active_membership]" value="0">
        <label class="wd-check">
            <input type="checkbox" name="<?php echo esc_attr($field); ?>[eligibility][require_active_membership]" value="1" <?php checked($eligibility['require_active_membership']); ?>>
            <?php esc_html_e('Only list active members', 'wicket-directory'); ?>
        </label>
    </div>

    <fieldset class="wd-panel">
        <legend class="wd-panel__label"><?php esc_html_e('Opt-in data field', 'wicket-directory'); ?></legend>
        <div class="wd-inline-fields">
            <p>
                <label class="wd-sublabel" for="wicket-directory-opt-in-schema"><?php esc_html_e('Schema key', 'wicket-directory'); ?></label>
                <input type="text" id="wicket-directory-opt-in-schema" class="wd-field code" name="<?php echo esc_attr($field); ?>[eligibility][opt_in][schema_key]" value="<?php echo esc_attr($opt_in['schema_key']); ?>" placeholder="orgmemberdir">
            </p>
            <p>
                <label class="wd-sublabel" for="wicket-directory-opt-in-field"><?php esc_html_e('Field', 'wicket-directory'); ?></label>
                <input type="text" id="wicket-directory-opt-in-field" class="wd-field code" name="<?php echo esc_attr($field); ?>[eligibility][opt_in][field]" value="<?php echo esc_attr($opt_in['field']); ?>" placeholder="optin">
            </p>
            <p>
                <label class="wd-sublabel" for="wicket-directory-opt-in-value"><?php esc_html_e('Value', 'wicket-directory'); ?></label>
                <input type="text" id="wicket-directory-opt-in-value" class="wd-field code" name="<?php echo esc_attr($field); ?>[eligibility][opt_in][value]" value="<?php echo esc_attr((string) $opt_in_value); ?>" placeholder="true">
            </p>
        </div>
        <p class="wd-help"><?php esc_html_e('List only records whose data field has this value, e.g. schema key "orgmemberdir", field "optin", value "true". Use letters, numbers, "_" and "-" only. Leave any of the three empty to turn the opt-in off.', 'wicket-directory'); ?></p>
    </fieldset>
</div>
