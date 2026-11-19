<?php

declare(strict_types=1);

namespace Charon;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/**
 * Default clock implementation returning the current system time.
 */
final class SystemClock implements ClockInterface
{
    #[\Override]
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
}
