<?php

declare(strict_types=1);

namespace Wicket\Directory\Admin;

use Closure;
use RuntimeException;
use Throwable;
use Wicket\Directory\Api\DirectoryRepository;

/**
 * Resource-type choices for the directory edit screen: organization types and
 * the address, email, phone and website types of the card's contact rules.
 *
 * `GET resource_types` isn't paged and returns every list at once, so one
 * request per instance serves them all. It is loaded on first use.
 *
 * A failed load is logged and remembered for the rest of the request.
 * failed() then tells the edit screen to say so, and for() gives no types.
 */
final class ResourceTypes
{
    /**
     * Returns the `GET resource_types` response.
     *
     * @var Closure(): mixed
     */
    private Closure $loader;

    /**
     * Resource-type attributes, grouped by their `resource_type`; null until loaded.
     *
     * @var array<string, list<array<string, mixed>>>|null
     */
    private ?array $groups = null;

    /**
     * Whether the load failed.
     */
    private bool $failed = false;

    /**
     * @param (callable(): mixed)|null $loader Returns `GET resource_types` (`['data' => [...]]`).
     *                                         Defaults to the API client. Tests pass a fake.
     */
    public function __construct(?callable $loader = null)
    {
        $this->loader = $loader !== null
            ? Closure::fromCallable($loader)
            : static fn (): mixed => wicket_api_client()->get('resource_types');
    }

    /**
     * The types of one list, e.g. `organizations` or `phones`.
     *
     * @param string $resource_type The list's `resource_type`, e.g. a ContactResolver::RELATIONSHIPS value.
     * @param string $lang          Two-letter language code.
     *
     * @return array<string, string> Slug => `name_{lang}`, else `name`, else `name_en`, else the slug,
     *                               in API order. Empty when the load failed.
     */
    public function for(string $resource_type, string $lang): array
    {
        $types = [];

        foreach ($this->groups()[$resource_type] ?? [] as $attributes) {
            $slug = $attributes['slug'] ?? null;

            if (!is_string($slug) || $slug === '') {
                continue;
            }

            $types[$slug] ??= self::first_label([
                $attributes['name_' . $lang] ?? null,
                $attributes['name'] ?? null,
                $attributes['name_en'] ?? null,
            ]) ?? $slug;
        }

        return $types;
    }

    /**
     * Whether the resource types couldn't be loaded.
     *
     * @return bool
     */
    public function failed(): bool
    {
        $this->groups();

        return $this->failed;
    }

    /**
     * Resource-type attributes by `resource_type`. Loaded on first use.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function groups(): array
    {
        if ($this->groups !== null) {
            return $this->groups;
        }

        $groups = [];

        try {
            $response = ($this->loader)();

            if (!is_array($response) || !is_array($response['data'] ?? null)) {
                throw new RuntimeException('Unexpected response from resource_types.');
            }

            foreach ($response['data'] as $record) {
                $attributes = is_array($record) && is_array($record['attributes'] ?? null) ? $record['attributes'] : null;
                $group = $attributes['resource_type'] ?? null;

                if (is_string($group) && $group !== '') {
                    $groups[$group][] = $attributes;
                }
            }
        } catch (Throwable $e) {
            // The base client is called without a check, so a missing one is an Error; catch both.
            Wicket()->log()->error('Could not load resource types for the directory edit screen: ' . $e->getMessage(), [
                'source' => DirectoryRepository::LOG_SOURCE,
            ]);

            $this->failed = true;
            $groups = [];
        }

        return $this->groups = $groups;
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
