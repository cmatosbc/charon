<?php

declare(strict_types=1);

namespace Charon;

use Closure;
use InvalidArgumentException;
use LogicException;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;

/**
 * ThrottleMiddleware implements rate limiting for HTTP requests.
 *
 * The middleware is a thin PSR-15 adapter over a RateLimiterInterface.
 * By default it builds a FixedWindowRateLimiter from the constructor
 * arguments; a custom limiter can be injected via $limiter.
 */
final class ThrottleMiddleware implements MiddlewareInterface
{
    private const string RESERVED_KEY_CHARS = '{}()/\@:';

    private readonly LoggerInterface $logger;
    private readonly SignatureStrategy|Closure $signatureStrategy;
    private readonly ?Closure $bypass;
    private readonly ?Closure $costResolver;
    private readonly RateLimiterInterface $limiter;

    /**
     * @param int $limit Maximum number of requests allowed within the time window
     * @param int $windowPeriod Time window in seconds
     * @param CacheInterface $cache PSR-16 cache implementation
     * @param ResponseFactoryInterface $responseFactory PSR-17 factory used to create throttle responses
     * @param LoggerInterface|null $logger Optional PSR-3 logger
     * @param bool $logAllRequests Whether to log all requests or only rate-limited ones
     * @param ClockInterface $clock Time source, defaults to system time
     * @param SignatureStrategy|callable $signatureStrategy Client identification: a SignatureStrategy
     *        or a callable(ServerRequestInterface): string returning the client discriminator
     * @param string[] $trustedProxies Proxy IPs or IPv4 CIDR ranges trusted to provide X-Forwarded-For
     * @param callable|null $bypass callable(ServerRequestInterface): bool — matching requests skip rate limiting
     * @param callable|null $costResolver callable(ServerRequestInterface): int — quota cost per request, defaults to 1
     * @param string $cacheKeyPrefix Cache key prefix, must not contain the characters {}()/\@:
     * @param RateLimiterInterface|null $limiter Custom rate limiter; when null a
     *        FixedWindowRateLimiter is built from $limit, $windowPeriod, $cache, $clock and $cacheKeyPrefix
     */
    public function __construct(
        private readonly int $limit,
        private readonly int $windowPeriod,
        private readonly CacheInterface $cache,
        private readonly ResponseFactoryInterface $responseFactory,
        ?LoggerInterface $logger = null,
        private readonly bool $logAllRequests = false,
        private readonly ClockInterface $clock = new SystemClock(),
        SignatureStrategy|callable $signatureStrategy = SignatureStrategy::Ip,
        private readonly array $trustedProxies = [],
        ?callable $bypass = null,
        ?callable $costResolver = null,
        private readonly string $cacheKeyPrefix = 'charon',
        ?RateLimiterInterface $limiter = null,
    ) {
        if ($limit < 1) {
            throw new InvalidArgumentException('Limit must be greater than 0.');
        }
        if ($windowPeriod < 1) {
            throw new InvalidArgumentException('Window period must be greater than 0.');
        }
        if ($cacheKeyPrefix === '' || strpbrk($cacheKeyPrefix, self::RESERVED_KEY_CHARS) !== false) {
            throw new InvalidArgumentException('Cache key prefix must not be empty or contain reserved characters {}()/\@:');
        }

        $this->logger = $logger ?? new NullLogger();
        $this->signatureStrategy = $signatureStrategy instanceof SignatureStrategy
            ? $signatureStrategy
            : Closure::fromCallable($signatureStrategy);
        $this->bypass = $bypass !== null ? Closure::fromCallable($bypass) : null;
        $this->costResolver = $costResolver !== null ? Closure::fromCallable($costResolver) : null;
        $this->limiter = $limiter ?? new FixedWindowRateLimiter(
            limit: $limit,
            windowPeriod: $windowPeriod,
            store: $cache,
            clock: $clock,
            cacheKeyPrefix: $cacheKeyPrefix,
        );
    }

    /**
     * Configure automatic blacklisting after recurring rate limit violations
     *
     * @param int $recurringEvents Number of violations required before blacklisting
     * @param int|null $ttl Blacklist lifetime in seconds, or null to blacklist indefinitely
     */
    public function maybeBlacklist(int $recurringEvents, ?int $ttl = null): self
    {
        if (!$this->limiter instanceof BlacklistAwareRateLimiter) {
            throw new LogicException('The configured rate limiter does not support blacklisting.');
        }
        if ($recurringEvents < 1) {
            throw new InvalidArgumentException('Recurring events must be greater than 0.');
        }
        if ($ttl !== null && $ttl < 1) {
            throw new InvalidArgumentException('Blacklist TTL must be greater than 0 or null.');
        }

        $this->limiter->maybeBlacklist($recurringEvents, $ttl);
        return $this;
    }

    /**
     * Generates a unique signature identifying the client
     */
    private function getRequestSignature(ServerRequestInterface $request): string
    {
        if ($this->signatureStrategy instanceof Closure) {
            return hash('sha256', (string) ($this->signatureStrategy)($request));
        }

        $identity = $this->resolveClientIp($request);
        if ($this->signatureStrategy === SignatureStrategy::IpAndUserAgent) {
            $identity .= $request->getHeaderLine('User-Agent');
        }

        return hash('sha256', $identity);
    }

    /**
     * Resolves the client IP, honoring X-Forwarded-For when the direct peer is a trusted proxy
     */
    private function resolveClientIp(ServerRequestInterface $request): string
    {
        $remoteAddr = trim((string) ($request->getServerParams()['REMOTE_ADDR'] ?? ''));

        if ($remoteAddr === '') {
            return 'unknown';
        }

        if (!$this->isTrustedProxy($remoteAddr)) {
            return $remoteAddr;
        }

        $forwardedIps = array_values(array_filter(
            array_map('trim', explode(',', $request->getHeaderLine('X-Forwarded-For'))),
            static fn (string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP) !== false
        ));

        // Walk right-to-left: the first untrusted hop is the client
        for ($i = count($forwardedIps) - 1; $i >= 0; $i--) {
            if (!$this->isTrustedProxy($forwardedIps[$i])) {
                return $forwardedIps[$i];
            }
        }

        return $forwardedIps[0] ?? $remoteAddr;
    }

    /**
     * Check whether an IP is a configured trusted proxy (exact match or IPv4 CIDR range)
     */
    private function isTrustedProxy(string $ip): bool
    {
        foreach ($this->trustedProxies as $proxy) {
            if ($proxy === $ip) {
                return true;
            }
            if (str_contains($proxy, '/') && $this->ipInCidr($ip, $proxy)) {
                return true;
            }
        }
        return false;
    }

    /**
     * IPv4 CIDR range check
     */
    private function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr, 2);
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        $bits = (int) $bits;

        if ($ipLong === false || $subnetLong === false || $bits < 0 || $bits > 32) {
            return false;
        }

        $mask = -1 << (32 - $bits);
        return ($ipLong & $mask) === ($subnetLong & $mask);
    }

    /**
     * Extracts client information for logging
     */
    private function getClientInfo(ServerRequestInterface $request): array
    {
        return [
            'ip' => $this->resolveClientIp($request),
            'user_agent' => $request->getHeaderLine('User-Agent'),
            'method' => $request->getMethod(),
            'path' => $request->getUri()->getPath()
        ];
    }

    /**
     * Creates an RFC 9457 problem details response
     *
     * @param array<string, string> $headers
     * @param array<string, mixed> $extraMembers Additional problem detail members
     */
    private function problemResponse(
        int $status,
        string $title,
        string $detail,
        array $headers = [],
        array $extraMembers = []
    ): ResponseInterface {
        $response = $this->responseFactory
            ->createResponse($status)
            ->withHeader('Content-Type', 'application/problem+json');

        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        $response->getBody()->write(json_encode([
            'type' => 'about:blank',
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
        ] + $extraMembers, JSON_THROW_ON_ERROR));

        return $response;
    }

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->bypass !== null && ($this->bypass)($request)) {
            return $handler->handle($request);
        }

        $signature = $this->getRequestSignature($request);
        $clientInfo = $this->getClientInfo($request);
        $cost = $this->costResolver !== null ? (int) ($this->costResolver)($request) : 1;
        $result = $this->limiter->consume($signature, $cost);

        if ($result->justBlacklisted) {
            $this->logger->alert('Client blacklisted due to recurring rate limit violations', [
                'client' => $clientInfo,
                'violations' => $result->violations,
                'threshold' => $result->blacklistThreshold
            ]);
        }

        if ($result->status === RateLimitStatus::Blacklisted) {
            $this->logger->warning('Blocked request from blacklisted client', [
                'client' => $clientInfo
            ]);

            return $this->problemResponse(
                403,
                'Forbidden',
                'Access denied due to repeated rate limit violations.'
            );
        }

        $policy = (string) $result->limit . ';w=' . $this->windowPeriod;

        if ($result->status === RateLimitStatus::Limited) {
            $this->logger->warning('Rate limit exceeded', [
                'client' => $clientInfo,
                'requests' => $result->requests,
                'limit' => $result->limit,
                'reset_time' => $result->resetTime
            ]);

            return $this->problemResponse(
                429,
                'Too Many Requests',
                'The client has sent too many requests in the configured time window.',
                [
                    'Retry-After' => (string) $result->retryAfter,
                    'RateLimit-Limit' => (string) $result->limit,
                    'RateLimit-Remaining' => '0',
                    'RateLimit-Reset' => (string) $result->retryAfter,
                    'RateLimit-Policy' => $policy,
                    'X-RateLimit-Limit' => (string) $result->limit,
                    'X-RateLimit-Remaining' => '0',
                    'X-RateLimit-Reset' => (string) $result->resetTime,
                ],
                ['retry_after' => $result->retryAfter]
            );
        }

        if ($this->logAllRequests) {
            $this->logger->info('Request processed', [
                'client' => $clientInfo,
                'requests' => $result->requests,
                'limit' => $result->limit,
                'remaining' => $result->remaining
            ]);
        }

        $response = $handler->handle($request);
        return $response
            ->withHeader('RateLimit-Limit', (string) $result->limit)
            ->withHeader('RateLimit-Remaining', (string) $result->remaining)
            ->withHeader('RateLimit-Reset', (string) $result->retryAfter)
            ->withHeader('RateLimit-Policy', $policy)
            ->withHeader('X-RateLimit-Limit', (string) $result->limit)
            ->withHeader('X-RateLimit-Remaining', (string) $result->remaining)
            ->withHeader('X-RateLimit-Reset', (string) $result->resetTime);
    }
}
