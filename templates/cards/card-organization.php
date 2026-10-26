<?php
/**
 * One organization's card.
 *
 * It has the same structure and `wicket-directory-card__*` classes as the
 * individual card, so one set of rules in `directory.css` styles both. The org
 * type takes the job title's place under the name, the logo the photo's, and
 * the description comes before the columns.
 *
 * A description longer than the excerpt gets Show more / Show less (Alpine).
 * Without Alpine the full text shows and the excerpt and toggle stay hidden
 * (`x-cloak`).
 *
 * The entry holds raw values, and anything the card config turns off, or that
 * has no value, is empty. So every row shows only when its value is non-empty,
 * and the card never checks the toggles itself. The `button`, `link` and
 * `icon` components print their args as given, so they get escaped values.
 *
 * Override it at `{theme}/wicket-directory/cards/card-organization.php`, or
 * swap the file with the `wicket_directory/card_template` filter.
 *
 * @var array{
 *     entry: Wicket\Directory\Data\OrganizationEntry,
 *     config: Wicket\Directory\Config\DirectoryConfig,
 *     html_id: string,
 * } $args Plus the rest of `directory.php`'s args.
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$entry = $args['entry'];

$website_label = $args['config']->card['website_label'] !== ''
    ? $args['config']->card['website_label']
    : __('Visit Website', 'wicket-directory');

// Words in the collapsed description. Comparing with one word more tells
// whether the text is longer; wp_trim_words() counts characters for languages
// that don't split words with spaces.
$excerpt_words = 40;
$excerpt = $entry->description !== '' ? wp_trim_words($entry->description, $excerpt_words, '') : '';
$is_long = $excerpt !== '' && $excerpt !== wp_trim_words($entry->description, $excerpt_words + 1, '');
$description_id = $args['html_id'] . '-' . sanitize_key($entry->id) . '-description';

$has_details = $entry->member_id !== '' || $entry->tiers !== [];
$has_contact = $entry->emails !== [] || $entry->phones !== [];
?>
<article class="component-card-listing wicket-directory-card wicket-directory-card--organization">
	<?php if ($entry->image_url !== '') : ?>
		<div class="wicket-directory-card__media">
			<?php // The name follows, so the logo is decorative.?>
			<img class="wicket-directory-card__image wicket-directory-card__image--logo" src="<?php echo esc_url($entry->image_url); ?>" alt="" loading="lazy" decoding="async">
		</div>
	<?php endif; ?>

	<div class="component-card-listing__content wicket-directory-card__content">
		<?php if ($entry->eyebrow !== '') : ?>
			<div class="component-card-listing__content-type wicket-directory-card__eyebrow"><?php echo esc_html($entry->eyebrow); ?></div>
		<?php endif; ?>

		<h3 class="component-card-listing__title wicket-directory-card__title"><?php echo esc_html($entry->name); ?></h3>

		<?php if ($entry->org_type_label !== '') : ?>
			<p class="wicket-directory-card__org-type"><?php echo esc_html($entry->org_type_label); ?></p>
		<?php endif; ?>

		<?php if ($entry->description !== '') : ?>
			<div class="component-card-listing__excerpt wicket-directory-card__description"<?php echo $is_long ? ' x-data="{ expanded: false }"' : ''; ?>>
				<div id="<?php echo esc_attr($description_id); ?>">
					<?php if ($is_long) : ?>
						<p class="wicket-directory-card__description-excerpt" x-show="!expanded" x-cloak>
							<?php
                            /* translators: %s: the first words of an organization's description. */
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

		<?php if ($has_details || $entry->addresses !== [] || $has_contact) : ?>
			<div class="wicket-directory-card__columns">
				<?php if ($has_details) : ?>
					<dl class="wicket-directory-card__column wicket-directory-card__details">
						<?php if ($entry->member_id !== '') : ?>
							<div class="wicket-directory-card__detail">
								<dt><?php esc_html_e('Member ID', 'wicket-directory'); ?></dt>
								<dd><?php echo esc_html($entry->member_id); ?></dd>
							</div>
						<?php endif; ?>
						<?php if ($entry->tiers !== []) : ?>
							<div class="wicket-directory-card__detail">
								<dt><?php esc_html_e('Membership', 'wicket-directory'); ?></dt>
								<dd><?php echo esc_html(implode(', ', $entry->tiers)); ?></dd>
							</div>
						<?php endif; ?>
					</dl>
				<?php endif; ?>

				<?php if ($entry->addresses !== []) : ?>
					<div class="wicket-directory-card__column wicket-directory-card__addresses">
						<?php foreach ($entry->addresses as $lines) : ?>
							<div class="wicket-directory-card__address">
								<?php get_component('icon', [
								    'icon'    => 'fa-regular fa-location-dot',
								    'text'    => esc_html__('Address', 'wicket-directory'),
								    'classes' => ['wicket-directory-card__icon'],
								]); ?>
								<address><?php echo implode('<br>', array_map('esc_html', $lines)); ?></address>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

				<?php if ($has_contact) : ?>
					<ul class="wicket-directory-card__column wicket-directory-card__contacts">
						<?php foreach ($entry->emails as $email) : ?>
							<li class="wicket-directory-card__contact">
								<?php get_component('link', [
								    'url'        => esc_url('mailto:' . $email),
								    'text'       => esc_html($email),
								    'classes'    => ['wicket-directory-card__email'],
								    'icon_start' => [
								        'icon' => 'fa-regular fa-envelope',
								        'text' => esc_html__('Email', 'wicket-directory'),
								    ],
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
								<?php if ($phone['uri'] !== '') : ?>
									<?php get_component('link', [
									    'url'        => esc_url($phone['uri']),
									    'text'       => esc_html($number),
									    'classes'    => ['wicket-directory-card__phone'],
									    'icon_start' => [
									        'icon' => 'fa-regular fa-phone',
									        'text' => esc_html__('Phone', 'wicket-directory'),
									    ],
									]); ?>
								<?php else : ?>
									<?php // A filter can clear the URI; show the number without a link.?>
									<span class="wicket-directory-card__phone">
										<?php get_component('icon', [
										    'icon' => 'fa-regular fa-phone',
										    'text' => esc_html__('Phone', 'wicket-directory'),
										]); ?>
										<?php echo esc_html($number); ?>
									</span>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<?php if ($entry->websites !== []) : ?>
			<div class="wicket-directory-card__actions">
				<?php foreach ($entry->websites as $website) : ?>
					<?php get_component('button', [
					    'variant'     => 'secondary',
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
