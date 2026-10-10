<?php
declare(strict_types=1);

namespace FloCMS\Api\Resources;

use FloCMS\Core\Http\Request;

/**
 * Output that is shaped per request. Route handlers may return one; the
 * Kernel resolves it and wraps it in the standard success envelope.
 */
interface Resolvable
{
    /** The response data for $request. */
    public function resolve(Request $request): mixed;

    /** Extra top-level `meta` for the envelope. */
    public function meta(Request $request): array;

    /**
     * Relations clients may ask for with ?include=a,b. Requests for anything
     * else are a 422.
     *
     * @return list<string>
     */
    public function allowedIncludes(): array;
}
