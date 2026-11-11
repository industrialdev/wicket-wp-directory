<?php
/**
 * One placed directory: the form, the Refine Results panel and the results.
 *
 * Override it by copying it to `{theme}/wicket-directory/directory.php`. Each
 * part (`parts/…`) and card (`cards/…`) can be overridden the same way.
 *
 * A group's member list renders through this template too. Then `roster` is
 * set (the group, and the Back to Directory link when there is a group list
 * to go back to), `type` is Individual (members are people), and `params`,
 * `card` and `facets` are the member list's own. Parts and cards read those,
 * not `config`'s. The header part comes before the form.
 *
 * Entries hold raw values; templates escape everything they print.
 *
 * @var array{
 *     directory: WP_Post,
 *     config: Wicket\Directory\Config\DirectoryConfig,
 *     type: Wicket\Directory\Config\DirectoryType,
 *     card: array<string, mixed>,
 *     facets: list<array{source: string, schema_key: string, field: string, label: string}>,
 *     collapse_refine: bool,
 *     roster: array{group: Wicket\Directory\Data\GroupEntry, back_url: string}|null,
 *     attributes: Wicket\Directory\Block\BlockAttributes,
 *     params: Wicket\Directory\Query\RequestParams,
 *     page: Wicket\Directory\Api\ResultPage,
 *     entries: list<Wicket\Directory\Data\Entry>,
 *     facet_options: array<string, list<array{value: string, label: string}>>,
 *     show_filters: bool,
 *     card_template: string,
 *     hidden_fields: list<array{name: string, value: string}>,
 *     clear_url: string,
 *     html_id: string,
 *     is_preview: bool,
 *     wicket_theme: bool,
 *     lang: string,
 *     templates: Wicket\Directory\Render\TemplateLoader,
 * } $args
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$templates = $args['templates'];
$roster = $args['roster'] ?? null;
$classes = [
    'wicket-directory',
    'wicket-directory--' . $args['config']->type->value,
];

if ($roster !== null) {
    $classes[] = 'wicket-directory--roster';
}

if ($args['show_filters']) {
    $classes[] = 'wicket-directory--has-filters';
}

// Without a Wicket theme nothing styles the card-listing, filter-form and
// pagination classes, so directory.css gives them its own look.
if (!$args['wicket_theme']) {
    $classes[] = 'wicket-directory--standalone';
}
?>
<div id="<?php echo esc_attr($args['html_id']); ?>" class="<?php echo esc_attr(implode(' ', $classes)); ?>">
	<?php if ($roster !== null) : ?>
		<?php $templates->load('parts/roster-header', $args); ?>
	<?php endif; ?>

	<form class="wicket-directory__form" method="get">
		<?php foreach ($args['hidden_fields'] as $field) : ?>
			<input type="hidden" name="<?php echo esc_attr($field['name']); ?>" value="<?php echo esc_attr($field['value']); ?>">
		<?php endforeach; ?>

		<?php $templates->load('parts/search-bar', $args); ?>

		<div class="wicket-directory__body">
			<?php if ($args['show_filters']) : ?>
				<?php $templates->load('parts/filters', $args); ?>
			<?php endif; ?>

			<div class="wicket-directory__results">
				<?php $templates->load('parts/results-header', $args); ?>

				<?php if ($args['entries'] === []) : ?>
					<?php $templates->load('parts/no-results', $args); ?>
				<?php else : ?>
					<ul class="wicket-directory__list">
						<?php foreach ($args['entries'] as $entry) : ?>
							<li class="wicket-directory__item">
								<?php $templates->load_file($args['card_template'], ['entry' => $entry] + $args); ?>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>

				<?php $templates->load('parts/pagination', $args); ?>
			</div>
		</div>
	</form>
</div>
