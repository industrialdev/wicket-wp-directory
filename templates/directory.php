<?php
/**
 * One placed directory: the form, the Refine Results panel and the results.
 *
 * Override it by copying it to `{theme}/wicket-directory/directory.php`. Each
 * part (`parts/…`) and card (`cards/…`) can be overridden the same way.
 *
 * Entries hold raw values; templates escape everything they print.
 *
 * @var array{
 *     directory: WP_Post,
 *     config: Wicket\Directory\Config\DirectoryConfig,
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
 *     lang: string,
 *     templates: Wicket\Directory\Render\TemplateLoader,
 * } $args
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$templates = $args['templates'];
$classes = [
    'wicket-directory',
    'wicket-directory--' . $args['config']->type->value,
];

if ($args['show_filters']) {
    $classes[] = 'wicket-directory--has-filters';
}
?>
<div id="<?php echo esc_attr($args['html_id']); ?>" class="<?php echo esc_attr(implode(' ', $classes)); ?>">
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
