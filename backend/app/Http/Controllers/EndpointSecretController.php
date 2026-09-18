<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Catalog\IssueEndpointSecret;
use App\Actions\Catalog\RevokeEndpointSecret;
use App\Http\Requests\StoreEndpointSecretRequest;
use App\Http\Resources\EndpointSecretResource;
use App\Http\Resources\IssuedEndpointSecretResource;
use App\Models\Endpoint;
use App\Models\EndpointSecret;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as Status;

final class EndpointSecretController extends Controller
{
    public function index(Endpoint $endpoint): AnonymousResourceCollection
    {
        return EndpointSecretResource::collection(
            $endpoint->secrets()->latest()->paginate(),
        );
    }

    /**
     * Issuing is how an endpoint rotates (App\Actions\Catalog\IssueEndpointSecret's
     * own docblock) — there is no separate rotate endpoint.
     */
    public function store(StoreEndpointSecretRequest $request, Endpoint $endpoint, IssueEndpointSecret $issue): JsonResponse
    {
        $user = $request->user();
        assert($user instanceof User);

        $issued = $issue->handle($endpoint, $user, $request->ip());

        return IssuedEndpointSecretResource::make($issued)
            ->response($request)
            ->setStatusCode(Status::HTTP_CREATED);
    }

    /**
     * A secret from another endpoint — even one in the same tenant — is a
     * 404 here: the route names an endpoint and a secret together, and a
     * secret that does not belong to that endpoint is not this route's
     * resource, the same "a foreign id is 404, not 403" reasoning route
     * model binding already applies across tenants.
     */
    public function destroy(Request $request, Endpoint $endpoint, EndpointSecret $secret, RevokeEndpointSecret $revoke): Response
    {
        if ($secret->endpoint_id !== $endpoint->id) {
            abort(Status::HTTP_NOT_FOUND);
        }

        $user = $request->user();
        assert($user instanceof User);

        $revoke->handle($secret, $endpoint->public_id, $user, $request->ip());

        return response()->noContent();
    }
}
