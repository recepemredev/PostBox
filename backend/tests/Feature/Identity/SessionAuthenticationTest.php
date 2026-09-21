<?php

declare(strict_types=1);

use App\Enums\RoleSlug;
use App\Models\User;

/*
 * The dashboard credential. It is a session cookie rather than a token because the
 * dashboard and the API share an origin behind nginx, which is also why these
 * routes carry CSRF and the public API will not.
 */

beforeEach(function (): void {
    $this->acme = tenantNamed('Acme');
    $this->ada = memberOf($this->acme);
});

it('signs a member in and answers with who they are and where', function (): void {
    $response = $this->postJson(route('login'), [
        'email' => $this->ada->email,
        'password' => 'password',
    ]);

    $response->assertOk()
        ->assertJsonPath('user.id', $this->ada->public_id)
        ->assertJsonPath('user.email', $this->ada->email)
        ->assertJsonPath('tenant.id', $this->acme->public_id)
        ->assertJsonPath('permissions', [
            'api_key.read',
            'api_key.manage',
            'delivery.replay',
            'catalog.read',
            'catalog.manage',
            'endpoint_secret.manage',
            'endpoint.test',
            'ledger.read',
        ]);

    $this->assertAuthenticatedAs($this->ada);
});

it('never says which half of the credentials was wrong', function (): void {
    $wrongPassword = $this->postJson(route('login'), [
        'email' => $this->ada->email,
        'password' => 'not-the-password',
    ]);

    $unknownAddress = $this->postJson(route('login'), [
        'email' => 'nobody@acme.test',
        'password' => 'password',
    ]);

    $wrongPassword->assertStatus(422);
    $unknownAddress->assertStatus(422);

    expect($wrongPassword->json('message'))->toBe($unknownAddress->json('message'));

    $this->assertGuest();
});

it('stops a password from being guessed one request at a time', function (): void {
    foreach (range(1, 5) as $ignored) {
        $this->postJson(route('login'), [
            'email' => $this->ada->email,
            'password' => 'wrong',
        ])->assertStatus(422);
    }

    $this->postJson(route('login'), [
        'email' => $this->ada->email,
        'password' => 'wrong',
    ])->assertStatus(429);
});

it('refuses an account that belongs to no tenant', function (): void {
    $stranger = User::factory()->create();

    $this->postJson(route('login'), [
        'email' => $stranger->email,
        'password' => 'password',
    ])->assertForbidden();
});

it('answers 401 rather than redirecting when there is no session', function (): void {
    $this->getJson(route('me'))->assertUnauthorized();
});

it('describes the current identity to a signed-in member', function (): void {
    $this->actingAs($this->ada)
        ->getJson(route('me'))
        ->assertOk()
        ->assertJsonPath('tenant.name', 'Acme')
        ->assertJsonPath('user.email', $this->ada->email);
});

it('ends the session on sign out', function (): void {
    $this->actingAs($this->ada)->postJson(route('logout'))->assertNoContent();

    $this->assertGuest();
});

it('tells a viewer that they may only read', function (): void {
    $viewer = memberOf($this->acme, RoleSlug::Viewer);

    $this->actingAs($viewer)
        ->getJson(route('me'))
        ->assertOk()
        ->assertJsonPath('permissions', ['api_key.read', 'catalog.read', 'ledger.read']);
});
