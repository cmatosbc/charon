<?php

namespace Charon;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\SimpleCache\CacheInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Nyholm\Psr7\Response;

/**
 * ThrottleMiddleware implements rate limiting for HTTP requests.
 */
class ThrottleMiddleware implements MiddlewareInterface
{
    private const BLACKLIST_SUFFIX = '_blacklist';
    private const VIOLATIONS_SUFFIX = '_violations';

    private int $limit;
    private int $windowPeriod;
    private CacheInterface $cache;
    private LoggerInterface $logger;
    private bool $logAllRequests;
    private ?int $blacklistAfterViolations;

    /**
     * @param int $limit Maximum number of requests allowed within the time window
     * @param int $windowPeriod Time window in seconds
     * @param CacheInterface $cache Cache implementation
     * @param LoggerInterface|null $logger Optional PSR-3 logger
     * @param bool $logAllRequests Whether to log all requests or only rate-limited ones
     */
    public function __construct(
        int $limit,
        int $windowPeriod,
        CacheInterface $cache,
        ?LoggerInterface $logger = null,
        bool $logAllRequests = false
    ) {
        $this->limit = $limit;
        $this->windowPeriod = $windowPeriod;
        $this->cache = $cache;
        $this->logger = $logger ?? new NullLogger();
        $this->logAllRequests = $logAllRequests;
        $this->blacklistAfterViolations = null;
    }

    /**
     * Configure automatic blacklisting after recurring rate limit violations
     */
    public function maybeBlacklist(int $recurringEvents): self
    {
        if ($recurringEvents < 1) {
            throw new \InvalidArgumentException('Recurring events must be greater than 0');
        }
        $this->blacklistAfterViolations = $recurringEvents;
        return $this;
    }

    /**
     * Generates a unique signature for the request based on IP and User-Agent
     */
    private function getRequestSignature(ServerRequestInterface $request): string
    {
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? '';
        $userAgent = $request->getHeaderLine('User-Agent');
        return hash('sha256', $ip . $userAgent);
    }

    /**
     * Extracts client information for logging
     */
    private function getClientInfo(ServerRequestInterface $request): array
    {
        return [
            'ip' => $request->getServerParams()['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $request->getHeaderLine('User-Agent'),
            'method' => $request->getMethod(),
            'path' => $request->getUri()->getPath()
        ];
    }

    /**
     * Check if a signature is blacklisted
     */
    private function isBlacklisted(string $signature): bool
    {
        if ($this->blacklistAfterViolations === null) {
            return false;
        }
        return (bool) $this->cache->get($signature . self::BLACKLIST_SUFFIX);
    }

    /**
     * Track rate limit violations and maybe blacklist
     */
    private function handleViolation(string $signature, array $clientInfo): void
    {
        if ($this->blacklistAfterViolations === null) {
            return;
        }

        $violationsKey = $signature . self::VIOLATIONS_SUFFIX;
        $violations = (int) $this->cache->get($violationsKey) + 1;
        $this->cache->set($violationsKey, $violations, $this->windowPeriod * 2);

        if ($violations >= $this->blacklistAfterViolations) {
            $this->cache->set($signature . self::BLACKLIST_SUFFIX, true);
            $this->logger->alert('Client blacklisted due to recurring rate limit violations', [
                'client' => $clientInfo,
                'violations' => $violations,
                'threshold' => $this->blacklistAfterViolations
            ]);
        }
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $signature = $this->getRequestSignature($request);
        $clientInfo = $this->getClientInfo($request);

        // Check blacklist first
        if ($this->isBlacklisted($signature)) {
            $this->logger->warning('Blocked request from blacklisted client', [
                'client' => $clientInfo
            ]);

            return new Response(
                403,
                ['Content-Type' => 'application/json'],
                json_encode(['error' => 'Access denied due to repeated rate limit violations'])
            );
        }

        $now = time();
        $cachedData = $this->cache->get($signature);
        $throttleData = $cachedData ? unserialize($cachedData) : ['requests' => 0, 'window_start' => $now];

        if (($now - $throttleData['window_start']) > $this->windowPeriod) {
            $throttleData = ['requests' => 0, 'window_start' => $now];
        }

        if ($throttleData['requests'] >= $this->limit) {
            $resetTime = $throttleData['window_start'] + $this->windowPeriod;
            
            $this->logger->warning('Rate limit exceeded', [
                'client' => $clientInfo,
                'requests' => $throttleData['requests'],
                'limit' => $this->limit,
                'reset_time' => $resetTime
            ]);

            $this->handleViolation($signature, $clientInfo);

            return new Response(
                429,
                [
                    'Retry-After' => $resetTime - $now,
                    'X-RateLimit-Limit' => $this->limit,
                    'X-RateLimit-Remaining' => 0,
                    'X-RateLimit-Reset' => $resetTime
                ],
                'Too many requests, please try again later.'
            );
        }

        $throttleData['requests']++;
        $this->cache->set($signature, serialize($throttleData), $this->windowPeriod);

        if ($this->logAllRequests) {
            $this->logger->info('Request processed', [
                'client' => $clientInfo,
                'requests' => $throttleData['requests'],
                'limit' => $this->limit,
                'remaining' => $this->limit - $throttleData['requests']
            ]);
        }

        $response = $handler->handle($request);
        return $response->withHeader('X-RateLimit-Limit', $this->limit)
            ->withHeader('X-RateLimit-Remaining', $this->limit - $throttleData['requests'])
            ->withHeader('X-RateLimit-Reset', $throttleData['window_start'] + $this->windowPeriod);
    }
}
