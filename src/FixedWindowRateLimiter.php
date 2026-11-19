<?php

declare(strict_types=1);

namespace Charon;

use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Fixed window rate limiter backed by a PSR-16 cache.
 *
 * Tracks a request count per client key within a time window. When the
 * store implements CounterStore, window evaluation is delegated to the
 * backend so it can be performed atomically; the plain PSR-16 path uses a
 * non-atomic read-modify-write sequence.
 */
final class FixedWindowRateLimiter implements BlacklistAwareRateLimiter
{
    private const string RESERVED_KEY_CHARS = '{}()/\@:';

    private readonly BlacklistTracker $blacklist;

    /**
     * @param int $limit Maximum request cost allowed within the time window
     * @param int $windowPeriod Time window in seconds
     * @param CacheInterface $store PSR-16 cache, optionally a CounterStore for atomic evaluation
     * @param ClockInterface $clock Time source, defaults to system time
     * @param string $cacheKeyPrefix Cache key prefix, must not contain the characters {}()/\@:
     */
    public function __construct(
        private readonly int $limit,
        private readonly int $windowPeriod,
        private readonly CacheInterface $store,
        private readonly ClockInterface $clock = new SystemClock(),
        private readonly string $cacheKeyPrefix = 'charon',
    ) {
        if ($limit < 1) {
            throw new InvalidArgumentException('Limit must be greater than 0.');
        }
        if ($windowPeriod < 1) {
            throw new InvalidArgumentException('Window period must be greater than 0.');
        }
        if ($cacheKeyPrefix === '' || strpbrk($cacheKeyPrefix, self::RESERVED_KEY_CHARS) !== false) {
            throw new InvalidArgumentException('Cache key prefix must not be empty or contain reserved characters {}()/\@:');
        }

        $this->blacklist = new BlacklistTracker($store, $windowPeriod * 2);
    }

    #[\Override]
    public function maybeBlacklist(int $recurringEvents, ?int $ttl = null): self
    {
        $this->blacklist->configure($recurringEvents, $ttl);
        return $this;
    }

    #[\Override]
    public function consume(string $key, int $cost = 1): RateLimitResult
    {
        if ($cost < 1) {
            throw new InvalidArgumentException('Cost must be greater than 0.');
        }

        $now = $this->clock->now()->getTimestamp();
        $cacheKey = $this->cacheKeyPrefix . '_' . $key;

        if ($this->blacklist->isBlacklisted($cacheKey)) {
            return new RateLimitResult(
                status: RateLimitStatus::Blacklisted,
                limit: $this->limit,
                requests: 0,
                remaining: 0,
                resetTime: 0,
                retryAfter: 0,
                blacklistThreshold: $this->blacklist->threshold(),
            );
        }

        $state = $this->evaluate($cacheKey, $now, $cost);
        $resetTime = $state['window_start'] + $this->windowPeriod;
        $retryAfter = max(0, $resetTime - $now);

        if (!$state['allowed']) {
            [$violations, $justBlacklisted] = $this->blacklist->recordViolation($cacheKey);

            return new RateLimitResult(
                status: RateLimitStatus::Limited,
                limit: $this->limit,
                requests: $state['requests'],
                remaining: 0,
                resetTime: $resetTime,
                retryAfter: $retryAfter,
                violations: $violations,
                blacklistThreshold: $this->blacklist->threshold(),
                justBlacklisted: $justBlacklisted,
            );
        }

        return new RateLimitResult(
            status: RateLimitStatus::Allowed,
            limit: $this->limit,
            requests: $state['requests'],
            remaining: max(0, $this->limit - $state['requests']),
            resetTime: $resetTime,
            retryAfter: $retryAfter,
        );
    }

    /**
     * Evaluates the fixed window counter for a cache key
     *
     * @return array{requests: int, window_start: int, allowed: bool}
     */
    private function evaluate(string $cacheKey, int $now, int $cost): array
    {
        if ($this->store instanceof CounterStore) {
            return $this->store->evaluate($cacheKey, $this->limit, $this->windowPeriod, $now, $cost);
        }

        $data = $this->loadThrottleData($this->store->get($cacheKey), $now);

        if (($now - $data['window_start']) > $this->windowPeriod) {
            $data = ['requests' => 0, 'window_start' => $now];
        }

        $allowed = ($data['requests'] + $cost) <= $this->limit;
        if ($allowed) {
            $data['requests'] += $cost;
            $this->store->set($cacheKey, $data, $this->windowPeriod);
        }

        return $data + ['allowed' => $allowed];
    }

    /**
     * Reads stored throttle data, starting a fresh window on missing or invalid data
     *
     * @return array{requests: int, window_start: int}
     */
    private function loadThrottleData(mixed $cachedData, int $now): array
    {
        if (
            !is_array($cachedData)
            || !is_int($cachedData['requests'] ?? null)
            || !is_int($cachedData['window_start'] ?? null)
        ) {
            return ['requests' => 0, 'window_start' => $now];
        }

        return $cachedData;
    }
}
