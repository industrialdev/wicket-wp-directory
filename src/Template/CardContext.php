<?php

declare(strict_types=1);

namespace Wicket\Directory\Template;

use Wicket\Directory\Api\ResultPage;
use Wicket\Directory\Config\DirectoryType;
use Wicket\Directory\Data\Entry;

/**
 * What one card's merge tags resolve against.
 *
 * - The entry: EntryMapper's values after the wicket_directory/entry
 *   filters. Catalog fields read it, so a filter changes the card.
 * - The raw resource and the page's included records: typed contact tags
 *   (`emails[primary].address`) and data-field tags read them. Null when the
 *   resource isn't on the page (a filter changed the entry's ID).
 * - The card's settings, for the website label and the address format.
 */
final class CardContext
{
    /**
     * @param Entry                     $entry    The card's entry.
     * @param DirectoryType             $type     The card's type: Individual for a member list.
     * @param array<string, mixed>      $card     The card's settings (DirectoryConfig::$card or $member_card).
     * @param array<string, mixed>|null $resource The raw `people` / `organizations` / `groups` resource.
     * @param list<array<string, mixed>> $included The page's included records.
     * @param string                    $lang     Two-letter language code.
     * @param string                    $html_id  The directory's HTML ID, for element IDs on the card.
     */
    public function __construct(
        public readonly Entry $entry,
        public readonly DirectoryType $type,
        public readonly array $card,
        public readonly ?array $resource = null,
        public readonly array $included = [],
        public readonly string $lang = 'en',
        public readonly string $html_id = 'wicket-directory',
    ) {}

    /**
     * The context of one card in a rendered list.
     *
     * @param Entry                $entry   The card's entry.
     * @param DirectoryType        $type    The card's type.
     * @param array<string, mixed> $card    The card's settings.
     * @param ResultPage|null      $page    The page the entry came from.
     * @param string               $lang    Two-letter language code.
     * @param string               $html_id The directory's HTML ID.
     *
     * @return self
     */
    public static function from_page(Entry $entry, DirectoryType $type, array $card, ?ResultPage $page, string $lang, string $html_id): self
    {
        $resource = null;

        foreach ($page?->items ?? [] as $item) {
            if (is_array($item) && ($item['id'] ?? null) === $entry->id) {
                $resource = $item;
                break;
            }
        }

        return new self($entry, $type, $card, $resource, $page?->included ?? [], $lang, $html_id);
    }
}
