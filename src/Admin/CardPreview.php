<?php

declare(strict_types=1);

namespace Wicket\Directory\Admin;

use Throwable;
use Wicket\Directory\Block\DirectoryBlock;
use Wicket\Directory\Config\DirectoryConfig;
use Wicket\Directory\PostType\DirectoryPostType;
use Wicket\Directory\Render\DirectoryRenderer;
use WP_Error;
use WP_Post;
use WP_REST_Request;

/**
 * The edit screen's live card preview: its REST endpoint and its panel.
 *
 * The Card View tab shows one card as the settings on the screen stand,
 * before they're saved. `assets/js/admin.js` posts the settings box's fields
 * (`wicket_directory[...]`) to the endpoint as the admin changes them. The
 * endpoint lays them over the stored config and sanitizes the result as the
 * save handler does (config()), then renders one card through
 * DirectoryRenderer::render_preview(): the one render path, so the card is
 * the one visitors and the block editor preview get. Nothing is saved.
 *
 * The card shows in a frame whose document (frame()) holds the stylesheets
 * a page of the site loads, so the theme styles it as on the site, and the
 * theme's breakpoints follow the frame's width (the Desktop / Tablet /
 * Mobile toggle). The frame runs no script.
 *
 * The endpoint is `POST /wicket-directory/v1/directories/{id}/preview`. It
 * needs the REST nonce (`X-WP-Nonce`: WordPress's cookie authentication
 * treats a request without it as logged out) and a user who can manage
 * directories and edit this one.
 */
final class CardPreview
{
    /**
     * REST namespace.
     */
    public const REST_NAMESPACE = 'wicket-directory/v1';

    /**
     * REST route, under the namespace.
     */
    public const REST_ROUTE = '/directories/(?P<id>\d+)/preview';

    /**
     * `card` value of the main card (the only one outside group directories).
     */
    public const CARD_MAIN = 'card';

    /**
     * `card` value of a group directory's Group Member card.
     */
    public const CARD_MEMBER = 'member';

    /**
     * Id of the element in the frame that holds the card.
     */
    public const FRAME_ROOT = 'wicket-directory-preview-root';

    /**
     * Renders the card; built on first use.
     */
    private ?DirectoryRenderer $renderer;

    /**
     * @param DirectoryRenderer|null $renderer Defaults to one with the plugin's repository,
     *                                         mapper and templates. Tests pass one.
     */
    public function __construct(?DirectoryRenderer $renderer = null)
    {
        $this->renderer = $renderer;
    }

    /**
     * Hook into WordPress.
     *
     * Not admin-only: REST requests aren't admin requests.
     *
     * @return void
     */
    public function register(): void
    {
        add_action('rest_api_init', [$this, 'register_route']);
    }

    /**
     * Register the preview endpoint.
     *
     * @return void
     */
    public function register_route(): void
    {
        register_rest_route(self::REST_NAMESPACE, self::REST_ROUTE, [
            'methods'             => 'POST',
            'callback'            => [$this, 'respond'],
            'permission_callback' => [$this, 'can_preview'],
            'args'                => [
                'id'     => [
                    'type'     => 'integer',
                    'required' => true,
                    'minimum'  => 1,
                ],
                'card'   => [
                    'type'    => 'string',
                    'enum'    => [self::CARD_MAIN, self::CARD_MEMBER],
                    'default' => self::CARD_MAIN,
                ],
                'sample' => [
                    'type'    => 'integer',
                    'minimum' => 1,
                    'default' => 1,
                ],
                'frame'  => [
                    'type'    => 'boolean',
                    'default' => false,
                ],
            ],
        ]);
    }

    /**
     * Whether the current user can preview this directory: the capability
     * that manages directories, and edit rights on the post.
     *
     * @param WP_REST_Request $request The request.
     *
     * @return bool
     */
    public function can_preview(WP_REST_Request $request): bool
    {
        $id = (int) $request->get_param('id');

        return $id > 0 && current_user_can(DirectoryPostType::CAPABILITY) && current_user_can('edit_post', $id);
    }

    /**
     * Render the preview.
     *
     * Body params: `wicket_directory` (the settings box's fields; sections
     * that aren't posted keep their stored value), `card` (CARD_MAIN or
     * CARD_MEMBER), `sample` (which matching record, from 1) and `frame`
     * (also return the frame's document, for the first request).
     *
     * @param WP_REST_Request $request The request.
     *
     * @return array<string, mixed>|WP_Error DirectoryRenderer::render_preview()'s result, plus
     *                                       `frame` when asked; a 404 for a post that isn't a directory.
     */
    public function respond(WP_REST_Request $request): array|WP_Error
    {
        $post = get_post((int) $request->get_param('id'));

        if (!$post instanceof WP_Post || $post->post_type !== DirectoryPostType::POST_TYPE || $post->post_status === 'trash') {
            return new WP_Error('wicket_directory_not_found', __('The directory doesn\'t exist.', 'wicket-directory'), ['status' => 404]);
        }

        $posted = $request->get_param(DirectoryMetaBoxes::FIELD);
        $config = self::config($post, is_array($posted) ? $posted : []);

        $preview = $this->renderer()->render_preview(
            $post,
            $config,
            $request->get_param('card') === self::CARD_MEMBER,
            max(1, (int) $request->get_param('sample')),
        );

        if ((bool) $request->get_param('frame')) {
            $preview['frame'] = self::frame();
        }

        return $preview;
    }

    /**
     * The config the preview shows: the posted sections over the stored
     * config, sanitized as DirectoryMetaBoxes::save() would save them.
     *
     * @param WP_Post      $post   The directory post.
     * @param array<mixed> $posted The posted `wicket_directory` array, unslashed (REST body params are).
     *
     * @return DirectoryConfig
     */
    public static function config(WP_Post $post, array $posted): DirectoryConfig
    {
        $previous = DirectoryConfig::from_post($post) ?? DirectoryConfig::defaults();

        return DirectoryConfig::sanitize(DirectoryMetaBoxes::merge($previous->to_array(), $posted), $previous);
    }

    /**
     * The args of the Card View's preview panel (`views/preview.php`).
     *
     * @param WP_Post $post Directory being edited.
     *
     * @return array<string, mixed>
     */
    public static function panel_args(WP_Post $post): array
    {
        return [
            'url'   => rest_url(self::REST_NAMESPACE . '/directories/' . $post->ID . '/preview'),
            'nonce' => wp_create_nonce('wp_rest'),
            'texts' => [
                'loading'     => __('Updating the preview…', 'wicket-directory'),
                /* translators: 1: The record's number, 2: how many records the directory lists. */
                'record'      => __('Record %1$s of %2$s, from Wicket.', 'wicket-directory'),
                /* translators: 1: The member's number, 2: how many members the list has, 3: group name. */
                'member'      => __('Member %1$s of %2$s in %3$s, from Wicket.', 'wicket-directory'),
                DirectoryRenderer::SAMPLE_NONE        => __('Sample data: no record in Wicket matches these settings yet.', 'wicket-directory'),
                DirectoryRenderer::SAMPLE_UNAVAILABLE => __('Sample data: Wicket couldn\'t be reached. Check the log for details.', 'wicket-directory'),
                DirectoryRenderer::SAMPLE_NO_GROUP    => __('Sample data: there\'s no group to show a member of. Choose the group in the Group Members view, or check which groups this directory lists.', 'wicket-directory'),
                'error'       => __('The preview couldn\'t be updated. Reload the page if this keeps happening.', 'wicket-directory'),
            ],
            'widths' => [
                'desktop' => [__('Desktop', 'wicket-directory'), 'dashicons-desktop', 1280],
                'tablet'  => [__('Tablet', 'wicket-directory'), 'dashicons-tablet', 768],
                'mobile'  => [__('Mobile', 'wicket-directory'), 'dashicons-smartphone', 375],
            ],
        ];
    }

    /**
     * The frame's document: the site's stylesheets and an empty root for the card.
     *
     * @return string HTML document.
     */
    public static function frame(): string
    {
        // Visitors see no admin bar: leave out its styles and the `admin-bar` body class.
        $no_admin_bar = fn (): bool => false;
        add_filter('show_admin_bar', $no_admin_bar, PHP_INT_MAX);

        try {
            $args = [
                'styles'     => self::frontend_styles(),
                'body_class' => self::body_class(),
                'root'       => self::FRAME_ROOT,
            ];
        } finally {
            remove_filter('show_admin_bar', $no_admin_bar, PHP_INT_MAX);
        }

        ob_start();

        // A static closure, so the view sees $args and not this class.
        (static function (array $args): void {
            include __DIR__ . '/views/preview-frame.php';
        })($args);

        return (string) ob_get_clean();
    }

    /**
     * The stylesheets a page of the site prints in its head, as tags.
     *
     * Runs `wp_enqueue_scripts`, as a page does, then prints only the styles
     * (with their inline CSS, so the theme's tokens and global styles come
     * along), directory.css, font faces and the Customizer's Additional CSS.
     * Scripts aren't printed: the card needs none, and analytics, consent
     * banners and the like stay out of the frame. Output the hook prints is
     * dropped; a callback that throws leaves whatever was enqueued so far.
     *
     * @return string `<link>` and `<style>` tags.
     */
    private static function frontend_styles(): string
    {
        $level = ob_get_level();
        ob_start();

        try {
            do_action('wp_enqueue_scripts');
        } catch (Throwable) {
            // Keep what was enqueued before the failure.
        }

        while (ob_get_level() > $level) {
            ob_end_clean();
        }

        wp_enqueue_style(generate_block_asset_handle(DirectoryBlock::NAME, 'viewStyle'));

        ob_start();
        wp_print_styles();

        if (function_exists('wp_print_font_faces')) {
            wp_print_font_faces();
        }

        wp_custom_css_cb();

        return (string) ob_get_clean();
    }

    /**
     * The frame's body classes: the site's (e.g. the Wicket theme's
     * `wicket-theme-v2`), plus the frame's own.
     *
     * @return string Space-separated, unescaped.
     */
    private static function body_class(): string
    {
        try {
            $classes = get_body_class('wicket-directory-preview-frame');
        } catch (Throwable) {
            $classes = ['wicket-directory-preview-frame'];
        }

        return implode(' ', array_map('strval', $classes));
    }

    /**
     * The renderer.
     *
     * @return DirectoryRenderer
     */
    private function renderer(): DirectoryRenderer
    {
        return $this->renderer ??= new DirectoryRenderer();
    }
}
