<?php

declare(strict_types=1);

namespace Wicket\Directory\Render;

use Closure;
use Throwable;
use Wicket\Directory\Api\DirectoryRepository;
use Wicket\Directory\Block\BlockAttributes;
use Wicket\Directory\Config\DirectoryConfig;
use Wicket\Directory\Data\EntryMapper;
use Wicket\Directory\Data\FacetOptions;
use Wicket\Directory\PostType\DirectoryPostType;
use Wicket\Directory\Query\QueryBuilder;
use Wicket\Directory\Query\RequestParams;
use WP_Post;

/**
 * Renders one placed directory: the single render path.
 *
 * The block's render callback calls it on the frontend, and the editor's
 * ServerSideRender reaches it through the same callback, so the preview is the
 * live output.
 *
 * A directory that is missing or not published renders nothing for visitors.
 * In the editor preview it renders a notice instead (parts/notice.php).
 */
final class DirectoryRenderer
{
    /**
     * Log source, as DirectoryRepository uses.
     */
    public const LOG_SOURCE = 'wicket-directory';

    /**
     * Runs one page query.
     */
    private DirectoryRepository $repository;

    /**
     * Turns a result page into card entries.
     */
    private EntryMapper $mapper;

    /**
     * Facet choices.
     */
    private FacetOptions $facets;

    /**
     * Finds and renders templates.
     */
    private TemplateLoader $templates;

    /**
     * Tells whether this render is the editor preview.
     *
     * @var Closure(): bool
     */
    private Closure $is_preview;

    /**
     * @param DirectoryRepository|null $repository Defaults to one using wicket_api_client().
     * @param EntryMapper|null         $mapper     Defaults to one using the shared labels.
     * @param FacetOptions|null        $facets     Defaults to one using the shared labels.
     * @param TemplateLoader|null      $templates  Defaults to the plugin's templates.
     * @param (callable(): bool)|null  $is_preview Whether the render is the editor preview.
     *                                             Defaults to is_editor_preview(). Tests pass one.
     */
    public function __construct(
        ?DirectoryRepository $repository = null,
        ?EntryMapper $mapper = null,
        ?FacetOptions $facets = null,
        ?TemplateLoader $templates = null,
        ?callable $is_preview = null,
    ) {
        $this->repository = $repository ?? new DirectoryRepository();
        $this->mapper = $mapper ?? new EntryMapper();
        $this->facets = $facets ?? new FacetOptions();
        $this->templates = $templates ?? new TemplateLoader();
        $this->is_preview = $is_preview !== null
            ? Closure::fromCallable($is_preview)
            : static fn (): bool => self::is_editor_preview();
    }

    /**
     * Render a placed directory.
     *
     * Order: load the directory, parse the visitor's params, fire
     * `wicket_directory/before_render` (and `_{slug}`), query the API, map the
     * results, then render `directory.php`. Output a before_render callback
     * prints comes before the directory.
     *
     * @param BlockAttributes $attributes The block's sanitized attributes.
     *
     * @return string HTML; '' for visitors when there is nothing to show.
     */
    public function render(BlockAttributes $attributes): string
    {
        if ($attributes->directory_id === 0) {
            return $this->notice(__('Select a directory in the block settings.', 'wicket-directory'));
        }

        $directory = get_post($attributes->directory_id);

        if (!$directory instanceof WP_Post || $directory->post_type !== DirectoryPostType::POST_TYPE || $directory->post_status === 'trash') {
            return $this->notice(__('The selected directory no longer exists. Select another one in the block settings.', 'wicket-directory'));
        }

        if ($directory->post_status !== 'publish') {
            return $this->notice(__('This directory isn’t published, so visitors don’t see it. Publish it to show it on this page.', 'wicket-directory'));
        }

        $config = DirectoryConfig::from_post($directory);

        if ($config === null) {
            return '';
        }

        $level = ob_get_level();
        ob_start();

        try {
            $this->render_directory($directory, $config, $attributes);

            return (string) ob_get_clean();
        } catch (Throwable $e) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }

            $this->log_error($directory, 'Could not render the directory: ' . $e->getMessage());

            return $this->notice(__('This directory could not be displayed. Check the log for details.', 'wicket-directory'));
        }
    }

    /**
     * Print one published directory.
     *
     * @param WP_Post         $directory  The directory post.
     * @param DirectoryConfig $config     Its config.
     * @param BlockAttributes $attributes The block's attributes.
     *
     * @return void
     */
    private function render_directory(WP_Post $directory, DirectoryConfig $config, BlockAttributes $attributes): void
    {
        $lang = QueryBuilder::language(wicket_get_current_language());

        // Hidden filters ignore their params, so their choices aren't needed.
        $facet_options = $attributes->hide_filters ? [] : $this->facets->for_config($config, $lang);

        $params = RequestParams::from_request(
            $directory->ID,
            $config,
            FacetOptions::values($facet_options),
            $attributes->sort($config->type),
            hide_keyword: $attributes->hide_keyword,
            hide_location: $attributes->hide_location,
            hide_filters: $attributes->hide_filters,
            hide_order_by: $attributes->hide_order_by,
        );

        do_action('wicket_directory/before_render', $config, $params, $directory);

        if ($directory->post_name !== '') {
            do_action('wicket_directory/before_render_' . $directory->post_name, $config, $params, $directory);
        }

        $page = $this->repository->fetch($directory, $config, $params, $attributes->results_per_page, $lang);
        $entries = $this->mapper->map($page, $config, $directory->post_name, $lang);
        $is_preview = ($this->is_preview)();
        $prefix = RequestParams::prefix($directory->ID);

        $this->templates->load('directory', [
            'directory'     => $directory,
            'config'        => $config,
            'attributes'    => $attributes,
            'params'        => $params,
            'page'          => $page,
            'entries'       => $entries,
            'facet_options' => $facet_options,
            'show_filters'  => self::has_choices($facet_options),
            'card_template' => $this->card_template($directory, $config),
            'hidden_fields' => $is_preview ? [] : self::hidden_fields($_GET, $prefix),
            'clear_url'     => $is_preview ? '#' : self::clear_url($_GET, $prefix),
            'html_id'       => 'wicket-directory-' . $directory->ID,
            'is_preview'    => $is_preview,
            'lang'          => $lang,
            'templates'     => $this->templates,
        ]);
    }

    /**
     * The card template file for a directory.
     *
     * `cards/card-{type}`, as located by TemplateLoader, then the
     * `wicket_directory/card_template` filter. A filter result that isn't an
     * existing file keeps the located one.
     *
     * @param WP_Post         $directory The directory post.
     * @param DirectoryConfig $config    Its config.
     *
     * @return string Absolute path; '' when there is no card template.
     */
    private function card_template(WP_Post $directory, DirectoryConfig $config): string
    {
        $located = $this->templates->locate('cards/card-' . $config->type->value);
        $file = apply_filters('wicket_directory/card_template', $located, $config, $directory);

        return is_string($file) && $file !== '' && is_file($file) ? $file : $located;
    }

    /**
     * The editor notice, or nothing for visitors.
     *
     * @param string $message Plain-text message; the template escapes it.
     *
     * @return string
     */
    private function notice(string $message): string
    {
        if (!($this->is_preview)()) {
            return '';
        }

        return $this->templates->render('parts/notice', [
            'message'   => $message,
            'templates' => $this->templates,
        ]);
    }

    /**
     * Whether any facet has choices to show.
     *
     * @param array<string, list<array{value: string, label: string}>> $facet_options Facet key => choices.
     *
     * @return bool
     */
    private static function has_choices(array $facet_options): bool
    {
        foreach ($facet_options as $options) {
            if ($options !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * The current query vars that don't belong to this directory, as hidden fields.
     *
     * A GET form replaces the page's whole query string. These fields keep
     * other directories' params, and any others on the page, when a visitor
     * searches this one. This directory's own params (including its page
     * number) are left out, so a new search starts on page 1.
     *
     * @param array<mixed> $query  Raw, slashed query vars.
     * @param string       $prefix This directory's param prefix, e.g. `wd12_`.
     *
     * @return list<array{name: string, value: string}>
     */
    private static function hidden_fields(array $query, string $prefix): array
    {
        $fields = [];

        foreach (wp_unslash($query) as $name => $value) {
            $name = (string) $name;

            if (!str_starts_with($name, $prefix)) {
                self::flatten($name, $value, $fields, 0);
            }
        }

        return $fields;
    }

    /**
     * The current page's URL without this directory's params.
     *
     * Clear All links here: it resets this directory's search, filters, sort
     * and page, and keeps everything else in the query string (another
     * directory's params, `page_id`, …).
     *
     * @param array<mixed> $query  Raw, slashed query vars.
     * @param string       $prefix This directory's param prefix, e.g. `wd12_`.
     *
     * @return string Unescaped URL.
     */
    private static function clear_url(array $query, string $prefix): string
    {
        $own = array_filter(
            array_map('strval', array_keys($query)),
            static fn (string $name): bool => str_starts_with($name, $prefix),
        );
        $url = isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';

        // remove_query_arg() gives back the URL as-is when there's nothing to remove.
        return remove_query_arg(array_values($own), $url);
    }

    /**
     * Add one query var to a list of fields, nested arrays as `name[key]`.
     *
     * @param string                                   $name   Field name so far.
     * @param mixed                                    $value  Value.
     * @param list<array{name: string, value: string}> $fields Collected fields.
     * @param int                                      $depth  Nesting level; deeper than 3 is dropped.
     *
     * @return void
     */
    private static function flatten(string $name, mixed $value, array &$fields, int $depth): void
    {
        if (is_array($value)) {
            if ($depth < 3) {
                foreach ($value as $key => $item) {
                    self::flatten($name . '[' . $key . ']', $item, $fields, $depth + 1);
                }
            }

            return;
        }

        if (is_scalar($value)) {
            $fields[] = ['name' => $name, 'value' => (string) $value];
        }
    }

    /**
     * Whether this request is the block editor's preview (ServerSideRender).
     *
     * The preview is a REST request from a user who can edit posts. The
     * block-renderer endpoint requires that capability, so a visitor never
     * gets the editor notices.
     *
     * @return bool
     */
    private static function is_editor_preview(): bool
    {
        $rest = function_exists('wp_is_serving_rest_request')
            ? wp_is_serving_rest_request()
            : defined('REST_REQUEST') && REST_REQUEST;

        return $rest && current_user_can('edit_posts');
    }

    /**
     * Log a render failure.
     *
     * @param WP_Post $directory The directory post.
     * @param string  $message   What went wrong.
     *
     * @return void
     */
    private function log_error(WP_Post $directory, string $message): void
    {
        if (!function_exists('Wicket')) {
            return;
        }

        Wicket()->log()->error($message, [
            'source'       => self::LOG_SOURCE,
            'directory_id' => $directory->ID,
        ]);
    }
}
