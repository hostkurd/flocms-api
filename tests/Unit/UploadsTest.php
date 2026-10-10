<?php
declare(strict_types=1);

namespace FloCMS\Api\Tests\Unit;

use FloCMS\Api\Controller;
use FloCMS\Api\Kernel;
use FloCMS\Api\Router;
use FloCMS\Api\Uploads\HandlesUploads;
use FloCMS\Core\Http\Request;
use FloCMS\Core\Http\Response;
use FloCMS\Uploader\Uploader;
use PHPUnit\Framework\TestCase;

final class UploadsTest extends TestCase
{
    use MakesRequests;

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/flo-api-uploads-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/public', 0777, true);
        mkdir($this->root . '/chunks', 0777, true);
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    public function testSingleUpload(): void
    {
        $tmp = $this->root . '/upload.tmp';
        file_put_contents($tmp, "hello,world\n");
        $request = new Request([], [], ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/v1/files'], [
            'file' => ['name' => 'report.csv', 'type' => 'text/csv', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => 12],
        ], []);

        $response = $this->kernel()->handle($request);
        $data = $this->payload($response)['data'];

        self::assertSame(201, $response->status());
        self::assertSame('report.csv', $data['original_name']);
        self::assertStringStartsWith('/uploads/docs/', $data['url']);
        self::assertArrayNotHasKey('absolute_path', $data);
        self::assertArrayNotHasKey('disk', $data);
        self::assertStringNotContainsString($this->root, $response->body());
    }

    public function testUploadValidationErrorsAre422(): void
    {
        $tmp = $this->root . '/evil.tmp';
        file_put_contents($tmp, '<?php echo 1;');
        $kernel = $this->kernel();

        $missing = $kernel->handle($this->request('POST', '/v1/files'));
        self::assertSame(422, $missing->status());
        self::assertSame(['A file is required.'], $this->payload($missing)['errors']['file']);

        $request = new Request([], [], ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/v1/files'], [
            'file' => ['name' => 'shell.php', 'type' => 'text/plain', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => 13],
        ], []);
        $blocked = $kernel->handle($request);
        self::assertSame(422, $blocked->status());
        self::assertStringContainsString('blocked', $this->payload($blocked)['errors']['file'][0]);
    }

    public function testChunkedUploadProtocol(): void
    {
        $kernel = $this->kernel();
        $content = str_repeat('0123456789', 5) . "\n";

        $start = $kernel->handle($this->request('POST', '/v1/uploads', ['Content-Type' => 'application/json', 'X-User' => '7'], json_encode(['filename' => 'data.csv', 'total_bytes' => strlen($content)])));
        self::assertSame(201, $start->status(), $start->body());
        $id = $this->payload($start)['data']['upload_id'];

        $first = $kernel->handle($this->request('PATCH', '/v1/uploads/' . $id, ['Upload-Offset' => '0', 'X-User' => '7'], substr($content, 0, 20)));
        self::assertSame(20, $this->payload($first)['data']['received_bytes']);

        $wrong = $kernel->handle($this->request('PATCH', '/v1/uploads/' . $id, ['Upload-Offset' => '0', 'X-User' => '7'], 'xxxx'));
        self::assertSame(409, $wrong->status());
        self::assertSame('20', $wrong->headers()['Upload-Offset']);
        self::assertSame(20, $this->payload($wrong)['errors']['expected_offset']);

        // Another user cannot see or continue this upload.
        self::assertSame(404, $kernel->handle($this->request('GET', '/v1/uploads/' . $id, ['X-User' => '8']))->status());

        $status = $kernel->handle($this->request('GET', '/v1/uploads/' . $id, ['X-User' => '7']));
        self::assertSame(20, $this->payload($status)['data']['received_bytes']);

        $kernel->handle($this->request('PATCH', '/v1/uploads/' . $id, ['Upload-Offset' => '20', 'X-User' => '7'], substr($content, 20)));
        $complete = $kernel->handle($this->request('POST', '/v1/uploads/' . $id . '/complete', ['X-User' => '7']));
        self::assertSame(201, $complete->status(), $complete->body());
        $file = $this->payload($complete)['data'];
        self::assertSame(strlen($content), $file['size']);
        self::assertSame($content, file_get_contents($this->root . '/public/' . $file['path']));

        self::assertSame(404, $kernel->handle($this->request('GET', '/v1/uploads/' . $id, ['X-User' => '7']))->status());
    }

    public function testAbortAndBadStart(): void
    {
        $kernel = $this->kernel();

        $bad = $kernel->handle($this->request('POST', '/v1/uploads', ['Content-Type' => 'application/json'], '{"filename":"","total_bytes":0}'));
        self::assertSame(422, $bad->status());
        self::assertSame(['filename', 'total_bytes'], array_keys($this->payload($bad)['errors']));

        $blocked = $kernel->handle($this->request('POST', '/v1/uploads', ['Content-Type' => 'application/json'], '{"filename":"x.php","total_bytes":10}'));
        self::assertSame(422, $blocked->status());

        $start = $kernel->handle($this->request('POST', '/v1/uploads', ['Content-Type' => 'application/json'], '{"filename":"a.csv","total_bytes":10}'));
        $id = $this->payload($start)['data']['upload_id'];
        self::assertSame(204, $kernel->handle($this->request('DELETE', '/v1/uploads/' . $id))->status());
        self::assertSame(404, $kernel->handle($this->request('GET', '/v1/uploads/' . $id))->status());
    }

    private function kernel(): Kernel
    {
        $config = [
            'default_disk' => 'public',
            'disks' => ['public' => ['driver' => 'local', 'root' => $this->root . '/public', 'url' => '/uploads', 'visibility' => 'public']],
            'chunks' => ['directory' => $this->root . '/chunks'],
        ];
        $controller = new class($config) extends Controller {
            use HandlesUploads;

            /** @param array<string, mixed> $config */
            public function __construct(private readonly array $config)
            {
            }

            public function store(Request $request): Response
            {
                return $this->storeUpload($request, (new Uploader($this->config))->to('docs')->allowExtensions(['csv', 'txt', 'php']));
            }

            public function start(Request $request): Response
            {
                return $this->startChunkedUpload($request, $this->chunks(), $request->header('X-User'));
            }

            public function append(Request $request, string $id): Response
            {
                return $this->appendChunk($request, $this->chunks(), $id, $request->header('X-User'));
            }

            public function status(Request $request, string $id): Response
            {
                return $this->chunkedUploadStatus($this->chunks(), $id, $request->header('X-User'));
            }

            public function complete(Request $request, string $id): Response
            {
                return $this->completeChunkedUpload($this->chunks(), $id, $request->header('X-User'));
            }

            public function abort(Request $request, string $id): Response
            {
                return $this->abortChunkedUpload($this->chunks(), $id, $request->header('X-User'));
            }

            private function chunks(): \FloCMS\Uploader\Chunked\ChunkedUploader
            {
                return (new Uploader($this->config))->to('large')->allowExtensions(['csv'])->chunked();
            }
        };

        $router = new Router();
        $router->post('/v1/files', [$controller, 'store']);
        $router->post('/v1/uploads', [$controller, 'start']);
        $router->patch('/v1/uploads/{id}', [$controller, 'append']);
        $router->get('/v1/uploads/{id}', [$controller, 'status']);
        $router->post('/v1/uploads/{id}/complete', [$controller, 'complete']);
        $router->delete('/v1/uploads/{id}', [$controller, 'abort']);

        return new Kernel($router);
    }
}
