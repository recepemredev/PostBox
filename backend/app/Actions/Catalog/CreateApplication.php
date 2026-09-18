<?php

declare(strict_types=1);

namespace App\Actions\Catalog;

use App\Models\Application;

/**
 * An application is not named in CLAUDE.md's audit rule ("endpoint and secret
 * changes") the way an endpoint or a secret is, so this stays a plain create
 * with no audit write — the same asymmetry the rule itself draws.
 */
final readonly class CreateApplication
{
    public function handle(string $name): Application
    {
        return Application::create(['name' => $name]);
    }
}
