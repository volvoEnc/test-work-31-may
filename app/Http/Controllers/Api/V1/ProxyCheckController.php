<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProxyCheckResource;
use App\Models\ProxyServer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProxyCheckController extends Controller
{
    public function index(Request $request, ProxyServer $proxy): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ]);

        return ProxyCheckResource::collection(
            $proxy->checks()->latest()->paginate((int) ($validated['per_page'] ?? 20))
        );
    }
}
