#!/usr/bin/env pwsh
#
# PostBox task runner for Windows. The Makefile is the same set of targets for
# Linux, macOS and CI; both are thin dispatchers over the same docker compose
# invocations, so the behaviour lives in the compose files rather than here.
# CI asserts that the two files expose the same targets.
#
# TARGETS: up down fresh logs test stan format check ci prod-up prod-down bench help

[CmdletBinding()]
param(
    [Parameter(Position = 0)]
    [string] $Target = 'help',

    [Parameter(Position = 1, ValueFromRemainingArguments = $true)]
    [string[]] $Rest = @()
)

$ErrorActionPreference = 'Stop'
Set-Location -Path $PSScriptRoot

$Dev = @('docker', 'compose', '--profile', 'dev')
$Prod = @('docker', 'compose', '-f', 'compose.yaml', '-f', 'compose.prod.yaml')

function Invoke-Step {
    param([string[]] $Command)

    Write-Host "> $($Command -join ' ')" -ForegroundColor DarkGray

    # docker writes its progress to stderr. Under `ErrorActionPreference = Stop`
    # PowerShell treats that as a terminating error, so ordinary build output
    # would abort the run. The exit code is the signal here, not the stream.
    $previous = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'

    try {
        & $Command[0] $Command[1..($Command.Length - 1)]
    } finally {
        $ErrorActionPreference = $previous
    }

    if ($LASTEXITCODE -ne 0) {
        throw "command failed with exit code ${LASTEXITCODE}: $($Command -join ' ')"
    }
}

function Resolve-Bash {
    # The CI assertion scripts are POSIX, and Windows' own `bash` on PATH is the
    # WSL stub: a different filesystem and a different docker, when a distribution
    # is installed at all. Git Bash is the shell that can run them against the
    # host's docker, and a checkout already requires Git — so Git's own location
    # is where to look, rather than a hard-coded install path.
    $git = Get-Command git -ErrorAction SilentlyContinue

    if ($git) {
        $candidate = Join-Path (Split-Path (Split-Path $git.Source -Parent) -Parent) 'bin/bash.exe'

        if (Test-Path $candidate) {
            return $candidate
        }
    }

    throw 'ci: Git Bash was not found. The assertion scripts are POSIX — install Git for Windows, or run `make ci` on Linux.'
}

function Initialize-Environment {
    if (-not (Test-Path '.env')) {
        Copy-Item '.env.example' '.env'
        Write-Host 'created .env from .env.example' -ForegroundColor Yellow
    }

    if (-not (Test-Path 'backend/.env')) {
        # Configuration comes from the container environment, which Compose fills
        # from the single .env at the repository root. Dotenv reads this path
        # unconditionally and the test runner reports the failed read as a
        # warning; that is the only reason the file exists.
        @(
            '# Intentionally empty. Configuration comes from the container environment,'
            '# which Compose fills from the single .env at the repository root. Do not'
            '# put values here — they would be a second source of truth.'
        ) | Set-Content 'backend/.env' -Encoding utf8
    }

    $envFile = Get-Content '.env' -Raw

    if ($envFile -match '(?m)^APP_KEY=\s*$') {
        # A Laravel key is `base64:` followed by 32 random bytes. Generating it
        # here rather than inside a container keeps the first run from needing an
        # image that is not built yet.
        Write-Host 'generating APP_KEY' -ForegroundColor Yellow

        $bytes = New-Object 'System.Byte[]' 32
        [System.Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
        $key = 'base64:' + [Convert]::ToBase64String($bytes)

        ($envFile -replace '(?m)^APP_KEY=\s*$', "APP_KEY=$key") |
            Set-Content '.env' -NoNewline -Encoding utf8
    }
}

switch ($Target) {
    'up' {
        Initialize-Environment
        Invoke-Step ($Dev + @('up', '-d', '--build'))
        Invoke-Step ($Dev + @('run', '--rm', 'healthgate'))
        Write-Host "PostBox is up on http://localhost:$((Select-String -Path '.env' -Pattern '^HTTP_PORT=(.*)$').Matches[0].Groups[1].Value)" -ForegroundColor Green
    }

    'down' {
        Invoke-Step ($Dev + @('down', '--remove-orphans'))
    }

    'fresh' {
        Invoke-Step ($Dev + @('down', '--remove-orphans', '--volumes'))
        Invoke-Step ($Dev + @('build', '--no-cache'))
        & $PSCommandPath up
    }

    'logs' {
        Invoke-Step ($Dev + @('logs', '--follow') + $Rest)
    }

    'test' {
        # The frontend has no test suite until the dashboard exists (Step 13).
        Invoke-Step ($Dev + @('run', '--rm', 'backend', 'vendor/bin/pest', '--colors=always'))
    }

    'format' {
        # Pint rewrites files in the bind-mounted source tree, which the image's
        # non-root user cannot write to. Docker Desktop maps the write back to the
        # host user, so root inside the container is the correct user here; the
        # Makefile passes the caller's own uid for the same reason on Linux.
        Invoke-Step ($Dev + @('run', '--rm', '--no-deps', '--user', 'root', 'backend', 'vendor/bin/pint'))
    }

    'stan' {
        Invoke-Step ($Dev + @('run', '--rm', '--no-deps', 'backend', 'vendor/bin/phpstan', 'analyse', '--memory-limit=1G'))
    }

    'check' {
        Invoke-Step ($Dev + @('run', '--rm', '--no-deps', 'backend', 'vendor/bin/pint', '--test'))
        Invoke-Step ($Dev + @('run', '--rm', '--no-deps', 'backend', 'vendor/bin/phpstan', 'analyse', '--memory-limit=1G'))
        Invoke-Step ($Dev + @('run', '--rm', '--no-deps', 'frontend', 'npm', 'run', 'lint'))
        Invoke-Step ($Dev + @('run', '--rm', '--no-deps', 'frontend', 'npm', 'run', 'typecheck'))
        Invoke-Step ($Dev + @('run', '--rm', '--no-deps', 'frontend', 'npm', 'run', 'build'))
    }

    'ci' {
        # Every gate the workflow runs, in one command and in the workflow's order.
        #
        # `check`, `test`, `prod-up` and `prod-down` are invoked rather than
        # repeated, so the gate cannot drift from the targets it is made of. What
        # this adds is the four assertions that until now existed only inside the
        # workflow — target parity, the absent baseline, the production image
        # contents and the scheduler singleton — plus the two lock files, which CI
        # installs from and a working tree never re-reads.
        #
        # The scheduler assertion tears the compose project down. This is a
        # pre-push gate, not something to run beside a live development stack.
        Initialize-Environment
        $bash = Resolve-Bash

        Invoke-Step @($bash, 'docker/scripts/assert-target-parity.sh')

        if (Test-Path 'backend/phpstan-baseline.neon') {
            throw 'FAIL: backend/phpstan-baseline.neon exists — D5 forbids a baseline'
        }
        Write-Host '  ok  no phpstan baseline' -ForegroundColor DarkGray

        Invoke-Step ($Dev + @('run', '--rm', '--no-deps', 'backend', 'composer', 'validate', '--strict'))
        Invoke-Step ($Dev + @('run', '--rm', '--no-deps', 'frontend', 'npm', 'ci', '--dry-run', '--no-audit', '--no-fund'))

        & $PSCommandPath check
        & $PSCommandPath test

        Invoke-Step ($Prod + @('build'))
        Invoke-Step @($bash, 'docker/scripts/assert-production-images.sh')
        Invoke-Step @($bash, 'docker/scripts/assert-bench-absent-from-production.sh')
        Invoke-Step @($bash, 'docker/scripts/assert-scheduler-singleton.sh')

        & $PSCommandPath prod-up
        & $PSCommandPath prod-down

        Write-Host 'ci: every gate the workflow runs passed locally' -ForegroundColor Green
    }

    'prod-up' {
        Initialize-Environment
        Invoke-Step ($Prod + @('up', '-d', '--build'))
        Invoke-Step ($Prod + @('run', '--rm', 'healthgate'))
        Write-Host 'production profile is up and passed the deep health check' -ForegroundColor Green
    }

    'prod-down' {
        Invoke-Step ($Prod + @('down', '--remove-orphans'))
    }

    'bench' {
        Write-Host 'bench: the load harness and the k6 scripts arrive in Step 10.' -ForegroundColor Red
        exit 1
    }

    'help' {
        Write-Host @'
PostBox task runner

  .\task.ps1 up          start the development stack and wait for it to be healthy
  .\task.ps1 down        stop the stack, keeping the volumes
  .\task.ps1 fresh       delete the volumes and rebuild from empty
  .\task.ps1 logs [svc]  follow the logs
  .\task.ps1 test        run the backend test suite
  .\task.ps1 stan        run Larastan at max
  .\task.ps1 format      rewrite the backend to the Pint style
  .\task.ps1 check       Pint, Larastan, ESLint, tsc and next build
  .\task.ps1 ci          every gate the workflow runs — the pre-push check
  .\task.ps1 prod-up     build and start the production profile, then assert health
  .\task.ps1 prod-down   stop the production profile
  .\task.ps1 bench       run the benchmark protocol (Step 10)
'@
    }

    default {
        Write-Host "unknown target: $Target" -ForegroundColor Red
        & $PSCommandPath help
        exit 1
    }
}
