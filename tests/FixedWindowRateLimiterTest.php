<?php

declare(strict_types=1);

namespace Charon\Tests;

use Charon\FixedWindowRateLimiter;
use Charon\RateLimitStatus;
use Charon\Tests\Mock\ArrayCache;
use Charon\Tests\Mock\AtomicArrayCache;
use Charon\Tests\Mock\MockClock;
use InvalidArgumentException;

final class FixedWindowRateLimiterTest extends TestCase
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

    public function testConsumeAllowsRequestsWithinLimit(): void
    {
        $limiter = new FixedWindowRateLimiter(
            limit: 2,
            windowPeriod: 3600,
            store: $this->cache,
            clock: $this->clock,
        );

        $first = $limiter->consume('client-a');
        $this->assertSame(RateLimitStatus::Allowed, $first->status);
        $this->assertTrue($first->isAllowed());
        $this->assertSame(1, $first->requests);
        $this->assertSame(1, $first->remaining);

        $second = $limiter->consume('client-a');
        $this->assertSame(RateLimitStatus::Allowed, $second->status);
        $this->assertSame(2, $second->requests);
        $this->assertSame(0, $second->remaining);
    }

    public function testConsumeLimitsRequestsOverThreshold(): void
    {
        $limiter = new FixedWindowRateLimiter(
            limit: 1,
            windowPeriod: 3600,
            store: $this->cache,
            clock: $this->clock,
        );

        $limiter->consume('client-a');
        $result = $limiter->consume('client-a');

        $this->assertSame(RateLimitStatus::Limited, $result->status);
        $this->assertFalse($result->isAllowed());
        $this->assertSame(0, $result->remaining);
        $this->assertGreaterThan(0, $result->retryAfter);
        $this->assertLessThanOrEqual(3600, $result->retryAfter);
    }

    public function testCounterResetsAfterWindow(): void
    {
        $limiter = new FixedWindowRateLimiter(
            limit: 1,
            windowPeriod: 10,
            store: $this->cache,
            clock: $this->clock,
        );

        $limiter->consume('client-a');
        $this->assertSame(RateLimitStatus::Limited, $limiter->consume('client-a')->status);

        $this->clock->advance(11);

        $this->assertSame(RateLimitStatus::Allowed, $limiter->consume('client-a')->status);
    }

    public function testClientsAreTrackedIndependently(): void
    {
        $limiter = new FixedWindowRateLimiter(
            limit: 1,
            windowPeriod: 3600,
            store: $this->cache,
            clock: $this->clock,
        );

        $limiter->consume('client-a');
        $this->assertSame(RateLimitStatus::Limited, $limiter->consume('client-a')->status);
        $this->assertSame(RateLimitStatus::Allowed, $limiter->consume('client-b')->status);
    }

    public function testBlacklistsAfterViolations(): void
    {
        $limiter = new FixedWindowRateLimiter(
            limit: 1,
            windowPeriod: 10,
            store: $this->cache,
            clock: $this->clock,
        );
        $limiter->maybeBlacklist(2);

        // First violation
        $limiter->consume('client-a');
        $result = $limiter->consume('client-a');
        $this->assertSame(RateLimitStatus::Limited, $result->status);
        $this->assertSame(1, $result->violations);
        $this->assertFalse($result->justBlacklisted);

        $this->clock->advance(11);

        // Second violation triggers blacklisting
        $limiter->consume('client-a');
        $result = $limiter->consume('client-a');
        $this->assertSame(RateLimitStatus::Limited, $result->status);
        $this->assertSame(2, $result->violations);
        $this->assertTrue($result->justBlacklisted);
        $this->assertSame(2, $result->blacklistThreshold);

        $this->clock->advance(11);

        // Subsequent requests are blacklisted
        $result = $limiter->consume('client-a');
        $this->assertSame(RateLimitStatus::Blacklisted, $result->status);
        $this->assertFalse($result->justBlacklisted);
    }

    public function testBlacklistExpiresAfterTtl(): void
    {
        $limiter = new FixedWindowRateLimiter(
            limit: 1,
            windowPeriod: 60,
            store: $this->cache,
            clock: $this->clock,
        );
        $limiter->maybeBlacklist(1, ttl: 120);

        $limiter->consume('client-a');
        $result = $limiter->consume('client-a');
        $this->assertTrue($result->justBlacklisted);

        $this->clock->advance(61);
        $this->assertSame(RateLimitStatus::Blacklisted, $limiter->consume('client-a')->status);

        $this->clock->advance(120);
        $this->assertSame(RateLimitStatus::Allowed, $limiter->consume('client-a')->status);
    }

    public function testConsumeWithAtomicStore(): void
    {
        $store = new AtomicArrayCache($this->clock);
        $limiter = new FixedWindowRateLimiter(
            limit: 1,
            windowPeriod: 3600,
            store: $store,
            clock: $this->clock,
        );

        $this->assertSame(RateLimitStatus::Allowed, $limiter->consume('client-a')->status);
        $this->assertSame(RateLimitStatus::Limited, $limiter->consume('client-a')->status);
        $this->assertSame(RateLimitStatus::Allowed, $limiter->consume('client-b')->status);
    }

    public function testAtomicStoreAndBlacklist(): void
    {
        $store = new AtomicArrayCache($this->clock);
        $limiter = new FixedWindowRateLimiter(
            limit: 1,
            windowPeriod: 60,
            store: $store,
            clock: $this->clock,
        );
        $limiter->maybeBlacklist(1);

        $limiter->consume('client-a');
        $this->assertSame(RateLimitStatus::Limited, $limiter->consume('client-a')->status);

        $this->clock->advance(61);
        $this->assertSame(RateLimitStatus::Blacklisted, $limiter->consume('client-a')->status);
    }

    public function testInvalidCacheDataStartsFreshWindow(): void
    {
        $limiter = new FixedWindowRateLimiter(
            limit: 1,
            windowPeriod: 3600,
            store: $this->cache,
            clock: $this->clock,
        );

        $this->cache->set('charon_client-a', 'corrupted-string');
        $this->assertSame(RateLimitStatus::Allowed, $limiter->consume('client-a')->status);

        $this->cache->set('charon_client-a', ['requests' => 'not-an-int']);
        $this->assertSame(RateLimitStatus::Allowed, $limiter->consume('client-a')->status);
    }

    public function testInvalidLimitThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new FixedWindowRateLimiter(limit: 0, windowPeriod: 60, store: $this->cache);
    }

    public function testInvalidWindowPeriodThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new FixedWindowRateLimiter(limit: 1, windowPeriod: 0, store: $this->cache);
    }

    public function testInvalidCacheKeyPrefixThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new FixedWindowRateLimiter(
            limit: 1,
            windowPeriod: 60,
            store: $this->cache,
            cacheKeyPrefix: 'bad@prefix'
        );
    }

    public function testInvalidBlacklistThresholdThrows(): void
    {
        $limiter = new FixedWindowRateLimiter(limit: 1, windowPeriod: 60, store: $this->cache);

        $this->expectException(InvalidArgumentException::class);
        $limiter->maybeBlacklist(0);
    }
}
