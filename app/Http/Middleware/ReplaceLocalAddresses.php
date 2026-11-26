<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pictures saved while the site was run from a developer's computer carry that computer's address
 * (http://localhost:8000/storage/...), which no visitor's browser can open. This swaps such an address in an API
 * answer for the address the API is really served from, so those pictures show up on the live site.
 * Does nothing while the API itself is served from a local address.
 */
class ReplaceLocalAddresses
{
    /** A local address as written in JSON text, with or without escaped slashes (\x5c is a backslash). */
    private const LOCAL_ORIGIN = '#https?:(?:\x5c?/){2}(?:localhost|127\.0\.0\.1)(?::\d+)?#';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $response instanceof JsonResponse) {
            return $response;
        }

        $base = rtrim((string) config('app.url'), '/');

        if ($base === '' || preg_match(self::LOCAL_ORIGIN, $base) === 1) {
            return $response;
        }

        $escapedBase = str_replace('/', '\/', $base);
        $content = (string) $response->getContent();

        $content = preg_replace_callback(
            self::LOCAL_ORIGIN,
            fn (array $match): string => str_contains($match[0], '\\') ? $escapedBase : $base,
            $content,
        ) ?? $content;

        return $response->setContent($content);
    }
}
