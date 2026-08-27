<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Health\CheckSystemHealth;
use App\Http\Resources\HealthResource;
use App\Support\Health\HealthStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class HealthController extends Controller
{
    public function __invoke(Request $request, CheckSystemHealth $checkSystemHealth): JsonResponse
    {
        $report = $checkSystemHealth->handle();

        return HealthResource::make($report)
            ->response($request)
            ->setStatusCode(
                $report->status() === HealthStatus::Ok
                    ? Response::HTTP_OK
                    : Response::HTTP_SERVICE_UNAVAILABLE,
            );
    }
}
