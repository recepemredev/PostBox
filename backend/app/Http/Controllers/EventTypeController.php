<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Catalog\CreateEventType;
use App\Http\Requests\StoreEventTypeRequest;
use App\Http\Resources\EventTypeResource;
use App\Models\EventType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response as Status;

final class EventTypeController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return EventTypeResource::collection(
            EventType::query()->withCount('subscriptions')->latest()->paginate(),
        );
    }

    public function store(StoreEventTypeRequest $request, CreateEventType $create): JsonResponse
    {
        $eventType = $create->handle($request->string('name')->value());
        $eventType->loadCount('subscriptions');

        return EventTypeResource::make($eventType)
            ->response($request)
            ->setStatusCode(Status::HTTP_CREATED);
    }
}
