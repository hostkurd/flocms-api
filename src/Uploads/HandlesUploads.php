<?php
declare(strict_types=1);

namespace FloCMS\Api\Uploads;

use FloCMS\Api\Exceptions\ApiException;
use FloCMS\Api\Exceptions\ValidationException;
use FloCMS\Api\ResponseFactory;
use FloCMS\Core\Http\Request;
use FloCMS\Core\Http\Response;
use FloCMS\Uploader\Chunked\ChunkedUploader;
use FloCMS\Uploader\Exceptions\ChunkOffsetException;
use FloCMS\Uploader\Exceptions\ValidationException as UploadValidationException;
use FloCMS\Uploader\UploadResult;
use FloCMS\Uploader\Uploader;
use RuntimeException;

/**
 * File uploads on API routes with hostkurd/flocms-uploader (1.2+ for chunked
 * uploads), answered with the standard JSON envelopes. Uploader validation
 * errors become 422s; storage errors stay 500s.
 *
 * Single request (multipart/form-data):
 *
 *     $router->post('/v1/images', [ImagesController::class, 'store'])
 *         ->middleware(new RequireJsonMiddleware(allowMultipart: true));
 *
 *     public function store(Request $request): Response
 *     {
 *         return $this->storeUpload($request, Uploader::image()->to('properties'), 'image');
 *     }
 *
 * Chunked and resumable, for files larger than upload_max_filesize:
 *
 *     POST   /v1/uploads            {"filename": "tour.mp4", "total_bytes": 73400320}  -> 201 {upload_id, chunk_size, ...}
 *     PATCH  /v1/uploads/{id}       raw chunk body, Upload-Offset: <bytes received>    -> 200 {received_bytes, ...}
 *     GET    /v1/uploads/{id}       resume: where to continue                           -> 200 {received_bytes, ...}
 *     POST   /v1/uploads/{id}/complete                                                  -> 201 file
 *     DELETE /v1/uploads/{id}                                                           -> 204
 *
 * A chunk sent at the wrong offset is a 409 with the expected offset in
 * Upload-Offset. Pass $owner (e.g. the identity id) to bind uploads to the
 * user who started them.
 */
trait HandlesUploads
{
    protected function storeUpload(Request $request, Uploader $uploader, string $field = 'file'): Response
    {
        $file = $request->file($field);
        if ($file === null) {
            throw new ValidationException([$field => ['A file is required.']]);
        }

        return $this->uploadCall(
            fn (): Response => ResponseFactory::success($this->uploadPayload($uploader->upload($file)), 201),
            $field
        );
    }

    protected function startChunkedUpload(Request $request, ChunkedUploader $chunks, ?string $owner = null): Response
    {
        $filename = $request->input('filename');
        $total = $request->input('total_bytes');
        $errors = [];
        if (!is_string($filename) || trim($filename) === '') {
            $errors['filename'] = ['filename is required.'];
        }
        if (filter_var($total, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            $errors['total_bytes'] = ['total_bytes must be a positive integer.'];
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $this->uploadCall(
            fn (): Response => ResponseFactory::success($chunks->start($filename, (int) $total, $owner), 201)
        );
    }

    /**
     * Append the request body (or a multipart field named "chunk") at the
     * offset given in the Upload-Offset header or an "offset" field.
     */
    protected function appendChunk(Request $request, ChunkedUploader $chunks, string $uploadId, ?string $owner = null): Response
    {
        $offset = $request->header('Upload-Offset') ?? $request->input('offset');
        if (filter_var($offset, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false) {
            throw new ValidationException(['offset' => ['Send the byte offset in the Upload-Offset header.']]);
        }

        $chunk = $request->file('chunk');
        $stream = null;
        if ($chunk === null) {
            $stream = fopen('php://temp', 'w+b');
            if ($stream === false) {
                throw new RuntimeException('Unable to buffer the chunk.');
            }
            fwrite($stream, $request->rawBody());
            rewind($stream);
            $chunk = $stream;
        }

        try {
            return $this->uploadCall(fn (): Response => ResponseFactory::success(
                $chunks->append($uploadId, (int) $offset, $chunk, $owner)
            ));
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    protected function chunkedUploadStatus(ChunkedUploader $chunks, string $uploadId, ?string $owner = null): Response
    {
        return $this->uploadCall(fn (): Response => ResponseFactory::success($chunks->status($uploadId, $owner)));
    }

    protected function completeChunkedUpload(ChunkedUploader $chunks, string $uploadId, ?string $owner = null, ?string $directory = null): Response
    {
        return $this->uploadCall(fn (): Response => ResponseFactory::success(
            $this->uploadPayload($chunks->complete($uploadId, $owner, $directory)),
            201
        ));
    }

    protected function abortChunkedUpload(ChunkedUploader $chunks, string $uploadId, ?string $owner = null): Response
    {
        return $this->uploadCall(static function () use ($chunks, $uploadId, $owner): Response {
            $chunks->abort($uploadId, $owner);

            return Response::noContent();
        });
    }

    /**
     * What the client sees of a stored file. Server paths are left out;
     * override to add or rename fields.
     *
     * @return array<string, mixed>
     */
    protected function uploadPayload(UploadResult $result): array
    {
        $data = $result->toArray();
        unset($data['disk']);

        return self::withoutServerPaths($data);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function withoutServerPaths(array $data): array
    {
        unset($data['absolute_path']);
        foreach ($data as $key => $value) {
            if ($value instanceof UploadResult) {
                $value = $value->toArray();
                unset($value['disk']);
            }
            if (is_array($value)) {
                $data[$key] = self::withoutServerPaths($value);
            }
        }

        return $data;
    }

    /** @param callable(): Response $call */
    private function uploadCall(callable $call, string $field = 'file'): Response
    {
        try {
            return $call();
        } catch (ChunkOffsetException $error) {
            throw new ApiException(
                $error->getMessage(),
                409,
                ['expected_offset' => $error->expectedOffset()],
                ['Upload-Offset' => (string) $error->expectedOffset()]
            );
        } catch (UploadValidationException $error) {
            if ($error->getMessage() === 'Unknown or expired upload.') {
                throw new ApiException('Unknown or expired upload.', 404);
            }
            throw new ValidationException([$field => [$error->getMessage()]]);
        }
    }
}
