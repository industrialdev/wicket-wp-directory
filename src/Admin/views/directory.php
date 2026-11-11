<?php
/**
 * The Directory panels: type, eligibility and a group directory's member list.
 *
 * Panels that depend on the type carry `data-wd-type` (one type, or several
 * separated by spaces); `assets/js/admin.js` shows them for the selected type.
 * Those that depend on the Default View carry `data-wd-view` the same way.
 * Elements with `data-wd-type-changed` show
 * only while the selected type differs from the saved one, and
 * `data-wd-type-unchanged` ones only while it matches. Without the script the
 * panels show the saved type's settings.
 *
 * The save handler replaces the whole eligibility and roster sections with
 * what these panels post, so an emptied chip picker saves as empty. A boolean checkbox
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
 *     group_types: array<string, string>,
 *     group_types_empty: bool,
 *     group_statuses: array<string, string>,
 *     groups: array<string, string>,
 *     groups_failed: bool,
 *     views: array<string, string>,
 *     roster_groups: array<string, string>,
 *     member_statuses: array<string, string>,
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

/**
 * The `hidden` attribute for a panel of some types, when the saved type isn't one of them.
 *
 * @param list<string> $types Directory type values.
 */
$hidden_unless = static fn (array $types): string => in_array($saved_type, $types, true) ? '' : 'hidden';
$member_types = 'individual organization';
$roster = $config->roster;
$saved_view = $roster['default_view'];

/**
 * The `hidden` attribute for a group panel of some Default Views, when the saved directory doesn't show it.
 *
 * @param list<string> $views Default View values.
 */
$hidden_unless_view = static fn (array $views): string => $saved_type === 'group' && in_array($saved_view, $views, true) ? '' : 'hidden';
$roster_views = Wicket\Directory\Config\DirectoryConfig::VIEW_MEMBERS . ' ' . Wicket\Directory\Config\DirectoryConfig::VIEW_GROUPS_MEMBERS;
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

    <div class="wd-panel" data-wd-type="group" <?php echo $hidden_unless(['group']); ?>>
        <label class="wd-panel__label" for="wicket-directory-default-view"><?php esc_html_e('Default view', 'wicket-directory'); ?></label>
        <select id="wicket-directory-default-view" class="wd-field" name="<?php echo esc_attr($field); ?>[roster][default_view]" data-wd-view-select>
            <?php foreach ($args['views'] as $view => $label) : ?>
                <option value="<?php echo esc_attr($view); ?>" <?php selected($saved_view, $view); ?>><?php echo esc_html($label); ?></option>
            <?php endforeach; ?>
        </select>
        <p class="wd-help"><?php esc_html_e('Group List shows the groups only. Group Members shows the members of one group, chosen below. Group List + Member List shows the groups, and each card\'s View Members button opens that group\'s members on the same page, with a link back.', 'wicket-directory'); ?></p>
    </div>

    <div class="wd-panel" data-wd-type="group" data-wd-view="<?php echo esc_attr(Wicket\Directory\Config\DirectoryConfig::VIEW_MEMBERS); ?>" <?php echo $hidden_unless_view([Wicket\Directory\Config\DirectoryConfig::VIEW_MEMBERS]); ?>>
        <label class="wd-panel__label" for="wicket-directory-roster-group"><?php esc_html_e('Group', 'wicket-directory'); ?></label>
        <div data-wd-type-unchanged>
            <?php if ($args['groups_failed']) : ?>
                <div class="notice notice-error inline">
                    <p><?php esc_html_e('Groups couldn\'t be loaded from Wicket. The saved group is kept.', 'wicket-directory'); ?></p>
                </div>
            <?php endif; ?>
            <select id="wicket-directory-roster-group" class="wd-field" name="<?php echo esc_attr($field); ?>[roster][group]">
                <option value=""><?php esc_html_e('Select a group', 'wicket-directory'); ?></option>
                <?php foreach ($args['roster_groups'] as $uuid => $label) : ?>
                    <option value="<?php echo esc_attr($uuid); ?>" <?php selected($roster['group'], $uuid); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
            <p class="wd-help"><?php esc_html_e('The group whose members the directory shows. Its status, type and the groups to exclude above still apply: a group the directory can\'t list isn\'t shown.', 'wicket-directory'); ?></p>
        </div>
        <p class="wd-help" data-wd-type-changed hidden><?php esc_html_e('Save the directory to choose the group.', 'wicket-directory'); ?></p>
    </div>

    <div class="wd-panel" data-wd-type="group" data-wd-view="<?php echo esc_attr($roster_views); ?>" <?php echo $hidden_unless_view([Wicket\Directory\Config\DirectoryConfig::VIEW_MEMBERS, Wicket\Directory\Config\DirectoryConfig::VIEW_GROUPS_MEMBERS]); ?>>
        <label class="wd-panel__label" for="wicket-directory-member-status"><?php esc_html_e('Group member status', 'wicket-directory'); ?></label>
        <select id="wicket-directory-member-status" class="wd-field" name="<?php echo esc_attr($field); ?>[roster][member_status]">
            <?php foreach ($args['member_statuses'] as $status => $label) : ?>
                <option value="<?php echo esc_attr($status); ?>" <?php selected($roster['member_status'], $status); ?>><?php echo esc_html($label); ?></option>
            <?php endforeach; ?>
        </select>
        <p class="wd-help"><?php esc_html_e('Which members a group\'s member list shows. Inactive lists people whose terms in the group have all ended. The card\'s active member count always counts active members.', 'wicket-directory'); ?></p>
    </div>

    <div class="wd-panel" data-wd-type="group" <?php echo $hidden_unless(['group']); ?>>
        <label class="wd-panel__label" for="wicket-directory-group-status"><?php esc_html_e('Group status', 'wicket-directory'); ?></label>
        <select id="wicket-directory-group-status" class="wd-field" name="<?php echo esc_attr($field); ?>[eligibility][group_status]">
            <?php foreach ($args['group_statuses'] as $status => $label) : ?>
                <option value="<?php echo esc_attr($status); ?>" <?php selected($eligibility['group_status'], $status); ?>><?php echo esc_html($label); ?></option>
            <?php endforeach; ?>
        </select>
        <p class="wd-help"><?php esc_html_e('List only groups with this status. A group that isn\'t listed can\'t be reached through a link either.', 'wicket-directory'); ?></p>
    </div>

    <div class="wd-panel" data-wd-type="group" <?php echo $hidden_unless(['group']); ?>>
        <p class="wd-panel__label"><?php esc_html_e('Group types', 'wicket-directory'); ?></p>
        <?php if ($args['group_types'] !== []) : ?>
            <?php
            $chip_picker([
                'name'     => $field . '[eligibility][group_types][]',
                'label'    => __('Group types', 'wicket-directory'),
                'choices'  => ['' => $args['group_types']],
                'selected' => $eligibility['group_types'],
            ]);
            ?>
        <?php endif; ?>
        <?php if ($args['group_types_empty']) : ?>
            <p><?php esc_html_e('No group types could be loaded from Wicket.', 'wicket-directory'); ?></p>
        <?php endif; ?>
        <p class="wd-help"><?php esc_html_e('List only groups of these types. Leave empty to list every type.', 'wicket-directory'); ?></p>
    </div>

    <div class="wd-panel" data-wd-type="group" <?php echo $hidden_unless(['group']); ?>>
        <p class="wd-panel__label"><?php esc_html_e('Groups which shouldn\'t be displayed', 'wicket-directory'); ?></p>
        <div data-wd-type-unchanged>
            <?php if ($args['groups_failed']) : ?>
                <div class="notice notice-error inline">
                    <p><?php esc_html_e('Groups couldn\'t be loaded from Wicket. Saved groups are kept.', 'wicket-directory'); ?></p>
                </div>
            <?php endif; ?>
            <?php if ($args['groups'] !== []) : ?>
                <?php
                $chip_picker([
                    'name'     => $field . '[eligibility][excluded_groups][]',
                    'label'    => __('Groups which shouldn\'t be displayed', 'wicket-directory'),
                    'choices'  => ['' => $args['groups']],
                    'selected' => $eligibility['excluded_groups'],
                ]);
                ?>
            <?php elseif (!$args['groups_failed'] && $saved_type === 'group') : ?>
                <p><?php esc_html_e('No groups were found.', 'wicket-directory'); ?></p>
            <?php endif; ?>
            <p class="wd-help"><?php esc_html_e('These groups are never listed, whatever their status and type.', 'wicket-directory'); ?></p>
        </div>
        <p class="wd-help" data-wd-type-changed hidden><?php esc_html_e('Save the directory to choose groups to exclude.', 'wicket-directory'); ?></p>
    </div>

    <div class="wd-panel" data-wd-type="organization" <?php echo $hidden_unless(['organization']); ?>>
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

    <div class="wd-panel" data-wd-type="<?php echo esc_attr($member_types); ?>" <?php echo $hidden_unless(['individual', 'organization']); ?>>
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

    <div class="wd-panel" data-wd-type="<?php echo esc_attr($member_types); ?>" <?php echo $hidden_unless(['individual', 'organization']); ?>>
        <p class="wd-panel__label"><?php esc_html_e('Membership', 'wicket-directory'); ?></p>
        <input type="hidden" name="<?php echo esc_attr($field); ?>[eligibility][require_active_membership]" value="0">
        <label class="wd-check">
            <input type="checkbox" name="<?php echo esc_attr($field); ?>[eligibility][require_active_membership]" value="1" <?php checked($eligibility['require_active_membership']); ?>>
            <?php esc_html_e('Only list active members', 'wicket-directory'); ?>
        </label>
    </div>

    <fieldset class="wd-panel" data-wd-type="<?php echo esc_attr($member_types); ?>" <?php echo $hidden_unless(['individual', 'organization']); ?>>
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
