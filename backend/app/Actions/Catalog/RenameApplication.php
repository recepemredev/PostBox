<?php

declare(strict_types=1);

namespace App\Actions\Catalog;

use App\Models\Application;

final readonly class RenameApplication
{
    public function handle(Application $application, string $name): Application
    {
        $application->update(['name' => $name]);

        return $application;
    }
}
