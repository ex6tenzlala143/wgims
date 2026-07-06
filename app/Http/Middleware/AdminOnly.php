<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class AdminOnly
{
    public function handle(Request $request, Closure $next)
    {
        // Not authenticated at all — redirect to login instead of 403
        if (! auth()->check()) {
            return redirect()->route('login');
        }

        // Authenticated but not admin or warehouse manager — 403
        if (! auth()->user()->hasAdminAccess()) {
            abort(403, 'Access denied. Admin only.');
        }

        return $next($request);
    }
}
