<?php

declare(strict_types=1);

namespace Wicket\Directory\Render;

use Closure;
use Throwable;
use Wicket\Directory\Api\DirectoryRepository;
use Wicket\Directory\Api\ResultPage;
use Wicket\Directory\Block\BlockAttributes;
use Wicket\Directory\Config\DirectoryConfig;
use Wicket\Directory\Config\DirectoryType;
use Wicket\Directory\Data\EntryMapper;
use Wicket\Directory\Data\EnumLabels;
use Wicket\Directory\Data\FacetOptions;
use Wicket\Directory\PostType\DirectoryPostType;
use Wicket\Directory\Query\QueryBuilder;
use Wicket\Directory\Query\RequestParams;
use Wicket\Directory\Template\MergeTags;
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
 *
 * A group directory can show one group's member list (the roster) instead of
 * its group list, through the same `directory.php`: always, in the Group
 * Members view (the group from its settings), or when the visitor followed a
 * View Members link, in the Group List + Member List view (`wd{ID}_group`).
 * The group is looked up with every eligibility key first, so an inactive or
 * excluded group's members can't be reached; a link to one shows the list.
 */
final class DirectoryRenderer
{
    /**
     * Log source, as DirectoryRepository uses.
     */
    public const LOG_SOURCE = 'wicket-directory';

    /**
     * The card template of a card laid out by an Advance template
     * (DirectoryConfig::template()): it renders the template for each entry.
     */
    public const ADVANCE_CARD = 'cards/card-template';

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
     * Role labels, for a member list's keyword, and data-field labels for Advance cards.
     */
    private EnumLabels $labels;

    /**
     * @param DirectoryRepository|null $repository Defaults to one using wicket_api_client().
     * @param EntryMapper|null         $mapper     Defaults to one using the shared labels.
     * @param FacetOptions|null        $facets     Defaults to one using the shared labels.
     * @param TemplateLoader|null      $templates  Defaults to the plugin's templates.
     * @param (callable(): bool)|null  $is_preview Whether the render is the editor preview.
     *                                             Defaults to is_editor_preview(). Tests pass one.
     * @param EnumLabels|null          $labels     Role labels for member lists, and data-field labels for
     *                                             Advance cards' tags. Defaults to the shared labels.
     */
    public function __construct(
        ?DirectoryRepository $repository = null,
        ?EntryMapper $mapper = null,
        ?FacetOptions $facets = null,
        ?TemplateLoader $templates = null,
        ?callable $is_preview = null,
        ?EnumLabels $labels = null,
    ) {
        $this->labels = $labels ?? EnumLabels::shared();
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

        // An Advance card's toggles follow its template (for_render()), for the batches and the mapper.
        $config = DirectoryConfig::from_post($directory)?->for_render();

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
     * Print one published directory: its list, or a group's member list.
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

        if ($config->has_roster()) {
            $members_view = $config->roster['default_view'] === DirectoryConfig::VIEW_MEMBERS;
            $uuid = $members_view ? $config->roster['group'] : RequestParams::requested_group($directory->ID);

            if ($members_view && $uuid === '') {
                echo $this->notice(__('Choose the group whose members this directory shows, in the directory\'s settings.', 'wicket-directory'));

                return;
            }

            if ($uuid !== '') {
                $group = $this->repository->group($directory, $config, $uuid);

                if ($group !== null) {
                    $this->render_roster($directory, $config, $attributes, $group, $lang);

                    return;
                }

                if ($members_view) {
                    echo $this->notice(__('The group this directory shows can\'t be displayed. It may be inactive, excluded or of another type in the directory\'s settings, or Wicket couldn\'t be reached.', 'wicket-directory'));

                    return;
                }

                // An unknown, ineligible or unreachable group: show the list, as if there were no link.
            }
        }

        $this->render_list($directory, $config, $attributes, $lang);
    }

    /**
     * Print the directory's own list: people, organizations or groups.
     *
     * @param WP_Post         $directory  The directory post.
     * @param DirectoryConfig $config     Its config.
     * @param BlockAttributes $attributes The block's attributes.
     * @param string          $lang       Two-letter language code.
     *
     * @return void
     */
    private function render_list(WP_Post $directory, DirectoryConfig $config, BlockAttributes $attributes, string $lang): void
    {
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

        $this->before_render($directory, $config, $params);

        $is_preview = ($this->is_preview)();
        $members_url = null;

        // Only the Group List + Member List view has member lists for View Members to open.
        if ($config->type === DirectoryType::Group && $config->roster['default_view'] === DirectoryConfig::VIEW_GROUPS_MEMBERS) {
            $members_url = $is_preview
                ? static fn (string $uuid): string => '#'
                : static fn (string $uuid): string => self::members_url($_GET, $directory->ID, $uuid);
        }

        $page = $this->repository->fetch($directory, $config, $params, $attributes->results_per_page, $lang);
        $entries = $this->mapper->map($page, $config, $directory->post_name, $lang, $members_url);

        $this->load_directory($directory, $config, $attributes, $params, $page, $entries, $facet_options, [
            'type'            => $config->type,
            'card'            => $config->card,
            'facets'          => $config->facets,
            'collapse_refine' => $attributes->collapse_refine_on_mobile,
            'roster'          => null,
            'card_template'   => $this->card_template($directory, $config, $config->template() !== null ? self::ADVANCE_CARD : 'cards/card-' . $config->type->value),
        ], $lang);
    }

    /**
     * Print one group's member list.
     *
     * Members are people: the individual sort options and location search
     * apply, with params of their own (RequestParams::for_roster()). The
     * card is the Group Member card (`cards/card-group-member`) and the
     * facets are the member list's, both from the directory's config. In
     * the Group List + Member List view the block's member list settings
     * apply and the header links back to the group list; in the Group
     * Members view the member list is the directory, so the block's main
     * settings apply.
     *
     * @param WP_Post              $directory  The directory post.
     * @param DirectoryConfig      $config     Its config.
     * @param BlockAttributes      $attributes The block's attributes.
     * @param array<string, mixed> $group      The `groups` resource, from DirectoryRepository::group().
     * @param string               $lang       Two-letter language code.
     *
     * @return void
     */
    private function render_roster(WP_Post $directory, DirectoryConfig $config, BlockAttributes $attributes, array $group, string $lang): void
    {
        $with_list = $config->roster['default_view'] === DirectoryConfig::VIEW_GROUPS_MEMBERS;
        $uuid = strtolower((string) $group['id']);
        $is_preview = ($this->is_preview)();

        // Hidden filters ignore their params, so their choices aren't needed.
        $facet_options = $attributes->hide_filters ? [] : $this->facets->for_members($config, $lang);

        // With the group list, the block's sort is a group sort, so the list starts on the individual default.
        $params = RequestParams::for_roster(
            $directory->ID,
            $uuid,
            $config->member_facets,
            FacetOptions::values($facet_options),
            $attributes->sort(DirectoryType::Individual),
            hide_keyword: $attributes->hide_keyword,
            hide_location: $attributes->hide_location,
            hide_filters: $attributes->hide_filters,
            hide_order_by: $attributes->hide_order_by,
        );

        $this->before_render($directory, $config, $params);

        $roles = $params->keyword !== '' ? $this->labels->group_member_roles($lang) : [];
        $per_page = $with_list ? $attributes->roster_per_page() : $attributes->results_per_page;
        $page = $this->repository->fetch_roster($directory, $config, $uuid, $params, $per_page, $roles, $lang);
        $entries = $this->mapper->map_members($page, $config, $directory->post_name, $lang);

        $back_url = '';

        if ($with_list) {
            $back_url = $is_preview ? '#' : self::back_url($_GET, $directory->ID);
        }

        $this->load_directory($directory, $config, $attributes, $params, $page, $entries, $facet_options, [
            'type'            => DirectoryType::Individual,
            'card'            => $config->member_card,
            'facets'          => $config->member_facets,
            'collapse_refine' => $with_list ? $attributes->collapse_roster_refine_on_mobile : $attributes->collapse_refine_on_mobile,
            'roster'          => [
                'group'    => $this->mapper->roster_group($group, $lang),
                'back_url' => $back_url,
            ],
            'card_template'   => $this->card_template($directory, $config, $config->template(true) !== null ? self::ADVANCE_CARD : 'cards/card-group-member'),
        ], $lang);
    }

    /**
     * Fire `wicket_directory/before_render` and its `_{slug}` variant.
     *
     * @param WP_Post         $directory The directory post.
     * @param DirectoryConfig $config    Its config.
     * @param RequestParams   $params    The params of what is about to render.
     *
     * @return void
     */
    private function before_render(WP_Post $directory, DirectoryConfig $config, RequestParams $params): void
    {
        do_action('wicket_directory/before_render', $config, $params, $directory);

        if ($directory->post_name !== '') {
            do_action('wicket_directory/before_render_' . $directory->post_name, $config, $params, $directory);
        }
    }

    /**
     * Render `directory.php` for a list or a member list.
     *
     * @param WP_Post                                                  $directory     The directory post.
     * @param DirectoryConfig                                          $config        Its config.
     * @param BlockAttributes                                          $attributes    The block's attributes.
     * @param RequestParams                                            $params        The rendered list's params.
     * @param ResultPage                                               $page          The page of results.
     * @param list<\Wicket\Directory\Data\Entry>                         $entries       Its entries.
     * @param array<string, list<array{value: string, label: string}>> $facet_options Facet choices.
     * @param array<string, mixed>                                     $view          `type`, `card`, `facets`,
     *                                                                                `collapse_refine`, `roster`
     *                                                                                and `card_template`.
     * @param string                                                   $lang          Two-letter language code.
     *
     * @return void
     */
    private function load_directory(
        WP_Post $directory,
        DirectoryConfig $config,
        BlockAttributes $attributes,
        RequestParams $params,
        ResultPage $page,
        array $entries,
        array $facet_options,
        array $view,
        string $lang,
    ): void {
        $is_preview = ($this->is_preview)();
        $prefix = $params->prefix;

        $this->templates->load('directory', $view + [
            'directory'     => $directory,
            'config'        => $config,
            'attributes'    => $attributes,
            'params'        => $params,
            'page'          => $page,
            'entries'       => $entries,
            'facet_options' => $facet_options,
            'show_filters'  => self::has_choices($facet_options),
            'hidden_fields' => $is_preview ? [] : self::hidden_fields($_GET, $prefix),
            'clear_url'     => $is_preview ? '#' : self::clear_url($_GET, $prefix),
            'html_id'       => self::html_id($directory->ID),
            'is_preview'    => $is_preview,
            'wicket_theme'  => function_exists('is_wicket_theme_active') && is_wicket_theme_active(),
            'lang'          => $lang,
            'templates'     => $this->templates,
            // An Advance card's tag resolver (cards/card-template.php), one per list.
            'merge_tags'    => new MergeTags($this->labels),
        ]);
    }

    /**
     * The card template file for a directory.
     *
     * `$name` (`cards/card-{type}`, or `cards/card-group-member` for a
     * member list; ADVANCE_CARD for a card with an Advance template), as
     * located by TemplateLoader, then the
     * `wicket_directory/card_template` filter. A filter result that isn't an
     * existing file keeps the located one.
     *
     * @param WP_Post         $directory The directory post.
     * @param DirectoryConfig $config    Its config.
     * @param string          $name      Template name.
     *
     * @return string Absolute path; '' when there is no card template.
     */
    private function card_template(WP_Post $directory, DirectoryConfig $config, string $name): string
    {
        $located = $this->templates->locate($name);
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
     * number) are left out, so a new search starts on page 1. For a member
     * list, `$prefix` is the member list's: the group and the group list's
     * params are kept, so the search stays on the group and Back to
     * Directory still returns to the same list.
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
     * directory's params, `page_id`, …). For a member list, `$prefix` is the
     * member list's, so the group and the group list's params stay.
     *
     * @param array<mixed> $query  Raw, slashed query vars.
     * @param string       $prefix This directory's param prefix, e.g. `wd12_`.
     *
     * @return string Unescaped URL.
     */
    private static function clear_url(array $query, string $prefix): string
    {
        return self::without_params($query, $prefix);
    }

    /**
     * The id of a directory's wrapper element, also the fragment its member
     * list links jump to.
     *
     * @param int $directory_id Directory post ID.
     *
     * @return string
     */
    private static function html_id(int $directory_id): string
    {
        return 'wicket-directory-' . $directory_id;
    }

    /**
     * The View Members link: the current page with `wd{ID}_group` set.
     *
     * It keeps the group list's params, so Back to Directory returns to the
     * same search and page, and drops any member list params, so each group's
     * list starts fresh.
     *
     * @param array<mixed> $query        Raw, slashed query vars.
     * @param int          $directory_id Directory post ID.
     * @param string       $uuid         The group's UUID.
     *
     * @return string Unescaped URL.
     */
    private static function members_url(array $query, int $directory_id, string $uuid): string
    {
        $url = self::without_params($query, RequestParams::roster_prefix($directory_id), [RequestParams::prefix($directory_id) . RequestParams::GROUP_PARAM]);

        return add_query_arg(RequestParams::prefix($directory_id) . RequestParams::GROUP_PARAM, rawurlencode($uuid), $url) . '#' . self::html_id($directory_id);
    }

    /**
     * The Back to Directory link: the current page without the group and the
     * member list's params, so the group list's own search and page are back.
     *
     * @param array<mixed> $query        Raw, slashed query vars.
     * @param int          $directory_id Directory post ID.
     *
     * @return string Unescaped URL.
     */
    private static function back_url(array $query, int $directory_id): string
    {
        return self::without_params($query, RequestParams::roster_prefix($directory_id), [RequestParams::prefix($directory_id) . RequestParams::GROUP_PARAM])
            . '#' . self::html_id($directory_id);
    }

    /**
     * The current page's URL without the params under a prefix, and some others.
     *
     * @param array<mixed> $query  Raw, slashed query vars.
     * @param string       $prefix Params to drop, by prefix.
     * @param list<string> $names  More params to drop, by name.
     *
     * @return string Unescaped URL.
     */
    private static function without_params(array $query, string $prefix, array $names = []): string
    {
        $drop = array_filter(
            array_map('strval', array_keys($query)),
            static fn (string $name): bool => str_starts_with($name, $prefix) || in_array($name, $names, true),
        );
        $url = isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';

        // remove_query_arg() gives back the URL as-is when there's nothing to remove.
        return remove_query_arg(array_values($drop), $url);
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
