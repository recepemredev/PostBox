<?php

declare(strict_types=1);

namespace App\Actions\Catalog;

use App\Models\EventType;

final readonly class CreateEventType
{
    public function handle(string $name): EventType
    {
        return EventType::create(['name' => $name]);
    }
}
