<?php

declare(strict_types=1);

namespace Wicket\Directory\Render;

/**
 * Finds and renders the directory's PHP templates.
 *
 * A theme overrides a template by copying it to
 * `{theme}/wicket-directory/{name}.php`, e.g.
 * `wicket-directory/cards/card-organization.php`. A child theme wins over its
 * parent. Otherwise the plugin's `templates/{name}.php` is used.
 *
 * Templates see one variable, `$args`. A template that can't be found renders
 * nothing.
 */
final class TemplateLoader
{
    /**
     * Folder in the theme that holds overrides.
     */
    public const THEME_DIR = 'wicket-directory';

    /**
     * The plugin's templates folder, without a trailing slash.
     */
    private string $plugin_dir;

    /**
     * @param string|null $plugin_dir The plugin's templates folder. Defaults to `templates/`
     *                                in the plugin root. Tests pass a fixture folder.
     */
    public function __construct(?string $plugin_dir = null)
    {
        $this->plugin_dir = rtrim($plugin_dir ?? dirname(__DIR__, 2) . '/templates', '/');
    }

    /**
     * The file a template name resolves to.
     *
     * @param string $name Template name without `.php`: lowercase letters, digits, `-` and `_`,
     *                     with `/` between folders. E.g. `directory` or `parts/search-bar`.
     *
     * @return string Absolute path; '' when the name is invalid or no file exists.
     */
    public function locate(string $name): string
    {
        if (preg_match('#^[a-z0-9_-]+(/[a-z0-9_-]+)*$#', $name) !== 1) {
            return '';
        }

        $override = locate_template([self::THEME_DIR . '/' . $name . '.php']);

        if (is_string($override) && $override !== '') {
            return $override;
        }

        $file = $this->plugin_dir . '/' . $name . '.php';

        return is_file($file) ? $file : '';
    }

    /**
     * Render a template by name.
     *
     * @param string               $name Template name, as for locate().
     * @param array<string, mixed> $args Passed to the template as `$args`.
     *
     * @return string The template's output; '' when it can't be found.
     */
    public function render(string $name, array $args = []): string
    {
        return $this->render_file($this->locate($name), $args);
    }

    /**
     * Print a template by name. Templates use this to load their parts.
     *
     * @param string               $name Template name, as for locate().
     * @param array<string, mixed> $args Passed to the template as `$args`.
     *
     * @return void
     */
    public function load(string $name, array $args = []): void
    {
        $this->load_file($this->locate($name), $args);
    }

    /**
     * Render a template file.
     *
     * @param string               $file Absolute path, e.g. from locate() or the
     *                                   wicket_directory/card_template filter.
     * @param array<string, mixed> $args Passed to the template as `$args`.
     *
     * @return string The template's output; '' when the file doesn't exist.
     */
    public function render_file(string $file, array $args = []): string
    {
        ob_start();

        try {
            $this->load_file($file, $args);
        } finally {
            $output = (string) ob_get_clean();
        }

        return $output;
    }

    /**
     * Print a template file.
     *
     * @param string               $file Absolute path.
     * @param array<string, mixed> $args Passed to the template as `$args`.
     *
     * @return void
     */
    public function load_file(string $file, array $args = []): void
    {
        if ($file === '' || !is_file($file)) {
            return;
        }

        // A static closure, so the template sees $args and not $this.
        (static function (string $__file, array $args): void {
            include $__file;
        })($file, $args);
    }
}
