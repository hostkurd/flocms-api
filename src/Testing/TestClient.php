<?php
declare(strict_types=1);

namespace FloCMS\Api\Testing;

use FloCMS\Api\Kernel;
use FloCMS\Core\Http\Request;
use JsonException;

/**
 * Calls an API Kernel in-process, for application tests:
 *
 *     $client = new TestClient($kernel);
 *     $client->get('/api/v1/properties', ['status' => 'sale'])
 *         ->assertOk()
 *         ->assertJsonPath('meta.total', 3)
 *         ->assertJsonPath('data.0.title', 'Sea villa');
 *
 *     $client->withToken($token)->postJson('/api/v1/inquiries', ['email' => 'x'])
 *         ->assertValidationError('email');
 *
 * Clients are immutable: with*() return a copy.
 */
final class TestClient
{
    /** @var array<string, string> */
    private array $headers = [];

    /** @var array<string, string> */
    private array $cookies = [];

    /** @param array<string, mixed> $server extra $_SERVER values, e.g. REMOTE_ADDR */
    public function __construct(
        private readonly Kernel $kernel,
        private array $server = []
    ) {
    }

    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers[$name] = $value;

        return $clone;
    }

    /** @param array<string, string> $headers */
    public function withHeaders(array $headers): self
    {
        $clone = clone $this;
        foreach ($headers as $name => $value) {
            $clone->headers[$name] = $value;
        }

        return $clone;
    }

    public function withToken(string $token): self
    {
        return $this->withHeader('Authorization', 'Bearer ' . $token);
    }

    public function withApiKey(string $key, string $header = 'X-API-Key'): self
    {
        return $this->withHeader($header, $key);
    }

    public function withCookie(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->cookies[$name] = $value;

        return $clone;
    }

    public function fromIp(string $ip): self
    {
        $clone = clone $this;
        $clone->server['REMOTE_ADDR'] = $ip;

        return $clone;
    }

    /** @param array<string, mixed> $query */
    public function get(string $uri, array $query = []): TestResponse
    {
        return $this->send('GET', self::withQuery($uri, $query));
    }

    /** @param array<string, mixed> $query */
    public function head(string $uri, array $query = []): TestResponse
    {
        return $this->send('HEAD', self::withQuery($uri, $query));
    }

    public function options(string $uri): TestResponse
    {
        return $this->send('OPTIONS', $uri);
    }

    /** @param array<string, mixed> $data */
    public function postJson(string $uri, array $data = []): TestResponse
    {
        return $this->json('POST', $uri, $data);
    }

    /** @param array<string, mixed> $data */
    public function putJson(string $uri, array $data = []): TestResponse
    {
        return $this->json('PUT', $uri, $data);
    }

    /** @param array<string, mixed> $data */
    public function patchJson(string $uri, array $data = []): TestResponse
    {
        return $this->json('PATCH', $uri, $data);
    }

    public function delete(string $uri): TestResponse
    {
        return $this->send('DELETE', $uri);
    }

    /** @param array<string, mixed> $data */
    public function json(string $method, string $uri, array $data = []): TestResponse
    {
        try {
            $body = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $error) {
            throw new \InvalidArgumentException('Test data cannot be encoded as JSON.', 0, $error);
        }

        return $this->send($method, $uri, $body, ['Content-Type' => 'application/json', 'Accept' => 'application/json']);
    }

    /** Form fields as application/x-www-form-urlencoded. @param array<string, mixed> $fields */
    public function postForm(string $uri, array $fields): TestResponse
    {
        return $this->send('POST', $uri, http_build_query($fields), ['Content-Type' => 'application/x-www-form-urlencoded'], $fields);
    }

    /**
     * Send any request.
     *
     * @param array<string, string> $headers
     * @param array<string, mixed> $post parsed form fields ($_POST)
     * @param array<string, mixed> $files $_FILES-style uploads
     */
    public function send(
        string $method,
        string $uri,
        string $body = '',
        array $headers = [],
        array $post = [],
        array $files = []
    ): TestResponse {
        $server = $this->server + [
            'REQUEST_METHOD' => strtoupper($method),
            'REQUEST_URI' => $uri,
            'REMOTE_ADDR' => '127.0.0.1',
            'SERVER_PROTOCOL' => 'HTTP/1.1',
        ];
        $server['REQUEST_METHOD'] = strtoupper($method);
        $server['REQUEST_URI'] = $uri;
        $headers = $headers + $this->headers;
        if ($body !== '') {
            $headers['Content-Length'] ??= (string) strlen($body);
        }
        foreach ($headers as $name => $value) {
            $key = strtoupper(str_replace('-', '_', $name));
            $server[in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true) ? $key : 'HTTP_' . $key] = $value;
        }
        if ($this->cookies !== []) {
            $server['HTTP_COOKIE'] = http_build_query($this->cookies, '', '; ');
        }

        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
        $request = new Request($query, $post, $server, $files, $this->cookies, $body);

        return new TestResponse($this->kernel->handle($request));
    }

    /** @param array<string, mixed> $query */
    private static function withQuery(string $uri, array $query): string
    {
        if ($query === []) {
            return $uri;
        }

        return $uri . (str_contains($uri, '?') ? '&' : '?') . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
}
