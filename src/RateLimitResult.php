<?php

declare(strict_types=1);

namespace Charon;

/**
 * The result of a single consume() evaluation.
 */
final readonly class RateLimitResult
{
    /**
     * @param RateLimitStatus $status Evaluation outcome
     * @param int $limit Configured request limit
     * @param int $requests Requests counted in the current window
     * @param int $remaining Requests still allowed in the current window
     * @param int $resetTime Unix timestamp at which the current window resets
     * @param int $retryAfter Seconds until the window resets
     * @param int $violations Rate limit violations recorded for this client
     * @param int|null $blacklistThreshold Violation threshold that triggers blacklisting
     * @param bool $justBlacklisted Whether this evaluation caused the client to be blacklisted
     */
    public function __construct(
        public RateLimitStatus $status,
        public int $limit,
        public int $requests,
        public int $remaining,
        public int $resetTime,
        public int $retryAfter,
        public int $violations = 0,
        public ?int $blacklistThreshold = null,
        public bool $justBlacklisted = false,
    ) {
    }

    public function isAllowed(): bool
    {
        return $this->status === RateLimitStatus::Allowed;
    }
}
