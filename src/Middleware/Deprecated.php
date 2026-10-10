<?php
declare(strict_types=1);

namespace FloCMS\Api\Middleware;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use FloCMS\Api\Contracts\MiddlewareInterface;
use FloCMS\Api\Contracts\RequestHandlerInterface;
use FloCMS\Api\Exceptions\DecoratedException;
use FloCMS\Core\Http\Request;
use FloCMS\Core\Http\Response;
use InvalidArgumentException;
use Throwable;

/**
 * Marks old endpoints as deprecated (RFC 9745 Deprecation, RFC 8594 Sunset):
 *
 *     $router->version('v1', $routes, [new Deprecated(sunset: '2027-06-30', link: 'https://example.com/api/v2')]);
 *
 * Adds `Deprecation`, `Sunset` and `Link: <...>; rel="deprecation"` (plus
 * rel="successor-version" when given) to every response, errors included.
 */
final class Deprecated implements MiddlewareInterface
{
    /** @var array<string, string> */
    private readonly array $headers;

    /**
     * @param DateTimeInterface|string|null $since when the endpoint became deprecated (default: now)
     * @param DateTimeInterface|string|null $sunset when it will stop working
     * @param string|null $link documentation about the deprecation
     * @param string|null $successor the endpoint to use instead
     */
    public function __construct(
        DateTimeInterface|string|null $since = null,
        DateTimeInterface|string|null $sunset = null,
        ?string $link = null,
        ?string $successor = null
    ) {
        $headers = [
            // RFC 9745: "@" followed by a Unix timestamp; "true" when unknown.
            'Deprecation' => $since === null ? 'true' : '@' . self::date($since, 'since')->getTimestamp(),
        ];
        if ($sunset !== null) {
            $headers['Sunset'] = self::date($sunset, 'sunset')->setTimezone(new DateTimeZone('UTC'))->format('D, d M Y H:i:s') . ' GMT';
        }

        $links = [];
        foreach (['deprecation' => $link, 'successor-version' => $successor] as $relation => $target) {
            if ($target === null) {
                continue;
            }
            if (preg_match('/[\s<>"]/', $target) === 1 || $target === '') {
                throw new InvalidArgumentException('Invalid ' . $relation . ' link: ' . $target);
            }
            $links[] = '<' . $target . '>; rel="' . $relation . '"';
        }
        if ($links !== []) {
            $headers['Link'] = implode(', ', $links);
        }

        $this->headers = $headers;
    }

    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        try {
            $response = $handler->handle($request);
        } catch (Throwable $error) {
            throw new DecoratedException($error, $this->headers);
        }

        foreach ($this->headers as $name => $value) {
            $name === 'Link' ? $response->appendHeader($name, $value) : $response->header($name, $value);
        }

        return $response;
    }

    private static function date(DateTimeInterface|string $value, string $name): DateTimeImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }
        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Throwable) {
            throw new InvalidArgumentException('Invalid ' . $name . ' date: ' . $value);
        }
    }
}
