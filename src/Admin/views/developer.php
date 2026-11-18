<?php
/**
 * The Developer tab: whether the directory runs custom code, notes about it,
 * the hook reference and the custom fields code registers.
 *
 * Only the Custom code switch and the notes post (section `developer`); the
 * switch needs its hidden "0", because DirectoryConfig keeps the default for a
 * missing one. The Copy buttons are `hidden` until `assets/js/admin.js` turns
 * them on; without the script the names can still be selected and copied.
 *
 * @var array{
 *     field: string,
 *     developer: array{custom_code: bool, notes: string},
 *     slug: string,
 *     hooks: list<array{name: string, scoped: string|null, kind: string, args: string, description: string}>,
 *     fields: list<array{key: string, label: string, description: string, tag: string}>,
 * } $args
 */

declare(strict_types=1);

use Wicket\Directory\Admin\DeveloperTab;
use Wicket\Directory\Config\DirectoryConfig;

defined('ABSPATH') || exit;

$name = $args['field'] . '[developer]';
$developer = $args['developer'];
$kinds = [
    DeveloperTab::FILTER => __('Filter', 'wicket-directory'),
    DeveloperTab::ACTION => __('Action', 'wicket-directory'),
];

/**
 * Print a name with its Copy button.
 *
 * @param string $text  What to show and copy.
 * @param string $label The Copy button's accessible name.
 */
$copyable = static function (string $text, string $label): void {
    ?>
    <span class="wd-copy">
        <code class="wd-code"><?php echo esc_html($text); ?></code>
        <button type="button" class="button button-small wd-copy__button" data-wd-copy="<?php echo esc_attr($text); ?>" data-wd-copied="<?php esc_attr_e('Copied', 'wicket-directory'); ?>" aria-label="<?php echo esc_attr($label); ?>" hidden>
            <?php esc_html_e('Copy', 'wicket-directory'); ?>
        </button>
    </span>
    <?php
};

/* translators: %s: A hook name or merge tag. */
$copy_label = __('Copy %s', 'wicket-directory');
$code = static fn (string $text): string => '<code class="wd-code">' . esc_html($text) . '</code>';

// Escaped help texts with code in them.
$slug_help = $args['slug'] !== ''
    ? sprintf(
        /* translators: %s: The directory's slug. */
        esc_html__('Each hook with a "This directory only" name runs for every directory first, then for this one by its slug, %s. Changing the slug changes those names, and code that uses the old ones stops running.', 'wicket-directory'),
        $code($args['slug'])
    )
    : esc_html__('This directory has no slug yet; WordPress sets one when it is published. Until then, the "This directory only" names end in {slug}.', 'wicket-directory');
$fields_help = sprintf(
    /* translators: 1: Filter name. 2: Filter name. 3: Entry key. */
    esc_html__('None registered. Code that adds data to the cards can list its fields here with %1$s or %2$s, by the key it uses under %3$s.', 'wicket-directory'),
    $code(DeveloperTab::FIELDS_FILTER),
    $code(DeveloperTab::FIELDS_FILTER . '_' . ($args['slug'] !== '' ? $args['slug'] : DeveloperTab::SLUG_PLACEHOLDER)),
    $code('extra')
);
?>
<div class="wd-developer">
    <div class="wd-panel">
        <p class="wd-panel__label"><?php esc_html_e('Custom code', 'wicket-directory'); ?></p>
        <p class="wd-help wd-help--intro"><?php esc_html_e('For the record only: nothing here runs, writes or turns off code, and nothing here changes what visitors see.', 'wicket-directory'); ?></p>
        <input type="hidden" name="<?php echo esc_attr($name); ?>[custom_code]" value="0">
        <label class="wd-check wd-switch">
            <input type="checkbox" role="switch" name="<?php echo esc_attr($name); ?>[custom_code]" value="1" <?php checked($developer['custom_code']); ?>>
            <?php esc_html_e('This directory runs custom code', 'wicket-directory'); ?>
        </label>
        <p class="wd-help wd-indent"><?php esc_html_e('Turn on when a theme or plugin changes this directory through its hooks. All Directories then shows a Custom Code label next to it.', 'wicket-directory'); ?></p>
        <label class="wd-sublabel" for="wicket-directory-developer-notes"><?php esc_html_e('Developer notes', 'wicket-directory'); ?></label>
        <textarea id="wicket-directory-developer-notes" class="large-text" name="<?php echo esc_attr($name); ?>[notes]" rows="5" maxlength="<?php echo esc_attr((string) DirectoryConfig::NOTES_MAX_LENGTH); ?>"><?php echo esc_textarea($developer['notes']); ?></textarea>
        <p class="wd-help"><?php esc_html_e('Where the code lives and what it changes, for whoever edits this directory next. Only admins see the notes.', 'wicket-directory'); ?></p>
    </div>

    <div class="wd-panel">
        <p class="wd-panel__label"><?php esc_html_e('Hook reference', 'wicket-directory'); ?></p>
        <p class="wd-help wd-help--intro"><?php echo $slug_help; // Escaped above.?></p>
        <ul class="wd-hooks">
            <?php foreach ($args['hooks'] as $hook) : ?>
                <li class="wd-hook">
                    <div class="wd-hook__name">
                        <span class="wd-hook__kind wd-hook__kind--<?php echo esc_attr($hook['kind']); ?>"><?php echo esc_html($kinds[$hook['kind']] ?? $hook['kind']); ?></span>
                        <?php $copyable($hook['name'], sprintf($copy_label, $hook['name'])); ?>
                    </div>
                    <?php if ($hook['scoped'] !== null) : ?>
                        <div class="wd-hook__scoped">
                            <span class="wd-caption"><?php esc_html_e('This directory only', 'wicket-directory'); ?></span>
                            <?php $copyable($hook['scoped'], sprintf($copy_label, $hook['scoped'])); ?>
                        </div>
                    <?php endif; ?>
                    <p class="wd-help"><?php echo esc_html($hook['description']); ?></p>
                    <p class="wd-hook__args"><code><?php echo esc_html($hook['args']); ?></code></p>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>

    <div class="wd-panel">
        <p class="wd-panel__label"><?php esc_html_e('Registered custom fields', 'wicket-directory'); ?></p>
        <?php if ($args['fields'] === []) : ?>
            <p class="wd-help wd-help--intro"><?php echo $fields_help; // Escaped above.?></p>
        <?php else : ?>
            <p class="wd-help wd-help--intro"><?php esc_html_e('Fields that code adds to this directory\'s results. An Advance card prints one with its merge tag; the Card View\'s field picker lists them under Custom Fields.', 'wicket-directory'); ?></p>
            <table class="widefat striped wd-fields">
                <thead>
                    <tr>
                        <th scope="col"><?php esc_html_e('Field', 'wicket-directory'); ?></th>
                        <th scope="col"><?php esc_html_e('Merge tag', 'wicket-directory'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($args['fields'] as $custom_field) : ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($custom_field['label']); ?></strong>
                                <?php if ($custom_field['description'] !== '') : ?>
                                    <p class="wd-help"><?php echo esc_html($custom_field['description']); ?></p>
                                <?php endif; ?>
                            </td>
                            <td><?php $copyable($custom_field['tag'], sprintf($copy_label, $custom_field['tag'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
