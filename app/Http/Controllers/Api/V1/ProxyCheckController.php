<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Proxy\IndexProxyCheckRequest;
use App\Http\Resources\ProxyCheckResource;
use App\Models\ProxyServer;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProxyCheckController extends Controller
{
    public function index(IndexProxyCheckRequest $request, ProxyServer $proxy): AnonymousResourceCollection
    {
        $validated = $request->validated();

        return ProxyCheckResource::collection(
            $proxy->checks()->latest()->paginate((int) ($validated['per_page'] ?? 20))
        );
    }
}
