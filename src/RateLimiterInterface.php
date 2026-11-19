<?php

declare(strict_types=1);

namespace Charon;

/**
 * A rate limiter evaluating request counts per client key.
 */
interface RateLimiterInterface
{
    /**
     * Evaluates a single request for the given client key.
     *
     * @param string $key Unique client identifier (e.g. a hashed signature)
     * @param int $cost Quota consumed by this request; must be greater than 0
     */
    public function consume(string $key, int $cost = 1): RateLimitResult;
}
