<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Recovery\ReplayDeliveries;
use App\Http\Requests\ReplayMessageRequest;
use App\Http\Resources\ReplayResource;
use App\Models\Message;
use App\Support\Recovery\ReplayScope;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response as Status;

final class ReplayController extends Controller
{
    /**
     * The message is resolved through the tenant scope by route model
     * binding, so one belonging to another tenant is answered with 404 and
     * never reaches this method — the same guarantee MessageController's own
     * {application} parameter carries.
     *
     * A duplicate answers 200 rather than 201, with the same header Ingest's
     * own replay carries: the body is the original receipt, and nothing was
     * opened to produce it.
     */
    public function message(ReplayMessageRequest $request, Message $message, ReplayDeliveries $replay): JsonResponse
    {
        $scope = ReplayScope::forMessage($message, $request->targetEndpoint());

        $result = $replay->handle($scope, $request->idempotencyKey());

        $response = ReplayResource::make($result->replay)->response($request);

        return $result->duplicate
            ? $response->setStatusCode(Status::HTTP_OK)->withHeaders(['Idempotent-Replay' => 'true'])
            : $response->setStatusCode(Status::HTTP_CREATED);
    }
}
