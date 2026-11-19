<?php
/**
 * The Card View's live preview panel (CardPreview::panel_args()).
 *
 * One card, rendered by the server from the settings on the screen, before
 * they're saved. A group directory has one panel for both cards: it shows
 * the card whose settings are shown (`data-wd-card`). The width buttons
 * set the frame's width (the preview's chrome, not a setting); Previous and
 * Next step through the records the settings match.
 *
 * Everything here needs `assets/js/admin.js`, so the panel is `hidden` until
 * the script shows it. Like the card settings, it hides while the type
 * select differs from the saved type (`data-wd-type-unchanged`). Nothing in
 * it posts with the form.
 *
 * @var array{
 *     url: string,
 *     nonce: string,
 *     texts: array<string, string>,
 *     widths: array<string, array{0: string, 1: string, 2: int}>,
 * } $args
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$widths = [];

foreach ($args['widths'] as $key => [, , $width]) {
    $widths[$key] = $width;
}
?>
<section class="wd-preview" aria-labelledby="wicket-directory-preview-title" data-wd-preview data-wd-type-unchanged hidden
    data-url="<?php echo esc_url($args['url']); ?>"
    data-nonce="<?php echo esc_attr($args['nonce']); ?>"
    data-texts="<?php echo esc_attr((string) wp_json_encode($args['texts'])); ?>"
    data-widths="<?php echo esc_attr((string) wp_json_encode($widths)); ?>">
    <div class="wd-preview__bar">
        <div>
            <p class="wd-panel__label" id="wicket-directory-preview-title"><?php esc_html_e('Preview', 'wicket-directory'); ?></p>
            <p class="wd-help wd-preview__intro"><?php esc_html_e('One card with the settings below, as visitors will see it. It updates as you change them; nothing is saved until you update the directory.', 'wicket-directory'); ?></p>
        </div>
        <div class="wd-preview__widths" role="group" aria-label="<?php esc_attr_e('Preview width', 'wicket-directory'); ?>">
            <?php foreach ($args['widths'] as $key => [$label, $icon, $width]) : ?>
                <button type="button" class="button wd-preview__width" data-wd-preview-width="<?php echo esc_attr($key); ?>" aria-pressed="false" title="<?php echo esc_attr(sprintf('%s (%dpx)', $label, $width)); ?>">
                    <span class="dashicons <?php echo esc_attr($icon); ?>" aria-hidden="true"></span>
                    <span class="screen-reader-text"><?php echo esc_html($label); ?></span>
                </button>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="wd-preview__stage" data-wd-preview-stage>
        <iframe class="wd-preview__frame" title="<?php esc_attr_e('Card preview', 'wicket-directory'); ?>" tabindex="-1" data-wd-preview-frame></iframe>
    </div>
    <div class="wd-preview__footer">
        <p class="wd-preview__status" role="status" data-wd-preview-status></p>
        <div class="wd-preview__records" data-wd-preview-records hidden>
            <button type="button" class="button button-small" data-wd-preview-step="-1"><?php esc_html_e('Previous record', 'wicket-directory'); ?></button>
            <button type="button" class="button button-small" data-wd-preview-step="1"><?php esc_html_e('Next record', 'wicket-directory'); ?></button>
        </div>
    </div>
</section>
