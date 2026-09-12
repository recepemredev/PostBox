<?php

declare(strict_types=1);

namespace App\Support\Delivery;

use App\Exceptions\BlockedTarget;

/**
 * Refuses a delivery target before a single byte leaves the process.
 *
 * CLAUDE.md names private, loopback and link-local ranges explicitly; this
 * list is a little wider — carrier-grade NAT and multicast are neither
 * private nor loopback nor link-local, but no webhook endpoint legitimately
 * lives in either, and each is a real SSRF vector left open otherwise. The
 * ranges are declared as plain CIDR data, in one place, so an auditor reads
 * the whole policy without following a chain of method calls.
 *
 * This runs on every send, not only at registration (an endpoint's DNS can
 * change after it is created), and it resolves the host exactly once: the
 * GuardedTarget it returns carries the address it checked, and the transport
 * pins its connection to that address rather than resolving the host again —
 * a second, independent lookup is the DNS-rebinding window this exists to
 * close.
 */
final readonly class AddressGuard
{
    /**
     * @var list<string>
     */
    private const array DisallowedRanges = [
        // IPv4
        '0.0.0.0/8',       // "this network"
        '10.0.0.0/8',      // private (RFC 1918)
        '100.64.0.0/10',   // carrier-grade NAT (RFC 6598)
        '127.0.0.0/8',     // loopback
        '169.254.0.0/16',  // link-local — includes cloud metadata endpoints
        '172.16.0.0/12',   // private (RFC 1918)
        '192.168.0.0/16',  // private (RFC 1918)
        '224.0.0.0/4',     // multicast
        '240.0.0.0/4',     // reserved — includes the broadcast address

        // IPv6
        '::1/128',         // loopback
        '::/128',          // unspecified
        '::ffff:0:0/96',   // IPv4-mapped — refused outright, never unwrapped and re-checked
        'fc00::/7',        // unique local — IPv6's private range
        'fe80::/10',       // link-local
        'ff00::/8',        // multicast
    ];

    public function __construct(private HostResolver $resolver) {}

    /**
     * @throws BlockedTarget
     */
    public function guard(string $url): GuardedTarget
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['host']) || ! in_array($parts['scheme'] ?? null, ['http', 'https'], true)) {
            throw BlockedTarget::invalidUrl($url);
        }

        $host = self::unbracket($parts['host']);
        $port = $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80);

        $addresses = $this->resolver->resolve($host);

        if ($addresses === []) {
            throw BlockedTarget::unresolvable($host);
        }

        foreach ($addresses as $address) {
            if (self::isDisallowed($address)) {
                throw BlockedTarget::disallowedRange($host, $address);
            }
        }

        return new GuardedTarget($host, $port, $addresses[0]);
    }

    /**
     * An IPv6 literal is written in a URL inside square brackets, and parse_url
     * hands them back as part of the host: `http://[::1]/` parses to `[::1]`,
     * which is no longer an address. filter_var refuses it, so the resolver used
     * to send it to DNS instead of returning it as a literal — every IPv6 range
     * above was unreachable through this path, and what refused `[::1]` was the
     * unresolvable branch rather than the range check. Unbracketed is also the
     * form curl matches CURLOPT_RESOLVE against.
     */
    private static function unbracket(string $host): string
    {
        return str_starts_with($host, '[') && str_ends_with($host, ']')
            ? substr($host, 1, -1)
            : $host;
    }

    private static function isDisallowed(string $address): bool
    {
        foreach (self::DisallowedRanges as $range) {
            if (self::inRange($address, $range)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether $address falls within $cidr. Both are converted through
     * inet_pton to their raw bytes, so the same comparison handles IPv4 (4
     * bytes) and IPv6 (16 bytes) alike — a length mismatch between the two
     * simply means the families differ, which is never a match.
     */
    private static function inRange(string $address, string $cidr): bool
    {
        [$subnet, $prefixLength] = explode('/', $cidr);

        $addressBytes = inet_pton($address);
        $subnetBytes = inet_pton($subnet);

        if ($addressBytes === false || $subnetBytes === false || strlen($addressBytes) !== strlen($subnetBytes)) {
            return false;
        }

        $prefixLength = (int) $prefixLength;
        $fullBytes = intdiv($prefixLength, 8);
        $remainingBits = $prefixLength % 8;

        if ($fullBytes > 0 && substr($addressBytes, 0, $fullBytes) !== substr($subnetBytes, 0, $fullBytes)) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = (~0 << (8 - $remainingBits)) & 0xFF;

        return (ord($addressBytes[$fullBytes]) & $mask) === (ord($subnetBytes[$fullBytes]) & $mask);
    }
}
