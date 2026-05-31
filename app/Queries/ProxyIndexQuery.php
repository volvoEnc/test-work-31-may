<?php

namespace App\Queries;

use App\Models\ProxyServer;
use App\Support\ProxyIndexSortOptions;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ProxyIndexQuery
{
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
        return ProxyIndexSortOptions::normalizeSort($filters['sort'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function direction(array $filters): string
    {
        return ProxyIndexSortOptions::normalizeDirection($filters['direction'] ?? null);
    }
}
