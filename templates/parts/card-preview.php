<?php
/**
 * One card, for the edit screen's live preview (Admin\CardPreview).
 *
 * The card sits in the same wrappers and classes as in `directory.php` (the
 * directory, its results and list item), so the card's container queries and
 * the theme's styles apply as they do on the site. Only the admin sees it,
 * in a frame that loads the site's stylesheets.
 *
 * Override it at `{theme}/wicket-directory/parts/card-preview.php`, e.g. to
 * match an override of `directory.php` that changes these wrappers.
 *
 * @var array{
 *     entry: Wicket\Directory\Data\Entry,
 *     config: Wicket\Directory\Config\DirectoryConfig,
 *     roster: array<string, mixed>|null,
 *     card_template: string,
 *     html_id: string,
 *     wicket_theme: bool,
 *     templates: Wicket\Directory\Render\TemplateLoader,
 * } $args Plus the rest of `directory.php`'s args (`is_preview` is true).
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$classes = [
    'wicket-directory',
    'wicket-directory--' . $args['config']->type->value,
    'wicket-directory--preview',
];

if (($args['roster'] ?? null) !== null) {
    $classes[] = 'wicket-directory--roster';
}

if (!$args['wicket_theme']) {
    $classes[] = 'wicket-directory--standalone';
}
?>
<div id="<?php echo esc_attr($args['html_id']); ?>" class="<?php echo esc_attr(implode(' ', $classes)); ?>">
	<div class="wicket-directory__results">
		<ul class="wicket-directory__list">
			<li class="wicket-directory__item">
				<?php $args['templates']->load_file($args['card_template'], $args); ?>
			</li>
		</ul>
	</div>
</div>
