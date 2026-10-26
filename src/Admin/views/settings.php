<?php
/**
 * The settings meta box: the Directory panels, then a tab per part of the card
 * and filter setup.
 *
 * The tab bar is `hidden` until `assets/js/admin.js` turns it on and hides the
 * inactive panels. Without the script every panel is shown, one after another.
 *
 * @var array{
 *     directory: callable(): void,
 *     tabs: array<string, array{0: string, 1: callable(): void}>,
 * } $args
 */

declare(strict_types=1);

defined('ABSPATH') || exit;
?>
<div class="wicket-directory-admin">
    <?php ($args['directory'])(); ?>
    <div class="wd-tabs" role="tablist" data-wd-tabs hidden>
        <?php foreach ($args['tabs'] as $key => [$label]) : ?>
            <button type="button" class="wd-tab" role="tab" id="wicket-directory-tab-<?php echo esc_attr($key); ?>" aria-controls="wicket-directory-panel-<?php echo esc_attr($key); ?>" data-wd-tab="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></button>
        <?php endforeach; ?>
    </div>
    <?php foreach ($args['tabs'] as $key => [$label, $render]) : ?>
        <div class="wd-panel wd-tabpanel" role="tabpanel" id="wicket-directory-panel-<?php echo esc_attr($key); ?>" aria-labelledby="wicket-directory-tab-<?php echo esc_attr($key); ?>" data-wd-tab-panel="<?php echo esc_attr($key); ?>">
            <?php $render(); ?>
        </div>
    <?php endforeach; ?>
</div>
