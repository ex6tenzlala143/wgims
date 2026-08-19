<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Prevents the browser (and any intermediate cache) from storing authenticated
 * pages. Without this, a user who logs out could still see a previously
 * visited protected page via the browser Back button, a bookmark, or a
 * cached copy — even though the server session is already gone.
 *
 * no-store is the strongest directive: nothing is written to the HTTP cache,
 * and the browser's back/forward cache (bfcache) will not be used either.
 */
class NoCache
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');

        return $response;
    }
}