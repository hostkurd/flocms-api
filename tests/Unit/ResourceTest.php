<?php
declare(strict_types=1);

namespace FloCMS\Api\Tests\Unit;

use FloCMS\Api\Kernel;
use FloCMS\Api\Resources\JsonResource;
use FloCMS\Api\Router;
use FloCMS\Core\Http\Request;
use PHPUnit\Framework\TestCase;

final class ResourceTest extends TestCase
{
    use MakesRequests;

    private const ROW = [
        'id' => '7',
        'title' => 'Sea villa',
        'price' => '250000.00',
        'slug' => 'sea-villa',
        'created_at' => '2026-10-01 09:30:00',
        'owner_phone' => '+964 750 000 0000',
        'internal_notes' => 'motivated seller',
    ];

    public function testResourceHidesColumnsAndFormatsFields(): void
    {
        $data = $this->payload($this->kernel()->handle($this->request('GET', '/v1/properties/7')))['data'];

        self::assertSame(7, $data['id']);
        self::assertEquals(250000, $data['price']);
        self::assertSame('/properties/sea-villa', $data['url']);
        self::assertStringStartsWith('2026-10-01T09:30:00', $data['created_at']);
        self::assertArrayNotHasKey('owner_phone', $data);
        self::assertArrayNotHasKey('internal_notes', $data);
        self::assertArrayNotHasKey('notes', $data, 'when() left the field out');
        self::assertArrayNotHasKey('images', $data);
    }

    public function testConditionalFieldsAndIncludes(): void
    {
        $response = $this->kernel()->handle($this->request('GET', '/v1/properties/7?include=images&staff=1'));
        $data = $this->payload($response)['data'];

        self::assertSame('motivated seller', $data['notes']);
        self::assertSame([['url' => '/img/1.jpg'], ['url' => '/img/2.jpg']], $data['images']);
    }

    public function testUnknownIncludeIsA422(): void
    {
        $response = $this->kernel()->handle($this->request('GET', '/v1/properties/7?include=images,owner'));

        self::assertSame(422, $response->status());
        self::assertSame('Unknown include: owner.', $this->payload($response)['message']);
    }

    public function testCollectionWithObjectsAndMeta(): void
    {
        $router = new Router();
        $router->get('/v1/properties', static fn () => PropertyResource::collection([
            (object) self::ROW,
            (object) (['id' => '8', 'slug' => 'river-house'] + self::ROW),
        ])->withMeta(['source' => 'test']));

        $payload = $this->payload((new Kernel($router))->handle($this->request('GET', '/v1/properties?include=images')));

        self::assertSame([7, 8], array_column($payload['data'], 'id'));
        self::assertSame('/properties/river-house', $payload['data'][1]['url']);
        self::assertCount(2, $payload['data'][0]['images']);
        self::assertSame(['source' => 'test'], $payload['meta']);
    }

    public function testResponseHelperWithStatus(): void
    {
        $response = PropertyResource::make(self::ROW)->response($this->request('POST', '/v1/properties'), 201);

        self::assertSame(201, $response->status());
        self::assertSame(7, $this->payload($response)['data']['id']);
    }

    private function kernel(): Kernel
    {
        $router = new Router();
        $router->get('/v1/properties/{id:\d+}', static fn () => PropertyResource::make(self::ROW));

        return new Kernel($router);
    }
}

final class PropertyResource extends JsonResource
{
    protected array $includes = ['images'];

    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'title' => $this->title,
            'price' => (float) $this->price,
            'url' => '/properties/' . $this->slug,
            'created_at' => $this->date($this->created_at),
            'notes' => $this->when($request->queryValue('staff') === '1', fn () => $this->internal_notes),
            'images' => $this->whenIncluded($request, 'images', fn () => ImageResource::collection([
                ['url' => '/img/1.jpg', 'path' => '/srv/1.jpg'],
                ['url' => '/img/2.jpg', 'path' => '/srv/2.jpg'],
            ])),
        ];
    }
}

final class ImageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['url' => $this->url];
    }
}
