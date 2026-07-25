<?php
declare(strict_types=1);

namespace FloCMS\Api;

use FloCMS\Core\Http\Response;

final class ResponseFactory
{
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

        return Response::json($payload, $status);
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

        return Response::json($payload, $status);
    }
}
