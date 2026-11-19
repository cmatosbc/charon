<?php

declare(strict_types=1);

namespace Charon\Tests;

use Charon\FixedWindowRateLimiter;
use Charon\RateLimiterInterface;
use Charon\RateLimitResult;
use Charon\RateLimitStatus;
use Charon\SignatureStrategy;
use Charon\ThrottleMiddleware;
use Charon\Tests\Mock\ArrayCache;
use Charon\Tests\Mock\MockClock;
use Charon\Tests\Mock\TestLogger;
use InvalidArgumentException;
use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;

final class ThrottleMiddlewareTest extends TestCase
{
    private ArrayCache $cache;
    private TestLogger $logger;
    private MockClock $clock;
    private Psr17Factory $responseFactory;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock();
        $this->cache = new ArrayCache($this->clock);
        $this->logger = new TestLogger();
        $this->responseFactory = new Psr17Factory();
    }

    private function createMiddleware(
        int $limit = 1,
        int $windowPeriod = 3600,
        ?TestLogger $logger = null,
        bool $logAllRequests = false,
        SignatureStrategy|callable|null $signatureStrategy = null,
        array $trustedProxies = [],
        ?callable $bypass = null,
        string $cacheKeyPrefix = 'charon',
    ): ThrottleMiddleware {
        return new ThrottleMiddleware(
            limit: $limit,
            windowPeriod: $windowPeriod,
            cache: $this->cache,
            responseFactory: $this->responseFactory,
            logger: $logger,
            logAllRequests: $logAllRequests,
            clock: $this->clock,
            signatureStrategy: $signatureStrategy ?? SignatureStrategy::Ip,
            trustedProxies: $trustedProxies,
            bypass: $bypass,
            cacheKeyPrefix: $cacheKeyPrefix,
        );
    }

    public function testAllowsRequestsWithinLimit(): void
    {
        $middleware = $this->createMiddleware(limit: 2);

        $request = $this->createRequest();
        $handler = $this->createHandler();

        // First request
        $response1 = $middleware->process($request, $handler);
        $this->assertEquals(200, $response1->getStatusCode());
        $this->assertEquals('2', $response1->getHeaderLine('X-RateLimit-Limit'));
        $this->assertEquals('1', $response1->getHeaderLine('X-RateLimit-Remaining'));

        // Second request
        $response2 = $middleware->process($request, $handler);
        $this->assertEquals(200, $response2->getStatusCode());
        $this->assertEquals('2', $response2->getHeaderLine('X-RateLimit-Limit'));
        $this->assertEquals('0', $response2->getHeaderLine('X-RateLimit-Remaining'));
    }

    public function testEmitsStandardRateLimitHeaders(): void
    {
        $middleware = $this->createMiddleware(limit: 10, windowPeriod: 3600);

        $response = $middleware->process($this->createRequest(), $this->createHandler());

        $this->assertEquals('10', $response->getHeaderLine('RateLimit-Limit'));
        $this->assertEquals('9', $response->getHeaderLine('RateLimit-Remaining'));
        $this->assertEquals('10;w=3600', $response->getHeaderLine('RateLimit-Policy'));
        $reset = (int) $response->getHeaderLine('RateLimit-Reset');
        $this->assertGreaterThan(0, $reset);
        $this->assertLessThanOrEqual(3600, $reset);
    }

    public function testBlocksRequestsOverLimit(): void
    {
        $middleware = $this->createMiddleware(limit: 1);

        $request = $this->createRequest();
        $handler = $this->createHandler();

        // First request (allowed)
        $response1 = $middleware->process($request, $handler);
        $this->assertEquals(200, $response1->getStatusCode());

        // Second request (blocked)
        $response2 = $middleware->process($request, $handler);
        $this->assertEquals(429, $response2->getStatusCode());
        $this->assertNotEmpty($response2->getHeaderLine('Retry-After'));
        $this->assertEquals('0', $response2->getHeaderLine('RateLimit-Remaining'));
        $this->assertEquals('1;w=3600', $response2->getHeaderLine('RateLimit-Policy'));
    }

    public function testThrottledResponseIsProblemJson(): void
    {
        $middleware = $this->createMiddleware(limit: 1);

        $request = $this->createRequest();
        $handler = $this->createHandler();
        $middleware->process($request, $handler);

        $response = $middleware->process($request, $handler);

        $this->assertEquals('application/problem+json', $response->getHeaderLine('Content-Type'));
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame('about:blank', $body['type']);
        $this->assertSame('Too Many Requests', $body['title']);
        $this->assertSame(429, $body['status']);
        $this->assertArrayHasKey('detail', $body);
        $this->assertArrayHasKey('retry_after', $body);
    }

    public function testResetsCounterAfterWindowPeriod(): void
    {
        $middleware = $this->createMiddleware(limit: 1, windowPeriod: 1);

        $request = $this->createRequest();
        $handler = $this->createHandler();

        // First request
        $response1 = $middleware->process($request, $handler);
        $this->assertEquals(200, $response1->getStatusCode());

        // Second request (should be blocked)
        $response2 = $middleware->process($request, $handler);
        $this->assertEquals(429, $response2->getStatusCode());

        // Move past the window
        $this->clock->advance(2);

        // Third request (should be allowed)
        $response3 = $middleware->process($request, $handler);
        $this->assertEquals(200, $response3->getStatusCode());
    }

    public function testLogsRateLimitExceeded(): void
    {
        $middleware = $this->createMiddleware(limit: 1, logger: $this->logger);

        $request = $this->createRequest();
        $handler = $this->createHandler();

        // Exhaust limit
        $middleware->process($request, $handler);
        $middleware->process($request, $handler);

        $this->assertTrue($this->logger->hasWarningRecords());
        $this->assertTrue($this->logger->hasWarningThatContains('Rate limit exceeded'));
    }

    public function testBlacklistAfterViolations(): void
    {
        $middleware = $this->createMiddleware(limit: 1, windowPeriod: 1, logger: $this->logger);
        $middleware->maybeBlacklist(2);

        $request = $this->createRequest();
        $handler = $this->createHandler();

        // First violation
        $middleware->process($request, $handler);
        $response1 = $middleware->process($request, $handler);
        $this->assertEquals(429, $response1->getStatusCode());

        $this->clock->advance(2); // Move past the window

        // Second violation
        $middleware->process($request, $handler);
        $response2 = $middleware->process($request, $handler);
        $this->assertEquals(429, $response2->getStatusCode());

        $this->clock->advance(2); // Move past the window

        // Should be blacklisted now
        $response3 = $middleware->process($request, $handler);
        $this->assertEquals(403, $response3->getStatusCode());
        $this->assertEquals('application/problem+json', $response3->getHeaderLine('Content-Type'));
        $this->assertTrue($this->logger->hasRecordThatContains('Client blacklisted'));
    }

    public function testBlacklistExpiresAfterConfiguredTtl(): void
    {
        $middleware = $this->createMiddleware(limit: 1, windowPeriod: 60, logger: $this->logger);
        $middleware->maybeBlacklist(1, ttl: 120);

        $request = $this->createRequest();
        $handler = $this->createHandler();

        // Trigger a violation, which blacklists the client immediately
        $middleware->process($request, $handler);
        $response1 = $middleware->process($request, $handler);
        $this->assertEquals(429, $response1->getStatusCode());

        $this->clock->advance(61);

        // Client is blacklisted
        $response2 = $middleware->process($request, $handler);
        $this->assertEquals(403, $response2->getStatusCode());

        // Blacklist TTL expires
        $this->clock->advance(120);

        $response3 = $middleware->process($request, $handler);
        $this->assertEquals(200, $response3->getStatusCode());
    }

    public function testDifferentClientsTrackedSeparately(): void
    {
        $middleware = $this->createMiddleware(limit: 1);

        $request1 = $this->createRequest(serverParams: ['REMOTE_ADDR' => '1.1.1.1']);
        $request2 = $this->createRequest(serverParams: ['REMOTE_ADDR' => '2.2.2.2']);
        $handler = $this->createHandler();

        // First client's requests
        $response1 = $middleware->process($request1, $handler);
        $this->assertEquals(200, $response1->getStatusCode());
        $response2 = $middleware->process($request1, $handler);
        $this->assertEquals(429, $response2->getStatusCode());

        // Second client's request (should be allowed)
        $response3 = $middleware->process($request2, $handler);
        $this->assertEquals(200, $response3->getStatusCode());
    }

    public function testIpStrategyIgnoresUserAgent(): void
    {
        $middleware = $this->createMiddleware(limit: 1, signatureStrategy: SignatureStrategy::Ip);
        $handler = $this->createHandler();

        $request1 = $this->createRequest(headers: ['User-Agent' => 'Agent-A']);
        $request2 = $this->createRequest(headers: ['User-Agent' => 'Agent-B']);

        // Same IP, different agents share one bucket
        $this->assertEquals(200, $middleware->process($request1, $handler)->getStatusCode());
        $this->assertEquals(429, $middleware->process($request2, $handler)->getStatusCode());
    }

    public function testIpAndUserAgentStrategySeparatesClientsByAgent(): void
    {
        $middleware = $this->createMiddleware(
            limit: 1,
            signatureStrategy: SignatureStrategy::IpAndUserAgent
        );
        $handler = $this->createHandler();

        $request1 = $this->createRequest(headers: ['User-Agent' => 'Agent-A']);
        $request2 = $this->createRequest(headers: ['User-Agent' => 'Agent-B']);

        // Same IP, different agents get separate buckets
        $this->assertEquals(200, $middleware->process($request1, $handler)->getStatusCode());
        $this->assertEquals(200, $middleware->process($request2, $handler)->getStatusCode());
        $this->assertEquals(429, $middleware->process($request1, $handler)->getStatusCode());
    }

    public function testCustomSignatureResolver(): void
    {
        $middleware = $this->createMiddleware(
            limit: 1,
            signatureStrategy: fn (ServerRequestInterface $r): string => $r->getHeaderLine('Authorization')
        );
        $handler = $this->createHandler();

        $request1 = $this->createRequest(headers: ['Authorization' => 'Bearer token-1']);
        $request2 = $this->createRequest(
            headers: ['Authorization' => 'Bearer token-1'],
            serverParams: ['REMOTE_ADDR' => '9.9.9.9']
        );
        $request3 = $this->createRequest(headers: ['Authorization' => 'Bearer token-2']);

        // Same token from different IPs shares a bucket
        $this->assertEquals(200, $middleware->process($request1, $handler)->getStatusCode());
        $this->assertEquals(429, $middleware->process($request2, $handler)->getStatusCode());

        // Different token gets its own bucket
        $this->assertEquals(200, $middleware->process($request3, $handler)->getStatusCode());
    }

    public function testTrustedProxyResolvesForwardedClientIp(): void
    {
        $middleware = $this->createMiddleware(limit: 1, trustedProxies: ['10.0.0.1']);
        $handler = $this->createHandler();

        $request1 = $this->createRequest(
            headers: ['X-Forwarded-For' => '1.2.3.4'],
            serverParams: ['REMOTE_ADDR' => '10.0.0.1']
        );
        $request2 = $this->createRequest(
            headers: ['X-Forwarded-For' => '9.9.9.9'],
            serverParams: ['REMOTE_ADDR' => '10.0.0.1']
        );

        // Distinct forwarded clients get separate buckets behind the same proxy
        $this->assertEquals(200, $middleware->process($request1, $handler)->getStatusCode());
        $this->assertEquals(200, $middleware->process($request2, $handler)->getStatusCode());
        $this->assertEquals(429, $middleware->process($request1, $handler)->getStatusCode());
    }

    public function testTrustedProxyPicksRightmostUntrustedForwardedIp(): void
    {
        $middleware = $this->createMiddleware(limit: 1, trustedProxies: ['10.0.0.1']);
        $handler = $this->createHandler();

        // client 1.1.1.1 -> untrusted relay 2.2.2.2 -> trusted proxy 10.0.0.1
        $request1 = $this->createRequest(
            headers: ['X-Forwarded-For' => '1.1.1.1, 2.2.2.2, 10.0.0.1'],
            serverParams: ['REMOTE_ADDR' => '10.0.0.1']
        );
        // Different claimed client, same rightmost untrusted relay
        $request2 = $this->createRequest(
            headers: ['X-Forwarded-For' => '9.9.9.9, 2.2.2.2, 10.0.0.1'],
            serverParams: ['REMOTE_ADDR' => '10.0.0.1']
        );

        $this->assertEquals(200, $middleware->process($request1, $handler)->getStatusCode());
        $this->assertEquals(429, $middleware->process($request2, $handler)->getStatusCode());
    }

    public function testCidrTrustedProxy(): void
    {
        $middleware = $this->createMiddleware(limit: 1, trustedProxies: ['10.0.0.0/8']);
        $handler = $this->createHandler();

        $request1 = $this->createRequest(
            headers: ['X-Forwarded-For' => '1.2.3.4'],
            serverParams: ['REMOTE_ADDR' => '10.20.30.40']
        );
        $request2 = $this->createRequest(
            headers: ['X-Forwarded-For' => '1.2.3.4'],
            serverParams: ['REMOTE_ADDR' => '10.99.0.1']
        );

        // Both proxies inside the trusted range resolve to the same client bucket
        $this->assertEquals(200, $middleware->process($request1, $handler)->getStatusCode());
        $this->assertEquals(429, $middleware->process($request2, $handler)->getStatusCode());
    }

    public function testUntrustedRemoteAddrIgnoresForwardedFor(): void
    {
        $middleware = $this->createMiddleware(limit: 1, trustedProxies: ['10.0.0.1']);
        $handler = $this->createHandler();

        $request1 = $this->createRequest(
            headers: ['X-Forwarded-For' => '1.2.3.4'],
            serverParams: ['REMOTE_ADDR' => '8.8.8.8']
        );
        $request2 = $this->createRequest(
            headers: ['X-Forwarded-For' => '5.6.7.8'],
            serverParams: ['REMOTE_ADDR' => '8.8.8.8']
        );

        // Spoofed XFF values are ignored; the untrusted peer shares one bucket
        $this->assertEquals(200, $middleware->process($request1, $handler)->getStatusCode());
        $this->assertEquals(429, $middleware->process($request2, $handler)->getStatusCode());
    }

    public function testBypassSkipsRateLimiting(): void
    {
        $middleware = $this->createMiddleware(
            limit: 1,
            bypass: fn (ServerRequestInterface $r): bool => $r->getUri()->getPath() === '/health'
        );
        $handler = $this->createHandler();

        $healthCheck = $this->createRequest(uri: '/health');
        $apiRequest = $this->createRequest(uri: '/api');

        // Bypassed requests are unlimited and carry no rate limit headers
        for ($i = 0; $i < 3; $i++) {
            $response = $middleware->process($healthCheck, $handler);
            $this->assertEquals(200, $response->getStatusCode());
            $this->assertEmpty($response->getHeaderLine('RateLimit-Limit'));
        }

        // Other paths are still limited
        $this->assertEquals(200, $middleware->process($apiRequest, $handler)->getStatusCode());
        $this->assertEquals(429, $middleware->process($apiRequest, $handler)->getStatusCode());
    }

    public function testCacheKeysUseConfiguredPrefix(): void
    {
        $middleware = $this->createMiddleware(limit: 10, cacheKeyPrefix: 'myapp');
        $signature = hash('sha256', '127.0.0.1');

        $middleware->process($this->createRequest(), $this->createHandler());

        $this->assertNotNull($this->cache->get('myapp_' . $signature));
        $this->assertFalse($this->cache->has('charon_' . $signature));
    }

    public function testRequestsWithoutRemoteAddrShareBucket(): void
    {
        $middleware = $this->createMiddleware(limit: 1, windowPeriod: 3600);
        $handler = $this->createHandler();

        $request1 = new ServerRequest('GET', '/', [], null, '1.1', []);
        $request2 = new ServerRequest('GET', '/', [], null, '1.1', []);

        $this->assertEquals(200, $middleware->process($request1, $handler)->getStatusCode());
        $this->assertEquals(429, $middleware->process($request2, $handler)->getStatusCode());
    }

    public function testInvalidCacheDataStartsFreshWindow(): void
    {
        $signature = hash('sha256', '127.0.0.1');
        $middleware = $this->createMiddleware(limit: 1);
        $request = $this->createRequest();
        $handler = $this->createHandler();

        // Corrupted scalar value
        $this->cache->set('charon_' . $signature, 'corrupted-string');
        $this->assertEquals(200, $middleware->process($request, $handler)->getStatusCode());

        // Malformed array payload
        $this->cache->set('charon_' . $signature, ['requests' => 'not-an-int']);
        $this->assertEquals(200, $middleware->process($request, $handler)->getStatusCode());
    }

    public function testInvalidLimitThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ThrottleMiddleware(
            limit: 0,
            windowPeriod: 3600,
            cache: $this->cache,
            responseFactory: $this->responseFactory,
        );
    }

    public function testInvalidWindowPeriodThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ThrottleMiddleware(
            limit: 1,
            windowPeriod: 0,
            cache: $this->cache,
            responseFactory: $this->responseFactory,
        );
    }

    public function testInvalidCacheKeyPrefixThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ThrottleMiddleware(
            limit: 1,
            windowPeriod: 3600,
            cache: $this->cache,
            responseFactory: $this->responseFactory,
            cacheKeyPrefix: 'bad:prefix',
        );
    }

    public function testInvalidBlacklistThreshold(): void
    {
        $middleware = $this->createMiddleware(limit: 1);

        $this->expectException(InvalidArgumentException::class);
        $middleware->maybeBlacklist(0);
    }

    public function testInvalidBlacklistTtlThrows(): void
    {
        $middleware = $this->createMiddleware(limit: 1);

        $this->expectException(InvalidArgumentException::class);
        $middleware->maybeBlacklist(1, ttl: 0);
    }

    public function testInjectedLimiterIsUsed(): void
    {
        // The injected limiter wins over the facade arguments
        $limiter = new FixedWindowRateLimiter(
            limit: 1,
            windowPeriod: 3600,
            store: $this->cache,
            clock: $this->clock,
        );

        $middleware = new ThrottleMiddleware(
            limit: 999,
            windowPeriod: 3600,
            cache: $this->cache,
            responseFactory: $this->responseFactory,
            limiter: $limiter,
        );

        $request = $this->createRequest();
        $handler = $this->createHandler();

        $this->assertEquals(200, $middleware->process($request, $handler)->getStatusCode());
        $this->assertEquals(429, $middleware->process($request, $handler)->getStatusCode());
        $this->assertEquals(
            '1',
            $middleware->process($request, $handler)->getHeaderLine('RateLimit-Limit')
        );
    }

    public function testMaybeBlacklistWithoutBlacklistAwareLimiterThrows(): void
    {
        $limiter = new class implements RateLimiterInterface {
            public function consume(string $key, int $cost = 1): RateLimitResult
            {
                return new RateLimitResult(
                    status: RateLimitStatus::Allowed,
                    limit: 1,
                    requests: 1,
                    remaining: 0,
                    resetTime: 0,
                    retryAfter: 0,
                );
            }
        };

        $middleware = new ThrottleMiddleware(
            limit: 1,
            windowPeriod: 3600,
            cache: $this->cache,
            responseFactory: $this->responseFactory,
            limiter: $limiter,
        );

        $this->expectException(LogicException::class);
        $middleware->maybeBlacklist(1);
    }

    public function testCostResolverChargesPerRequest(): void
    {
        $middleware = new ThrottleMiddleware(
            limit: 3,
            windowPeriod: 3600,
            cache: $this->cache,
            responseFactory: $this->responseFactory,
            costResolver: fn (ServerRequestInterface $r): int =>
                $r->getMethod() === 'POST' ? 2 : 1,
            clock: $this->clock,
        );

        $get = $this->createRequest(method: 'GET');
        $post = $this->createRequest(method: 'POST');
        $handler = $this->createHandler();

        $response1 = $middleware->process($get, $handler);
        $this->assertEquals(200, $response1->getStatusCode());
        $this->assertEquals('2', $response1->getHeaderLine('RateLimit-Remaining'));

        $response2 = $middleware->process($post, $handler);
        $this->assertEquals(200, $response2->getStatusCode());
        $this->assertEquals('0', $response2->getHeaderLine('RateLimit-Remaining'));

        $this->assertEquals(429, $middleware->process($get, $handler)->getStatusCode());
    }

    public function testOptionalLogging(): void
    {
        $middleware = $this->createMiddleware(limit: 1, logger: $this->logger, logAllRequests: true);

        $request = $this->createRequest();
        $handler = $this->createHandler();

        $middleware->process($request, $handler);

        $this->assertTrue($this->logger->hasInfoRecords());
        $this->assertTrue($this->logger->hasInfoThatContains('Request processed'));
    }
}
