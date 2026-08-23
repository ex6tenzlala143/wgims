<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Prevents disk caching of sensitive pages while allowing the browser's
 * back/forward cache (bfcache) for smooth Back/Forward navigation.
 *
 * Previously this used `no-store` on every response, which disables bfcache
 * entirely. That caused a full network reload on Back and a visible
 * skeleton/loading flash even though the page was already rendered.
 *
 * Now:
 * - Authenticated GETs use `private, no-cache, must-revalidate` (no `no-store`)
 *   so the browser may keep the page in bfcache and restore it instantly on
 *   Back/Forward without a skeleton flash. The `pageshow` handler in the layout
 *   hides any loading UI on `event.persisted` restores and verifies the session
 *   is still valid (reloads to login if the user logged out).
 * - `no-store` is kept for the login page, non-GETs and guest responses where
 *   no bfcache benefit exists and strict no-cache is desired.
 * - `Clear-Site-Data` is set on logout (AuthController) to explicitly clear the
 *   bfcache entry for that navigation.
 */
class NoCache
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        $isGet = $request->isMethod('GET');
        $isLogin = $request->is('login');
        $isAuthenticated = auth()->check();

        // Authenticated GETs (the pages where Back-button smoothness matters) —
        // allow bfcache but still require revalidation. This prevents the disk
        // HTTP cache from storing the response while keeping bfcache usable.
        if ($isGet && $isAuthenticated && ! $isLogin) {
            $response->headers->set('Cache-Control', 'private, no-cache, must-revalidate, max-age=0');
        } else {
            // Login, guest, or non-GET: strongest no-cache — nothing stored.
            $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private, max-age=0');
        }
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');

        return $response;
    }
}