<?php

namespace App\Queries;

use App\Models\ProxyServer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ProxyIndexQuery
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
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $perPage = (int) ($filters['per_page'] ?? 20);
        $sort = $this->sort($filters);
        $direction = $this->direction($filters);
        $page = isset($filters['page']) ? (int) $filters['page'] : null;

        $query = ProxyServer::query();

        if (filled($filters['search'] ?? null)) {
            $search = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $filters['search']).'%';

            $query->where(function ($query) use ($search): void {
                $query
                    ->where('name', 'like', $search)
                    ->orWhere('host', 'like', $search)
                    ->orWhere('username', 'like', $search);
            });
        }

        if (filled($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }

        if (filled($filters['scheme'] ?? null)) {
            $query->where('scheme', $filters['scheme']);
        }

        return $query
            ->orderBy($sort, $direction)
            ->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function sort(array $filters): string
    {
        $sort = (string) ($filters['sort'] ?? self::DEFAULT_SORT);

        return in_array($sort, self::ALLOWED_SORTS, true) ? $sort : self::DEFAULT_SORT;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function direction(array $filters): string
    {
        $direction = strtolower((string) ($filters['direction'] ?? self::DEFAULT_DIRECTION));

        return in_array($direction, self::ALLOWED_DIRECTIONS, true) ? $direction : self::DEFAULT_DIRECTION;
    }
}
