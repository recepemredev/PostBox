<?php

declare(strict_types=1);

namespace App\Support\Contract;

use App\Support\Health\HealthStatus;
use Dedoc\Scramble\Contracts\OperationTransformer;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\RouteInfo;

/**
 * HealthController returns `HealthResource::make($report)->response($request)->
 * setStatusCode(...)` — a JsonResponse built and then mutated, not a bare Resource
 * return, which is one idiom short of what Scramble's response-type inference follows.
 * Both status codes carry the same body (HealthResource::toArray()'s own documented
 * shape), so it is declared here once rather than guessed wrong for 200 and missing
 * for 503. `detail` is left out on purpose: it exists only when app.debug is true,
 * which is never true in production — putting it in the contract would describe a
 * field a real deployment never returns.
 */
final class DescribeDegradedHealth implements OperationTransformer
{
    public function handle(Operation $operation, RouteInfo $routeInfo): void
    {
        if ($routeInfo->route->getName() !== 'health') {
            return;
        }

        $operation->responses = [];

        $operation->addResponse((new Response(200))
            ->setDescription('Every dependency probe succeeded.')
            ->setContent('application/json', OpenApiSchema::of($this->reportType())));

        $operation->addResponse((new Response(503))
            ->setDescription('At least one dependency (database, Redis, the queue or pending migrations) failed its probe.')
            ->setContent('application/json', OpenApiSchema::of($this->reportType())));
    }

    private function reportType(): ObjectType
    {
        $statuses = array_map(static fn (HealthStatus $case) => $case->value, HealthStatus::cases());

        $check = new ObjectType;
        $check->addProperty('name', (new StringType)->setDescription('database, redis, queue or migrations.'));
        $check->addProperty('status', (new StringType)->enum($statuses));
        $check->addProperty('duration_ms', new IntegerType);
        $check->setRequired(['name', 'status', 'duration_ms']);

        $checks = (new ArrayType)->setItems($check);

        $report = new ObjectType;
        $report->addProperty('status', (new StringType)->enum($statuses));
        $report->addProperty('checked_at', (new StringType)->format('date-time'));
        $report->addProperty('duration_ms', new IntegerType);
        $report->addProperty('checks', $checks);
        $report->setRequired(['status', 'checked_at', 'duration_ms', 'checks']);

        return $report;
    }
}
