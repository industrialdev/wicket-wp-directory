<?php
/**
 * The results header: "Displaying X–Y of N results" and Order by.
 *
 * Order by is a native `<select>` inside `.form`, so the theme's form styles
 * apply; there's no base component for it. It's a field of the directory form,
 * so a sort keeps the search and filters, and changing it submits the form
 * (as the theme's listing block does). Without JS, a Sort button submits it.
 * With Hide Order By on, the select isn't rendered and the block's default
 * sort applies. The header isn't rendered for an empty page.
 *
 * Override it at `{theme}/wicket-directory/parts/results-header.php`.
 *
 * @var array{
 *     config: Wicket\Directory\Config\DirectoryConfig,
 *     attributes: Wicket\Directory\Block\BlockAttributes,
 *     params: Wicket\Directory\Query\RequestParams,
 *     page: Wicket\Directory\Api\ResultPage,
 * } $args Plus the rest of `directory.php`'s args.
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$page = $args['page'];
$params = $args['params'];

if ($page->is_empty()) {
    return;
}

$sort_param = $params->param('sort');
$count = sprintf(
    /* translators: 1: first result shown, 2: last result shown, 3: total results. */
    _n('Displaying %1$s–%2$s of %3$s result', 'Displaying %1$s–%2$s of %3$s results', $page->total, 'wicket-directory'),
    number_format_i18n($page->first_number()),
    number_format_i18n($page->last_number()),
    number_format_i18n($page->total),
);
?>
<div class="wicket-directory__results-header">
	<p class="wicket-directory__count"><?php echo esc_html($count); ?></p>

	<?php if (!$args['attributes']->hide_order_by) : ?>
		<div class="wicket-directory__sort form">
			<label for="<?php echo esc_attr($sort_param); ?>" class="wicket-directory__sort-label"><?php esc_html_e('Order by', 'wicket-directory'); ?></label>
			<select id="<?php echo esc_attr($sort_param); ?>" name="<?php echo esc_attr($sort_param); ?>" class="wicket-directory__sort-select"
				onchange="this.form.submit()">
				<?php foreach ($args['config']->type->sort_options() as $token => $label) : ?>
					<option value="<?php echo esc_attr($token); ?>"<?php echo $token === $params->sort ? ' selected' : ''; ?>><?php echo esc_html($label); ?></option>
				<?php endforeach; ?>
			</select>
			<noscript>
				<?php get_component('button', [
				    'variant' => 'secondary',
				    'label'   => esc_html__('Sort', 'wicket-directory'),
				    'type'    => 'submit',
				    'classes' => ['wicket-directory__sort-submit'],
				]); ?>
			</noscript>
		</div>
	<?php endif; ?>
</div>
