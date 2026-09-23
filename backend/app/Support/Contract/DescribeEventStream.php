<?php

declare(strict_types=1);

namespace App\Support\Contract;

use Dedoc\Scramble\Contracts\OperationTransformer;
use Dedoc\Scramble\Support\Generator\Header;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Parameter;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\RouteInfo;

/**
 * Scramble cannot infer `text/event-stream` from StreamController's
 * StreamedResponse return type — the same blind spot DescribeDegradedHealth
 * already covers for the health route's mutated JsonResponse. Declared here
 * instead: the 200 response as an SSE frame stream shaped like
 * StreamedAttemptResource, the Last-Event-ID request header, and the 429
 * both the slot cap (StreamSlotExceeded) and the `throttle:stream` limiter
 * can answer with.
 */
final class DescribeEventStream implements OperationTransformer
{
    public function handle(Operation $operation, RouteInfo $routeInfo): void
    {
        if ($routeInfo->route->getName() !== 'stream') {
            return;
        }

        $operation->responses = [];

        $operation->addResponse((new Response(200))
            ->setDescription('A live stream of this tenant\'s delivery attempts, as Server-Sent Events.')
            ->setContent('text/event-stream', OpenApiSchema::of($this->attemptType())));

        $rateLimited = OpenApiSchema::errorResponse(
            429,
            'Too many concurrent live streams, or too many reconnects, for this tenant.',
        );
        $rateLimited->addHeader('Retry-After', new Header(
            description: 'Seconds until a retry is likely to succeed.',
            schema: OpenApiSchema::of(new IntegerType),
        ));
        $operation->addResponse($rateLimited);

        $parameter = Parameter::make('Last-Event-ID', 'header');
        $parameter->description(
            'The opaque cursor from the previous response\'s own id: field, '
            .'sent automatically by EventSource on reconnect. Absent on a '
            .'first connect, where ?cursor= is the equivalent query parameter.'
        );
        $parameter->setSchema(OpenApiSchema::of((new StringType)->nullable(true)));

        $operation->addParameters([$parameter]);
    }

    private function attemptType(): ObjectType
    {
        $type = new ObjectType;
        $type->addProperty('id', (new StringType)->setDescription('att_...'));
        $type->addProperty('delivery_id', (new StringType)->setDescription('dlv_...'));
        $type->addProperty('message_id', (new StringType)->setDescription('msg_...'));
        $type->addProperty('endpoint_id', (new StringType)->setDescription('ep_...'));
        $type->addProperty('endpoint_name', new StringType);
        $type->addProperty('event_type', new StringType);
        $type->addProperty('attempt_number', new IntegerType);
        $type->addProperty('outcome', (new StringType)->enum([
            'succeeded', 'failed', 'timeout', 'dns_error', 'tls_error', 'connection_error', 'blocked',
        ]));
        $type->addProperty('response_status', (new IntegerType)->nullable(true));
        $type->addProperty('duration_ms', new IntegerType);
        $type->addProperty('delivery_status', (new StringType)->enum(['pending', 'succeeded', 'exhausted']));
        $type->addProperty('created_at', (new StringType)->format('date-time'));
        $type->setRequired([
            'id', 'delivery_id', 'message_id', 'endpoint_id', 'endpoint_name', 'event_type',
            'attempt_number', 'outcome', 'response_status', 'duration_ms', 'delivery_status', 'created_at',
        ]);

        return $type;
    }
}
