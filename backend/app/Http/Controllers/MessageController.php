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
     */
    public function store(
        PublishMessageRequest $request,
        Application $application,
        PublishMessage $publish,
    ): JsonResponse {
        $message = $publish->handle(
            $application,
            $request->string('event_type')->value(),
            $request->array('payload'),
        );

        return MessageResource::make($message)
            ->response($request)
            ->setStatusCode(Status::HTTP_CREATED);
    }
}
