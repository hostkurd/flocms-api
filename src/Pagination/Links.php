<?php
declare(strict_types=1);

namespace FloCMS\Api\Pagination;

use FloCMS\Core\Http\Request;

/**
 * Builds pagination links from the current path and query string. Links are
 * relative to the host ("/api/v1/properties?page=2"), so they never depend on
 * a client-supplied Host header.
 *
 * @internal
 */
final class Links
{
    /** @param array<string, string|int|null> $changes null removes a parameter */
    public static function to(Request $request, array $changes): string
    {
        $query = $request->query();
        foreach ($changes as $key => $value) {
            if ($value === null) {
                unset($query[$key]);
            } else {
                $query[$key] = (string) $value;
            }
        }
        ksort($query);
        $string = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        return $request->path() . ($string !== '' ? '?' . $string : '');
    }
}
