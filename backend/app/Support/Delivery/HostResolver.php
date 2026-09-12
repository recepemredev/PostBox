<?php

declare(strict_types=1);

namespace App\Support\Delivery;

/**
 * DNS resolution behind one method, so AddressGuard can be tested without a
 * real network lookup and so the exact addresses it validates are the ones
 * available to pin the outbound connection to — a second, independent lookup
 * at send time is the DNS-rebinding window this whole arrangement exists to
 * close, which is why AddressGuard calls this exactly once per target and
 * hands the caller the address it already checked rather than the hostname.
 *
 * Not final: it is the one collaborator here a test cannot make deterministic
 * by choosing a literal IP address instead of a hostname, so the rebinding
 * test doubles it the same way CheckSystemHealth's own tests double a
 * concrete framework collaborator — Mockery subclassing it, not a new
 * interface. The two pre-approved boundary interfaces are transport and
 * clock/jitter; this is not a third.
 */
class HostResolver
{
    /**
     * Every address a host resolves to. A literal IP is returned as itself,
     * with nothing to resolve; PostBox otherwise supports A and AAAA records
     * only — an endpoint reached exclusively through some other record type
     * is unresolvable as far as this guard is concerned.
     *
     * @return list<string>
     */
    public function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        // A host malformed enough that there is nothing to ask DNS about makes
        // dns_get_record raise a PHP warning, which Laravel turns into an
        // ErrorException. This method promises a list of addresses, and its one
        // caller is the SSRF guard on the delivery path, where AttemptDelivery
        // catches BlockedTarget and nothing else — an exception escaping here is
        // a delivery attempt that never gets recorded. The failure is already in
        // the return value, so the warning is suppressed and read from there.
        $records = @dns_get_record($host, DNS_A + DNS_AAAA);

        if ($records === false) {
            return [];
        }

        $addresses = [];

        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;

            if (is_string($address)) {
                $addresses[] = $address;
            }
        }

        return array_values(array_unique($addresses));
    }
}
