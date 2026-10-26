<?php

declare(strict_types=1);

namespace Wicket\Directory\Block;

use Wicket\Directory\Config\DirectoryType;
use Wicket\Directory\Render\DirectoryRenderer;

/**
 * The dynamic `wicket/directory` block: the only way to place a directory.
 *
 * block.json holds the attributes and assets. The editor script is built from
 * blocks/directory into build/directory (`npm run build`), and the block
 * registers from the build, which carries a copy of block.json. The render
 * callback serves both the frontend and the editor preview (ServerSideRender),
 * so there is one render path.
 *
 * directory.css is the block's `viewStyle` and `editorStyle`, not its `style`:
 * classic themes load every block's `style` on every page, but a view style
 * only loads when the block renders. See enqueue_style() for the frontend.
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
     * Global the editor script reads its data from; see editor_data().
     */
    public const EDITOR_DATA_VAR = 'wicketDirectoryEditor';

    /**
     * Renders the block; see renderer().
     */
    private ?DirectoryRenderer $renderer;

    /**
     * @param DirectoryRenderer|null $renderer Defaults to one made on first render. Tests pass one.
     */
    public function __construct(?DirectoryRenderer $renderer = null)
    {
        $this->renderer = $renderer;
    }

    /**
     * Hook into WordPress.
     *
     * @return void
     */
    public function register(): void
    {
        add_action('init', [$this, 'register_block']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_style']);
        add_action('enqueue_block_editor_assets', [$this, 'enqueue_editor_data']);
        add_filter('block_categories_all', [$this, 'add_category']);
    }

    /**
     * Register the block from the built block.json.
     *
     * @return void
     */
    public function register_block(): void
    {
        register_block_type(dirname(__DIR__, 2) . '/build/directory', [
            'render_callback' => [$this, 'render'],
        ]);
    }

    /**
     * Load directory.css in the `<head>` of a post whose content has the block.
     *
     * WordPress enqueues a view style when the block renders, which on a
     * classic theme is after `wp_head`, so the stylesheet would print in the
     * footer and the directory would show unstyled first. Elsewhere (a widget,
     * a synced pattern, a template) that footer load still applies.
     *
     * @return void
     */
    public function enqueue_style(): void
    {
        if (is_singular() && has_block(self::NAME, get_queried_object_id())) {
            wp_enqueue_style(generate_block_asset_handle(self::NAME, 'viewStyle'));
        }
    }

    /**
     * Give the editor script the sort options of each directory type.
     *
     * The Default Sort Order control lists them for the selected directory's
     * type. They come from DirectoryType so the editor offers exactly the
     * tokens BlockAttributes accepts, with the same translated labels.
     *
     * @return void
     */
    public function enqueue_editor_data(): void
    {
        wp_add_inline_script(
            generate_block_asset_handle(self::NAME, 'editorScript'),
            sprintf('window.%s = %s;', self::EDITOR_DATA_VAR, wp_json_encode(self::editor_data(), JSON_HEX_TAG | JSON_HEX_AMP)),
            'before'
        );
    }

    /**
     * Data for the editor script.
     *
     * @return array{sortOptions: array<string, list<array{value: string, label: string}>>}
     */
    public static function editor_data(): array
    {
        $sort_options = [];

        foreach (DirectoryType::cases() as $type) {
            foreach ($type->sort_options() as $value => $label) {
                $sort_options[$type->value][] = ['value' => $value, 'label' => $label];
            }
        }

        return ['sortOptions' => $sort_options];
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
        return $this->renderer()->render(BlockAttributes::from_array($attributes));
    }

    /**
     * The directory renderer, made on first use so the block can register
     * without building the API and label services.
     *
     * @return DirectoryRenderer
     */
    private function renderer(): DirectoryRenderer
    {
        return $this->renderer ??= new DirectoryRenderer();
    }
}
