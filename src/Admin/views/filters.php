<?php
/**
 * The Filters tab: the facets visitors refine results with, in order.
 *
 * Each row posts `facets[i][source|schema_key|field|label]`; the rows' order
 * in the form is the order on the page. The hidden `facets` marker comes
 * first, so a tab with every row removed still posts the section and saving
 * clears it. `assets/js/admin.js` adds rows from the `<template>` when a field
 * is picked, moves and removes them, renumbers their names, filters the picker
 * by its search box and disables picker choices already in the list.
 * DirectoryConfig drops duplicates whatever is posted.
 *
 * Only the saved type's filters are printed; a type change resets them on save.
 *
 * @var array{
 *     field: string,
 *     rows: list<array{source: string, schema_key: string, field: string, label: string, key: string, name: string, placeholder: string, available: bool, code: string}>,
 *     choices: array<string, array<string, array{source: string, schema_key: string, field: string, name: string, default: string, placeholder: string, code: string}>>,
 *     schemas_failed: bool,
 * } $args
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$name = $args['field'] . '[facets]';
$keys = array_column($args['rows'], 'key');

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
    'code'        => '',
];

/**
 * Print one facet row.
 *
 * @param string               $name  The facets field name.
 * @param int|string           $index Row index, or '' for the template.
 * @param array<string, mixed> $row   The row's facet, key, name, code and placeholder.
 * @param bool                 $first Whether it's the first row (no Move up).
 * @param bool                 $last  Whether it's the last row (no Move down).
 */
$print_row = static function (string $name, int|string $index, array $row, bool $first = false, bool $last = false): void {
    $prefix = $name . '[' . $index . ']';
    ?>
    <div class="wd-facet" data-wd-facet-row data-facet-key="<?php echo esc_attr($row['key']); ?>">
        <div class="wd-facet__move">
            <button type="button" class="wd-icon-button" data-wd-facet-move="-1" <?php disabled($first); ?>>
                <span class="dashicons dashicons-arrow-up-alt2" aria-hidden="true"></span>
                <span class="screen-reader-text"><?php esc_html_e('Move up', 'wicket-directory'); ?></span>
            </button>
            <button type="button" class="wd-icon-button" data-wd-facet-move="1" <?php disabled($last); ?>>
                <span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
                <span class="screen-reader-text"><?php esc_html_e('Move down', 'wicket-directory'); ?></span>
            </button>
        </div>
        <div class="wd-facet__field">
            <span class="wd-caption"><?php esc_html_e('Field', 'wicket-directory'); ?></span>
            <strong data-wd-facet-name><?php echo esc_html($row['name']); ?></strong>
            <code class="wd-code" data-wd-facet-code><?php echo esc_html($row['code']); ?></code>
            <?php if (!$row['available']) : ?>
                <span class="wd-help"><?php esc_html_e('Not available: this field has no choices in Wicket, so visitors don\'t see this filter.', 'wicket-directory'); ?></span>
            <?php endif; ?>
            <?php foreach (['source', 'schema_key', 'field'] as $part) : ?>
                <input type="hidden" data-wd-facet-input="<?php echo esc_attr($part); ?>" name="<?php echo esc_attr($prefix . '[' . $part . ']'); ?>" value="<?php echo esc_attr($row[$part]); ?>">
            <?php endforeach; ?>
        </div>
        <div class="wd-facet__label">
            <label class="wd-caption" for="wicket-directory-facet-<?php echo esc_attr($row['key']); ?>"><?php esc_html_e('Label', 'wicket-directory'); ?></label>
            <input type="text" id="wicket-directory-facet-<?php echo esc_attr($row['key']); ?>" class="wd-field" data-wd-facet-input="label" name="<?php echo esc_attr($prefix . '[label]'); ?>" value="<?php echo esc_attr($row['label']); ?>" placeholder="<?php echo esc_attr($row['placeholder']); ?>">
        </div>
        <button type="button" class="wd-icon-button wd-icon-button--delete" data-wd-facet-remove>
            <span class="dashicons dashicons-trash" aria-hidden="true"></span>
            <span class="screen-reader-text"><?php esc_html_e('Remove', 'wicket-directory'); ?></span>
        </button>
    </div>
    <?php
};
?>
<div class="wicket-directory-filter-settings" data-wicket-directory-filters data-name="<?php echo esc_attr($name); ?>">
    <p class="wd-help" data-wd-type-changed hidden><?php esc_html_e('Save the directory to set up filters for the new type.', 'wicket-directory'); ?></p>
    <div data-wd-type-unchanged>
        <?php if ($args['schemas_failed']) : ?>
            <div class="notice notice-error inline">
                <p><?php esc_html_e('Data fields couldn\'t be loaded from Wicket. Saved filters are kept.', 'wicket-directory'); ?></p>
            </div>
        <?php endif; ?>
        <p class="wd-panel__label"><?php esc_html_e('Available filter facets', 'wicket-directory'); ?></p>
        <p class="wd-help wd-help--intro"><?php esc_html_e('Filters appear in Refine Results in this order, with these labels; an empty label shows the field\'s key. Only fields with a fixed list of choices can be filters, and each can be added once.', 'wicket-directory'); ?></p>
        <input type="hidden" name="<?php echo esc_attr($name); ?>" value="">
        <div class="wd-facets" data-wd-facet-rows>
            <?php foreach ($args['rows'] as $index => $row) : ?>
                <?php $print_row($name, $index, $row, $index === 0, $index === count($args['rows']) - 1); ?>
            <?php endforeach; ?>
        </div>
        <p class="wd-help" data-wd-facet-empty <?php echo $args['rows'] === [] ? '' : 'hidden'; ?>><?php esc_html_e('No filters yet. Visitors see no Refine Results panel.', 'wicket-directory'); ?></p>
        <details class="wd-popover wd-fieldpicker" data-wd-popover data-wd-facet-picker>
            <summary class="button">
                <span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
                <?php esc_html_e('Add filter field…', 'wicket-directory'); ?>
            </summary>
            <div class="wd-fieldpicker__panel">
                <div class="wd-fieldpicker__search">
                    <span class="dashicons dashicons-search" aria-hidden="true"></span>
                    <label class="screen-reader-text" for="wicket-directory-facet-search"><?php esc_html_e('Search fields', 'wicket-directory'); ?></label>
                    <input type="search" id="wicket-directory-facet-search" placeholder="<?php esc_attr_e('Search fields…', 'wicket-directory'); ?>" data-wd-facet-search>
                </div>
                <div class="wd-fieldpicker__list">
                    <?php foreach ($args['choices'] as $group => $choices) : ?>
                        <div class="wd-fieldpicker__group" data-wd-facet-group>
                            <p class="wd-menu__group"><?php echo esc_html((string) $group); ?></p>
                            <?php foreach ($choices as $key => $choice) : ?>
                                <button
                                    type="button"
                                    class="wd-fieldpicker__row"
                                    data-wd-facet-choice
                                    value="<?php echo esc_attr((string) $key); ?>"
                                    data-source="<?php echo esc_attr($choice['source']); ?>"
                                    data-schema-key="<?php echo esc_attr($choice['schema_key']); ?>"
                                    data-field="<?php echo esc_attr($choice['field']); ?>"
                                    data-name="<?php echo esc_attr($choice['name']); ?>"
                                    data-code="<?php echo esc_attr($choice['code']); ?>"
                                    data-default="<?php echo esc_attr($choice['default']); ?>"
                                    data-placeholder="<?php echo esc_attr($choice['placeholder']); ?>"
                                    <?php disabled(in_array((string) $key, $keys, true)); ?>
                                >
                                    <span class="wd-fieldpicker__label"><?php echo esc_html($choice['default']); ?></span>
                                    <code class="wd-code"><?php echo esc_html($choice['code']); ?></code>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                    <p class="wd-fieldpicker__empty" data-wd-facet-no-match <?php echo $args['choices'] === [] ? '' : 'hidden'; ?>><?php esc_html_e('No fields to add.', 'wicket-directory'); ?></p>
                </div>
            </div>
        </details>
        <template data-wd-facet-template>
            <?php $print_row($name, '', $template_row); ?>
        </template>
        <p class="wd-help"><?php esc_html_e('Whether the keyword search, location search, filters and Order by are shown is set on each Wicket Directory block, not here.', 'wicket-directory'); ?></p>
    </div>
</div>
