<?php

declare(strict_types=1);

use App\Http\Resources\StreamedAttemptResource;
use App\Models\DeliveryAttempt;

/**
 * The payload carries no request_body, response_body or headers of any
 * kind — the guard against someone widening this resource later and
 * pushing 256 KiB bodies down a live feed for every attempt.
 */
beforeEach(function (): void {
    [$this->tenant, $this->endpoint, $this->delivery] = publishedDelivery();
});

it('carries no request_body, response_body or headers of any kind, and carries every stream-specific field', function (): void {
    forTenant($this->tenant, function (): void {
        $attempt = DeliveryAttempt::factory()
            ->for($this->delivery)
            ->for($this->endpoint)
            ->create([
                'attempt_number' => 1,
                'request_headers' => ['Content-Type' => 'application/json', 'X-Custom' => 'value'],
                'request_body' => '{"total":4200}',
                'response_headers' => ['Content-Type' => 'application/json'],
                'response_body' => '{"ok":true}',
            ]);

        $resource = StreamedAttemptResource::make($attempt->fresh())->resolve();

        expect($resource)->not->toHaveKey('request_headers')
            ->not->toHaveKey('request_body')
            ->not->toHaveKey('response_headers')
            ->not->toHaveKey('response_body');

        expect($resource)->toMatchArray([
            'id' => $attempt->public_id,
            'delivery_id' => $this->delivery->public_id,
            'message_id' => $this->delivery->message->public_id,
            'endpoint_id' => $this->endpoint->public_id,
            'endpoint_name' => $this->endpoint->name,
            'attempt_number' => 1,
            'outcome' => 'succeeded',
            'response_status' => 200,
        ]);

        expect($resource)->toHaveKeys(['event_type', 'duration_ms', 'delivery_status', 'created_at']);

        // Encoded to JSON the way the controller actually sends it: still no
        // trace of the real bodies anywhere in the frame, not only absent
        // under their usual keys.
        $encoded = json_encode($resource, JSON_THROW_ON_ERROR);
        expect($encoded)->not->toContain('4200')->not->toContain('"ok":true');
    });
});
