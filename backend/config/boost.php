<?php

declare(strict_types=1);

use Laravel\Boost\Mcp\Tools\BrowserLogs;
use Laravel\Boost\Mcp\Tools\RecordRule;

/*
|--------------------------------------------------------------------------
| Laravel Boost
|--------------------------------------------------------------------------
|
| Only the keys PostBox changes. Boost merges this file over its own defaults,
| so everything else keeps the package's value.
|
| Browser logs are off entirely. Boost collects them by injecting a script into
| HTML responses of Laravel's `web` group, but PostBox serves no pages: the
| dashboard is the Next.js application, and nginx sends only /api and /v1 to
| the backend. The watcher would register an unreachable, CSRF-exempt route
| and a log channel nothing writes to, and the tool would only ever report a
| missing file.
|
| record-rule is off too. It writes agent-recorded rules to .ai/rules, a second
| source of project rules beside CLAUDE.md and the conventions document. Run in
| the container, it would also land under backend/ on a mount the container
| user cannot write.
|
| Boost is a development dependency. The production image does not contain
| this class, which is safe because `::class` resolves the name without
| loading it.
|
*/

return [
    'browser_logs_watcher' => false,

    'mcp' => [
        'tools' => [
            'exclude' => [BrowserLogs::class, RecordRule::class],
        ],
    ],
];
