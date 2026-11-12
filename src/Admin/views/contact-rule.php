<?php
/**
 * One contact rule's controls: a "show" checkbox that reveals the record type,
 * the Show in Directory and primary flags, and the hint for the ticked flags.
 *
 * The Card View prints one per contact field of the card (`card.address`,
 * …), and one per record of an Organization Contact (`card.org_contact.email`
 * and `.phone`). An address rule also has the format radios, a website rule
 * the button label; those post under the card (`card_name`).
 *
 * Included by card.php through a closure, so `$args` is this rule's:
 *
 * @var array{
 *     key: string,
 *     name: string,
 *     id: string,
 *     label: string,
 *     type_label: string,
 *     types: array<string, string>,
 *     rule: array{show: bool, type: string, only_directory: bool, only_primary: bool},
 *     hints: array<string, string>,
 *     state: string,
 *     card_name: string,
 *     address_format: string,
 *     address_formats: array<string, string>,
 *     website_label: string,
 * } $args `key` is the contact field: `address`, `email`, `phone` or `website`.
 */

declare(strict_types=1);

defined('ABSPATH') || exit;
?>
<div class="wd-item" data-wd-item="contact">
    <input type="hidden" name="<?php echo esc_attr($args['name']); ?>[show]" value="0">
    <label class="wd-check">
        <input type="checkbox" name="<?php echo esc_attr($args['name']); ?>[show]" value="1" data-wd-reveal-toggle <?php checked($args['rule']['show']); ?>>
        <?php echo esc_html($args['label']); ?>
    </label>
    <div class="wd-indent" data-wd-reveal>
        <?php if ($args['key'] === 'address') : ?>
            <fieldset>
                <legend class="wd-sublabel"><?php esc_html_e('Format', 'wicket-directory'); ?></legend>
                <?php foreach ($args['address_formats'] as $format => $label) : ?>
                    <label class="wd-check">
                        <input type="radio" name="<?php echo esc_attr($args['card_name']); ?>[address_format]" value="<?php echo esc_attr($format); ?>" <?php checked($args['address_format'], $format); ?>>
                        <?php echo esc_html($label); ?>
                    </label>
                <?php endforeach; ?>
            </fieldset>
        <?php endif; ?>
        <label class="wd-sublabel" for="<?php echo esc_attr($args['id']); ?>-type"><?php echo esc_html($args['type_label']); ?></label>
        <select id="<?php echo esc_attr($args['id']); ?>-type" class="wd-field" name="<?php echo esc_attr($args['name']); ?>[type]">
            <option value="any" <?php selected($args['rule']['type'], 'any'); ?>><?php esc_html_e('Any type', 'wicket-directory'); ?></option>
            <?php foreach ($args['types'] as $slug => $label) : ?>
                <option value="<?php echo esc_attr((string) $slug); ?>" <?php selected($args['rule']['type'], (string) $slug); ?>><?php echo esc_html($label); ?></option>
            <?php endforeach; ?>
        </select>
        <input type="hidden" name="<?php echo esc_attr($args['name']); ?>[only_directory]" value="0">
        <label class="wd-check">
            <input type="checkbox" name="<?php echo esc_attr($args['name']); ?>[only_directory]" value="1" data-wd-flag="directory" <?php checked($args['rule']['only_directory']); ?>>
            <?php esc_html_e('Only show if flagged "Show in Directory"', 'wicket-directory'); ?>
        </label>
        <?php if ($args['key'] !== 'website') : ?>
            <input type="hidden" name="<?php echo esc_attr($args['name']); ?>[only_primary]" value="0">
            <label class="wd-check">
                <input type="checkbox" name="<?php echo esc_attr($args['name']); ?>[only_primary]" value="1" data-wd-flag="primary" <?php checked($args['rule']['only_primary']); ?>>
                <?php esc_html_e('Only show if primary', 'wicket-directory'); ?>
            </label>
        <?php endif; ?>
        <?php foreach ($args['hints'] as $hint => $text) : ?>
            <p class="wd-help" data-wd-hint="<?php echo esc_attr($hint); ?>" <?php echo $hint === $args['state'] ? '' : 'hidden'; ?>><?php echo esc_html($text); ?></p>
        <?php endforeach; ?>
        <?php if ($args['key'] === 'website') : ?>
            <label class="wd-sublabel" for="<?php echo esc_attr($args['id']); ?>-label"><?php esc_html_e('Button label', 'wicket-directory'); ?></label>
            <input type="text" id="<?php echo esc_attr($args['id']); ?>-label" class="wd-field" name="<?php echo esc_attr($args['card_name']); ?>[website_label]" value="<?php echo esc_attr($args['website_label']); ?>" placeholder="<?php esc_attr_e('Visit Website', 'wicket-directory'); ?>">
        <?php endif; ?>
    </div>
</div>
