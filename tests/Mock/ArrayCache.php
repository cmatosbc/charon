<?php

declare(strict_types=1);

namespace Charon\Tests\Mock;

use Charon\SystemClock;
use Psr\Clock\ClockInterface;
use Psr\SimpleCache\CacheInterface;

class ArrayCache implements CacheInterface
{
    private array $cache = [];
    private array $ttls = [];
    private readonly ClockInterface $clock;

    public function __construct(?ClockInterface $clock = null)
    {
        $this->clock = $clock ?? new SystemClock();
    }

    #[\Override]
    public function get($key, $default = null): mixed
    {
        if (!$this->has($key)) {
            return $default;
        }

        return $this->cache[$key];
    }

    #[\Override]
    public function set($key, $value, $ttl = null): bool
    {
        $this->cache[$key] = $value;
        if ($ttl !== null) {
            $this->ttls[$key] = $this->clock->now()->getTimestamp() + $ttl;
        }
        return true;
    }

    #[\Override]
    public function delete($key): bool
    {
        unset($this->cache[$key], $this->ttls[$key]);
        return true;
    }

    #[\Override]
    public function clear(): bool
    {
        $this->cache = [];
        $this->ttls = [];
        return true;
    }

    #[\Override]
    public function getMultiple($keys, $default = null): iterable
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }
        return $result;
    }

    #[\Override]
    public function setMultiple($values, $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value, $ttl);
        }
        return true;
    }

    #[\Override]
    public function deleteMultiple($keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }
        return true;
    }

    #[\Override]
    public function has($key): bool
    {
        if (!isset($this->cache[$key])) {
            return false;
        }

        if (isset($this->ttls[$key]) && $this->clock->now()->getTimestamp() > $this->ttls[$key]) {
            $this->delete($key);
            return false;
        }

        return true;
    }
}
