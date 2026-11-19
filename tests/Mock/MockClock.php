<?php

declare(strict_types=1);

namespace Charon\Tests\Mock;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

final class MockClock implements ClockInterface
{
    private DateTimeImmutable $now;

    public function __construct()
    {
        $this->now = new DateTimeImmutable();
    }

    #[\Override]
    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(int $seconds): void
    {
        $this->now = $this->now->modify("+{$seconds} seconds");
    }
}
