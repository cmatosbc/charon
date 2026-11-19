<?php

declare(strict_types=1);

namespace Charon\Tests\Mock;

use Charon\CounterStore;

class AtomicArrayCache extends ArrayCache implements CounterStore
{
    /**
     * Simulates an atomic fixed-window evaluation.
     *
     * @return array{requests: int, window_start: int, allowed: bool}
     */
    #[\Override]
    public function evaluate(string $key, int $limit, int $windowPeriod, int $now): array
    {
        $data = $this->get($key);

        if (
            !is_array($data)
            || !is_int($data['requests'] ?? null)
            || !is_int($data['window_start'] ?? null)
            || ($now - $data['window_start']) > $windowPeriod
        ) {
            $data = ['requests' => 0, 'window_start' => $now];
        }

        $allowed = $data['requests'] < $limit;
        if ($allowed) {
            $data['requests']++;
            $this->set($key, $data, $windowPeriod);
        }

        return $data + ['allowed' => $allowed];
    }
}
