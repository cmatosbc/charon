<?php

declare(strict_types=1);

namespace Charon\Tests;

use Charon\RateLimitStatus;
use Charon\Tests\Mock\ArrayCache;
use Charon\Tests\Mock\MockClock;
use Charon\TokenBucketRateLimiter;
use InvalidArgumentException;

final class TokenBucketRateLimiterTest extends TestCase
{
    private ArrayCache $cache;
    private MockClock $clock;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock();
        $this->cache = new ArrayCache($this->clock);
    }

    private function createLimiter(
        int $capacity = 3,
        float $refillRate = 1.0,
    ): TokenBucketRateLimiter {
        return new TokenBucketRateLimiter(
            capacity: $capacity,
            refillRate: $refillRate,
            store: $this->cache,
            clock: $this->clock,
        );
    }

    public function testAllowsBurstUpToCapacity(): void
    {
        $limiter = $this->createLimiter(capacity: 3, refillRate: 1.0);

        $this->assertSame(RateLimitStatus::Allowed, $limiter->consume('client-a')->status);
        $this->assertSame(RateLimitStatus::Allowed, $limiter->consume('client-a')->status);
        $this->assertSame(RateLimitStatus::Allowed, $limiter->consume('client-a')->status);

        $result = $limiter->consume('client-a');
        $this->assertSame(RateLimitStatus::Limited, $result->status);
        $this->assertSame(0, $result->remaining);
    }

    public function testTokensRefillOverTime(): void
    {
        $limiter = $this->createLimiter(capacity: 2, refillRate: 1.0);

        $limiter->consume('client-a');
        $limiter->consume('client-a');
        $this->assertSame(RateLimitStatus::Limited, $limiter->consume('client-a')->status);

        // One token refills per second
        $this->clock->advance(1);
        $this->assertSame(RateLimitStatus::Allowed, $limiter->consume('client-a')->status);
        $this->assertSame(RateLimitStatus::Limited, $limiter->consume('client-a')->status);

        // Bucket refills fully but does not exceed capacity
        $this->clock->advance(10);
        $result = $limiter->consume('client-a');
        $this->assertSame(RateLimitStatus::Allowed, $result->status);
        $this->assertSame(1, $result->remaining);
    }

    public function testRetryAfterOnLimited(): void
    {
        $limiter = $this->createLimiter(capacity: 1, refillRate: 0.5);

        $limiter->consume('client-a');
        $result = $limiter->consume('client-a');

        $this->assertSame(RateLimitStatus::Limited, $result->status);
        $this->assertSame(2, $result->retryAfter); // 1 token at 0.5 tokens/s
    }

    public function testCostParameter(): void
    {
        $limiter = $this->createLimiter(capacity: 5, refillRate: 1.0);

        $result = $limiter->consume('client-a', cost: 3);
        $this->assertSame(RateLimitStatus::Allowed, $result->status);
        $this->assertSame(2, $result->remaining);

        $result = $limiter->consume('client-a', cost: 3);
        $this->assertSame(RateLimitStatus::Limited, $result->status);
    }

    public function testCostAboveCapacityIsAlwaysLimited(): void
    {
        $limiter = $this->createLimiter(capacity: 5, refillRate: 1.0);

        $result = $limiter->consume('client-a', cost: 6);
        $this->assertSame(RateLimitStatus::Limited, $result->status);
    }

    public function testClientsAreTrackedIndependently(): void
    {
        $limiter = $this->createLimiter(capacity: 1);

        $limiter->consume('client-a');
        $this->assertSame(RateLimitStatus::Limited, $limiter->consume('client-a')->status);
        $this->assertSame(RateLimitStatus::Allowed, $limiter->consume('client-b')->status);
    }

    public function testBlacklistsAfterViolations(): void
    {
        $limiter = $this->createLimiter(capacity: 1, refillRate: 0.01);
        $limiter->maybeBlacklist(1);

        $limiter->consume('client-a');
        $result = $limiter->consume('client-a');
        $this->assertSame(RateLimitStatus::Limited, $result->status);
        $this->assertTrue($result->justBlacklisted);

        $result = $limiter->consume('client-a');
        $this->assertSame(RateLimitStatus::Blacklisted, $result->status);
    }

    public function testInvalidBucketDataStartsFull(): void
    {
        $limiter = $this->createLimiter(capacity: 1);

        $this->cache->set('charon_client-a', 'corrupted');
        $this->assertSame(RateLimitStatus::Allowed, $limiter->consume('client-a')->status);

        $this->cache->set('charon_client-a', ['tokens' => 'nope']);
        $this->assertSame(RateLimitStatus::Allowed, $limiter->consume('client-a')->status);
        $this->assertSame(RateLimitStatus::Limited, $limiter->consume('client-a')->status);
    }

    public function testInvalidCapacityThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new TokenBucketRateLimiter(capacity: 0, refillRate: 1.0, store: $this->cache);
    }

    public function testInvalidRefillRateThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new TokenBucketRateLimiter(capacity: 5, refillRate: 0.0, store: $this->cache);
    }

    public function testInvalidCostThrows(): void
    {
        $limiter = $this->createLimiter();

        $this->expectException(InvalidArgumentException::class);
        $limiter->consume('client-a', cost: 0);
    }
}
