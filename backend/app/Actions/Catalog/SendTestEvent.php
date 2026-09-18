<?php

declare(strict_types=1);

namespace App\Actions\Catalog;

use App\Actions\Ingest\PublishMessage;
use App\Enums\MessageSource;
use App\Models\Delivery;
use App\Models\Endpoint;
use App\Support\Catalog\TestEventResult;
use Carbon\CarbonImmutable;

/**
 * D76: not a second delivery path. This calls the same PublishMessage every
 * producer's own publish calls — same signing, same guard, same governor,
 * same attempt record — narrowed to this one endpoint and marked so the
 * message it writes is never mistaken for a producer's own traffic.
 */
final readonly class SendTestEvent
{
    public function __construct(private PublishMessage $publish) {}

    public function handle(Endpoint $endpoint, string $eventType): TestEventResult
    {
        // Not yet loaded: the endpoint reaches this action straight off
        // route model binding, and PublishMessage needs the Application
        // model itself, not merely its id.
        $endpoint->loadMissing('application');

        $now = CarbonImmutable::now();

        $payload = [
            'postbox' => [
                'test' => true,
                'endpoint' => $endpoint->public_id,
                'sent_at' => $now->toIso8601ZuluString(),
            ],
        ];

        $published = $this->publish->handle(
            $endpoint->application,
            $eventType,
            $payload,
            source: MessageSource::DashboardTest,
            only: $endpoint,
        );

        $delivery = Delivery::query()
            ->where('message_id', $published->message->id)
            ->where('endpoint_id', $endpoint->id)
            ->firstOrFail();

        return new TestEventResult($published->message, $delivery);
    }
}
