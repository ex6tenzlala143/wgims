<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Allows admins AND warehouse managers to create new records.
 * Warehouse managers can create but cannot edit or delete.
 * Edit/delete routes remain under AdminWriteOnly (admin-only).
 */
class AdminCreateOnly
{
    public function handle(Request $request, Closure $next)
    {
        if (! auth()->check()) {
            return redirect()->route('login');
        }

        if (! auth()->user()->canCreate()) {
            abort(403, 'Access denied.');
        }

        return $next($request);
    }
}
