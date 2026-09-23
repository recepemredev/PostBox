<?php

declare(strict_types=1);

namespace Tests\Support;

use Psr\Http\Client\ClientExceptionInterface;
use RuntimeException;

/**
 * Stands in for whatever a real PSR-18 client throws on a DNS failure, a
 * refused connection or a timeout — PostBox::publish() only depends on the
 * interface, never on a concrete client's own exception classes.
 */
final class FakeTransportException extends RuntimeException implements ClientExceptionInterface {}
