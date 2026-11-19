<?php

declare(strict_types=1);

namespace Charon;

use Psr\SimpleCache\CacheInterface;

/**
 * A PSR-16 cache that can evaluate a fixed-window counter atomically.
 *
 * Implement this over backends supporting server-side scripts or atomic
 * operations (e.g. a Redis Lua script, Memcached CAS) to eliminate the
 * read-modify-write race present in the plain PSR-16 path.
 */
interface CounterStore extends CacheInterface
{
    /**
     * Atomically evaluates and updates the counter for a fixed window.
     *
     * Resets the window when $now - window_start exceeds $windowPeriod and
     * increments the request count by $cost only while the result stays at
     * or below $limit.
     *
     * @return array{requests: int, window_start: int, allowed: bool} The post-evaluation window state
     */
    public function evaluate(string $key, int $limit, int $windowPeriod, int $now, int $cost = 1): array;
}
