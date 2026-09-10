<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Application;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An application is written for whichever tenant is current, so a test establishes
 * that tenant rather than passing an identifier here.
 *
 * @extends Factory<Application>
 */
final class ApplicationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
        ];
    }
}
