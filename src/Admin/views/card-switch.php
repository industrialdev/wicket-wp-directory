<?php
/**
 * The Group Card / Group Member Card switch above the Card View and Filters
 * tabs, for a group directory.
 *
 * It shows only in the Group List + Member List view, the one where visitors
 * reach both cards (`data-wd-view`). `assets/js/admin.js` shows the chosen
 * card's `data-wd-card` sections in both tabs; the other views show the one
 * card they have. Its radios aren't under `wicket_directory[...]`, so the
 * save handler ignores them. Without the script both cards' settings show.
 *
 * @var array{
 *     view: string,
 *     cards: array<string, string>,
 * } $args
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$view = Wicket\Directory\Config\DirectoryConfig::VIEW_GROUPS_MEMBERS;
$first = array_key_first($args['cards']);
?>
<fieldset class="wd-panel wd-card-switch" data-wd-type="group" data-wd-view="<?php echo esc_attr($view); ?>" <?php echo $args['view'] === $view ? '' : 'hidden'; ?>>
    <legend class="wd-panel__label"><?php esc_html_e('Card to set up', 'wicket-directory'); ?></legend>
    <div class="wd-card-switch__options">
        <?php foreach ($args['cards'] as $card => $label) : ?>
            <label class="wd-check">
                <input type="radio" name="wicket_directory_card_switch" value="<?php echo esc_attr($card); ?>" data-wd-card-switch <?php checked($card, $first); ?>>
                <?php echo esc_html($label); ?>
            </label>
        <?php endforeach; ?>
    </div>
    <p class="wd-help"><?php esc_html_e('Group List + Member List shows both cards: the group list\'s cards, and each member list\'s cards. The Card View and Filters tabs set up the one chosen here.', 'wicket-directory'); ?></p>
</fieldset>
