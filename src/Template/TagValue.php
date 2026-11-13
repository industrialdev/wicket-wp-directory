<?php

declare(strict_types=1);

namespace Wicket\Directory\Template;

/**
 * What one merge tag resolved to: HTML, already escaped.
 *
 * - `items`: the inline value. One item for a single value, one per record
 *   for a list field (`is_list`): a line holding a list field repeats once
 *   per item. Empty when there is nothing to show.
 * - `block`: the markup a tag gives when it is alone on its line, e.g. the
 *   website buttons in their actions row. Null to print the items as a line.
 * - `standalone`: the block sits at the card's top level, between rows,
 *   rather than inside the current group of lines (the image, description,
 *   website buttons and tag chips).
 */
final class TagValue
{
    /**
     * @param list<string> $items      Inline HTML, one per value.
     * @param bool         $is_list    Whether a line holding it repeats per item.
     * @param string|null  $block      HTML when the tag is alone on its line.
     * @param bool         $standalone Whether the block breaks out of the current group of lines.
     */
    public function __construct(
        public readonly array $items = [],
        public readonly bool $is_list = false,
        public readonly ?string $block = null,
        public readonly bool $standalone = false,
    ) {}

    /**
     * Nothing to show.
     *
     * @return self
     */
    public static function empty(): self
    {
        return new self();
    }

    /**
     * One escaped text value; empty text is nothing.
     *
     * @param string $text Raw text.
     *
     * @return self
     */
    public static function text(string $text): self
    {
        return $text === '' ? new self() : new self([esc_html($text)]);
    }

    /**
     * A list of escaped text values; empty ones are dropped.
     *
     * @param list<string> $texts Raw texts.
     *
     * @return self
     */
    public static function texts(array $texts): self
    {
        $texts = array_values(array_filter($texts, static fn (string $text): bool => $text !== ''));

        return new self(array_map('esc_html', $texts), true);
    }

    /**
     * Whether there is nothing to show.
     *
     * @return bool
     */
    public function is_empty(): bool
    {
        return $this->items === [] && ($this->block === null || $this->block === '');
    }
}
