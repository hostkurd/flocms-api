<?php
declare(strict_types=1);

namespace FloCMS\Api;

use FloCMS\Api\Exceptions\ApiException;
use FloCMS\Api\Exceptions\ValidationException;
use FloCMS\Core\Http\Request;
use FloCMS\Core\Http\Response;

abstract class Controller
{
    protected function success(
        mixed $data = null,
        int $status = 200,
        array $meta = []
    ): Response {
        return ResponseFactory::success($data, $status, $meta);
    }

    protected function created(mixed $data = null): Response
    {
        return ResponseFactory::success($data, 201);
    }

    protected function noContent(): Response
    {
        return Response::noContent();
    }

    /** @param array<string, mixed> $errors */
    protected function fail(
        string $message,
        int $status = 400,
        array $errors = []
    ): never {
        throw new ApiException($message, $status, $errors);
    }

    /** @param array<string, mixed> $errors */
    protected function invalid(array $errors, string $message = 'Validation failed.'): never
    {
        throw new ValidationException($errors, $message);
    }

    protected function routeId(Request $request, string $name = 'id'): int
    {
        $id = filter_var(
            $request->route($name),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        if ($id === false) {
            $this->invalid([$name => 'A valid positive integer is required.']);
        }

        return (int) $id;
    }
}
