<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How one delivery attempt concluded. A timeout, a DNS failure and a TLS
 * failure are each their own value rather than one generic "connection
 * failed" — CLAUDE.md names all three as failure classes that must never go
 * unrecorded, and the dashboard's attempt inspector (Step 14) needs to say
 * which one happened, not just that something did.
 *
 * Blocked is not a network fact — it is the SSRF guard refusing a target
 * before a single byte left the process, and it needs its own value for
 * exactly that reason: folding it into ConnectionError would tell an operator
 * their own endpoint's network is at fault for a request PostBox itself
 * chose not to send.
 */
enum AttemptOutcome: string
{
    case Succeeded = 'succeeded';

    case Failed = 'failed';

    case Timeout = 'timeout';

    case DnsError = 'dns_error';

    case TlsError = 'tls_error';

    case ConnectionError = 'connection_error';

    case Blocked = 'blocked';
}
