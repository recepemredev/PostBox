<?php

declare(strict_types=1);

use App\Models\AuditLog;
use Illuminate\Database\QueryException;

/*
 * The one property that makes an audit trail worth trusting: once written, a
 * row cannot be changed or removed by anything, including the schema owner. A
 * trigger enforces this in the database itself, not by convention.
 */

beforeEach(function (): void {
    $this->tenant = tenantNamed('Acme');
});

it('rejects an action name the format constraint does not allow', function (): void {
    expect(fn () => forTenant($this->tenant, fn (): AuditLog => AuditLog::factory()->create(['action' => 'Endpoint Updated'])))
        ->toThrow(QueryException::class);
});

it('refuses to update a row once it is written', function (): void {
    $entry = forTenant($this->tenant, fn (): AuditLog => AuditLog::factory()->create());

    expect(fn () => forTenant($this->tenant, fn (): bool => $entry->update(['action' => 'endpoint.deleted'])))
        ->toThrow(QueryException::class);
});

it('refuses to delete a row once it is written', function (): void {
    $entry = forTenant($this->tenant, fn (): AuditLog => AuditLog::factory()->create());

    expect(fn () => forTenant($this->tenant, fn (): ?bool => $entry->delete()))
        ->toThrow(QueryException::class);
});
