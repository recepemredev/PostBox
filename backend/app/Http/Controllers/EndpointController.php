<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Catalog\CreateEndpoint;
use App\Actions\Catalog\SendTestEvent;
use App\Actions\Catalog\SyncSubscriptions;
use App\Actions\Catalog\UpdateEndpoint;
use App\Http\Requests\SendTestEventRequest;
use App\Http\Requests\StoreEndpointRequest;
use App\Http\Requests\SyncSubscriptionsRequest;
use App\Http\Requests\UpdateEndpointRequest;
use App\Http\Resources\EndpointResource;
use App\Http\Resources\TestEventResource;
use App\Models\Application;
use App\Models\Endpoint;
use App\Models\EndpointSecret;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response as Status;

final class EndpointController extends Controller
{
    /**
     * Nested under the application whose endpoints these are; a foreign
     * tenant's application is already a 404 by the time this runs.
     */
    public function index(Application $application): AnonymousResourceCollection
    {
        return EndpointResource::collection(
            $application->endpoints()
                ->with(['application', 'subscriptions.eventType', 'breaker'])
                ->withCount(['secrets as active_secrets_count' => $this->onlyCurrentSecrets(...)])
                ->latest()
                ->paginate(),
        );
    }

    public function store(StoreEndpointRequest $request, Application $application, CreateEndpoint $create): JsonResponse
    {
        $user = $request->user();
        assert($user instanceof User);

        $endpoint = $create->handle(
            $application,
            $request->string('name')->value(),
            $request->string('url')->value(),
            $user,
            $request->ip(),
        );

        return EndpointResource::make($this->reloadPresentation($endpoint))
            ->response($request)
            ->setStatusCode(Status::HTTP_CREATED);
    }

    public function show(Endpoint $endpoint): EndpointResource
    {
        return EndpointResource::make($this->reloadPresentation($endpoint));
    }

    public function update(UpdateEndpointRequest $request, Endpoint $endpoint, UpdateEndpoint $update): EndpointResource
    {
        $user = $request->user();
        assert($user instanceof User);

        $update->handle($endpoint, $request->changes(), $user, $request->ip());

        return EndpointResource::make($this->reloadPresentation($endpoint));
    }

    public function syncSubscriptions(SyncSubscriptionsRequest $request, Endpoint $endpoint, SyncSubscriptions $sync): EndpointResource
    {
        $sync->handle($endpoint, $request->eventTypeNames());

        return EndpointResource::make($this->reloadPresentation($endpoint));
    }

    /**
     * D76: the ordinary ingest path, narrowed to this one endpoint and
     * marked — not a second delivery mechanism. governor still applies
     * (routes/api.php), so this costs the tenant a rate-limit token and a
     * quota unit exactly like a producer's own publish.
     */
    public function sendTestEvent(SendTestEventRequest $request, Endpoint $endpoint, SendTestEvent $send): JsonResponse
    {
        $result = $send->handle($endpoint, $request->eventType());

        return TestEventResource::make($result)
            ->response($request)
            ->setStatusCode(Status::HTTP_CREATED);
    }

    /**
     * The relations and counts every EndpointResource reads, applied to a
     * single already-loaded model. index() above builds the same shape on
     * a query builder instead — a Model and a Builder do not share one
     * eager-loading API, so this is not the second occurrence CLAUDE.md's
     * "unify at the second occurrence" rule would otherwise ask to collapse.
     */
    private function reloadPresentation(Endpoint $endpoint): Endpoint
    {
        $endpoint->load(['application', 'subscriptions.eventType', 'breaker']);
        $endpoint->loadCount(['secrets as active_secrets_count' => $this->onlyCurrentSecrets(...)]);

        return $endpoint;
    }

    /**
     * The `withCount`/`loadCount` constraint both call sites above share.
     * Typed against EndpointSecret explicitly: a bare `Builder $secrets`
     * parameter carries no generic argument for Larastan to resolve
     * Expirable::scopeCurrent() through.
     *
     * @param  Builder<EndpointSecret>  $secrets
     * @return Builder<EndpointSecret>
     */
    private function onlyCurrentSecrets(Builder $secrets): Builder
    {
        return $secrets->current();
    }
}
