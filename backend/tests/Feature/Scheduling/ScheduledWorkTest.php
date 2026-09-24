<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

/*
 * routes/console.php's own comment states the whole premise: "the scheduler
 * container is a singleton, so anything defined here runs exactly once per
 * interval across the whole stack." That premise is enforced structurally —
 * container_name and replicas: 1 in compose.yaml, asserted in CI by
 * assert-scheduler-singleton.sh — never inside the application itself.
 *
 * What is asserted here is narrower and belongs to the application: that
 * routes/console.php's own registrations carry the guard they claim to.
 * withoutOverlapping() is still worth having even under a singleton scheduler
 * — it is the one line standing between a slow pass and passes stacking up
 * behind it — and nothing here proves that guard actually acquires anything;
 * that would be testing Illuminate\Console\Scheduling's own behaviour, which
 * CLAUDE.md rules out. What this proves is ours: every command this file
 * registers declares the guard, and exactly the commands the roadmap names
 * are registered at all — a fourth command added without one would fail
 * here, which the compose-level assertion has no way to see.
 */

/**
 * @return list<Event>
 */
function scheduledEvents(): array
{
    return app(Schedule::class)->events();
}

it('guards every scheduled command against overlapping itself', function (): void {
    $events = scheduledEvents();

    expect($events)->not->toBeEmpty();

    foreach ($events as $event) {
        expect($event->withoutOverlapping)->toBeTrue();
    }
});

it('registers exactly the four commands the scheduler is meant to run', function (): void {
    $names = collect(scheduledEvents())
        ->map(fn (Event $event): string => collect(['partitions:ensure', 'outbox:dispatch', 'idempotency:prune', 'horizon:snapshot'])
            ->first(fn (string $name): bool => str_ends_with($event->command, $name)) ?? $event->command)
        ->sort()
        ->values()
        ->all();

    expect($names)->toBe(['horizon:snapshot', 'idempotency:prune', 'outbox:dispatch', 'partitions:ensure']);
});

it('relies on the scheduler being a singleton rather than on onOneServer', function (): void {
    // Deliberately inverted: onOneServer() coordinates several scheduler
    // instances, and PostBox runs exactly one, enforced structurally and
    // asserted in CI. Adding it here would make that guarantee look optional
    // — whoever changes this later has to delete this test and read D93
    // first, rather than the change passing unnoticed.
    foreach (scheduledEvents() as $event) {
        expect($event->onOneServer)->toBeFalse();
    }
});
