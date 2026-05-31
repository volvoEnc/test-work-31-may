<?php

namespace App\Queries;

use App\Models\ProxyServer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ProxyCheckIndexQuery
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(ProxyServer $proxy, array $filters): LengthAwarePaginator
    {
        $perPage = (int) ($filters['per_page'] ?? 20);
        $page = isset($filters['page']) ? (int) $filters['page'] : null;

        return $proxy
            ->checks()
            ->latest()
            ->paginate($perPage, ['*'], 'page', $page);
    }
}
