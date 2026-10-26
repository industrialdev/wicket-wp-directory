<?php

declare(strict_types=1);

namespace Wicket\Directory\Admin;

use Closure;
use RuntimeException;
use Throwable;
use Wicket\Directory\Api\DirectoryRepository;
use Wicket\Directory\Config\DirectoryType;

/**
 * Membership tier choices for the directory edit screen.
 *
 * Tiers come from `GET memberships`. Each record has `attributes.type`
 * (`individual` or `organization`), and a directory only offers the tiers of
 * its own type. All pages are loaded once per instance, on first use.
 *
 * A failed load is logged and remembered for the rest of the request.
 * failed() then tells the edit screen to say so, and for_type() gives no tiers.
 */
final class MembershipTiers
{
    /**
     * Tiers per request. The MDP's maximum page size.
     */
    public const PAGE_SIZE = 100;

    /**
     * Stop after this many pages, so a misreported total can't loop forever.
     */
    public const MAX_PAGES = 10;

    /**
     * Returns one page of the `GET memberships` response.
     *
     * @var Closure(int): mixed
     */
    private Closure $loader;

    /**
     * Tier resources; null until loaded.
     *
     * @var list<array<string, mixed>>|null
     */
    private ?array $records = null;

    /**
     * Whether the last load failed.
     */
    private bool $failed = false;

    /**
     * @param (callable(int): mixed)|null $loader Returns one page (1-based) of `GET memberships`
     *                                            (`['data' => [...], 'meta' => [...]]`).
     *                                            Defaults to the API client. Tests pass a fake.
     */
    public function __construct(?callable $loader = null)
    {
        $this->loader = $loader !== null
            ? Closure::fromCallable($loader)
            : static fn (int $page): mixed => wicket_api_client()->get('memberships?' . http_build_query([
                'page' => ['size' => self::PAGE_SIZE, 'number' => $page],
            ]));
    }

    /**
     * Tiers a directory of this type can be limited to.
     *
     * @param DirectoryType $type Directory type.
     * @param string        $lang Two-letter language code.
     *
     * @return array<string, string> Lower-case UUID => label (`name_{lang}`, else `name`,
     *                               else `name_en`, else the slug), sorted by label.
     *                               Inactive tiers are marked. Empty when the load failed.
     */
    public function for_type(DirectoryType $type, string $lang): array
    {
        $tiers = [];

        foreach ($this->records() as $record) {
            $attributes = is_array($record['attributes'] ?? null) ? $record['attributes'] : [];
            $id = is_string($record['id'] ?? null) ? strtolower($record['id']) : '';

            if (($attributes['type'] ?? null) !== $type->value || !wp_is_uuid($id)) {
                continue;
            }

            $label = self::first_label([
                $attributes['name_' . $lang] ?? null,
                $attributes['name'] ?? null,
                $attributes['name_en'] ?? null,
                $attributes['slug'] ?? null,
            ]) ?? $id;

            if (($attributes['active'] ?? true) === false) {
                /* translators: %s: Membership tier name. */
                $label = sprintf(__('%s (inactive)', 'wicket-directory'), $label);
            }

            $tiers[$id] = $label;
        }

        uasort($tiers, static fn (string $a, string $b): int => strnatcasecmp($a, $b));

        return $tiers;
    }

    /**
     * Whether the tiers couldn't be loaded.
     *
     * @return bool
     */
    public function failed(): bool
    {
        $this->records();

        return $this->failed;
    }

    /**
     * Every tier resource. Loaded on first use.
     *
     * @return list<array<string, mixed>>
     */
    private function records(): array
    {
        if ($this->records !== null) {
            return $this->records;
        }

        $records = [];

        try {
            for ($page = 1; $page <= self::MAX_PAGES; $page++) {
                $response = ($this->loader)($page);

                if (!is_array($response) || !is_array($response['data'] ?? null)) {
                    throw new RuntimeException('Unexpected response from memberships.');
                }

                $data = array_values(array_filter($response['data'], 'is_array'));
                array_push($records, ...$data);

                $total = $response['meta']['page']['total_items'] ?? null;

                if ($data === [] || !is_numeric($total) || $page * self::PAGE_SIZE >= (int) $total) {
                    break;
                }
            }
        } catch (Throwable $e) {
            // The base client is called without a check, so a missing one is an Error; catch both.
            Wicket()->log()->error('Could not load membership tiers for the directory edit screen: ' . $e->getMessage(), [
                'source' => DirectoryRepository::LOG_SOURCE,
            ]);

            $this->failed = true;
            $records = [];
        }

        return $this->records = $records;
    }

    /**
     * The first non-empty string.
     *
     * @param list<mixed> $candidates Candidate labels, in order of preference.
     *
     * @return string|null
     */
    private static function first_label(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }
}
