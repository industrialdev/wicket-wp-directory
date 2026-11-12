<?php
/**
 * One member's card, in a group's member list.
 *
 * The individual card (`card-individual.php`), plus the member's row in the
 * group: the status pill beside the name (whether that row is active, not the
 * person's account status), the role under the name, and the term's start
 * and end dates in the Details column ("Present" for an open-ended term),
 * after the job title and employers. The
 * same `component-card-listing` and `wicket-directory-card__*` classes apply.
 *
 * The entry holds raw values, and anything the Group Member card config turns
 * off, or that has no value, is empty. So every row shows only when its value
 * is non-empty, and the card never checks the toggles itself. The `button`,
 * `link` and `icon` components print their args as given, so they get escaped
 * values; `tag` escapes its own label.
 *
 * Override it at `{theme}/wicket-directory/cards/card-group-member.php`, or
 * swap the file with the `wicket_directory/card_template` filter.
 *
 * @var array{
 *     entry: GroupMemberEntry,
 *     config: Wicket\Directory\Config\DirectoryConfig,
 *     card?: array<string, mixed>,
 * } $args Plus the rest of `directory.php`'s args.
 */

declare(strict_types=1);

use Wicket\Directory\Data\GroupMemberEntry;

defined('ABSPATH') || exit;

$entry = $args['entry'];

$title = $entry->post_nominal !== ''
    ? sprintf(
        /* translators: 1: person's name, 2: post-nominal letters (e.g. "PhD"). */
        __('%1$s, %2$s', 'wicket-directory'),
        $entry->name,
        $entry->post_nominal,
    )
    : $entry->name;

$card = $args['card'] ?? $args['config']->member_card;
$website_label = $card['website_label'] !== ''
    ? $card['website_label']
    : __('Visit Website', 'wicket-directory');

$member_id = $entry->member_id !== ''
    /* translators: %s: member ID. */
    ? sprintf(__('ID: %s', 'wicket-directory'), $entry->member_id)
    : '';

// A filter can set any status; only the two known ones get a label and a modifier class.
$status_labels = [
    GroupMemberEntry::STATUS_ACTIVE   => __('Active', 'wicket-directory'),
    GroupMemberEntry::STATUS_INACTIVE => __('Inactive', 'wicket-directory'),
];
$status_label = $status_labels[$entry->status] ?? $entry->status;
$status_classes = ['wicket-directory-card__status'];

if (isset($status_labels[$entry->status])) {
    $status_classes[] = 'wicket-directory-card__status--' . $entry->status;
}

/**
 * A date in the site's date format; a value that isn't a date shows as given.
 *
 * @param string $date `Y-m-d`.
 */
$format_date = static function (string $date): string {
    $formatted = mysql2date((string) get_option('date_format'), $date);

    return is_string($formatted) && $formatted !== '' ? $formatted : $date;
};

$start_date = $entry->start_date !== '' ? $format_date($entry->start_date) : '';
$end_date = match (true) {
    $entry->end_date === GroupMemberEntry::END_PRESENT => __('Present', 'wicket-directory'),
    $entry->end_date !== ''                            => $format_date($entry->end_date),
    default                                            => '',
};

$has_meta = $member_id !== '' || $entry->tiers !== [];
$has_details = $entry->job_title !== '' || $entry->employers !== [] || $start_date !== '' || $end_date !== '';
$has_contact = $entry->emails !== [] || $entry->phones !== [];
?>
<article class="component-card-listing wicket-directory-card wicket-directory-card--group-member">
	<?php if ($entry->image_url !== '') : ?>
		<div class="wicket-directory-card__media">
			<?php // The name follows, so the photo is decorative.?>
			<img class="wicket-directory-card__image" src="<?php echo esc_url($entry->image_url); ?>" alt="" loading="lazy" decoding="async">
		</div>
	<?php endif; ?>

	<div class="component-card-listing__content wicket-directory-card__content">
		<header class="wicket-directory-card__header">
			<?php if ($entry->eyebrow !== '') : ?>
				<div class="component-card-listing__content-type wicket-directory-card__eyebrow"><?php echo esc_html($entry->eyebrow); ?></div>
			<?php endif; ?>

			<div class="wicket-directory-card__title-row">
				<h3 class="component-card-listing__title wicket-directory-card__title"><?php echo esc_html($title); ?></h3>
				<?php if ($status_label !== '') : ?>
					<?php get_component('tag', [
					    'label'   => $status_label,
					    'link'    => '',
					    'classes' => $status_classes,
					]); ?>
				<?php endif; ?>
			</div>

			<?php if ($entry->role_label !== '') : ?>
				<p class="wicket-directory-card__role"><?php echo esc_html($entry->role_label); ?></p>
			<?php endif; ?>

			<?php if ($has_meta) : ?>
				<ul class="wicket-directory-card__meta">
					<?php if ($member_id !== '') : ?>
						<li class="wicket-directory-card__member-id"><?php echo esc_html($member_id); ?></li>
					<?php endif; ?>
					<?php if ($entry->tiers !== []) : ?>
						<li class="wicket-directory-card__tiers"><?php echo esc_html(implode(', ', $entry->tiers)); ?></li>
					<?php endif; ?>
				</ul>
			<?php endif; ?>
		</header>

		<?php if ($has_details || $entry->addresses !== [] || $has_contact) : ?>
			<div class="wicket-directory-card__columns">
				<?php if ($has_details) : ?>
					<div class="wicket-directory-card__column wicket-directory-card__details">
						<h4 class="wicket-directory-card__column-title"><?php esc_html_e('Details', 'wicket-directory'); ?></h4>
						<?php if ($entry->job_title !== '') : ?>
							<p class="wicket-directory-card__job-title"><?php echo esc_html($entry->job_title); ?></p>
						<?php endif; ?>
						<?php foreach ($entry->employers as $employer) : ?>
							<p class="wicket-directory-card__employer">
								<span class="wicket-directory-card__employer-name"><?php echo esc_html($employer['name']); ?></span>
								<?php if ($employer['relationship'] !== '') : ?>
									<span class="wicket-directory-card__employer-relationship"><?php echo esc_html($employer['relationship']); ?></span>
								<?php endif; ?>
							</p>
						<?php endforeach; ?>
						<?php if ($start_date !== '') : ?>
							<p class="wicket-directory-card__start-date">
								<span class="wicket-directory-card__date-label"><?php esc_html_e('Start date:', 'wicket-directory'); ?></span>
								<?php echo esc_html($start_date); ?>
							</p>
						<?php endif; ?>
						<?php if ($end_date !== '') : ?>
							<p class="wicket-directory-card__end-date">
								<span class="wicket-directory-card__date-label"><?php esc_html_e('End date:', 'wicket-directory'); ?></span>
								<?php echo esc_html($end_date); ?>
							</p>
						<?php endif; ?>
					</div>
				<?php endif; ?>

				<?php if ($entry->addresses !== []) : ?>
					<div class="wicket-directory-card__column wicket-directory-card__addresses">
						<h4 class="wicket-directory-card__column-title"><?php esc_html_e('Address', 'wicket-directory'); ?></h4>
						<?php foreach ($entry->addresses as $lines) : ?>
							<address class="wicket-directory-card__address"><?php echo implode('<br>', array_map('esc_html', $lines)); ?></address>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

				<?php if ($has_contact) : ?>
					<div class="wicket-directory-card__column wicket-directory-card__contacts">
						<h4 class="wicket-directory-card__column-title"><?php esc_html_e('Contact', 'wicket-directory'); ?></h4>
						<ul class="wicket-directory-card__contact-list">
							<?php foreach ($entry->emails as $email) : ?>
								<li class="wicket-directory-card__contact">
									<span class="wicket-directory-card__contact-label"><?php esc_html_e('Email:', 'wicket-directory'); ?></span>
									<?php get_component('link', [
									    'url'     => esc_url('mailto:' . $email),
									    'text'    => esc_html($email),
									    'classes' => ['wicket-directory-card__email'],
									]); ?>
								</li>
							<?php endforeach; ?>
							<?php foreach ($entry->phones as $phone) : ?>
								<?php
                                $number = $phone['extension'] !== ''
                                    ? sprintf(
                                        /* translators: 1: phone number, 2: phone extension. */
                                        __('%1$s ext. %2$s', 'wicket-directory'),
                                        $phone['number'],
                                        $phone['extension'],
                                    )
                                    : $phone['number'];
							    ?>
								<li class="wicket-directory-card__contact">
									<span class="wicket-directory-card__contact-label"><?php esc_html_e('Phone:', 'wicket-directory'); ?></span>
									<?php if ($phone['uri'] !== '') : ?>
										<?php get_component('link', [
										    'url'     => esc_url($phone['uri']),
										    'text'    => esc_html($number),
										    'classes' => ['wicket-directory-card__phone'],
										]); ?>
									<?php else : ?>
										<?php // A filter can clear the URI; show the number without a link.?>
										<span class="wicket-directory-card__phone"><?php echo esc_html($number); ?></span>
									<?php endif; ?>
								</li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<?php if ($entry->websites !== []) : ?>
			<div class="wicket-directory-card__actions">
				<?php foreach ($entry->websites as $website) : ?>
					<?php get_component('button', [
					    'variant'     => 'primary',
					    'label'       => esc_html($website_label),
					    'suffix_icon' => 'fa-solid fa-arrow-up-right-from-square',
					    'a_tag'       => true,
					    'link'        => esc_url($website),
					    'link_target' => '_blank',
					    'classes'     => ['wicket-directory-card__website'],
					    'atts'        => ['rel' => 'noopener'],
					]); ?>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<?php if ($entry->tags !== []) : ?>
			<div class="card__topics wicket-directory-card__tags">
				<?php foreach ($entry->tags as $tag) : ?>
					<?php
                    // tag escapes its own label.
                    get_component('tag', [
                        'label' => $tag,
                        'link'  => '',
                    ]);
				    ?>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</div>
</article>
