<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a tenant is provisioned for. Governor reads both of its ceilings — burst
 * capacity and period quota — from config by plan, the same way
 * max_payload_bytes is a product decision rather than a per-tenant value an
 * operator edits. This enum only names the closed set; resolving a plan to its
 * numbers is Governor's job, not this one's.
 */
enum Plan: string
{
    case Free = 'free';

    case Pro = 'pro';
}
