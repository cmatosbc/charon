# Charon

A simple yet powerful PSR-15 compliant rate limiting middleware for PHP applications. Charon provides an effective way to protect your applications from abuse through configurable request throttling.

[![Tests](https://github.com/cmatosbc/charon/actions/workflows/php.yml/badge.svg)](https://github.com/cmatosbc/charon/actions/workflows/php.yml)
[![Latest Stable Version](https://img.shields.io/packagist/v/cmatosbc/charon.svg?style=flat-square)](https://packagist.org/packages/cmatosbc/charon)
[![PHP Version](https://img.shields.io/packagist/php-v/cmatosbc/charon.svg?style=flat-square)](https://packagist.org/packages/cmatosbc/charon)
[![Software License](https://img.shields.io/badge/license-GPL--3.0-brightgreen.svg?style=flat-square)](LICENSE)

## Features

- 🚀 PSR-15 Middleware compliant
- 💾 PSR-16 Simple Cache support for storage
- 🏭 PSR-17 Response Factory for throttle responses (works with any PSR-7 implementation)
- 🕐 PSR-20 Clock support for testable time handling
- 📝 Optional PSR-3 Logger integration
- ⚡ Efficient rate limiting using fixed window counters
- 🔒 IP or IP+User-Agent based throttling, or bring your own signature resolver
- � Trusted proxy support for correct client IP resolution (X-Forwarded-For, IPv4 CIDR)
- ⏭️ Bypass callable for exempt requests (health checks, internal traffic)
- �🎯 Configurable rate limits and time windows
- 📊 IETF `RateLimit-*` headers plus legacy `X-RateLimit-*` for compatibility
- 📄 RFC 9457 `application/problem+json` error responses
- 🚫 Automatic blacklisting for repeat offenders, with optional TTL
- 🧩 Standalone `RateLimiter` usable without the middleware, with atomic counter support

## Installation

You can install the package via composer:

```bash
composer require cmatosbc/charon
```

Requires PHP 8.3 or later and a PSR-17 response factory implementation (e.g. `nyholm/psr7`, `guzzlehttp/psr7`, `laminas/laminas-diactoros`).

## Usage

### Basic Usage

```php
use Charon\ThrottleMiddleware;

// Create the middleware with basic configuration
$middleware = new ThrottleMiddleware(
    limit: 100,                    // Maximum requests allowed
    windowPeriod: 3600,            // Time window in seconds (1 hour)
    cache: $cacheImpl,             // PSR-16 cache implementation
    responseFactory: $psr17Factory // PSR-17 response factory
);

// Add it to your middleware stack
$app->add($middleware);
```

### With Logging

```php
use Charon\ThrottleMiddleware;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

// Create a PSR-3 logger
$logger = new Logger('rate-limits');
$logger->pushHandler(new StreamHandler('path/to/rate-limit.log', Logger::WARNING));

// Create middleware with logging
$middleware = new ThrottleMiddleware(
    limit: 100,
    windowPeriod: 3600,
    cache: $cacheImpl,
    responseFactory: $psr17Factory,
    logger: $logger,          // PSR-3 logger
    logAllRequests: false     // Set to true to log all requests
);
```

### With Automatic Blacklisting

```php
use Charon\ThrottleMiddleware;

$middleware = new ThrottleMiddleware(
    limit: 100,
    windowPeriod: 3600,
    cache: $cacheImpl,
    responseFactory: $psr17Factory,
    logger: $logger
);

// Blacklist clients after 5 rate limit violations
$middleware->maybeBlacklist(5);

// Or blacklist with an expiration (e.g. unban after 24 hours)
$middleware->maybeBlacklist(5, ttl: 86400);
```

When blacklisting is enabled:
- Clients exceeding rate limits multiple times will be tracked
- After reaching the specified number of violations, the client will be blacklisted
- Blacklisted clients receive a 403 Forbidden response
- Violations are tracked across multiple time windows
- Blacklist status is stored in cache with client signature, optionally expiring after a configured TTL (permanent by default)

### Client Identification

By default, clients are identified by their resolved IP address (`SignatureStrategy::Ip`). Two alternatives are available:

```php
use Charon\SignatureStrategy;
use Charon\ThrottleMiddleware;

// Per IP + User-Agent pair (per-browser granularity)
$middleware = new ThrottleMiddleware(
    limit: 100,
    windowPeriod: 3600,
    cache: $cacheImpl,
    responseFactory: $psr17Factory,
    signatureStrategy: SignatureStrategy::IpAndUserAgent
);

// Fully custom identification (e.g. per API token)
$middleware = new ThrottleMiddleware(
    limit: 100,
    windowPeriod: 3600,
    cache: $cacheImpl,
    responseFactory: $psr17Factory,
    signatureStrategy: fn (ServerRequestInterface $request): string =>
        $request->getHeaderLine('Authorization')
);
```

#### Trusted Proxies

When the application sits behind a reverse proxy or load balancer, `REMOTE_ADDR` contains the proxy's IP. Configure trusted proxies (exact IPs or IPv4 CIDR ranges) so the real client address is taken from `X-Forwarded-For`:

```php
$middleware = new ThrottleMiddleware(
    limit: 100,
    windowPeriod: 3600,
    cache: $cacheImpl,
    responseFactory: $psr17Factory,
    trustedProxies: ['10.0.0.0/8', '192.168.1.10']
);
```

The header is only honored when the direct peer is trusted, and the rightmost untrusted entry is used as the client IP, so clients cannot spoof their identity.

### Bypassing Rate Limits

Certain requests — health checks, internal traffic — can skip rate limiting entirely via a `bypass` callable:

```php
$middleware = new ThrottleMiddleware(
    limit: 100,
    windowPeriod: 3600,
    cache: $cacheImpl,
    responseFactory: $psr17Factory,
    bypass: fn (ServerRequestInterface $request): bool =>
        $request->getUri()->getPath() === '/health'
);
```

Bypassed requests return the handler's response untouched, without rate limit headers.

### Standalone Rate Limiter

The throttling algorithm lives in `Charon\FixedWindowRateLimiter` and can be used without the middleware — in console commands, queue consumers, WebSocket servers, or any non-HTTP context:

```php
use Charon\FixedWindowRateLimiter;
use Charon\RateLimitStatus;

$limiter = new FixedWindowRateLimiter(
    limit: 100,
    windowPeriod: 3600,
    store: $cacheImpl,        // PSR-16 cache
    cacheKeyPrefix: 'charon'  // optional key prefix
);
$limiter->maybeBlacklist(5, ttl: 86400);

$result = $limiter->consume($clientKey);

match ($result->status) {
    RateLimitStatus::Allowed    => /* proceed */,
    RateLimitStatus::Limited    => /* reject, retry in $result->retryAfter seconds */,
    RateLimitStatus::Blacklisted => /* hard block */,
};
```

`$result` exposes `limit`, `requests`, `remaining`, `resetTime` (Unix timestamp), `retryAfter` (delta seconds), `violations`, `blacklistThreshold`, and `justBlacklisted`.

To plug a custom algorithm into the middleware, inject any `Charon\RateLimiterInterface` implementation:

```php
$middleware = new ThrottleMiddleware(
    limit: 100,                // facade args, ignored when $limiter is set
    windowPeriod: 3600,
    cache: $cacheImpl,
    responseFactory: $psr17Factory,
    limiter: $myRateLimiter
);
```

#### Atomic Counters

The plain PSR-16 storage path uses a read-modify-write sequence: under heavy concurrency, simultaneous requests can both read the same counter and the limit may be slightly exceeded. For race-free counting, implement `Charon\CounterStore` over a backend with atomic operations (e.g. a Redis Lua script or Memcached CAS) and pass it as the limiter's `store`:

```php
$limiter = new FixedWindowRateLimiter(
    limit: 100,
    windowPeriod: 3600,
    store: new RedisCounterStore($redis)  // implements CounterStore
);
```

### Framework Integration Examples

#### Slim 4

```php
use Slim\Factory\AppFactory;
use Charon\ThrottleMiddleware;

$app = AppFactory::create();

// Add the middleware with blacklisting
$app->add((new ThrottleMiddleware(
    limit: 100,
    windowPeriod: 3600,
    cache: $cache,
    responseFactory: $psr17Factory
))->maybeBlacklist(5));
```

#### Laravel

```php
use Charon\ThrottleMiddleware;

// In a service provider
public function boot()
{
    $this->app->middleware([
        (new ThrottleMiddleware(
            limit: 100,
            windowPeriod: 3600,
            cache: app()->make('cache.store'),
            responseFactory: app()->make(ResponseFactoryInterface::class)
        ))->maybeBlacklist(5)
    ]);
}
```

#### Symfony

```php
use Charon\ThrottleMiddleware;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Cache\Psr16Cache;

// In services.yaml
services:
    Charon\ThrottleMiddleware:
        arguments:
            $limit: 100
            $windowPeriod: 3600
            $cache: '@cache.app'
            $responseFactory: '@Psr\Http\Message\ResponseFactoryInterface'
        calls:
            - maybeBlacklist: [5]
        tags:
            - { name: 'kernel.event_listener', event: 'kernel.request', priority: 300 }

// Or in a Controller/EventSubscriber
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class RateLimitSubscriber implements EventSubscriberInterface
{
    private ThrottleMiddleware $throttle;

    public function __construct(
        private Psr16Cache $cache,
        private ResponseFactoryInterface $responseFactory
    ) {
        $this->throttle = (new ThrottleMiddleware(
            limit: 100,
            windowPeriod: 3600,
            cache: $this->cache,
            responseFactory: $this->responseFactory
        ))->maybeBlacklist(5);
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 300]
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response();
            }
        };

        $response = $this->throttle->process($request, $handler);
        if ($response->getStatusCode() !== 200) {
            $event->setResponse($response);
        }
    }
}
```

#### WordPress REST API

```php
use Charon\ThrottleMiddleware;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;

// In your plugin file or functions.php
add_action('rest_api_init', function () {
    $cache = new Psr16Cache(new FilesystemAdapter());
    $psr17Factory = new Psr17Factory();
    $throttle = (new ThrottleMiddleware(
        limit: 100,
        windowPeriod: 3600,
        cache: $cache,
        responseFactory: $psr17Factory
    ))->maybeBlacklist(5);

    // Apply to all REST API endpoints
    add_filter('rest_pre_dispatch', function ($result, $server, $request) use ($throttle, $psr17Factory) {
        if (null !== $result) {
            return $result;
        }

        // Convert WordPress request to PSR-7
        $psrRequest = new ServerRequest(
            $request->get_method(),
            $request->get_route(),
            getallheaders(),
            null,
            '1.1',
            array_merge($_SERVER, ['REMOTE_ADDR' => $_SERVER['REMOTE_ADDR']])
        );

        // Handle rate limiting
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new Psr17Factory())->createResponse(200);
            }
        };

        $response = $throttle->process($psrRequest, $handler);
        
        // Check if request should be blocked
        if ($response->getStatusCode() !== 200) {
            return new WP_Error(
                'rest_throttled',
                $response->getReasonPhrase(),
                ['status' => $response->getStatusCode()]
            );
        }

        // Add rate limit headers to WordPress response
        add_filter('rest_post_dispatch', function ($response) use ($throttle) {
            if ($response instanceof WP_REST_Response) {
                foreach ($response->get_headers() as $key => $value) {
                    if (strpos($key, 'X-RateLimit') === 0) {
                        $response->header($key, $value);
                    }
                }
            }
            return $response;
        });

        return $result;
    }, 10, 3);
});
```

### Response Headers

The middleware emits both the IETF standard rate limit headers (draft-ietf-httpapi-ratelimit-headers) and the widely used `X-RateLimit-*` variants:

```http
RateLimit-Limit: 100
RateLimit-Remaining: 99
RateLimit-Reset: 3540
RateLimit-Policy: 100;w=3600
X-RateLimit-Limit: 100
X-RateLimit-Remaining: 99
X-RateLimit-Reset: 1635789600
```

Note that `RateLimit-Reset` is a delta in seconds, while `X-RateLimit-Reset` is a Unix timestamp.

### Error Responses

Throttled and blacklisted requests receive RFC 9457 `application/problem+json` responses.

When the rate limit is exceeded:

```http
Status: 429 Too Many Requests
Content-Type: application/problem+json
Retry-After: 3540
RateLimit-Limit: 100
RateLimit-Remaining: 0
RateLimit-Reset: 3540
RateLimit-Policy: 100;w=3600

{
    "type": "about:blank",
    "title": "Too Many Requests",
    "status": 429,
    "detail": "The client has sent too many requests in the configured time window.",
    "retry_after": 3540
}
```

When a blacklisted client attempts to access the resource:

```http
Status: 403 Forbidden
Content-Type: application/problem+json

{
    "type": "about:blank",
    "title": "Forbidden",
    "status": 403,
    "detail": "Access denied due to repeated rate limit violations."
}
```

## Logging

When logging is enabled, the middleware logs the following information:

### Rate Limit Exceeded (Warning Level)
```json
{
    "message": "Rate limit exceeded",
    "context": {
        "client": {
            "ip": "192.168.1.1",
            "user_agent": "Mozilla/5.0...",
            "method": "GET",
            "path": "/api/resource"
        },
        "requests": 101,
        "limit": 100,
        "reset_time": 1635789600
    }
}
```

### Client Blacklisted (Alert Level)
```json
{
    "message": "Client blacklisted due to recurring rate limit violations",
    "context": {
        "client": {
            "ip": "192.168.1.1",
            "user_agent": "Mozilla/5.0...",
            "method": "GET",
            "path": "/api/resource"
        },
        "violations": 5,
        "threshold": 5
    }
}
```

### Request Processed (Info Level, when logAllRequests is true)
```json
{
    "message": "Request processed",
    "context": {
        "client": {
            "ip": "192.168.1.1",
            "user_agent": "Mozilla/5.0...",
            "method": "GET",
            "path": "/api/resource"
        },
        "requests": 50,
        "limit": 100,
        "remaining": 50
    }
}
```

## Use Cases

- **API Rate Limiting**: Protect your API from abuse by limiting requests per client
- **Login Throttling**: Prevent brute force attacks by limiting login attempts
- **Resource Protection**: Protect expensive operations from overuse
- **DDoS Mitigation**: Basic protection against distributed denial of service attacks
- **Fair Usage**: Ensure fair resource distribution among clients
- **Abuse Prevention**: Automatically block repeat offenders with blacklisting

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## License

The GNU General Public License v3.0. Please see [License File](LICENSE) for more information.
