<?php

declare(strict_types=1);

namespace Wicket\Directory\Data;

use Closure;
use Throwable;
use Wicket\Directory\Api\DirectoryRepository;

/**
 * Display labels for data-field enum keys and organization type slugs.
 *
 * Each source is loaded at most once per instance, and only when a label is
 * first asked for. EntryMapper uses the shared() instance, so every directory
 * on a page shares one `json_schemas` request and one `resource_types` request.
 *
 * A failed load is logged and remembered for the rest of the request. Callers
 * then get no labels and show the raw keys.
 */
final class EnumLabels
{
    /**
     * The instance shared by every directory rendered in this request.
     */
    private static ?self $shared = null;

    /**
     * Returns the json_schemas response.
     *
     * @var Closure(): mixed
     */
    private Closure $schemas_loader;

    /**
     * Returns the organization resource types.
     *
     * @var Closure(): mixed
     */
    private Closure $org_types_loader;

    /**
     * Schema resources keyed by schema key; null until loaded.
     *
     * @var array<string, array<string, mixed>>|null
     */
    private ?array $schemas = null;

    /**
     * Organization resource types; null until loaded.
     *
     * @var list<array<string, mixed>>|null
     */
    private ?array $org_types = null;

    /**
     * Resolved label maps, keyed by "lang|schema_key|field" or "lang|org_types".
     *
     * @var array<string, array<string, string>>
     */
    private array $maps = [];

    /**
     * @param (callable(): mixed)|null $schemas   Returns the json_schemas response (`['data' => [...]]`).
     *                                            Defaults to wicket_get_schemas(). Tests pass a fake.
     * @param (callable(): mixed)|null $org_types Returns the organization resource types (a list of
     *                                            resources). Defaults to wicket_get_org_types_list().
     */
    public function __construct(?callable $schemas = null, ?callable $org_types = null)
    {
        $this->schemas_loader = $schemas !== null
            ? Closure::fromCallable($schemas)
            : static fn (): mixed => wicket_get_schemas();
        $this->org_types_loader = $org_types !== null
            ? Closure::fromCallable($org_types)
            : static fn (): mixed => wicket_get_org_types_list();
    }

    /**
     * The instance shared by this request.
     *
     * @return self
     */
    public static function shared(): self
    {
        return self::$shared ??= new self();
    }

    /**
     * Labels for a data field's enum keys.
     *
     * Keys and site-language labels come from wicket_get_schemas_options().
     * That helper reads labels in the site language only, and misses the
     * schema's own `enumNames`, so each key's label is the first non-empty of:
     * 1. `ui_schema.{field}.ui:i18n.enumNames.{lang}`;
     * 2. the helper's label (site language);
     * 3. the schema's `enumNames` (untranslated);
     * 4. `ui_schema.{field}.ui:i18n.enumNames.en`;
     * 5. the key itself.
     *
     * @param string $schema_key Schema key, as in a data-field reference.
     * @param string $field      Field name within the schema's value.
     * @param string $lang       Two-letter language code.
     *
     * @return array<string, string> Enum key => label, in schema order. Empty when the
     *                               field isn't an enum or the schema can't be loaded.
     */
    public function data_field(string $schema_key, string $field, string $lang): array
    {
        $cache_key = $lang . '|' . $schema_key . '|' . $field;

        if (isset($this->maps[$cache_key])) {
            return $this->maps[$cache_key];
        }

        $schema = $this->schemas()[$schema_key] ?? null;
        $map = [];

        if ($schema !== null && function_exists('wicket_get_schemas_options')) {
            $attributes = is_array($schema['attributes'] ?? null) ? $schema['attributes'] : [];
            $i18n = $attributes['ui_schema'][$field]['ui:i18n']['enumNames'] ?? [];
            $property = $attributes['schema']['properties'][$field] ?? [];
            $enum_names = $property['enumNames'] ?? $property['items']['enumNames'] ?? [];

            foreach (array_values((array) wicket_get_schemas_options($schema, $field, '')) as $index => $option) {
                $key = $option['key'] ?? null;

                if (!is_scalar($key) || is_bool($key) || (string) $key === '') {
                    continue;
                }

                $map[(string) $key] = self::first_label([
                    $i18n[$lang][$index] ?? null,
                    $option['value'] ?? null,
                    $enum_names[$index] ?? null,
                    $i18n['en'][$index] ?? null,
                ]) ?? (string) $key;
            }
        }

        return $this->maps[$cache_key] = $map;
    }

    /**
     * Labels for organization type slugs.
     *
     * @param string $lang Two-letter language code.
     *
     * @return array<string, string> Slug => `name_{lang}`, else `name`, else `name_en`, else the slug.
     */
    public function org_types(string $lang): array
    {
        $cache_key = $lang . '|org_types';

        if (isset($this->maps[$cache_key])) {
            return $this->maps[$cache_key];
        }

        if ($this->org_types === null) {
            $loaded = $this->load($this->org_types_loader, 'organization types');
            $this->org_types = is_array($loaded) ? array_values(array_filter($loaded, 'is_array')) : [];
        }

        $map = [];

        foreach ($this->org_types as $type) {
            $attributes = is_array($type['attributes'] ?? null) ? $type['attributes'] : [];
            $slug = $attributes['slug'] ?? null;

            if (!is_string($slug) || $slug === '') {
                continue;
            }

            $map[$slug] = self::first_label([
                $attributes['name_' . $lang] ?? null,
                $attributes['name'] ?? null,
                $attributes['name_en'] ?? null,
            ]) ?? $slug;
        }

        return $this->maps[$cache_key] = $map;
    }

    /**
     * The schemas, keyed by schema key. Loaded on first use.
     *
     * @return array<string, array<string, mixed>>
     */
    private function schemas(): array
    {
        if ($this->schemas !== null) {
            return $this->schemas;
        }

        $loaded = $this->load($this->schemas_loader, 'JSON schemas');
        $this->schemas = [];

        foreach (is_array($loaded['data'] ?? null) ? $loaded['data'] : [] as $schema) {
            $key = is_array($schema) ? ($schema['attributes']['key'] ?? null) : null;

            if (is_string($key) && $key !== '') {
                $this->schemas[$key] ??= $schema;
            }
        }

        return $this->schemas;
    }

    /**
     * Run a loader. Failures are logged and give null.
     *
     * The base helpers call the API client without checking it, so a missing
     * client is an Error, not an exception; catch both.
     *
     * @param Closure(): mixed $loader Loader.
     * @param string           $what   What it loads, for the log.
     *
     * @return mixed
     */
    private function load(Closure $loader, string $what): mixed
    {
        try {
            return $loader();
        } catch (Throwable $e) {
            Wicket()->log()->error(sprintf('Could not load %s for directory labels: %s', $what, $e->getMessage()), [
                'source' => DirectoryRepository::LOG_SOURCE,
            ]);

            return null;
        }
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
