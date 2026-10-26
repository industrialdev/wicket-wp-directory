<?php

declare(strict_types=1);

namespace Wicket\Directory\Admin;

use Closure;
use RuntimeException;
use Throwable;
use Wicket\Directory\Api\DirectoryRepository;
use Wicket\Directory\Config\DirectoryType;

/**
 * Data-field choices for the directory edit screen's pickers.
 *
 * Schemas come from `GET json_schemas?include=json_schema_resources` (one
 * request; the endpoint isn't paged). What a schema profiles is recorded on its
 * `json_schema_resources` (`resource_type` `people`, `organizations`, ...), not
 * on the schema, so a directory is offered the schemas bound to its type plus
 * any schema with no binding at all.
 *
 * Only top-level fields a card can show are offered: text and number fields
 * (`text`) and lists of them (`list`). Booleans, objects and repeaters are left
 * out, because EntryMapper shows nothing for them.
 *
 * A failed load is logged and remembered for the rest of the request.
 * failed() then tells the edit screen to say so, and for_type() gives nothing.
 *
 * @phpstan-type Field array{label: string, kind: 'text'|'list', enum: bool}
 * @phpstan-type Schema array{label: string, fields: array<string, Field>}
 */
final class SchemaFields
{
    /**
     * Field kind: a single text or number value.
     */
    public const KIND_TEXT = 'text';

    /**
     * Field kind: a list of text or number values.
     */
    public const KIND_LIST = 'list';

    /**
     * Scalar JSON schema types a card can show.
     */
    private const SCALAR_TYPES = ['string', 'number', 'integer'];

    /**
     * Returns the `GET json_schemas?include=json_schema_resources` response.
     *
     * @var Closure(): mixed
     */
    private Closure $loader;

    /**
     * The response; null until loaded.
     *
     * @var array<string, mixed>|null
     */
    private ?array $response = null;

    /**
     * Whether the load failed.
     */
    private bool $failed = false;

    /**
     * @param (callable(): mixed)|null $loader Returns `GET json_schemas?include=json_schema_resources`.
     *                                         Defaults to the API client. Tests pass a fake.
     */
    public function __construct(?callable $loader = null)
    {
        $this->loader = $loader !== null
            ? Closure::fromCallable($loader)
            : static fn (): mixed => wicket_api_client()->get('json_schemas?include=json_schema_resources');
    }

    /**
     * The schemas and fields a directory of this type can pick from.
     *
     * Labels are the ui_schema's `ui:i18n` title or label in $lang, else in
     * English, else the JSON schema's `title`, else the key.
     *
     * @param DirectoryType $type Directory type.
     * @param string        $lang Two-letter language code.
     *
     * @return array<string, Schema> Schema key => schema, sorted by label. Fields are in schema
     *                               order; schemas with no showable field are left out.
     */
    public function for_type(DirectoryType $type, string $lang): array
    {
        $response = $this->response();
        $scope = $type === DirectoryType::Individual ? 'people' : 'organizations';
        $scopes = self::scopes($response['included'] ?? null);
        $schemas = [];

        foreach (is_array($response['data'] ?? null) ? $response['data'] : [] as $schema) {
            $attributes = is_array($schema) && is_array($schema['attributes'] ?? null) ? $schema['attributes'] : [];
            $key = self::identifier($attributes['key'] ?? null);
            $bound = $scopes[is_string($schema['id'] ?? null) ? $schema['id'] : ''] ?? [];

            if ($key === '' || isset($schemas[$key]) || ($bound !== [] && !isset($bound[$scope]))) {
                continue;
            }

            $ui = is_array($attributes['ui_schema'] ?? null) ? $attributes['ui_schema'] : [];
            $json = is_array($attributes['schema'] ?? null) ? $attributes['schema'] : [];
            $fields = [];

            foreach (is_array($json['properties'] ?? null) ? $json['properties'] : [] as $field => $property) {
                $field = self::identifier((string) $field);
                $kind = is_array($property) ? self::kind($property) : null;

                if ($field === '' || $kind === null) {
                    continue;
                }

                $fields[$field] = [
                    'label' => self::label($ui[$field]['ui:i18n']['label'] ?? null, $property['title'] ?? null, $lang) ?? $field,
                    'kind'  => $kind,
                    'enum'  => is_array($property['enum'] ?? $property['items']['enum'] ?? null),
                ];
            }

            if ($fields !== []) {
                $schemas[$key] = [
                    'label'  => self::label($ui['ui:i18n']['title'] ?? null, $json['title'] ?? null, $lang) ?? $key,
                    'fields' => $fields,
                ];
            }
        }

        uasort($schemas, static fn (array $a, array $b): int => strnatcasecmp($a['label'], $b['label']));

        return $schemas;
    }

    /**
     * Whether the schemas couldn't be loaded.
     *
     * @return bool
     */
    public function failed(): bool
    {
        $this->response();

        return $this->failed;
    }

    /**
     * The response. Loaded on first use.
     *
     * @return array<string, mixed>
     */
    private function response(): array
    {
        if ($this->response !== null) {
            return $this->response;
        }

        try {
            $response = ($this->loader)();

            if (!is_array($response) || !is_array($response['data'] ?? null)) {
                throw new RuntimeException('Unexpected response from json_schemas.');
            }
        } catch (Throwable $e) {
            // The base client is called without a check, so a missing one is an Error; catch both.
            Wicket()->log()->error('Could not load JSON schemas for the directory edit screen: ' . $e->getMessage(), [
                'source' => DirectoryRepository::LOG_SOURCE,
            ]);

            $this->failed = true;
            $response = [];
        }

        return $this->response = $response;
    }

    /**
     * The resource types each schema is bound to.
     *
     * @param mixed $included The response's `included`.
     *
     * @return array<string, array<string, true>> Schema ID => set of resource types.
     */
    private static function scopes(mixed $included): array
    {
        $scopes = [];

        foreach (is_array($included) ? $included : [] as $record) {
            if (!is_array($record) || ($record['type'] ?? null) !== 'json_schema_resources') {
                continue;
            }

            $owner = $record['relationships']['json_schema']['data']['id'] ?? null;
            $type = $record['attributes']['resource_type'] ?? null;

            if (is_string($owner) && is_string($type) && $type !== '') {
                $scopes[$owner][$type] = true;
            }
        }

        return $scopes;
    }

    /**
     * What a card can show of a property: a text value, a list, or nothing.
     *
     * @param array<string, mixed> $property JSON schema property.
     *
     * @return 'text'|'list'|null
     */
    private static function kind(array $property): ?string
    {
        $type = self::type($property);

        if (in_array($type, self::SCALAR_TYPES, true) || ($type === null && is_array($property['enum'] ?? null))) {
            return self::KIND_TEXT;
        }

        $items = is_array($property['items'] ?? null) ? $property['items'] : null;

        if ($type === 'array' && $items !== null && (in_array(self::type($items), self::SCALAR_TYPES, true) || is_array($items['enum'] ?? null))) {
            return self::KIND_LIST;
        }

        return null;
    }

    /**
     * A property's JSON type. A type list like `["string", "null"]` gives its first non-null type.
     *
     * @param array<string, mixed> $property JSON schema property.
     *
     * @return string|null
     */
    private static function type(array $property): ?string
    {
        $type = $property['type'] ?? null;

        if (is_array($type)) {
            $type = array_values(array_filter($type, static fn (mixed $item): bool => is_string($item) && $item !== 'null'))[0] ?? null;
        }

        return is_string($type) ? $type : null;
    }

    /**
     * A label from `ui:i18n` translations, else the JSON schema title.
     *
     * @param mixed  $i18n  `{lang: label}` translations.
     * @param mixed  $title JSON schema title.
     * @param string $lang  Two-letter language code.
     *
     * @return string|null
     */
    private static function label(mixed $i18n, mixed $title, string $lang): ?string
    {
        $i18n = is_array($i18n) ? $i18n : [];

        foreach ([$i18n[$lang] ?? null, $i18n['en'] ?? null, $title] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }

    /**
     * A schema key or field name DirectoryConfig accepts, else ''.
     *
     * @param mixed $value Raw value.
     *
     * @return string
     */
    private static function identifier(mixed $value): string
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9_-]{1,100}$/', $value) === 1 ? $value : '';
    }
}
