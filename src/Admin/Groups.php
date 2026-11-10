<?php

declare(strict_types=1);

namespace Wicket\Directory\Admin;

use Closure;
use RuntimeException;
use Throwable;
use Wicket\Directory\Api\DirectoryRepository;

/**
 * Group choices for the "Groups which shouldn't be displayed" picker on the
 * directory edit screen.
 *
 * One `groups/query` of up to PAGE_SIZE groups (the MDP's cap), every status
 * and type, loaded on first use. Tenants have a handful of groups, so one page
 * is enough; groups past it can't be picked, but saved ones are kept.
 *
 * A failed load is logged and remembered for the rest of the request.
 * failed() then tells the edit screen to say so, and choices() gives none.
 */
final class Groups
{
    /**
     * Groups asked for: the MDP's page[size] cap.
     */
    public const PAGE_SIZE = 2000;

    /**
     * Returns the `groups/query` response for a language.
     *
     * @var Closure(string): mixed
     */
    private Closure $loader;

    /**
     * Group resources per language; a language is missing until loaded.
     *
     * @var array<string, list<array<string, mixed>>>
     */
    private array $records = [];

    /**
     * Whether a load failed.
     */
    private bool $failed = false;

    /**
     * @param (callable(string): mixed)|null $loader Returns `groups/query` sorted by `name_{lang}` for a
     *                                               two-letter language (`['data' => [...]]`).
     *                                               Defaults to the API client. Tests pass a fake.
     */
    public function __construct(?callable $loader = null)
    {
        $this->loader = $loader !== null
            ? Closure::fromCallable($loader)
            : static fn (string $lang): mixed => wicket_api_client()->post('groups/query?' . http_build_query([
                'page' => ['size' => self::PAGE_SIZE],
                'sort' => 'name_' . $lang,
            ]), ['json' => ['filter' => (object) []]]);
    }

    /**
     * Every group, for the exclude picker.
     *
     * @param string $lang Two-letter language code.
     *
     * @return array<string, string> Lower-case UUID => `name_{lang}`, else `name`, else `name_en`, in
     *                               API order (by name). A group with no name is labelled by its
     *                               slug or ID. Inactive groups are marked. Empty when the load failed.
     */
    public function choices(string $lang): array
    {
        $groups = [];

        foreach ($this->records($lang) as $record) {
            $attributes = is_array($record['attributes'] ?? null) ? $record['attributes'] : [];
            $id = is_string($record['id'] ?? null) ? strtolower($record['id']) : '';

            if (!wp_is_uuid($id)) {
                continue;
            }

            $label = self::first_label([
                $attributes['name_' . $lang] ?? null,
                $attributes['name'] ?? null,
                $attributes['name_en'] ?? null,
            ]);

            if ($label === null) {
                /* translators: %s: Group slug, number or UUID. */
                $label = sprintf(__('Unnamed group (%s)', 'wicket-directory'), self::first_label([
                    $attributes['slug'] ?? null,
                    is_scalar($attributes['identifying_number'] ?? null) ? (string) $attributes['identifying_number'] : null,
                ]) ?? $id);
            }

            if (($attributes['active'] ?? true) === false) {
                /* translators: %s: Group name. */
                $label = sprintf(__('%s (inactive)', 'wicket-directory'), $label);
            }

            $groups[$id] = $label;
        }

        return $groups;
    }

    /**
     * Whether the groups couldn't be loaded.
     *
     * @param string $lang Two-letter language code.
     *
     * @return bool
     */
    public function failed(string $lang): bool
    {
        $this->records($lang);

        return $this->failed;
    }

    /**
     * The group resources for a language. Loaded on first use.
     *
     * @param string $lang Two-letter language code.
     *
     * @return list<array<string, mixed>>
     */
    private function records(string $lang): array
    {
        if (isset($this->records[$lang])) {
            return $this->records[$lang];
        }

        try {
            $response = ($this->loader)($lang);

            if (!is_array($response) || !is_array($response['data'] ?? null)) {
                throw new RuntimeException('Unexpected response from groups/query.');
            }

            $records = array_values(array_filter($response['data'], 'is_array'));
        } catch (Throwable $e) {
            // The base client is called without a check, so a missing one is an Error; catch both.
            Wicket()->log()->error('Could not load groups for the directory edit screen: ' . $e->getMessage(), [
                'source' => DirectoryRepository::LOG_SOURCE,
            ]);

            $this->failed = true;
            $records = [];
        }

        return $this->records[$lang] = $records;
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
