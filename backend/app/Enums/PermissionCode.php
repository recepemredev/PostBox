<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Every permission the application checks.
 *
 * The rows live in the database so a role can be composed there; the cases live
 * here so a policy is a typed expression rather than a string literal that a typo
 * turns into a silent "denied". A test asserts that the two sets are identical,
 * which is the price of having both.
 */
enum PermissionCode: string
{
    case ApiKeyRead = 'api_key.read';

    case ApiKeyManage = 'api_key.manage';

    case DeliveryReplay = 'delivery.replay';

    case CatalogRead = 'catalog.read';

    case CatalogManage = 'catalog.manage';

    case EndpointSecretManage = 'endpoint_secret.manage';

    case EndpointTest = 'endpoint.test';
}
