<?php
/**
 * One card laid out by its Advance template (`[col]` / `{{field}}`).
 *
 * DirectoryRenderer uses it instead of `cards/card-{type}.php` when the card's
 * mode is Advance (DirectoryConfig::template()). The template's rows render
 * through Template\CardTemplate, its tags through Template\MergeTags, so the
 * card has the same `component-card-listing` and `wicket-directory-card__*`
 * structure as the Standard cards: the image beside the content, the header,
 * groups of rows, column rows, the actions and tag chips. Every value is
 * escaped there.
 *
 * Override it at `{theme}/wicket-directory/cards/card-template.php`, or swap
 * the file with the `wicket_directory/card_template` filter.
 *
 * @var array{
 *     entry: Wicket\Directory\Data\Entry,
 *     config: Wicket\Directory\Config\DirectoryConfig,
 *     type: Wicket\Directory\Config\DirectoryType,
 *     card: array<string, mixed>,
 *     roster: array<string, mixed>|null,
 *     page: Wicket\Directory\Api\ResultPage,
 *     lang: string,
 *     html_id: string,
 *     merge_tags?: MergeTags,
 * } $args Plus the rest of `directory.php`'s args.
 */

declare(strict_types=1);

use Wicket\Directory\Template\CardContext;
use Wicket\Directory\Template\CardTemplate;
use Wicket\Directory\Template\MergeTags;

defined('ABSPATH') || exit;

$entry = $args['entry'];
$card = $args['card'] ?? $args['config']->card;
$template = is_string($card['template'] ?? null) ? $card['template'] : '';

// A member list's cards are people, with their row in the group.
$modifier = ($args['roster'] ?? null) !== null ? 'group-member' : $args['type']->value;

$rendered = CardTemplate::parse($template)->render(
    $args['merge_tags'] ?? new MergeTags(),
    CardContext::from_page($entry, $args['type'], $card, $args['page'] ?? null, $args['lang'] ?? 'en', $args['html_id'] ?? 'wicket-directory'),
);
?>
<article class="<?php echo esc_attr('component-card-listing wicket-directory-card wicket-directory-card--' . $modifier . ' wicket-directory-card--template'); ?>">
	<?php
    // CardTemplate built both from escaped values and the theme's components.
    echo $rendered['media']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
?>
	<div class="component-card-listing__content wicket-directory-card__content">
		<?php echo $rendered['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped?>
	</div>
</article>
