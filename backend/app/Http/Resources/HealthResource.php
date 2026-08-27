<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Support\Health\CheckResult;
use App\Support\Health\HealthReport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;

/**
 * @property-read HealthReport $resource
 */
final class HealthResource extends JsonResource
{
    /**
     * @return array{status: string, checked_at: string, duration_ms: int, checks: list<array<string, mixed>>}
     */
    public function toArray(Request $request): array
    {
        return [
            'status' => $this->resource->status()->value,
            'checked_at' => Carbon::now('UTC')->toIso8601ZuluString(),
            'duration_ms' => $this->resource->durationMs,
            'checks' => array_map($this->presentCheck(...), $this->resource->checks),
        ];
    }

    /**
     * The failure reason is internal detail — a driver message can name a host, a
     * user or a schema. It is rendered only when debug output is already enabled.
     *
     * @return array<string, mixed>
     */
    private function presentCheck(CheckResult $check): array
    {
        $presented = [
            'name' => $check->name,
            'status' => $check->status->value,
            'duration_ms' => $check->durationMs,
        ];

        if ($check->detail !== null && Config::boolean('app.debug')) {
            $presented['detail'] = $check->detail;
        }

        return $presented;
    }
}
