<?php
/**
 * One individual's card.
 *
 * It uses the `component-card-listing` structure and classes (not the
 * component: its args are post-shaped), so the theme's card styles apply.
 * The photo sits left of the content. The header holds the name and a meta
 * line (member ID | membership tiers); a divider follows it, then the
 * Details, Address and Contact columns. A container query in
 * `directory.css` puts the photo and the columns side by side when the card
 * is wide enough.
 *
 * The entry holds raw values, and anything the card config turns off, or that
 * has no value, is empty. So every row shows only when its value is non-empty,
 * and the card never checks the toggles itself. The `button`, `link` and
 * `icon` components print their args as given, so they get escaped values.
 *
 * Override it at `{theme}/wicket-directory/cards/card-individual.php`, or swap
 * the file with the `wicket_directory/card_template` filter.
 *
 * @var array{
 *     entry: Wicket\Directory\Data\PersonEntry,
 *     config: Wicket\Directory\Config\DirectoryConfig,
 * } $args Plus the rest of `directory.php`'s args.
 */

declare(strict_types=1);

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

$website_label = $args['config']->card['website_label'] !== ''
    ? $args['config']->card['website_label']
    : __('Visit Website', 'wicket-directory');

$member_id = $entry->member_id !== ''
    /* translators: %s: member ID. */
    ? sprintf(__('ID: %s', 'wicket-directory'), $entry->member_id)
    : '';

$has_meta = $member_id !== '' || $entry->tiers !== [];
$has_contact = $entry->emails !== [] || $entry->phones !== [];
?>
<article class="component-card-listing wicket-directory-card wicket-directory-card--individual">
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

			<h3 class="component-card-listing__title wicket-directory-card__title"><?php echo esc_html($title); ?></h3>

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

		<?php if ($entry->job_title !== '' || $entry->addresses !== [] || $has_contact) : ?>
			<div class="wicket-directory-card__columns">
				<?php if ($entry->job_title !== '') : ?>
					<div class="wicket-directory-card__column wicket-directory-card__details">
						<h4 class="wicket-directory-card__column-title"><?php esc_html_e('Details', 'wicket-directory'); ?></h4>
						<p class="wicket-directory-card__job-title"><?php echo esc_html($entry->job_title); ?></p>
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
