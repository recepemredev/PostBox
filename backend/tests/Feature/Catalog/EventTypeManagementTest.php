<?php

declare(strict_types=1);

use App\Enums\RoleSlug;

/*
 * The HTTP surface over EventType. The name's own shape and tenant scoping
 * already have a test at the schema layer (EventTypeTest.php); what is
 * worth proving here is the permission split and the subscriber count.
 */

beforeEach(function (): void {
    $this->acme = tenantNamed('Acme');
    $this->admin = memberOf($this->acme);
    $this->viewer = memberOf($this->acme, RoleSlug::Viewer);
});

it('registers an event type', function (): void {
    $this->actingAs($this->admin)
        ->postJson(route('event-types.store'), ['name' => 'invoice.paid'])
        ->assertCreated()
        ->assertJsonPath('name', 'invoice.paid')
        ->assertJsonPath('subscriber_count', 0);

    $this->actingAs($this->admin)
        ->getJson(route('event-types.index'))
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('rejects a name the format rule does not allow', function (): void {
    $this->actingAs($this->admin)
        ->postJson(route('event-types.store'), ['name' => 'Invoice Paid'])
        ->assertUnprocessable()
        ->assertJsonValidationErrorFor('name');
});

it('lets a viewer read event types but not register one', function (): void {
    $this->actingAs($this->viewer)->getJson(route('event-types.index'))->assertOk();

    $this->actingAs($this->viewer)
        ->postJson(route('event-types.store'), ['name' => 'invoice.paid'])
        ->assertForbidden();
});
