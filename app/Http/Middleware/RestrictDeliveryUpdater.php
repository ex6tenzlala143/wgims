<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Delivery updaters may only use the dashboard and requisitions
 * (view + confirm/unconfirm delivery). Every other authenticated page
 * is forbidden server-side — sidebar hiding alone is not security.
 */
class RestrictDeliveryUpdater
{
    /**
     * Route names a delivery updater is allowed to visit.
     * Everything else under the auth group gets a 403.
     */
    public const ALLOWED_ROUTES = [
        'dashboard',
        // Requisitions: list, detail, print (confirm buttons live on show).
        'requisitions.index',
        'requisitions.show',
        'requisitions.print',
        // Delivery confirmation workflow (the updater's actual job).
        'requisitions.dispatch_confirm_delivery',
        'requisitions.dispatch_unconfirm_delivery',
        // Notifications power the layout bell; blocking them would break
        // every allowed page for updaters.
        'notifications.index',
        'notifications.read',
        'notifications.read_ajax',
        'notifications.read_all',
        'notifications.unread',
        // Logout (registered outside auth, listed for completeness).
        'logout',
    ];

    public function handle(Request $request, Closure $next)
    {
        $user = auth()->user();

        // Guests pass through — the `auth` middleware handles them.
        // Non-updaters pass through — their own middleware owns them.
        if (! $user || ! $user->isDeliveryUpdater()) {
            return $next($request);
        }

        $routeName = $request->route()?->getName();

        if (! in_array($routeName, self::ALLOWED_ROUTES, true)) {
            abort(403, 'Access denied. Delivery updaters can only access the dashboard and requisitions.');
        }

        return $next($request);
    }
}
