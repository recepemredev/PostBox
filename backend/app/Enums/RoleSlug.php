<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The roles the migration writes. Code names a role only where it has to create
 * one — the first member of a tenant — and never to decide what that role may do;
 * that question is always asked of a permission.
 */
enum RoleSlug: string
{
    case Admin = 'admin';

    case Viewer = 'viewer';
}
