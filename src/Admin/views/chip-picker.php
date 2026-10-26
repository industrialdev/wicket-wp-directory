<?php
/**
 * A multi-select as chips with an "Add" menu.
 *
 * The menu is a `<details>` of plain checkboxes, which are what the form posts,
 * so it works without `assets/js/admin.js`. The chips only show what's ticked;
 * the script keeps them in step with the checkboxes and makes their remove
 * buttons untick them.
 *
 * Included by the other views through a closure, so `$args` is this picker's:
 *
 * @var array{
 *     name: string,
 *     label: string,
 *     choices: array<string, array<string, string>>,
 *     selected: list<string>,
 * } $args `choices` is group label => value => label; one group with an empty label shows no heading.
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

// Chips follow the menu's order, which is the order the checkboxes post in.
$chosen = [];

foreach ($args['choices'] as $choices) {
    foreach ($choices as $value => $label) {
        if (in_array((string) $value, $args['selected'], true)) {
            $chosen[$value] = $label;
        }
    }
}
?>
<?php /* translators: %s: Name of the chosen item. */ ?>
<div class="wd-chips" data-wd-chips data-remove-label="<?php esc_attr_e('Remove %s', 'wicket-directory'); ?>">
    <ul class="wd-chips__list" data-wd-chips-list>
        <?php foreach ($chosen as $value => $label) : ?>
            <?php
            /* translators: %s: Name of the chosen item. */
            $remove = sprintf(__('Remove %s', 'wicket-directory'), $label);
            ?>
            <li class="wd-chip" data-value="<?php echo esc_attr((string) $value); ?>">
                <span class="wd-chip__label"><?php echo esc_html($label); ?></span>
                <button type="button" class="wd-chip__remove" data-wd-chip-remove>
                    <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
                    <span class="screen-reader-text"><?php echo esc_html($remove); ?></span>
                </button>
            </li>
        <?php endforeach; ?>
    </ul>
    <details class="wd-popover" data-wd-popover>
        <summary class="wd-chips__add">
            <span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
            <?php esc_html_e('Add', 'wicket-directory'); ?>
            <span class="screen-reader-text"><?php echo esc_html($args['label']); ?></span>
        </summary>
        <fieldset class="wd-menu">
            <legend class="screen-reader-text"><?php echo esc_html($args['label']); ?></legend>
            <?php foreach ($args['choices'] as $group => $choices) : ?>
                <?php if ((string) $group !== '') : ?>
                    <p class="wd-menu__group"><?php echo esc_html((string) $group); ?></p>
                <?php endif; ?>
                <?php foreach ($choices as $value => $label) : ?>
                    <label class="wd-menu__item">
                        <input type="checkbox" name="<?php echo esc_attr($args['name']); ?>" value="<?php echo esc_attr((string) $value); ?>" data-label="<?php echo esc_attr($label); ?>" <?php checked(in_array((string) $value, $args['selected'], true)); ?>>
                        <?php echo esc_html($label); ?>
                    </label>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </fieldset>
    </details>
</div>
