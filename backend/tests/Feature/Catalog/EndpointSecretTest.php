<?php

declare(strict_types=1);

use App\Models\Endpoint;
use App\Models\EndpointSecret;
use Illuminate\Support\Facades\DB;

/*
 * Two properties matter at this layer. The signing key never sits in the database
 * in the clear, and "which secrets sign a request right now" counts the live ones
 * only — an endpoint in mid-rotation holds several, but the rotated-out and
 * revoked rows are not among them.
 */

beforeEach(function (): void {
    $this->tenant = tenantNamed('Acme');
    $this->endpoint = forTenant($this->tenant, fn (): Endpoint => Endpoint::factory()->create());
});

it('stores the signing key encrypted, not in the clear', function (): void {
    $secret = forTenant($this->tenant, fn (): EndpointSecret => EndpointSecret::factory()
        ->for($this->endpoint)
        ->create(['secret' => 'whsec_the_plaintext_key']));

    [$stored, $roundTripped] = forTenant($this->tenant, fn (): array => [
        DB::table('endpoint_secrets')->where('id', $secret->id)->value('secret'),
        $secret->fresh()->secret,
    ]);

    expect($stored)->not->toBe('whsec_the_plaintext_key')
        ->and($roundTripped)->toBe('whsec_the_plaintext_key');
});

it('counts only the live secrets as current during rotation', function (): void {
    forTenant($this->tenant, function (): void {
        EndpointSecret::factory()->for($this->endpoint)->create();
        EndpointSecret::factory()->for($this->endpoint)->create(['expires_at' => now()->addHour()]);
        EndpointSecret::factory()->for($this->endpoint)->expired()->create();
        EndpointSecret::factory()->for($this->endpoint)->revoked()->create();
    });

    $current = forTenant($this->tenant, fn (): int => $this->endpoint->secrets()->current()->count());

    expect($current)->toBe(2);
});
