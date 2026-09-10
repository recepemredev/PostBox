<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Identity\IssueApiKey;
use App\Actions\Identity\RevokeApiKey;
use App\Http\Requests\StoreApiKeyRequest;
use App\Http\Resources\ApiKeyResource;
use App\Http\Resources\IssuedApiKeyResource;
use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as Status;

final class ApiKeyController extends Controller
{
    /**
     * Paginated because every list in this application is. The global scope and
     * the database policy both restrict it to the current tenant; neither is
     * mentioned here, which is the point of putting them where they are.
     */
    public function index(): AnonymousResourceCollection
    {
        return ApiKeyResource::collection(
            ApiKey::query()->latest()->paginate(),
        );
    }

    public function store(StoreApiKeyRequest $request, IssueApiKey $issue): JsonResponse
    {
        $user = $request->user();
        assert($user instanceof User);

        $issued = $issue->handle(
            $request->string('name')->value(),
            $user,
            $request->date('expires_at')?->toImmutable(),
        );

        return IssuedApiKeyResource::make($issued)
            ->response($request)
            ->setStatusCode(Status::HTTP_CREATED);
    }

    /**
     * A key from another tenant does not reach this method: the route binding
     * resolves it through the tenant scope, so the answer is 404 rather than 403.
     * Whether a key exists elsewhere is not this tenant's business.
     */
    public function destroy(Request $request, ApiKey $apiKey, RevokeApiKey $revoke): Response
    {
        $revoke->handle($apiKey);

        return response()->noContent();
    }
}
