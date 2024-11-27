<?php

namespace Charon\Tests\Mock;

use Psr\SimpleCache\CacheInterface;

class ArrayCache implements CacheInterface
{
    private array $cache = [];
    private array $ttls = [];

    public function get($key, $default = null): mixed
    {
        if (!$this->has($key)) {
            return $default;
        }

        return $this->cache[$key];
    }

    public function set($key, $value, $ttl = null): bool
    {
        $this->cache[$key] = $value;
        if ($ttl !== null) {
            $this->ttls[$key] = time() + $ttl;
        }
        return true;
    }

    public function delete($key): bool
    {
        unset($this->cache[$key], $this->ttls[$key]);
        return true;
    }

    public function clear(): bool
    {
        $this->cache = [];
        $this->ttls = [];
        return true;
    }

    public function getMultiple($keys, $default = null): iterable
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }
        return $result;
    }

    public function setMultiple($values, $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value, $ttl);
        }
        return true;
    }

    public function deleteMultiple($keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }
        return true;
    }

    public function has($key): bool
    {
        if (!isset($this->cache[$key])) {
            return false;
        }

        if (isset($this->ttls[$key]) && time() > $this->ttls[$key]) {
            $this->delete($key);
            return false;
        }

        return true;
    }
}
