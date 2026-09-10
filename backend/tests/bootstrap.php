<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The test environment, forced before anything reads it
|--------------------------------------------------------------------------
|
| Compose hands the container its configuration as real environment variables,
| and PHP copies those into both $_ENV and $_SERVER. PHPUnit's own <env> elements
| write $_ENV and putenv() but never $_SERVER — and Laravel's environment
| repository reads $_SERVER first, so a value forced through PHPUnit alone loses
| to the one the container set.
|
| The consequence is not a subtlety: a suite that uses RefreshDatabase would drop
| every table in the development database and report success. Each value is
| written to all three sources here, in one place, so the three cannot disagree.
|
| Redis gets its own index and the database its own name for the same reason.
|
*/
$environment = [
    'APP_ENV' => 'testing',
    'APP_DEBUG' => 'false',
    'APP_MAINTENANCE_DRIVER' => 'file',
    'BCRYPT_ROUNDS' => '4',

    // Row Level Security, partitioning and CHECK constraints are PostgreSQL
    // behaviour, so the suite runs against PostgreSQL rather than SQLite (D16).
    'DB_CONNECTION' => 'pgsql',
    'DB_DATABASE' => 'postbox_test',

    'REDIS_DB' => '1',
    'REDIS_CACHE_DB' => '1',
    'QUEUE_CONNECTION' => 'redis',

    'CACHE_STORE' => 'array',
    'SESSION_DRIVER' => 'array',
    'MAIL_MAILER' => 'array',
];

foreach ($environment as $key => $value) {
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
    putenv("{$key}={$value}");
}

require __DIR__.'/../vendor/autoload.php';
