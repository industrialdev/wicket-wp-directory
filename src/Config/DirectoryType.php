<?php

declare(strict_types=1);

namespace Wicket\Directory\Config;

/**
 * What a directory lists: people or organizations.
 *
 * The backing value is what the config stores in its `type` key.
 */
enum DirectoryType: string
{
    case Individual = 'individual';
    case Organization = 'organization';

    /**
     * Translated name for admin screens.
     *
     * @return string
     */
    public function label(): string
    {
        return match ($this) {
            self::Individual   => __('Individual', 'wicket-directory'),
            self::Organization => __('Organization', 'wicket-directory'),
        };
    }

    /**
     * Sort options visitors and editors can pick, in display order.
     *
     * Keys are language-neutral tokens stored in the defaultSort block
     * attribute and sent in the sort GET parameter. The query builders map
     * them to MDP sort keys; the MDP ignores unknown ones, so only these
     * tokens are ever accepted. The first option is the type's default.
     *
     * @return array<string, string> Token => translated label.
     */
    public function sort_options(): array
    {
        return match ($this) {
            self::Individual => [
                'first_name_asc'  => __('First Name (A–Z)', 'wicket-directory'),
                'first_name_desc' => __('First Name (Z–A)', 'wicket-directory'),
                'last_name_asc'   => __('Last Name (A–Z)', 'wicket-directory'),
                'last_name_desc'  => __('Last Name (Z–A)', 'wicket-directory'),
            ],
            self::Organization => [
                'name_asc'  => __('Name (A–Z)', 'wicket-directory'),
                'name_desc' => __('Name (Z–A)', 'wicket-directory'),
            ],
        };
    }

    /**
     * The sort used when none (or an unknown one) is given.
     *
     * @return string
     */
    public function default_sort(): string
    {
        return array_key_first($this->sort_options());
    }

    /**
     * A valid sort token for this type.
     *
     * @param mixed $sort Raw token.
     *
     * @return string The token, or the type's default when it isn't one of sort_options().
     */
    public function sanitize_sort(mixed $sort): string
    {
        return is_string($sort) && array_key_exists($sort, $this->sort_options()) ? $sort : $this->default_sort();
    }

    /**
     * Every backing value, for schemas and validation.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
