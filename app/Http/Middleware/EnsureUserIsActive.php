<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Terminates the session of any authenticated user whose account has been
 * deactivated (is_active = false) BEFORE the request reaches a controller.
 *
 * Runs on every web request (appended to the web group) after the session
 * middleware, so Auth::check() and Auth::user() are already resolved. A
 * deactivated user gets logged out, their session invalidated and the CSRF
 * token regenerated — the same treatment as an explicit logout.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next)
    {
        if (Auth::check() && ! Auth::user()->is_active) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login');
        }

        return $next($request);
    }
}