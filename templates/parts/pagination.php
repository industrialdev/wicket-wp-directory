<?php
/**
 * Page links for the results.
 *
 * Pages use this directory's own `wd{ID}_pg` param, so the links are built on
 * the current URL with `paginate_links()` and keep everything else in it: the
 * keyword, location, facets and sort, and any other directory's params. The
 * Wicket theme's `wicket_pagination()` renders them when it exists. Otherwise
 * core `paginate_links()` gives the same `nav.wicket-pagination .page-numbers`
 * markup, so the theme's pagination styles still match. Nothing is rendered
 * for a single page. In the editor preview every link is `#`.
 *
 * Override it at `{theme}/wicket-directory/parts/pagination.php`.
 *
 * @var array{
 *     params: Wicket\Directory\Query\RequestParams,
 *     page: Wicket\Directory\Api\ResultPage,
 *     is_preview: bool,
 * } $args Plus the rest of `directory.php`'s args.
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$page = $args['page'];

if ($page->is_empty() || $page->total_pages < 2) {
    return;
}

$pagination_args = [
    // paginate_links() swaps %#% for the page number and adds the current URL's other query vars.
    'base'    => add_query_arg($args['params']->param('pg'), '%#%'),
    'format'  => '',
    'total'   => $page->total_pages,
    'current' => $page->page,
];

// The preview runs in a REST request, whose URL isn't the page's.
$to_hash = fn (): string => '#';

if ($args['is_preview']) {
    add_filter('paginate_links', $to_hash);
}

try {
    if (function_exists('wicket_pagination')) {
        $pagination = (string) wicket_pagination($pagination_args);
    } else {
        $links = paginate_links($pagination_args + [
            'type'      => 'plain',
            'prev_text' => '<i class="fa-solid fa-arrow-left" aria-hidden="true"></i> ' . esc_html__('Previous', 'wicket-directory'),
            'next_text' => esc_html__('Next', 'wicket-directory') . ' <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>',
        ]);
        $pagination = is_string($links) && $links !== ''
            ? '<nav class="wicket-pagination" aria-label="' . esc_attr__('Pagination', 'wicket-directory') . '">' . $links . '</nav>'
            : '';
    }
} finally {
    if ($args['is_preview']) {
        remove_filter('paginate_links', $to_hash);
    }
}

if ($pagination === '') {
    return;
}
?>
<div class="wicket-directory__pagination">
	<?php echo $pagination; ?>
</div>
