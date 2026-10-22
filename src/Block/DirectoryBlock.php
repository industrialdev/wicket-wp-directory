<?php

declare(strict_types=1);

namespace Wicket\Directory\Block;

/**
 * The dynamic `wicket/directory` block: the only way to place a directory.
 *
 * block.json holds the attributes and assets. The render callback serves both
 * the frontend and the editor preview (ServerSideRender), so there is one
 * render path.
 */
final class DirectoryBlock
{
    /**
     * Block name, as in block.json.
     */
    public const NAME = 'wicket/directory';

    /**
     * Block category slug. The base plugin registers it only when ACF is
     * active, so add_category() adds it when it's missing.
     */
    public const CATEGORY = 'wicket';

    /**
     * Hook into WordPress.
     *
     * @return void
     */
    public function register(): void
    {
        add_action('init', [$this, 'register_block']);
        add_filter('block_categories_all', [$this, 'add_category']);
    }

    /**
     * Register the block from its block.json.
     *
     * @return void
     */
    public function register_block(): void
    {
        register_block_type(dirname(__DIR__, 2) . '/blocks/directory', [
            'render_callback' => [$this, 'render'],
        ]);
    }

    /**
     * Add the Wicket block category unless another plugin already has.
     *
     * @param array<int, array<string, mixed>> $categories Registered block categories.
     *
     * @return array<int, array<string, mixed>>
     */
    public function add_category(array $categories): array
    {
        foreach ($categories as $category) {
            if (($category['slug'] ?? null) === self::CATEGORY) {
                return $categories;
            }
        }

        $categories[] = [
            'slug'  => self::CATEGORY,
            'title' => __('Wicket', 'wicket-directory'),
            'icon'  => null,
        ];

        return $categories;
    }

    /**
     * Render the block, on the frontend and in the editor preview.
     *
     * The attributes are sanitized here, before anything reads them.
     *
     * @param array<string, mixed> $attributes Block attributes.
     *
     * @return string Block HTML.
     */
    public function render(array $attributes): string
    {
        $attributes = BlockAttributes::from_array($attributes);

        // The directory renderer isn't wired in yet; until it is, the block outputs nothing.
        return '';
    }
}
