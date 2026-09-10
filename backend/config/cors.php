<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS)
    |--------------------------------------------------------------------------
    |
    | PostBox is single origin by construction: the browser talks to nginx, which
    | routes /api and /v1 to PHP and everything else to the dashboard. A
    | cross-origin browser request is therefore never one this application should
    | answer, and an Access-Control-Allow-Origin header on a cookie-authenticated
    | API is an invitation with no reason behind it.
    |
    | The framework's default matches `api/*` and allows every origin. Matching no
    | path at all is what turns the handler off; the remaining values are kept so
    | the shape of the configuration is still readable, not because they apply.
    |
    | Server-to-server callers — the SDKs, a producer's backend — are not browsers
    | and are unaffected by any of this.
    |
    */

    'paths' => [],

    'allowed_methods' => [],

    'allowed_origins' => [],

    'allowed_origins_patterns' => [],

    'allowed_headers' => [],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
