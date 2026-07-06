<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Allows only full Admins to perform write/delete actions.
 * Warehouse Managers pass the 'admin' middleware (view access) but are
 * blocked here for any route that mutates data.
 */
class AdminWriteOnly
{
    public function handle(Request $request, Closure $next)
    {
        if (! auth()->check()) {
            return redirect()->route('login');
        }

        if (! auth()->user()->canWrite()) {
            abort(403, 'Access denied. You have read-only access.');
        }

        return $next($request);
    }
}
