<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Catalog\CreateApplication;
use App\Actions\Catalog\RenameApplication;
use App\Http\Requests\StoreApplicationRequest;
use App\Http\Requests\UpdateApplicationRequest;
use App\Http\Resources\ApplicationResource;
use App\Models\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response as Status;

final class ApplicationController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return ApplicationResource::collection(
            Application::query()->withCount('endpoints')->latest()->paginate(),
        );
    }

    public function store(StoreApplicationRequest $request, CreateApplication $create): JsonResponse
    {
        $application = $create->handle($request->string('name')->value());
        $application->loadCount('endpoints');

        return ApplicationResource::make($application)
            ->response($request)
            ->setStatusCode(Status::HTTP_CREATED);
    }

    /**
     * A foreign tenant's application never reaches this method: route model
     * binding resolves it through the tenant scope, so the answer is 404
     * rather than 403.
     */
    public function show(Application $application): ApplicationResource
    {
        $application->loadCount('endpoints');

        return ApplicationResource::make($application);
    }

    public function update(UpdateApplicationRequest $request, Application $application, RenameApplication $rename): ApplicationResource
    {
        $rename->handle($application, $request->string('name')->value());
        $application->loadCount('endpoints');

        return ApplicationResource::make($application);
    }
}
