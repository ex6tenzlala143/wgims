<?php

namespace App\Http\Controllers;

use App\Models\SystemNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class NotificationController extends Controller
{
    public function index()
    {
        $notifications = SystemNotification::where('user_id', Auth::user()->id)
            ->orderByDesc('created_at')
            ->paginate(20);

        return view('notifications.index', compact('notifications'));
    }

    /**
     * Mark a single notification as read (from the index page).
     * If _stay is posted (from the index page), stay on the list.
     * Otherwise redirect to the notification's link.
     */
    public function markRead(SystemNotification $notification, Request $request)
    {
        if ($notification->user_id !== Auth::user()->id) {
            abort(403);
        }

        $notification->update(['is_read' => true]);

        // Called from the index page — stay there
        if ($request->has('_stay')) {
            return redirect()->route('notifications.index')->with('success', 'Notification marked as read.');
        }

        // Called from elsewhere — go to the link if available
        if ($notification->link) {
            return redirect($notification->link);
        }

        return redirect()->route('notifications.index')->with('success', 'Notification marked as read.');
    }

    /**
     * Mark a notification as read via AJAX (from the dropdown bell).
     * Returns JSON so the frontend can update the badge count without a page reload.
     */
    public function markReadAjax(SystemNotification $notification)
    {
        if ($notification->user_id !== Auth::user()->id) {
            abort(403);
        }

        $notification->update(['is_read' => true]);

        $count = SystemNotification::where('user_id', Auth::user()->id)
            ->where('is_read', false)
            ->count();

        return response()->json(['success' => true, 'remaining_count' => $count]);
    }

    /**
     * Mark all notifications as read for the current user.
     */
    public function markAllRead()
    {
        SystemNotification::where('user_id', Auth::user()->id)->update(['is_read' => true]);

        return back()->with('success', 'All notifications marked as read.');
    }

    /**
     * Return unread notifications as JSON for the dropdown bell.
     */
    public function getUnread()
    {
        $userId = Auth::user()->id;

        $notifications = SystemNotification::where('user_id', $userId)
            ->where('is_read', false)
            ->orderByDesc('created_at')
            ->take(10)
            ->get(['id', 'title', 'message', 'type', 'link', 'is_read', 'created_at']);

        $count = SystemNotification::where('user_id', $userId)
            ->where('is_read', false)
            ->count();

        return response()->json([
            'notifications' => $notifications->map(fn ($n) => [
                'id'         => $n->id,
                'title'      => $n->title,
                'message'    => $n->message,
                'type'       => $n->type,
                'link'       => $n->link,
                'is_read'    => $n->is_read,
                'created_at' => $n->created_at?->diffForHumans(),
            ]),
            'count' => $count,
        ]);
    }
}
