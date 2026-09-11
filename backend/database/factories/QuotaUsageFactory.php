<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\QuotaUsage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<QuotaUsage>
 */
final class QuotaUsageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'period_start' => Carbon::now()->startOfMonth()->toDateString(),
            'used' => 0,
        ];
    }
}
