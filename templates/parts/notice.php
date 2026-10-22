<?php
/**
 * Editor-only notice: no directory picked, or it's missing or not published.
 *
 * Visitors never see it; the renderer only loads it for the block editor
 * preview. Override it at `{theme}/wicket-directory/parts/notice.php`.
 *
 * @var array{message: string, templates: Wicket\Directory\Render\TemplateLoader} $args
 */

declare(strict_types=1);

defined('ABSPATH') || exit;
?>
<div class="wicket-directory wicket-directory--notice">
	<?php
    // The alert component prints its content as given, so pass it escaped.
    get_component('alert', ['content' => esc_html($args['message'])]);
?>
</div>
