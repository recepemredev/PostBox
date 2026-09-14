<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\RespondAsConfigured;
use App\Http\Requests\SinkRequest;
use Illuminate\Http\Response;

final readonly class SinkController
{
    public function __construct(private RespondAsConfigured $respond) {}

    public function __invoke(SinkRequest $request): Response
    {
        $verdict = $this->respond->handle(
            $request->delayMilliseconds(),
            $request->failuresPerPeriod(),
            $request->failureStatus(),
        );

        // Empty body by design: the sink's ceiling is the yardstick every
        // PostBox figure is reported against, so it does no work a receiver
        // is not required to do. The sequence number rides a header, which
        // PostBox records with the attempt.
        return response('', $verdict->status, ['Sink-Sequence' => (string) $verdict->sequence]);
    }
}
