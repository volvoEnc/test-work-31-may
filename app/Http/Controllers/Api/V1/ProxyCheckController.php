<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Proxy\IndexProxyCheckRequest;
use App\Http\Resources\ProxyCheckResource;
use App\Models\ProxyServer;
use App\Queries\ProxyCheckIndexQuery;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProxyCheckController extends Controller
{
    public function index(
        IndexProxyCheckRequest $request,
        ProxyServer $proxy,
        ProxyCheckIndexQuery $checks,
    ): AnonymousResourceCollection {
        return ProxyCheckResource::collection(
            $checks->paginate($proxy, $request->validated())
        );
    }
}
