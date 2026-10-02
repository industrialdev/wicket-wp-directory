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
     * Every backing value, for schemas and validation.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
