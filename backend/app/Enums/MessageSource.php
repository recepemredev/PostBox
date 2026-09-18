<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a message came from. `Api` is every producer's own publish; there is
 * exactly one other value, `DashboardTest`, for the synthetic event an
 * operator sends from an endpoint's own screen (D76) — the same ingest path,
 * the same fan-out, the same governor, marked so the attempt log never lies
 * about where the traffic came from.
 */
enum MessageSource: string
{
    case Api = 'api';

    case DashboardTest = 'dashboard_test';
}
