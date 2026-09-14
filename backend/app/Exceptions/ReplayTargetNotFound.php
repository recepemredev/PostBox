<?php

declare(strict_types=1);

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * An operator named a specific endpoint to replay a message to, and that
 * endpoint never received an original delivery of it.
 *
 * This is a 404 rather than a receipt reporting zero deliveries: naming "all
 * of a message's subscribers" and getting none back is an honest answer about
 * a message nobody was subscribed to, but naming one specific endpoint is a
 * claim about something that should exist — a caller who names a target gets
 * 404 when it is not there, not a quiet success that did nothing.
 */
final class ReplayTargetNotFound extends NotFoundHttpException
{
    public function __construct()
    {
        parent::__construct('No original delivery exists for this message and endpoint.');
    }
}
