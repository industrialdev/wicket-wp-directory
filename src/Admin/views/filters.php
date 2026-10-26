<?php
/**
 * The Filters meta box: the facets visitors refine results with, in order.
 *
 * Each row posts `facets[i][source|schema_key|field|label]`; the rows' order
 * in the form is the order on the page. The hidden `facets` marker comes
 * first, so a box with every row removed still posts the section and saving
 * clears it. `assets/js/admin.js` adds rows from the `<template>`, moves and
 * removes them, renumbers their names and disables picker choices already in
 * the list. DirectoryConfig drops duplicates whatever is posted.
 *
 * Only the saved type's filters are printed; a type change resets them on save.
 *
 * @var array{
 *     field: string,
 *     rows: list<array{source: string, schema_key: string, field: string, label: string, key: string, name: string, placeholder: string, available: bool}>,
 *     choices: array<string, array<string, array{source: string, schema_key: string, field: string, name: string, default: string, placeholder: string}>>,
 *     schemas_failed: bool,
 * } $args
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$name = $args['field'] . '[facets]';

// The blank row admin.js clones; it fills in the values and renumbers the names.
$template_row = [
    'source'      => '',
    'schema_key'  => '',
    'field'       => '',
    'label'       => '',
    'key'         => '',
    'name'        => '',
    'placeholder' => '',
    'available'   => true,
];

/**
 * Print one facet row.
 *
 * @param string               $name  The facets field name.
 * @param int|string           $index Row index, or '' for the template.
 * @param array<string, mixed> $row   The row's facet, key, name and placeholder.
 */
$print_row = static function (string $name, int|string $index, array $row): void {
    $prefix = $name . '[' . $index . ']';
    ?>
    <tr data-wd-facet-row data-facet-key="<?php echo esc_attr($row['key']); ?>">
        <td>
            <strong data-wd-facet-name><?php echo esc_html($row['name']); ?></strong>
            <?php if (!$row['available']) : ?>
                <br><span class="description"><?php esc_html_e('Not available: this field has no choices in Wicket, so visitors don\'t see this filter.', 'wicket-directory'); ?></span>
            <?php endif; ?>
            <?php foreach (['source', 'schema_key', 'field'] as $part) : ?>
                <input type="hidden" data-wd-facet-input="<?php echo esc_attr($part); ?>" name="<?php echo esc_attr($prefix . '[' . $part . ']'); ?>" value="<?php echo esc_attr($row[$part]); ?>">
            <?php endforeach; ?>
        </td>
        <td>
            <label class="screen-reader-text" for="wicket-directory-facet-<?php echo esc_attr($row['key']); ?>"><?php esc_html_e('Label', 'wicket-directory'); ?></label>
            <input type="text" id="wicket-directory-facet-<?php echo esc_attr($row['key']); ?>" class="regular-text" data-wd-facet-input="label" name="<?php echo esc_attr($prefix . '[label]'); ?>" value="<?php echo esc_attr($row['label']); ?>" placeholder="<?php echo esc_attr($row['placeholder']); ?>">
        </td>
        <td>
            <button type="button" class="button button-small" data-wd-facet-move="-1"><?php esc_html_e('Move up', 'wicket-directory'); ?></button>
            <button type="button" class="button button-small" data-wd-facet-move="1"><?php esc_html_e('Move down', 'wicket-directory'); ?></button>
            <button type="button" class="button button-small button-link-delete" data-wd-facet-remove><?php esc_html_e('Remove', 'wicket-directory'); ?></button>
        </td>
    </tr>
    <?php
};
?>
<div class="wicket-directory-filter-settings" data-wicket-directory-filters data-name="<?php echo esc_attr($name); ?>">
    <p class="description" data-wd-type-changed hidden><?php esc_html_e('Save the directory to set up filters for the new type.', 'wicket-directory'); ?></p>
    <div data-wd-type-unchanged>
        <?php if ($args['schemas_failed']) : ?>
            <div class="notice notice-error inline">
                <p><?php esc_html_e('Data fields couldn\'t be loaded from Wicket. Saved filters are kept.', 'wicket-directory'); ?></p>
            </div>
        <?php endif; ?>
        <input type="hidden" name="<?php echo esc_attr($name); ?>" value="">
        <table class="widefat striped">
            <thead>
                <tr>
                    <th scope="col"><?php esc_html_e('Filter', 'wicket-directory'); ?></th>
                    <th scope="col"><?php esc_html_e('Label', 'wicket-directory'); ?></th>
                    <th scope="col"><span class="screen-reader-text"><?php esc_html_e('Actions', 'wicket-directory'); ?></span></th>
                </tr>
            </thead>
            <tbody data-wd-facet-rows>
                <?php foreach ($args['rows'] as $index => $row) : ?>
                    <?php $print_row($name, $index, $row); ?>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p data-wd-facet-empty <?php echo $args['rows'] === [] ? '' : 'hidden'; ?>><?php esc_html_e('No filters yet. Visitors see no Refine Results panel.', 'wicket-directory'); ?></p>
        <p>
            <label for="wicket-directory-facet-choice"><?php esc_html_e('Add a filter', 'wicket-directory'); ?></label>
            <select id="wicket-directory-facet-choice" data-wd-facet-choice>
                <option value=""><?php esc_html_e('Choose a field…', 'wicket-directory'); ?></option>
                <?php foreach ($args['choices'] as $group => $choices) : ?>
                    <optgroup label="<?php echo esc_attr($group); ?>">
                        <?php foreach ($choices as $key => $choice) : ?>
                            <option
                                value="<?php echo esc_attr($key); ?>"
                                data-source="<?php echo esc_attr($choice['source']); ?>"
                                data-schema-key="<?php echo esc_attr($choice['schema_key']); ?>"
                                data-field="<?php echo esc_attr($choice['field']); ?>"
                                data-name="<?php echo esc_attr($choice['name']); ?>"
                                data-default="<?php echo esc_attr($choice['default']); ?>"
                                data-placeholder="<?php echo esc_attr($choice['placeholder']); ?>"
                                <?php disabled(in_array($key, array_column($args['rows'], 'key'), true)); ?>
                            ><?php echo esc_html($choice['default']); ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endforeach; ?>
            </select>
            <button type="button" class="button" data-wd-facet-add><?php esc_html_e('Add filter', 'wicket-directory'); ?></button>
        </p>
        <template data-wd-facet-template>
            <?php $print_row($name, '', $template_row); ?>
        </template>
        <p class="description"><?php esc_html_e('Filters appear in Refine Results in this order, with these labels; an empty label shows the field\'s key. Only fields with a fixed list of choices can be filters, and each can be added once.', 'wicket-directory'); ?></p>
        <p class="description"><?php esc_html_e('Whether the keyword search, location search, filters and Order by are shown is set on each Wicket Directory block, not here.', 'wicket-directory'); ?></p>
    </div>
</div>
