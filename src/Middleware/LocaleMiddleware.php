<?php
declare(strict_types=1);

namespace FloCMS\Api\Middleware;

use Closure;
use FloCMS\Api\Contracts\MiddlewareInterface;
use FloCMS\Api\Contracts\RequestHandlerInterface;
use FloCMS\Api\Support\ErrorRenderer;
use FloCMS\Core\Http\Request;
use FloCMS\Core\Http\Response;
use FloCMS\Core\Lang;
use InvalidArgumentException;

/**
 * Picks the response language: ?lang=, then Accept-Language (with q-values
 * and region fallback, so "ar-IQ" finds "ar"), then the default. Only
 * configured languages are used.
 *
 * The locale is stored in the request attribute 'locale'. By default, inside
 * a FloCMS app (ROOT defined), the language file is loaded with Lang::load()
 * and ACTIVE_LANG is defined if it is not yet. Responses get
 * Content-Language and Vary: Accept-Language.
 *
 *     new LocaleMiddleware(Config::get('languages'), Config::get('default_language'))
 */
final class LocaleMiddleware implements MiddlewareInterface
{
    /** @var list<string> */
    private readonly array $languages;

    private readonly string $default;

    private readonly ?Closure $loader;

    /**
     * @param list<string> $languages e.g. ['en', 'ar', 'ku']
     * @param null|callable(string): void $loader replaces the default Lang::load() call
     */
    public function __construct(
        array $languages,
        ?string $default = null,
        ?callable $loader = null,
        private readonly string $queryKey = 'lang'
    ) {
        $normalized = [];
        foreach ($languages as $language) {
            $language = self::normalize((string) $language);
            if ($language !== '') {
                $normalized[] = $language;
            }
        }
        if ($normalized === []) {
            throw new InvalidArgumentException('At least one language is required.');
        }

        $default = self::normalize((string) $default);
        $this->default = in_array($default, $normalized, true) ? $default : $normalized[0];
        $this->languages = array_values(array_unique($normalized));
        $this->loader = $loader !== null ? Closure::fromCallable($loader) : null;
    }

    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        $locale = $this->resolve($request);
        $this->load($locale);

        $response = $handler->handle($request->withAttribute('locale', $locale));
        $response->header('Content-Language', $locale);

        return ErrorRenderer::appendVary($response, 'Accept-Language');
    }

    public function resolve(Request $request): string
    {
        $requested = $request->queryValue($this->queryKey);
        if (is_string($requested)) {
            $match = $this->match(self::normalize($requested));
            if ($match !== null) {
                return $match;
            }
        }

        foreach (self::acceptLanguage((string) $request->header('Accept-Language', '')) as $tag) {
            $match = $this->match($tag);
            if ($match !== null) {
                return $match;
            }
        }

        return $this->default;
    }

    private function match(string $tag): ?string
    {
        if ($tag === '') {
            return null;
        }
        if (in_array($tag, $this->languages, true)) {
            return $tag;
        }
        $primary = explode('-', $tag)[0];

        return in_array($primary, $this->languages, true) ? $primary : null;
    }

    /**
     * Language tags in preference order, without q=0 entries.
     *
     * @return list<string>
     */
    private static function acceptLanguage(string $header): array
    {
        $tags = [];
        foreach (array_slice(explode(',', $header), 0, 20) as $position => $part) {
            $pieces = array_map('trim', explode(';', $part));
            $tag = self::normalize($pieces[0]);
            if ($tag === '' || $tag === '*') {
                continue;
            }
            $quality = 1.0;
            foreach (array_slice($pieces, 1) as $parameter) {
                if (preg_match('/^q=([01](?:\.\d{0,3})?)$/i', $parameter, $matches) === 1) {
                    $quality = (float) $matches[1];
                }
            }
            if ($quality > 0) {
                $tags[] = [$tag, $quality, $position];
            }
        }
        usort($tags, static fn (array $a, array $b): int => [$b[1], $a[2]] <=> [$a[1], $b[2]]);

        return array_column($tags, 0);
    }

    private static function normalize(string $tag): string
    {
        $tag = strtolower(str_replace('_', '-', trim($tag)));

        return preg_match('/^(\*|[a-z]{2,8}(-[a-z0-9]{1,8})*)$/', $tag) === 1 ? $tag : '';
    }

    private function load(string $locale): void
    {
        if ($this->loader !== null) {
            ($this->loader)($locale);

            return;
        }
        if (defined('ROOT')) {
            Lang::load($locale);
            if (!defined('ACTIVE_LANG')) {
                define('ACTIVE_LANG', $locale);
            }
        }
    }
}
