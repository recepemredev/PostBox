<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Tenancy\CreateTenant;
use Illuminate\Database\Seeder;

/**
 * A tenant to sign in as while developing. It goes through the same action the
 * console command uses, so the seeded instance and an operator-created one are
 * the same shape — a seeder that assembled the rows itself would be a second
 * definition of what a new tenant is.
 */
final class DatabaseSeeder extends Seeder
{
    public function run(CreateTenant $createTenant): void
    {
        $createTenant->handle(
            'Acme Industries',
            'Ada Lovelace',
            'ada@acme.test',
            'password',
        );
    }
}
