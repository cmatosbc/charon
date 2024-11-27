<?php

namespace Charon\Tests;

use Charon\ThrottleMiddleware;
use Charon\Tests\Mock\ArrayCache;
use Charon\Tests\Mock\TestLogger;
use Psr\Log\NullLogger;

class ThrottleMiddlewareTest extends TestCase
{
    private ArrayCache $cache;
    private TestLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cache = new ArrayCache();
        $this->logger = new TestLogger();
    }

    public function testAllowsRequestsWithinLimit(): void
    {
        $middleware = new ThrottleMiddleware(
            limit: 2,
            windowPeriod: 3600,
            cache: $this->cache
        );

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

    public function testBlocksRequestsOverLimit(): void
    {
        $middleware = new ThrottleMiddleware(
            limit: 1,
            windowPeriod: 3600,
            cache: $this->cache
        );

        $request = $this->createRequest();
        $handler = $this->createHandler();

        // First request (allowed)
        $response1 = $middleware->process($request, $handler);
        $this->assertEquals(200, $response1->getStatusCode());

        // Second request (blocked)
        $response2 = $middleware->process($request, $handler);
        $this->assertEquals(429, $response2->getStatusCode());
        $this->assertNotEmpty($response2->getHeaderLine('Retry-After'));
    }

    public function testResetsCounterAfterWindowPeriod(): void
    {
        $middleware = new ThrottleMiddleware(
            limit: 1,
            windowPeriod: 1,
            cache: $this->cache
        );

        $request = $this->createRequest();
        $handler = $this->createHandler();

        // First request
        $response1 = $middleware->process($request, $handler);
        $this->assertEquals(200, $response1->getStatusCode());

        // Second request (should be blocked)
        $response2 = $middleware->process($request, $handler);
        $this->assertEquals(429, $response2->getStatusCode());

        // Wait for window to expire
        sleep(2);

        // Third request (should be allowed)
        $response3 = $middleware->process($request, $handler);
        $this->assertEquals(200, $response3->getStatusCode());
    }

    public function testLogsRateLimitExceeded(): void
    {
        $middleware = new ThrottleMiddleware(
            limit: 1,
            windowPeriod: 3600,
            cache: $this->cache,
            logger: $this->logger
        );

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
        $middleware = new ThrottleMiddleware(
            limit: 1,
            windowPeriod: 1,
            cache: $this->cache,
            logger: $this->logger
        );
        $middleware->maybeBlacklist(2);

        $request = $this->createRequest();
        $handler = $this->createHandler();

        // First violation
        $middleware->process($request, $handler);
        $response1 = $middleware->process($request, $handler);
        $this->assertEquals(429, $response1->getStatusCode());

        sleep(2); // Wait for window to expire

        // Second violation
        $middleware->process($request, $handler);
        $response2 = $middleware->process($request, $handler);
        $this->assertEquals(429, $response2->getStatusCode());

        sleep(2); // Wait for window to expire

        // Should be blacklisted now
        $response3 = $middleware->process($request, $handler);
        $this->assertEquals(403, $response3->getStatusCode());
        $this->assertTrue($this->logger->hasRecordThatContains('Client blacklisted'));
    }

    public function testDifferentClientsTrackedSeparately(): void
    {
        $middleware = new ThrottleMiddleware(
            limit: 1,
            windowPeriod: 3600,
            cache: $this->cache
        );

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

    public function testInvalidBlacklistThreshold(): void
    {
        $middleware = new ThrottleMiddleware(
            limit: 1,
            windowPeriod: 3600,
            cache: $this->cache
        );

        $this->expectException(\InvalidArgumentException::class);
        $middleware->maybeBlacklist(0);
    }

    public function testOptionalLogging(): void
    {
        $middleware = new ThrottleMiddleware(
            limit: 1,
            windowPeriod: 3600,
            cache: $this->cache,
            logger: $this->logger,
            logAllRequests: true
        );

        $request = $this->createRequest();
        $handler = $this->createHandler();

        $middleware->process($request, $handler);

        $this->assertTrue($this->logger->hasInfoRecords());
        $this->assertTrue($this->logger->hasInfoThatContains('Request processed'));
    }
}
