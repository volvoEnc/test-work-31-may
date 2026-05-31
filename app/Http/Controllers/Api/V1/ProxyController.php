<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Proxies\CreateProxyAction;
use App\Actions\Proxies\DeleteProxyAction;
use App\Actions\Proxies\ScheduleAllProxyChecksAction;
use App\Actions\Proxies\ScheduleProxyCheckAction;
use App\Actions\Proxies\UpdateProxyAction;
use App\Enums\ProxyCheckSource;
use App\Exceptions\DuplicateProxyException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Proxy\IndexProxyRequest;
use App\Http\Requests\Proxy\StoreProxyRequest;
use App\Http\Requests\Proxy\UpdateProxyRequest;
use App\Http\Resources\ProxyResource;
use App\Models\ProxyServer;
use App\Queries\ProxyIndexQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ProxyController extends Controller
{
    public function index(IndexProxyRequest $request, ProxyIndexQuery $proxies): AnonymousResourceCollection
    {
        return ProxyResource::collection(
            $proxies->paginate($request->validated())
        );
    }

    public function store(StoreProxyRequest $request, CreateProxyAction $createProxy): JsonResponse
    {
        try {
            $proxy = $createProxy->execute($request->validated());
        } catch (DuplicateProxyException) {
            return $this->duplicateProxyResponse();
        }

        return (new ProxyResource($proxy))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(ProxyServer $proxy): ProxyResource
    {
        return new ProxyResource($proxy);
    }

    public function update(UpdateProxyRequest $request, ProxyServer $proxy, UpdateProxyAction $updateProxy): JsonResponse
    {
        try {
            $proxy = $updateProxy->execute($proxy, $request->validated());
        } catch (DuplicateProxyException) {
            return $this->duplicateProxyResponse();
        }

        return (new ProxyResource($proxy))->response();
    }

    public function destroy(ProxyServer $proxy, DeleteProxyAction $deleteProxy): Response
    {
        $deleteProxy->execute($proxy);

        return response()->noContent();
    }

    public function check(ProxyServer $proxy, ScheduleProxyCheckAction $scheduleProxyCheck): JsonResponse
    {
        $scheduleProxyCheck->execute($proxy, ProxyCheckSource::Manual);
        $proxy->refresh();

        return response()->json([
            'data' => [
                'id' => $proxy->id,
                'status' => $proxy->status->value,
                'queued' => true,
            ],
        ], Response::HTTP_ACCEPTED);
    }

    public function checkAll(ScheduleAllProxyChecksAction $scheduleAllProxyChecks): JsonResponse
    {
        $count = $scheduleAllProxyChecks->execute(ProxyCheckSource::Manual);

        return response()->json([
            'data' => [
                'queued' => true,
                'candidate_count' => $count,
            ],
        ], Response::HTTP_ACCEPTED);
    }

    private function duplicateProxyResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'Proxy already exists.',
            'errors' => [
                'host' => ['A proxy with the same scheme, host, port and username already exists.'],
            ],
        ], Response::HTTP_CONFLICT);
    }
}
