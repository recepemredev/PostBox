<?php

declare(strict_types=1);

use App\Actions\Stream\StreamAttempts;
use App\Models\DeliveryAttempt;
use App\Support\Pagination\KeysetCursor;
use Carbon\CarbonImmutable;

/**
 * The roadmap's own "reconnect resumes without gaps or duplicates" — the
 * watermark (D126) and the keyset cursor's own tie-break together make this
 * a property of the query, asserted here with a pinned clock rather than by
 * sleeping and hoping.
 */
beforeEach(function (): void {
    [$this->tenant, $this->endpoint, $this->delivery] = publishedDelivery();
    $this->action = app(StreamAttempts::class);
});

it('does not emit an attempt still inside the watermark window, and emits it once the window has fully elapsed', function (): void {
    $moment = CarbonImmutable::now();

    $attemptId = forTenant($this->tenant, function () use ($moment): string {
        return DeliveryAttempt::factory()
            ->for($this->delivery)
            ->for($this->endpoint)
            ->create(['attempt_number' => 1, 'created_at' => $moment])
            ->public_id;
    });

    $watermark = config('postbox.stream.watermark_seconds');

    // Still inside the watermark: nothing yet.
    $tooSoon = forTenant($this->tenant, fn () => $this->action->poll(null, $moment->addSeconds($watermark - 1)));
    expect($tooSoon->items)->toHaveCount(0);

    // Past the watermark: the attempt is now due.
    $due = forTenant($this->tenant, fn () => $this->action->poll(null, $moment->addSeconds($watermark + 1)));
    expect($due->items)->toHaveCount(1)
        ->and($due->items[0]->public_id)->toBe($attemptId);
});

it('pages attempts sharing the same second without a gap or a duplicate, resuming across the tie', function (): void {
    $moment = CarbonImmutable::now();

    $ids = forTenant($this->tenant, fn (): array => collect(range(1, 3))
        ->map(fn (int $n): string => DeliveryAttempt::factory()
            ->for($this->delivery)
            ->for($this->endpoint)
            ->create(['attempt_number' => $n, 'created_at' => $moment])
            ->public_id)
        ->all());

    $past = $moment->addSeconds(config('postbox.stream.watermark_seconds') + 1);

    config(['postbox.stream.batch_size' => 2]);

    $first = forTenant($this->tenant, fn () => $this->action->poll(null, $past));
    expect($first->items)->toHaveCount(2)
        ->and($first->next)->not->toBeNull();

    $second = forTenant($this->tenant, fn () => $this->action->poll($first->next, $past));
    expect($second->items)->toHaveCount(1)
        ->and($second->next)->toBeNull();

    $seen = $first->items->pluck('public_id')->merge($second->items->pluck('public_id'))->sort()->values()->all();
    expect($seen)->toBe(collect($ids)->sort()->values()->all());
});

it('a resume from a cursor already past every row yields nothing and no error', function (): void {
    $moment = CarbonImmutable::now();

    $last = forTenant($this->tenant, function () use ($moment): DeliveryAttempt {
        return DeliveryAttempt::factory()
            ->for($this->delivery)
            ->for($this->endpoint)
            ->create(['attempt_number' => 1, 'created_at' => $moment]);
    });

    $past = $moment->addSeconds(config('postbox.stream.watermark_seconds') + 1);
    $exhaustedCursor = KeysetCursor::after($last->created_at, $last->public_id);

    $page = forTenant($this->tenant, fn () => $this->action->poll($exhaustedCursor, $past));

    expect($page->items)->toHaveCount(0)
        ->and($page->next)->toBeNull();
});

it('a tampered Last-Event-ID answers 422, not a silent restart', function (): void {
    $this->actingAs(memberOf($this->tenant))
        ->withHeaders(['Last-Event-ID' => 'not-a-real-cursor'])
        ->getJson('/api/v1/stream')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('cursor');
});
