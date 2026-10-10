<?php
declare(strict_types=1);

namespace FloCMS\Api\Tests\Unit;

use FloCMS\Core\Http\Request;
use FloCMS\Core\Http\Response;

trait MakesRequests
{
    /** @param array<string, string> $headers e.g. ['Origin' => 'https://app.test'] */
    private function request(
        string $method,
        string $uri,
        array $headers = [],
        string $body = '',
        string $remoteAddr = '127.0.0.1'
    ): Request {
        $server = [
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => $uri,
            'REMOTE_ADDR' => $remoteAddr,
        ];
        foreach ($headers as $name => $value) {
            $key = strtoupper(str_replace('-', '_', $name));
            if ($key !== 'CONTENT_TYPE' && $key !== 'CONTENT_LENGTH') {
                $key = 'HTTP_' . $key;
            }
            $server[$key] = $value;
        }

        return new Request([], [], $server, [], [], $body);
    }

    /** @return array<string, mixed> */
    private function payload(Response $response): array
    {
        return json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
    }
}
