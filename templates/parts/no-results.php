<?php
/**
 * An empty page: no matches, or the API couldn't be reached.
 *
 * An API failure (`$page->unavailable`) shows the `alert` component, so
 * visitors see a friendly message instead of an empty directory. Otherwise it
 * says there are no results, and suggests changing the search when the visitor
 * searched or filtered.
 *
 * Override it at `{theme}/wicket-directory/parts/no-results.php`.
 *
 * @var array{
 *     params: Wicket\Directory\Query\RequestParams,
 *     page: Wicket\Directory\Api\ResultPage,
 * } $args Plus the rest of `directory.php`'s args.
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$params = $args['params'];
$searched = $params->keyword !== '' || $params->location !== '' || $params->facets !== [];
?>
<?php if ($args['page']->unavailable) : ?>
	<div class="wicket-directory__unavailable">
		<?php
        // The alert component prints its content as given, so pass it escaped.
        get_component('alert', [
            'content' => esc_html__('The directory is unavailable right now. Please try again later.', 'wicket-directory'),
        ]);
    ?>
	</div>
<?php else : ?>
	<div class="wicket-directory__no-results">
		<p class="wicket-directory__no-results-title"><?php esc_html_e('No results found.', 'wicket-directory'); ?></p>
		<?php if ($searched) : ?>
			<p class="wicket-directory__no-results-hint"><?php esc_html_e('Try adjusting your search or filters to find what you are looking for.', 'wicket-directory'); ?></p>
		<?php endif; ?>
	</div>
<?php endif; ?>
