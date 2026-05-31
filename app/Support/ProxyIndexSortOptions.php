<?php

namespace App\Support;

final class ProxyIndexSortOptions
{
    private const DEFAULT_SORT = 'created_at';

    private const DEFAULT_DIRECTION = 'desc';

    private const ALLOWED_SORTS = [
        'created_at',
        'last_checked_at',
        'status',
        'host',
    ];

    private const ALLOWED_DIRECTIONS = [
        'asc',
        'desc',
    ];

    /**
     * @return list<string>
     */
    public static function allowedSorts(): array
    {
        return self::ALLOWED_SORTS;
    }

    /**
     * @return list<string>
     */
    public static function allowedDirections(): array
    {
        return self::ALLOWED_DIRECTIONS;
    }

    public static function defaultSort(): string
    {
        return self::DEFAULT_SORT;
    }

    public static function defaultDirection(): string
    {
        return self::DEFAULT_DIRECTION;
    }

    public static function normalizeSort(mixed $sort): string
    {
        $sort = (string) ($sort ?? self::DEFAULT_SORT);

        return in_array($sort, self::ALLOWED_SORTS, true) ? $sort : self::DEFAULT_SORT;
    }

    public static function normalizeDirection(mixed $direction): string
    {
        $direction = strtolower((string) ($direction ?? self::DEFAULT_DIRECTION));

        return in_array($direction, self::ALLOWED_DIRECTIONS, true) ? $direction : self::DEFAULT_DIRECTION;
    }
}
