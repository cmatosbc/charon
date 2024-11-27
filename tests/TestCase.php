<?php

namespace Charon\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Response;

abstract class TestCase extends BaseTestCase
{
    protected function createRequest(
        string $method = 'GET',
        string $uri = '/',
        array $headers = [],
        array $serverParams = []
    ): ServerRequestInterface {
        $defaultServerParams = [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_USER_AGENT' => 'PHPUnit Test Agent'
        ];

        return new ServerRequest(
            $method,
            $uri,
            $headers,
            null,
            '1.1',
            array_merge($defaultServerParams, $serverParams)
        );
    }

    protected function createHandler(int $status = 200, array $headers = []): RequestHandlerInterface
    {
        return new class($status, $headers) implements RequestHandlerInterface {
            private $status;
            private $headers;

            public function __construct(int $status, array $headers)
            {
                $this->status = $status;
                $this->headers = $headers;
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response($this->status, $this->headers);
            }
        };
    }
}
