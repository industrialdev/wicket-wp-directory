<?php
/**
 * The card preview frame's document (CardPreview::frame()).
 *
 * `assets/js/admin.js` sets it as the frame's `srcdoc` once, then puts each
 * rendered card in the root. It holds the site's stylesheets and no script.
 * The frame grows to its content's height, so the page itself never scrolls.
 *
 * @var array{styles: string, body_class: string, root: string} $args
 */

declare(strict_types=1);

defined('ABSPATH') || exit;
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php echo esc_attr(get_bloginfo('charset')); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php esc_html_e('Card preview', 'wicket-directory'); ?></title>
<?php
// The site's own <link> and <style> tags, as wp_print_styles() prints them on its pages.
echo $args['styles']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
?>
<style>
html { overflow: hidden; }
body.wicket-directory-preview-frame { margin: 0; padding: 24px; }
@media (max-width: 600px) { body.wicket-directory-preview-frame { padding: 16px; } }
</style>
</head>
<body class="<?php echo esc_attr($args['body_class']); ?>">
<div id="<?php echo esc_attr($args['root']); ?>"></div>
</body>
</html>
