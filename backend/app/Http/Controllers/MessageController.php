<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Ingest\PublishMessage;
use App\Http\Requests\PublishMessageRequest;
use App\Http\Resources\MessageResource;
use App\Models\Application;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response as Status;

final class MessageController extends Controller
{
    /**
     * The application is resolved through the tenant scope by route model
     * binding, so one belonging to another tenant is answered with 404 and never
     * reaches this method.
     *
     * A replay answers 200 rather than 201, with a header saying so: the body is
     * the original message, byte for byte, and nothing was created to produce it.
     */
    public function store(
        PublishMessageRequest $request,
        Application $application,
        PublishMessage $publish,
    ): JsonResponse {
        $published = $publish->handle(
            $application,
            $request->string('event_type')->value(),
            $request->array('payload'),
            $request->idempotencyKey(),
        );

        $response = MessageResource::make($published->message)->response($request);

        return $published->replayed
            ? $response->setStatusCode(Status::HTTP_OK)->withHeaders(['Idempotent-Replay' => 'true'])
            : $response->setStatusCode(Status::HTTP_CREATED);
    }
}
