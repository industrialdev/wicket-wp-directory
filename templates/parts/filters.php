<?php
/**
 * The Refine Results panel: one checkbox group per facet that has choices.
 *
 * The base `filter-form` component only handles WP taxonomies, so this part
 * mirrors its markup and `component-filter-form__*` classes instead. The
 * theme's filter-form styles then apply, and the panel looks like the site's
 * other listings.
 *
 * Checkboxes are fields of the directory form, so Apply Filters submits them
 * with the search and Order by. Clear All links to the page without this
 * directory's params. Alpine adds the mobile collapse (initial state from the
 * block's `collapseRefineOnMobile`), each facet's collapse, See more / See less
 * after five options and the live selected count. Without Alpine the panel is
 * open, every option shows, and the JS-only controls stay hidden (`x-cloak`).
 *
 * Override it at `{theme}/wicket-directory/parts/filters.php`.
 *
 * @var array{
 *     config: Wicket\Directory\Config\DirectoryConfig,
 *     attributes: Wicket\Directory\Block\BlockAttributes,
 *     params: Wicket\Directory\Query\RequestParams,
 *     facet_options: array<string, list<array{value: string, label: string}>>,
 *     clear_url: string,
 *     html_id: string,
 * } $args Plus the rest of `directory.php`'s args.
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

// Options shown before See more.
$visible_options = 5;
$params = $args['params'];
$panel_id = $args['html_id'] . '-filters';
$facets = [];

foreach ($args['config']->facets as $facet) {
    $key = Wicket\Directory\Query\RequestParams::facet_key($facet);
    $options = $args['facet_options'][$key] ?? [];

    // Not an enum, gone from the schema, or no eligible org types: nothing to pick.
    if ($options === []) {
        continue;
    }

    $label = $facet['label'];

    if ($label === '') {
        $label = $facet['source'] === Wicket\Directory\Config\DirectoryConfig::FACET_ORG_TYPE
            ? __('Organization type', 'wicket-directory')
            : $facet['field'];
    }

    $selected = $params->facet_values($facet);
    $hidden_values = array_column(array_slice($options, $visible_options), 'value');

    $facets[] = [
        'name'     => $params->param($key) . '[]',
        'label'    => $label,
        'options'  => $options,
        'selected' => $selected,
        // A selected option past the fifth keeps the list expanded.
        'show_all' => array_intersect($hidden_values, $selected) !== [],
    ];
}

if ($facets === []) {
    return;
}

// The theme's `lg` breakpoint: below it the panel collapses. The whole top bar
// toggles it; its button gives keyboard focus and state, and clicks bubble up.
$show_filters = $args['attributes']->collapse_refine_on_mobile
    ? "!window.matchMedia('(max-width: 1023.98px)').matches"
    : 'true';
?>
<div id="<?php echo esc_attr($panel_id); ?>" class="wicket-directory__filters component-filter-form"
	x-data="{ showFilters: <?php echo esc_attr($show_filters); ?> }"
	:class="{ 'wicket-directory__filters--collapsed': !showFilters }">
	<div class="component-filter-form__top wicket-directory__filters-top" :class="showFilters ? 'open' : 'closed'" @click="showFilters = !showFilters">
		<span class="wicket-directory__filters-title">
			<?php get_component('icon', [
			    'icon'    => 'fa-solid fa-sliders',
			    'classes' => ['wicket-directory__filters-icon'],
			]); ?>
			<span id="<?php echo esc_attr($panel_id . '-heading'); ?>" class="component-filter-form__heading">
				<?php esc_html_e('Refine Results', 'wicket-directory'); ?>
			</span>
		</span>

		<button type="button" class="wicket-directory__filters-toggle" x-cloak
			aria-labelledby="<?php echo esc_attr($panel_id . '-heading'); ?>"
			aria-controls="<?php echo esc_attr($panel_id . '-group'); ?>"
			:aria-expanded="showFilters.toString()">
			<span x-show="!showFilters">
				<?php get_component('icon', ['icon' => 'fa-solid fa-plus']); ?>
			</span>
			<span x-show="showFilters">
				<?php get_component('icon', ['icon' => 'fa-solid fa-minus']); ?>
			</span>
		</button>
	</div>

	<div id="<?php echo esc_attr($panel_id . '-group'); ?>" class="component-filter-form__group wicket-directory__filters-group">
		<?php foreach ($facets as $index => $facet) : ?>
			<?php
            $facet_id = $panel_id . '-' . $index;
		    $count = count($facet['selected']);
		    ?>
			<div class="component-filter-form__filter-section wicket-directory__facet"
				x-data="{ open: true, showAll: <?php echo $facet['show_all'] ? 'true' : 'false'; ?>, selectedItemsCount: <?php echo (int) $count; ?> }">
				<button type="button" id="<?php echo esc_attr($facet_id . '-toggle'); ?>" class="wicket-directory__facet-toggle"
					aria-controls="<?php echo esc_attr($facet_id); ?>" :aria-expanded="open.toString()" @click="open = !open">
					<span class="component-filter-form__filter-section-label"><?php echo esc_html($facet['label']); ?></span>
					<span class="wicket-directory__facet-status">
						<span class="component-filter-form__selected-items-indicator"
							x-show="selectedItemsCount > 0" x-text="`(${selectedItemsCount})`"
							<?php echo $count === 0 ? 'style="display: none;"' : ''; ?>>(<?php echo (int) $count; ?>)</span>
						<i class="fas fa-caret-up component-filter-form__collapse-icon" aria-hidden="true" x-show="open" x-cloak></i>
						<i class="fas fa-caret-down component-filter-form__expand-icon" aria-hidden="true" x-show="!open" x-cloak></i>
					</span>
				</button>

				<div id="<?php echo esc_attr($facet_id); ?>" role="group" aria-labelledby="<?php echo esc_attr($facet_id . '-toggle'); ?>" x-show="open">
					<ul class="wicket-directory__options">
						<?php foreach ($facet['options'] as $option_index => $option) : ?>
							<?php $option_id = $facet_id . '-' . $option_index; ?>
							<li class="wicket-directory__option"<?php echo $option_index >= $visible_options ? ' x-show="showAll"' : ''; ?>>
								<input type="checkbox" id="<?php echo esc_attr($option_id); ?>" class="wicket-directory__checkbox"
									name="<?php echo esc_attr($facet['name']); ?>" value="<?php echo esc_attr($option['value']); ?>"
									<?php echo in_array($option['value'], $facet['selected'], true) ? 'checked' : ''; ?>
									@change="selectedItemsCount += $event.target.checked ? 1 : -1">
								<label for="<?php echo esc_attr($option_id); ?>"><?php echo esc_html($option['label']); ?></label>
							</li>
						<?php endforeach; ?>
					</ul>

					<?php if (count($facet['options']) > $visible_options) : ?>
						<button type="button" class="component-filter-form__see-more wicket-directory__see-more" x-cloak
							aria-controls="<?php echo esc_attr($facet_id); ?>" :aria-expanded="showAll.toString()" @click="showAll = !showAll">
							<span x-show="!showAll"><?php esc_html_e('See more', 'wicket-directory'); ?></span>
							<span x-show="showAll"><?php esc_html_e('See less', 'wicket-directory'); ?></span>
						</button>
					<?php endif; ?>
				</div>
			</div>
		<?php endforeach; ?>

		<div class="wicket-directory__filters-actions">
			<?php get_component('button', [
			    'variant' => 'primary',
			    'label'   => esc_html__('Apply Filters', 'wicket-directory'),
			    'type'    => 'submit',
			    'classes' => ['component-filter-form__submit', 'wicket-directory__apply'],
			]); ?>

			<?php get_component('button', [
			    'variant'     => 'ghost',
			    'label'       => esc_html__('Clear All', 'wicket-directory'),
			    'a_tag'       => true,
			    'prefix_icon' => 'fa-solid fa-xmark',
			    'link'        => esc_url($args['clear_url']),
			    'classes'     => ['component-filter-form__clear-btn', 'wicket-directory__clear'],
			]); ?>
		</div>
	</div>
</div>
