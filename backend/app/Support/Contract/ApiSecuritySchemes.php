<?php

declare(strict_types=1);

namespace App\Support\Contract;

use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Support\Facades\Config;

/**
 * The two credentials this API accepts, named once so the document transformer that
 * declares them and the operation transformer that assigns them per route (D26: a
 * session cookie for the dashboard, a bearer API key for ingest — never both on the
 * same route) cannot drift apart on the scheme name.
 */
final class ApiSecuritySchemes
{
    public const string BEARER_API_KEY = 'bearerApiKey';

    public const string SESSION_COOKIE = 'sessionCookie';

    public static function bearerApiKey(): SecurityScheme
    {
        // dedoc/scramble's own factory methods carry no declared return type, so
        // Larastan (vendor code is reflection-only, never analyzed) sees `mixed`
        // here — narrowed the same way AttemptDelivery narrows a vendor `mixed`.
        $scheme = SecurityScheme::http('bearer');
        assert($scheme instanceof SecurityScheme);

        return $scheme
            ->as(self::BEARER_API_KEY)
            ->setDescription(
                'A tenant-scoped API key, shown once on creation. Sent as '
                .'`Authorization: Bearer pbk_...` on the ingest surface only.'
            );
    }

    public static function sessionCookie(): SecurityScheme
    {
        // The cookie name is never hardcoded here — it is Laravel's own
        // `session.cookie` config, which defaults to `postbox-session` (fixed,
        // not derived from APP_NAME — see config/session.php) and can be
        // overridden by SESSION_COOKIE. Reading it keeps the two from drifting.
        $scheme = SecurityScheme::apiKey('cookie', Config::string('session.cookie'));
        assert($scheme instanceof SecurityScheme);

        return $scheme
            ->as(self::SESSION_COOKIE)
            ->setDescription(
                'The dashboard session, obtained by logging in through '.
                'POST /api/v1/login. Requests also need the X-XSRF-TOKEN header '.
                'from the cookie GET /api/v1/csrf-cookie sets.'
            );
    }
}
