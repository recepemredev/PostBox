<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Operations\BuildOperationsSnapshot;
use App\Http\Resources\OperationsResource;

final class OperationsController extends Controller
{
    public function __invoke(BuildOperationsSnapshot $build): OperationsResource
    {
        return OperationsResource::make($build->handle());
    }
}
