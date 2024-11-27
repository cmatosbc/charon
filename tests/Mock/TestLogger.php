<?php

namespace Charon\Tests\Mock;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

class TestLogger extends AbstractLogger
{
    private array $records = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => $level,
            'message' => $message,
            'context' => $context
        ];
    }

    public function hasRecords(): bool
    {
        return !empty($this->records);
    }

    public function hasRecord(string $message, string $level): bool
    {
        foreach ($this->records as $record) {
            if ($record['level'] === $level && $record['message'] === $message) {
                return true;
            }
        }
        return false;
    }

    public function hasRecordThatContains(string $message): bool
    {
        foreach ($this->records as $record) {
            if (str_contains($record['message'], $message)) {
                return true;
            }
        }
        return false;
    }

    public function hasWarningRecords(): bool
    {
        foreach ($this->records as $record) {
            if ($record['level'] === LogLevel::WARNING) {
                return true;
            }
        }
        return false;
    }

    public function hasWarningThatContains(string $message): bool
    {
        foreach ($this->records as $record) {
            if ($record['level'] === LogLevel::WARNING && str_contains($record['message'], $message)) {
                return true;
            }
        }
        return false;
    }

    public function hasInfoRecords(): bool
    {
        foreach ($this->records as $record) {
            if ($record['level'] === LogLevel::INFO) {
                return true;
            }
        }
        return false;
    }

    public function hasInfoThatContains(string $message): bool
    {
        foreach ($this->records as $record) {
            if ($record['level'] === LogLevel::INFO && str_contains($record['message'], $message)) {
                return true;
            }
        }
        return false;
    }

    public function clear(): void
    {
        $this->records = [];
    }
}
