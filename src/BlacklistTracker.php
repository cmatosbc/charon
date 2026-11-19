<?php

declare(strict_types=1);

namespace Charon;

use InvalidArgumentException;
use Psr\SimpleCache\CacheInterface;

/**
 * Tracks rate limit violations per cache key and blacklists repeat offenders.
 *
 * Shared implementation used by blacklist-aware rate limiters.
 */
final class BlacklistTracker
{
    private const string BLACKLIST_SUFFIX = '_blacklist';
    private const string VIOLATIONS_SUFFIX = '_violations';

    private ?int $afterViolations = null;
    private ?int $ttl = null;

    public function __construct(
        private readonly CacheInterface $store,
        private readonly int $violationWindow,
    ) {
    }

    /**
     * Configure automatic blacklisting after recurring rate limit violations
     *
     * @param int $recurringEvents Number of violations required before blacklisting
     * @param int|null $ttl Blacklist lifetime in seconds, or null to blacklist indefinitely
     */
    public function configure(int $recurringEvents, ?int $ttl): void
    {
        if ($recurringEvents < 1) {
            throw new InvalidArgumentException('Recurring events must be greater than 0.');
        }
        if ($ttl !== null && $ttl < 1) {
            throw new InvalidArgumentException('Blacklist TTL must be greater than 0 or null.');
        }

        $this->afterViolations = $recurringEvents;
        $this->ttl = $ttl;
    }

    public function threshold(): ?int
    {
        return $this->afterViolations;
    }

    /**
     * Check if a cache key is blacklisted
     */
    public function isBlacklisted(string $cacheKey): bool
    {
        if ($this->afterViolations === null) {
            return false;
        }
        return (bool) $this->store->get($cacheKey . self::BLACKLIST_SUFFIX);
    }

    /**
     * Record a rate limit violation and maybe blacklist the client
     *
     * @return array{0: int, 1: bool} Violation count and whether the client was just blacklisted
     */
    public function recordViolation(string $cacheKey): array
    {
        if ($this->afterViolations === null) {
            return [0, false];
        }

        $violationsKey = $cacheKey . self::VIOLATIONS_SUFFIX;
        $stored = $this->store->get($violationsKey);
        $violations = (is_int($stored) ? $stored : 0) + 1;
        $this->store->set($violationsKey, $violations, $this->violationWindow);

        if ($violations >= $this->afterViolations) {
            $this->store->set($cacheKey . self::BLACKLIST_SUFFIX, true, $this->ttl);
            return [$violations, true];
        }

        return [$violations, false];
    }
}
