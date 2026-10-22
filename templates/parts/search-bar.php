<?php
/**
 * The search row: [keyword] [location] [Search].
 *
 * Keyword search is the base `search-form` component as-is, so its Search
 * button submits the directory form. The location field sits next to it with
 * the same `component-search-form` classes; `directory.css` unwraps
 * `search-form` so the row reads [keyword] [location] [Search]. With keyword
 * search hidden, a `button` component is the Search button. With both hidden
 * there's nothing to search, so the row isn't rendered.
 *
 * Override it at `{theme}/wicket-directory/parts/search-bar.php`.
 *
 * @var array{
 *     config: Wicket\Directory\Config\DirectoryConfig,
 *     attributes: Wicket\Directory\Block\BlockAttributes,
 *     params: Wicket\Directory\Query\RequestParams,
 * } $args Plus the rest of `directory.php`'s args.
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$attributes = $args['attributes'];
$params = $args['params'];

if ($attributes->hide_keyword && $attributes->hide_location) {
    return;
}

$keyword_param = $params->param('keyword');
$location_param = $params->param('location');
$keyword_placeholder = match ($args['config']->type) {
    Wicket\Directory\Config\DirectoryType::Individual   => __('Search by name, member ID', 'wicket-directory'),
    Wicket\Directory\Config\DirectoryType::Organization => __('Search by organization', 'wicket-directory'),
};
$keyword_search = '';

if (!$attributes->hide_keyword) {
    // search-form pre-fills from $_GET[url-param] and prints it unescaped, so
    // it reads the sanitized, escaped keyword while it renders.
    $had_keyword = array_key_exists($keyword_param, $_GET);
    $raw_keyword = $_GET[$keyword_param] ?? null;
    $_GET[$keyword_param] = esc_attr($params->keyword);

    try {
        $keyword_search = (string) get_component('search-form', [
            'classes'     => [],
            'url-param'   => esc_attr($keyword_param),
            'placeholder' => esc_attr($keyword_placeholder),
        ], false);
    } finally {
        if ($had_keyword) {
            $_GET[$keyword_param] = $raw_keyword;
        } else {
            unset($_GET[$keyword_param]);
        }
    }
}
?>
<div class="wicket-directory__search" role="search" aria-label="<?php esc_attr_e('Directory search', 'wicket-directory'); ?>">
	<?php if (!$attributes->hide_keyword) : ?>
		<div class="wicket-directory__keyword">
			<?php echo $keyword_search; ?>
		</div>
	<?php endif; ?>

	<?php if (!$attributes->hide_location) : ?>
		<div class="wicket-directory__location component-search-form form">
			<?php get_component('icon', [
			    'icon'    => 'fa-solid fa-location-dot',
			    'classes' => ['wicket-directory__location-icon'],
			]); ?>
			<input type="search" id="<?php echo esc_attr($location_param); ?>" name="<?php echo esc_attr($location_param); ?>"
				value="<?php echo esc_attr($params->location); ?>"
				placeholder="<?php esc_attr_e('Search by city, province or state', 'wicket-directory'); ?>"
				aria-label="<?php esc_attr_e('Location', 'wicket-directory'); ?>"
				class="wicket-directory__location-input">
		</div>
	<?php endif; ?>

	<?php if ($attributes->hide_keyword) : ?>
		<?php get_component('button', [
		    'label'   => esc_html__('Search', 'wicket-directory'),
		    // The same variant search-form gives its button.
		    'variant' => esc_attr((string) apply_filters('wicket_search_form_button_variant', 'primary')),
		    'type'    => 'submit',
		    'classes' => ['wicket-directory__submit'],
		]); ?>
	<?php endif; ?>
</div>
