<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Ingest\PublishMessage;
use App\Enums\DeliveryStatus;
use App\Http\Requests\IndexMessagesRequest;
use App\Http\Requests\PublishMessageRequest;
use App\Http\Resources\MessageCollection;
use App\Http\Resources\MessageDetailResource;
use App\Http\Resources\MessageResource;
use App\Models\Application;
use App\Models\Delivery;
use App\Models\Message;
use App\Support\Ledger\MessageFilters;
use App\Support\Pagination\CursorPage;
use App\Support\Pagination\KeysetCursor;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response as Status;

final class MessageController extends Controller
{
    /**
     * The message list (Step 14): filtered, cursor-paginated, newest last —
     * conventions.md's cursor-pagination rule for an append-only table.
     * "Status" filters on whether any of a message's own deliveries are in
     * that state (MessageFilters), since there is no status column on the
     * message itself.
     */
    public function index(IndexMessagesRequest $request): MessageCollection
    {
        $filters = MessageFilters::fromRequest($request);

        $query = Message::query()
            ->with(['application', 'eventType'])
            ->withCount([
                'deliveries',
                'deliveries as succeeded_deliveries_count' => $this->onlyStatus(DeliveryStatus::Succeeded),
                'deliveries as exhausted_deliveries_count' => $this->onlyStatus(DeliveryStatus::Exhausted),
            ]);

        $page = CursorPage::fetch(
            $filters->apply($query),
            $filters->limit(),
            static fn (Message $message): KeysetCursor => KeysetCursor::after($message->created_at, $message->public_id),
        );

        return MessageCollection::make($page);
    }

    /**
     * A foreign tenant's message never reaches this method: route model
     * binding resolves it through the tenant scope, so the answer is 404
     * rather than 403 — the same guarantee every other detail route in this
     * application carries.
     */
    public function show(Message $message): MessageDetailResource
    {
        $message->load(['application', 'eventType', 'deliveries.endpoint', 'deliveries.replay']);

        return MessageDetailResource::make($message);
    }

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

    /**
     * The withCount() constraint index() reuses once per status it counts.
     * A closure factory rather than two near-identical inline closures,
     * which is what CLAUDE.md's "prefer parameterized functions over
     * separate variants" rule is about.
     *
     * @return Closure(Builder<Delivery>): Builder<Delivery>
     */
    private function onlyStatus(DeliveryStatus $status): Closure
    {
        return static fn (Builder $deliveries): Builder => $deliveries->where('status', $status);
    }
}
