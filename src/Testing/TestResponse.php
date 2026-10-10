<?php
declare(strict_types=1);

namespace FloCMS\Api\Testing;

use AssertionError;
use FloCMS\Core\Http\Response;
use PHPUnit\Framework\Assert;

/**
 * A response from TestClient with fluent assertions. Uses PHPUnit's Assert
 * when PHPUnit is installed (failures show up as normal test failures),
 * otherwise throws AssertionError.
 */
final class TestResponse
{
    private mixed $decoded = null;
    private bool $isDecoded = false;

    public function __construct(private readonly Response $response)
    {
    }

    public function response(): Response
    {
        return $this->response;
    }

    public function status(): int
    {
        return $this->response->status();
    }

    public function body(): string
    {
        return $this->response->body();
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->response->headers();
    }

    /** Header value by case-insensitive name, or null. */
    public function header(string $name): ?string
    {
        foreach ($this->response->headers() as $existing => $value) {
            if (strcasecmp($existing, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    /**
     * The decoded JSON body, or the value at a dot path ("data.0.id").
     */
    public function json(?string $path = null): mixed
    {
        if (!$this->isDecoded) {
            $this->isDecoded = true;
            $this->decoded = json_decode($this->body(), true);
        }
        if ($path === null) {
            return $this->decoded;
        }

        [$found, $value] = $this->find($path);

        return $found ? $value : null;
    }

    public function assertStatus(int $status): self
    {
        $this->check(
            $this->status() === $status,
            sprintf('Expected status %d, got %d. Body: %s', $status, $this->status(), self::excerpt($this->body()))
        );

        return $this;
    }

    public function assertOk(): self
    {
        return $this->assertStatus(200);
    }

    public function assertCreated(): self
    {
        return $this->assertStatus(201);
    }

    public function assertNoContent(): self
    {
        return $this->assertStatus(204);
    }

    public function assertUnauthorized(): self
    {
        return $this->assertStatus(401);
    }

    public function assertForbidden(): self
    {
        return $this->assertStatus(403);
    }

    public function assertNotFound(): self
    {
        return $this->assertStatus(404);
    }

    public function assertUnprocessable(): self
    {
        return $this->assertStatus(422);
    }

    public function assertHeader(string $name, ?string $value = null): self
    {
        $actual = $this->header($name);
        $this->check($actual !== null, sprintf('Expected header %s to be present.', $name));
        if ($value !== null) {
            $this->check($actual === $value, sprintf('Expected header %s to be "%s", got "%s".', $name, $value, (string) $actual));
        }

        return $this;
    }

    public function assertHeaderMissing(string $name): self
    {
        $this->check($this->header($name) === null, sprintf('Expected header %s to be absent.', $name));

        return $this;
    }

    /** Strict comparison of the value at a dot path ("data.0.id", "meta.total"). */
    public function assertJsonPath(string $path, mixed $expected): self
    {
        [$found, $value] = $this->find($path);
        $this->check($found, sprintf('JSON path "%s" not found in %s', $path, self::excerpt($this->body())));
        if (class_exists(Assert::class)) {
            Assert::assertSame($expected, $value, sprintf('Unexpected value at JSON path "%s".', $path));
        } else {
            $this->check($value === $expected, sprintf('Unexpected value at JSON path "%s".', $path));
        }

        return $this;
    }

    public function assertJsonMissingPath(string $path): self
    {
        $this->check(!$this->find($path)[0], sprintf('JSON path "%s" should not exist.', $path));

        return $this;
    }

    public function assertJsonCount(int $count, ?string $path = 'data'): self
    {
        $value = $this->json($path);
        $this->check(is_array($value), sprintf('JSON path "%s" is not an array.', (string) $path));
        $this->check(count($value) === $count, sprintf('Expected %d items at "%s", got %d.', $count, (string) $path, count($value)));

        return $this;
    }

    /** A 422 with an error for $field (and optionally a message containing $message). */
    public function assertValidationError(string $field, ?string $message = null): self
    {
        $this->assertStatus(422);
        $errors = $this->json('errors');
        $this->check(is_array($errors) && array_key_exists($field, $errors), sprintf('Expected a validation error for "%s". Errors: %s', $field, json_encode($errors)));
        if ($message !== null) {
            $messages = (array) $errors[$field];
            $this->check(
                (bool) array_filter($messages, static fn ($text): bool => is_string($text) && str_contains($text, $message)),
                sprintf('No "%s" error contains "%s": %s', $field, $message, json_encode($messages))
            );
        }

        return $this;
    }

    /** @return array{0: bool, 1: mixed} */
    private function find(string $path): array
    {
        $value = $this->json();
        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return [false, null];
            }
            $value = $value[$segment];
        }

        return [true, $value];
    }

    private function check(bool $condition, string $message): void
    {
        if (class_exists(Assert::class)) {
            Assert::assertTrue($condition, $message);

            return;
        }
        if (!$condition) {
            throw new AssertionError($message);
        }
    }

    private static function excerpt(string $body): string
    {
        return strlen($body) > 500 ? substr($body, 0, 500) . '...' : $body;
    }
}
