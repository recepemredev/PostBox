<?php

declare(strict_types=1);

use App\Exceptions\BlockedTarget;
use App\Support\Delivery\AddressGuard;
use App\Support\Delivery\HostResolver;

/*
 * Most of this runs against literal IP addresses, which never touch DNS —
 * HostResolver returns a literal straight back, so the guard's own range
 * logic is what is under test, deterministically. The rebinding-oriented
 * tests at the bottom double HostResolver instead, to prove the one property
 * a literal address cannot: that the guard resolves a hostname exactly once
 * and hands the caller the address it already checked, rather than a
 * hostname a transport could resolve differently a moment later.
 */

beforeEach(function (): void {
    $this->guard = new AddressGuard(new HostResolver);
});

/*
 * The message is asserted, not only the class. Both the range check and the
 * unresolvable branch throw BlockedTarget, so a class-only assertion passed
 * for every IPv6 literal here while the range check was never reached at all:
 * parse_url leaves an IPv6 host bracketed, and `[::1]` went to DNS instead of
 * being recognised as an address. Pinning the reason is what makes this
 * dataset an assertion about the ranges rather than about refusal in general.
 */
it('refuses a disallowed address', function (string $url): void {
    expect(fn () => $this->guard->guard($url))
        ->toThrow(BlockedTarget::class, 'resolves to a disallowed range');
})->with([
    'loopback (IPv4)' => 'http://127.0.0.1/webhook',
    'loopback, non-default port' => 'http://127.0.0.1:8080/webhook',
    'loopback (IPv6)' => 'http://[::1]/webhook',
    'private, class A (RFC 1918)' => 'http://10.1.2.3/webhook',
    'private, class B (RFC 1918)' => 'http://172.16.5.9/webhook',
    'private, class C (RFC 1918)' => 'http://192.168.1.1/webhook',
    'link-local — cloud metadata endpoint' => 'http://169.254.169.254/latest/meta-data',
    'carrier-grade NAT (RFC 6598)' => 'http://100.64.0.1/webhook',
    'multicast (IPv4)' => 'http://224.0.0.1/webhook',
    'broadcast' => 'http://255.255.255.255/webhook',
    'unspecified (IPv6)' => 'http://[::]/webhook',
    'unique local (IPv6 private)' => 'http://[fc00::1]/webhook',
    'link-local (IPv6)' => 'http://[fe80::1]/webhook',
    'multicast (IPv6)' => 'http://[ff02::1]/webhook',
    'IPv4-mapped IPv6 — refused outright, not unwrapped' => 'http://[::ffff:8.8.8.8]/webhook',
]);

it('allows a public address', function (): void {
    $target = $this->guard->guard('https://8.8.8.8:9443/webhook');

    expect($target->host)->toBe('8.8.8.8')
        ->and($target->address)->toBe('8.8.8.8')
        ->and($target->port)->toBe(9443);
});

/*
 * The counterpart to the bracket fix: nothing proved a public IPv6 endpoint
 * was reachable at all, so the guard could have refused every one of them and
 * the suite would have stayed green. The host is pinned unbracketed because
 * that is the form curl matches CURLOPT_RESOLVE against.
 */
it('allows a public IPv6 address and pins it unbracketed', function (): void {
    $target = $this->guard->guard('http://[2001:4860:4860::8888]:9443/webhook');

    expect($target->host)->toBe('2001:4860:4860::8888')
        ->and($target->address)->toBe('2001:4860:4860::8888')
        ->and($target->port)->toBe(9443);
});

it('defaults the port from the scheme when none is given', function (string $url, int $port): void {
    expect($this->guard->guard($url)->port)->toBe($port);
})->with([
    ['http://8.8.8.8/webhook', 80],
    ['https://8.8.8.8/webhook', 443],
]);

it('refuses a scheme that is neither http nor https', function (string $url): void {
    expect(fn () => $this->guard->guard($url))->toThrow(BlockedTarget::class);
})->with([
    'file://etc/passwd',
    'ftp://8.8.8.8/webhook',
    'gopher://8.8.8.8/webhook',
]);

it('refuses a URL with no host', function (): void {
    expect(fn () => $this->guard->guard('http:///webhook'))->toThrow(BlockedTarget::class);
});

it('refuses a hostname it cannot resolve', function (): void {
    $resolver = Mockery::mock(HostResolver::class);
    $resolver->shouldReceive('resolve')->once()->with('unresolvable.example')->andReturn([]);

    $guard = new AddressGuard($resolver);

    expect(fn () => $guard->guard('http://unresolvable.example/webhook'))->toThrow(BlockedTarget::class);
});

it('resolves a hostname exactly once and pins the address it checked', function (): void {
    $resolver = Mockery::mock(HostResolver::class);
    $resolver->shouldReceive('resolve')->once()->with('safe.example')->andReturn(['8.8.8.8']);

    $guard = new AddressGuard($resolver);
    $target = $guard->guard('http://safe.example/webhook');

    // Mockery's once() is part of the assertion: nothing about this call
    // path resolves the host a second time, so nothing downstream can be
    // handed an address the guard never saw.
    expect($target->host)->toBe('safe.example')
        ->and($target->address)->toBe('8.8.8.8');
});

it('refuses a hostname when any address it resolves to is disallowed', function (): void {
    $resolver = Mockery::mock(HostResolver::class);
    $resolver->shouldReceive('resolve')->once()->with('rebinding.example')->andReturn(['8.8.8.8', '169.254.169.254']);

    $guard = new AddressGuard($resolver);

    expect(fn () => $guard->guard('http://rebinding.example/webhook'))->toThrow(BlockedTarget::class);
});
