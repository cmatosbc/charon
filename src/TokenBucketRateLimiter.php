<?php

declare(strict_types=1);

namespace Charon;

use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Token bucket rate limiter backed by a PSR-16 cache.
 *
 * Each client key owns a bucket holding up to $capacity tokens. Tokens
 * refill continuously at $refillRate tokens per second and each request
 * consumes $cost tokens. This allows short bursts up to the bucket
 * capacity while enforcing the refill rate as the sustained average.
 *
 * Stored values are {'tokens': float, 'updated': int}.
 */
final class TokenBucketRateLimiter implements BlacklistAwareRateLimiter
{
    private const string RESERVED_KEY_CHARS = '{}()/\@:';

    private readonly BlacklistTracker $blacklist;

    /**
     * @param int $capacity Bucket size; also the maximum burst cost a request may consume
     * @param float $refillRate Tokens added per second
     * @param CacheInterface $store PSR-16 cache implementation
     * @param ClockInterface $clock Time source, defaults to system time
     * @param string $cacheKeyPrefix Cache key prefix, must not contain the characters {}()/\@:
     */
    public function __construct(
        private readonly int $capacity,
        private readonly float $refillRate,
        private readonly CacheInterface $store,
        private readonly ClockInterface $clock = new SystemClock(),
        private readonly string $cacheKeyPrefix = 'charon',
    ) {
        if ($capacity < 1) {
            throw new InvalidArgumentException('Capacity must be greater than 0.');
        }
        if ($refillRate <= 0.0) {
            throw new InvalidArgumentException('Refill rate must be greater than 0.');
        }
        if ($cacheKeyPrefix === '' || strpbrk($cacheKeyPrefix, self::RESERVED_KEY_CHARS) !== false) {
            throw new InvalidArgumentException('Cache key prefix must not be empty or contain reserved characters {}()/\@:');
        }

        $this->blacklist = new BlacklistTracker($store, $this->bucketTtl() * 2);
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
                limit: $this->capacity,
                requests: 0,
                remaining: 0,
                resetTime: 0,
                retryAfter: 0,
                blacklistThreshold: $this->blacklist->threshold(),
            );
        }

        $bucket = $this->loadBucket($this->store->get($cacheKey), $now);

        $elapsed = max(0, $now - $bucket['updated']);
        $tokens = min((float) $this->capacity, $bucket['tokens'] + $elapsed * $this->refillRate);

        $allowed = $tokens >= $cost;
        if ($allowed) {
            $tokens -= $cost;
            $this->store->set(
                $cacheKey,
                ['tokens' => $tokens, 'updated' => $now],
                $this->bucketTtl()
            );
        }

        $remaining = (int) floor($tokens);
        $requests = $this->capacity - $remaining;

        if (!$allowed) {
            $retryAfter = (int) ceil(($cost - $tokens) / $this->refillRate);
            [$violations, $justBlacklisted] = $this->blacklist->recordViolation($cacheKey);

            return new RateLimitResult(
                status: RateLimitStatus::Limited,
                limit: $this->capacity,
                requests: $requests,
                remaining: 0,
                resetTime: $now + $retryAfter,
                retryAfter: $retryAfter,
                violations: $violations,
                blacklistThreshold: $this->blacklist->threshold(),
                justBlacklisted: $justBlacklisted,
            );
        }

        $secondsToFull = (int) ceil(($this->capacity - $tokens) / $this->refillRate);

        return new RateLimitResult(
            status: RateLimitStatus::Allowed,
            limit: $this->capacity,
            requests: $requests,
            remaining: $remaining,
            resetTime: $now + $secondsToFull,
            retryAfter: $secondsToFull,
        );
    }

    /**
     * Time in seconds for an empty bucket to fully refill; also the storage TTL
     */
    private function bucketTtl(): int
    {
        return max(1, (int) ceil($this->capacity / $this->refillRate));
    }

    /**
     * Reads the stored bucket, starting a full bucket on missing or invalid data
     *
     * @return array{tokens: float, updated: int}
     */
    private function loadBucket(mixed $cachedData, int $now): array
    {
        if (
            !is_array($cachedData)
            || !is_numeric($cachedData['tokens'] ?? null)
            || !is_int($cachedData['updated'] ?? null)
        ) {
            return ['tokens' => (float) $this->capacity, 'updated' => $now];
        }

        return ['tokens' => (float) $cachedData['tokens'], 'updated' => $cachedData['updated']];
    }
}
