<?php
declare(strict_types=1);

namespace FloCMS\Api;

use FloCMS\Core\Http\Response;
use JsonException;
use RuntimeException;

final class ResponseFactory
{
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    /** Responses encoded with substituted invalid UTF-8 since the last take. */
    private static int $invalidUtf8 = 0;

    /** @param array<string, mixed> $meta */
    public static function success(
        mixed $data = null,
        int $status = 200,
        array $meta = []
    ): Response {
        $payload = [
            'success' => true,
            'data' => $data,
        ];

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return self::json($payload, $status);
    }

    /** @param array<string, mixed> $errors */
    public static function error(
        string $message,
        int $status,
        array $errors = [],
        ?string $requestId = null
    ): Response {
        $payload = [
            'success' => false,
            'message' => $message,
        ];

        if ($errors !== []) {
            $payload['errors'] = $errors;
        }
        if ($requestId !== null) {
            $payload['request_id'] = $requestId;
        }

        return self::json($payload, $status);
    }

    /**
     * Returns how many responses had invalid UTF-8 replaced by U+FFFD since
     * the last call, and resets the count. Used by the Kernel to log a warning.
     *
     * @internal
     */
    public static function takeInvalidUtf8Count(): int
    {
        $count = self::$invalidUtf8;
        self::$invalidUtf8 = 0;

        return $count;
    }

    /**
     * Like core Response::json(), but one bad byte from the database does not
     * turn the response into a 500: invalid UTF-8 is replaced by U+FFFD.
     */
    private static function json(mixed $payload, int $status): Response
    {
        try {
            $body = json_encode($payload, self::JSON_FLAGS);
        } catch (JsonException $error) {
            if ($error->getCode() !== JSON_ERROR_UTF8) {
                throw new RuntimeException('Unable to encode JSON response.', 0, $error);
            }

            try {
                $body = json_encode($payload, self::JSON_FLAGS | JSON_INVALID_UTF8_SUBSTITUTE);
            } catch (JsonException $retryError) {
                throw new RuntimeException('Unable to encode JSON response.', 0, $retryError);
            }
            self::$invalidUtf8++;
        }

        return Response::make($body, $status)
            ->header('Content-Type', 'application/json; charset=utf-8');
    }
}
