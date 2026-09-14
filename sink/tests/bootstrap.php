<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The test environment, forced before anything reads it
|--------------------------------------------------------------------------
|
| Same reasoning as backend/tests/bootstrap.php: PHPUnit's <env> elements write
| $_ENV and putenv() but never $_SERVER, and Laravel's environment repository
| reads $_SERVER first — a value forced through PHPUnit alone loses to whatever
| the container already set. Each value is written to all three sources here,
| in one place, so they cannot disagree.
|
| The key's value is arbitrary: nothing here encrypts anything, and no test
| asserts a ciphertext. Only its length matters, for the configured cipher.
|
*/
$environment = [
    'APP_ENV' => 'testing',
    'APP_DEBUG' => 'false',
    'APP_KEY' => 'base64:R2l2ZW4gdGhlIHNpbmsgbmV2ZXIgZW5jcnlwdHM6ZmFrZQ==',
    'APP_MAINTENANCE_DRIVER' => 'file',
    'CACHE_STORE' => 'array',
    'SESSION_DRIVER' => 'array',
];

foreach ($environment as $key => $value) {
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
    putenv("{$key}={$value}");
}

require __DIR__.'/../vendor/autoload.php';
