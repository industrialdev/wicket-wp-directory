<?php
/**
 * The header of a group's member list: Back to Directory, the group type,
 * the group's name and its description.
 *
 * Back to Directory shows only when the directory also has its group list
 * (the Group List + Member List view); it returns to the list's search and
 * page. The group type is the eyebrow above the name. The description is the
 * group's own, from the MDP, in the current language. The `link` component
 * prints its args as given, so it gets escaped values.
 *
 * Override it at `{theme}/wicket-directory/parts/roster-header.php`.
 *
 * @var array{
 *     roster: array{group: Wicket\Directory\Data\GroupEntry, back_url: string},
 * } $args Plus the rest of `directory.php`'s args.
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$group = $args['roster']['group'];
$back_url = $args['roster']['back_url'];

// Some MDP groups have no name; the heading still needs one (as on the group card).
$name = $group->name !== '' ? $group->name : __('Unnamed group', 'wicket-directory');
?>
<header class="wicket-directory__roster-header">
	<?php if ($back_url !== '') : ?>
		<p class="wicket-directory__back">
			<?php get_component('link', [
			    'url'        => esc_url($back_url),
			    'text'       => esc_html__('Back to Directory', 'wicket-directory'),
			    'classes'    => ['wicket-directory__back-link'],
			    'icon_start' => ['icon' => 'fa-solid fa-arrow-left'],
			]); ?>
		</p>
	<?php endif; ?>

	<?php if ($group->group_type_label !== '') : ?>
		<p class="wicket-directory__roster-eyebrow"><?php echo esc_html($group->group_type_label); ?></p>
	<?php endif; ?>

	<h2 class="wicket-directory__roster-title"><?php echo esc_html($name); ?></h2>

	<?php if ($group->description !== '') : ?>
		<div class="wicket-directory__roster-description">
			<p><?php echo nl2br(esc_html($group->description), false); ?></p>
		</div>
	<?php endif; ?>
</header>
