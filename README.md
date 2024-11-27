# Charon

A simple yet powerful PSR-15 compliant rate limiting middleware for PHP applications. Charon provides an effective way to protect your applications from abuse through configurable request throttling.

[![Software License](https://img.shields.io/badge/license-GPL--3.0-brightgreen.svg?style=flat-square)](LICENSE)

## Features

- 🚀 PSR-15 Middleware compliant
- 💾 PSR-16 Simple Cache support for storage
- 📝 Optional PSR-3 Logger integration
- ⚡ Efficient rate limiting using sliding window
- 🔒 IP and User-Agent based throttling
- 🎯 Configurable rate limits and time windows
- 📊 Standard rate limit headers (X-RateLimit-*)
- 🚫 Automatic blacklisting for repeat offenders

## Installation

You can install the package via composer:

```bash
composer require cmatosbc/charon
```

## Usage

### Basic Usage

```php
use Charon\ThrottleMiddleware;

// Create the middleware with basic configuration
$middleware = new ThrottleMiddleware(
    limit: 100,           // Maximum requests allowed
    windowPeriod: 3600,   // Time window in seconds (1 hour)
    cache: $cacheImpl     // PSR-16 cache implementation
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
    logger: $logger
);

// Blacklist clients after 5 rate limit violations
$middleware->maybeBlacklist(5);
```

When blacklisting is enabled:
- Clients exceeding rate limits multiple times will be tracked
- After reaching the specified number of violations, the client will be blacklisted
- Blacklisted clients receive a 403 Forbidden response
- Violations are tracked across multiple time windows
- Blacklist status is stored in cache with client signature

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
    cache: $cache
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
            cache: app()->make('cache.store')
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
        private Psr16Cache $cache
    ) {
        $this->throttle = (new ThrottleMiddleware(
            limit: 100,
            windowPeriod: 3600,
            cache: $this->cache
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
    $throttle = (new ThrottleMiddleware(
        limit: 100,
        windowPeriod: 3600,
        cache: $cache
    ))->maybeBlacklist(5);

    // Apply to all REST API endpoints
    add_filter('rest_pre_dispatch', function ($result, $server, $request) use ($throttle) {
        if (null !== $result) {
            return $result;
        }

        // Convert WordPress request to PSR-7
        $psr17Factory = new Psr17Factory();
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

The middleware adds standard rate limit headers to responses:

```http
X-RateLimit-Limit: 100
X-RateLimit-Remaining: 99
X-RateLimit-Reset: 1635789600
```

When the rate limit is exceeded, a 429 (Too Many Requests) response is returned with:

```http
Status: 429 Too Many Requests
Retry-After: 3600
X-RateLimit-Limit: 100
X-RateLimit-Remaining: 0
X-RateLimit-Reset: 1635789600
```

When a blacklisted client attempts to access the resource:

```http
Status: 403 Forbidden
Content-Type: application/json

{
    "error": "Access denied due to repeated rate limit violations"
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
