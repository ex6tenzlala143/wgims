<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Restricts access to true admins only.
 * Unlike the 'admin' middleware, this does NOT allow warehouse managers through.
 * Used for user management routes which warehouse managers must not access.
 */
class AdminOnlyStrict
{
    public function handle(Request $request, Closure $next)
    {
        if (! auth()->check()) {
            return redirect()->route('login');
        }

        if (! auth()->user()->isAdmin()) {
            abort(403, 'Access denied. Administrators only.');
        }

        return $next($request);
    }
}
