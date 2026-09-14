<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Recovery\ReplayDeliveries;
use App\Http\Requests\ReplayMessageRequest;
use App\Http\Requests\ReplayRangeRequest;
use App\Http\Resources\ReplayResource;
use App\Models\Endpoint;
use App\Models\Message;
use App\Support\Recovery\ReplayResult;
use App\Support\Recovery\ReplayScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as Status;

final class ReplayController extends Controller
{
    /**
     * The message is resolved through the tenant scope by route model
     * binding, so one belonging to another tenant is answered with 404 and
     * never reaches this method — the same guarantee MessageController's own
     * {application} parameter carries.
     */
    public function message(ReplayMessageRequest $request, Message $message, ReplayDeliveries $replay): JsonResponse
    {
        $scope = ReplayScope::forMessage($message, $request->targetEndpoint());

        return $this->respond($replay->handle($scope, $request->idempotencyKey()), $request);
    }

    /**
     * Bounded and paginated: at most postbox.replay.max_deliveries_per_request
     * exhausted deliveries per call, with next_cursor carrying the rest — an
     * operator recovering a large outage calls this again with that cursor
     * rather than this action holding one transaction open for the whole
     * window.
     */
    public function range(ReplayRangeRequest $request, Endpoint $endpoint, ReplayDeliveries $replay): JsonResponse
    {
        [$from, $to] = $request->range();

        $scope = ReplayScope::forRange($endpoint, $from, $to, $request->cursor());

        return $this->respond($replay->handle($scope, $request->idempotencyKey()), $request);
    }

    /**
     * A duplicate answers 200 rather than 201, with the same header Ingest's
     * own replay carries: the body is the original receipt, and nothing was
     * opened to produce it.
     */
    private function respond(ReplayResult $result, Request $request): JsonResponse
    {
        $response = ReplayResource::make($result)->response($request);

        return $result->duplicate
            ? $response->setStatusCode(Status::HTTP_OK)->withHeaders(['Idempotent-Replay' => 'true'])
            : $response->setStatusCode(Status::HTTP_CREATED);
    }
}
