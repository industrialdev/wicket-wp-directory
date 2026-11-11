<?php
/**
 * One group's card.
 *
 * It uses the same `component-card-listing` and `wicket-directory-card__*`
 * classes as the other cards, so one set of rules in `directory.css` styles
 * all three. Groups have no image. The summary row holds the header (name
 * with the status pill beside it, then the group type) and, right-aligned on
 * a wide card, the active member count and the View Members button. The
 * description follows the divider, with Show more / Show less (Alpine) past
 * the excerpt, as on the organization card.
 *
 * The entry holds raw values, and anything the card config turns off, or that
 * has no value, is empty (`''`, or `null` for the member count). So every row
 * shows only when its value is set, and the card never checks the toggles
 * itself. The `button` component prints its args as given, so it gets escaped
 * values; `tag` escapes its own label.
 *
 * Override it at `{theme}/wicket-directory/cards/card-group.php`, or swap the
 * file with the `wicket_directory/card_template` filter.
 *
 * @var array{
 *     entry: GroupEntry,
 *     config: Wicket\Directory\Config\DirectoryConfig,
 *     html_id: string,
 * } $args Plus the rest of `directory.php`'s args.
 */

declare(strict_types=1);

use Wicket\Directory\Data\GroupEntry;

defined('ABSPATH') || exit;

$entry = $args['entry'];

// Some MDP groups have no name; the card still needs a title.
$name = $entry->name !== '' ? $entry->name : __('Unnamed group', 'wicket-directory');

// A filter can set any status; only the two known ones get a label and a modifier class.
$status_labels = [
    GroupEntry::STATUS_ACTIVE   => __('Active', 'wicket-directory'),
    GroupEntry::STATUS_INACTIVE => __('Inactive', 'wicket-directory'),
];
$status_label = $status_labels[$entry->status] ?? $entry->status;
$status_classes = ['wicket-directory-card__status'];

if (isset($status_labels[$entry->status])) {
    $status_classes[] = 'wicket-directory-card__status--' . $entry->status;
}

$member_count = $entry->member_count !== null
    ? sprintf(
        /* translators: %s: number of active members in a group. */
        _n('%s active member', '%s active members', $entry->member_count, 'wicket-directory'),
        number_format_i18n($entry->member_count),
    )
    : '';

// Words in the collapsed description; see card-organization.php.
$excerpt_words = 40;
$excerpt = $entry->description !== '' ? wp_trim_words($entry->description, $excerpt_words, '') : '';
$is_long = $excerpt !== '' && $excerpt !== wp_trim_words($entry->description, $excerpt_words + 1, '');
$description_id = $args['html_id'] . '-' . sanitize_key($entry->id) . '-description';

$has_aside = $member_count !== '' || $entry->members_url !== '';
?>
<article class="component-card-listing wicket-directory-card wicket-directory-card--group">
	<div class="component-card-listing__content wicket-directory-card__content">
		<div class="wicket-directory-card__summary">
			<header class="wicket-directory-card__header">
				<div class="wicket-directory-card__title-row">
					<h3 class="component-card-listing__title wicket-directory-card__title"><?php echo esc_html($name); ?></h3>
					<?php if ($status_label !== '') : ?>
						<?php get_component('tag', [
						    'label'   => $status_label,
						    'link'    => '',
						    'classes' => $status_classes,
						]); ?>
					<?php endif; ?>
				</div>

				<?php if ($entry->group_type_label !== '') : ?>
					<p class="wicket-directory-card__group-type"><?php echo esc_html($entry->group_type_label); ?></p>
				<?php endif; ?>
			</header>

			<?php if ($has_aside) : ?>
				<div class="wicket-directory-card__aside">
					<?php if ($member_count !== '') : ?>
						<p class="wicket-directory-card__member-count"><?php echo esc_html($member_count); ?></p>
					<?php endif; ?>

					<?php if ($entry->members_url !== '') : ?>
						<?php get_component('button', [
						    'variant' => 'primary',
						    'label'   => esc_html__('View Members', 'wicket-directory'),
						    'a_tag'   => true,
						    'link'    => esc_url($entry->members_url),
						    'classes' => ['wicket-directory-card__view-members'],
						    // Every card has this button, so its name says whose members; button escapes atts.
						    'atts'    => [
						        /* translators: %s: group name. */
						        'aria-label' => sprintf(__('View Members of %s', 'wicket-directory'), $name),
						    ],
						]); ?>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>

		<?php if ($entry->description !== '') : ?>
			<div class="component-card-listing__excerpt wicket-directory-card__description"<?php echo $is_long ? ' x-data="{ expanded: false }"' : ''; ?>>
				<div id="<?php echo esc_attr($description_id); ?>">
					<?php if ($is_long) : ?>
						<p class="wicket-directory-card__description-excerpt" x-show="!expanded" x-cloak>
							<?php
                            /* translators: %s: the first words of a group's description. */
                            echo esc_html(sprintf(__('%s…', 'wicket-directory'), $excerpt));
					    ?>
						</p>
					<?php endif; ?>
					<p class="wicket-directory-card__description-text"<?php echo $is_long ? ' x-show="expanded"' : ''; ?>><?php echo nl2br(esc_html($entry->description), false); ?></p>
				</div>
				<?php if ($is_long) : ?>
					<button type="button" class="wicket-directory-card__description-toggle" x-cloak
						aria-controls="<?php echo esc_attr($description_id); ?>" :aria-expanded="expanded.toString()" @click="expanded = !expanded">
						<span x-show="!expanded"><?php esc_html_e('Show more', 'wicket-directory'); ?></span>
						<span x-show="expanded"><?php esc_html_e('Show less', 'wicket-directory'); ?></span>
					</button>
				<?php endif; ?>
			</div>
		<?php endif; ?>
	</div>
</article>
