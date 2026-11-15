<?php

namespace App\Modules\Notifications\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Notifications\NotificationTypes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** The bell and the notifications page. Every user only ever sees their own notifications. */
class NotificationController extends Controller
{
    public static function present(DatabaseNotification $n): array
    {
        return ['id' => $n->id, ...$n->data, 'read' => $n->read_at !== null, 'created_at' => $n->created_at];
    }

    public function index(Request $request): Response
    {
        $user = $request->user();
        $unreadOnly = $request->boolean('unread');
        $prefs = $user->notification_preferences ?? [];

        return Inertia::render('Notifications/Index', [
            'notifications' => ($unreadOnly ? $user->unreadNotifications() : $user->notifications())->paginate(25)->withQueryString()
                ->through(fn ($n) => self::present($n)),
            'unreadOnly' => $unreadOnly,
            'unreadCount' => $user->unreadNotifications()->count(),
            // only the alert types this user is allowed to receive
            'types' => collect(NotificationTypes::ALL)
                ->filter(fn ($t) => $user->isAdmin() || collect($t['permissions'])->contains(fn ($p) => $user->can($p)))
                ->map(fn ($t, $key) => ['key' => $key, 'label' => $t['label'], 'description' => $t['description'],
                    'app' => ! in_array($key, $prefs['off'] ?? [], true), 'email' => in_array($key, $prefs['email'] ?? [], true)])
                ->values(),
            'emailEnabled' => (bool) settings('alerts.email_enabled'),
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'unread' => $user->unreadNotifications()->count(),
            'latest' => $user->notifications()->limit(8)->get()->map(fn ($n) => self::present($n)),
        ]);
    }

    /** Click on a notification: mark it read and go where it points. */
    public function open(Request $request, string $id): RedirectResponse
    {
        $n = $request->user()->notifications()->findOrFail($id);
        $n->markAsRead();

        return redirect($n->data['url'] ?? route('notifications.index'));
    }

    public function toggle(Request $request, string $id): RedirectResponse
    {
        $n = $request->user()->notifications()->findOrFail($id);
        $n->read_at ? $n->markAsUnread() : $n->markAsRead();

        return back();
    }

    public function readAll(Request $request): RedirectResponse|JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return $request->expectsJson() ? response()->json(['unread' => 0]) : back()->with('success', 'All notifications marked as read.');
    }

    public function clearRead(Request $request): RedirectResponse
    {
        $request->user()->readNotifications()->delete();

        return back()->with('success', 'Read notifications cleared.');
    }

    public function preferences(Request $request): RedirectResponse
    {
        $types = array_keys(NotificationTypes::ALL);
        $data = $request->validate([
            'off' => ['array'], 'off.*' => [Rule::in($types)],
            'email' => ['array'], 'email.*' => [Rule::in($types)],
        ]);
        $request->user()->forceFill(['notification_preferences' => ['off' => array_values($data['off'] ?? []), 'email' => array_values($data['email'] ?? [])]])->save();

        return back()->with('success', 'Notification preferences saved.');
    }
}
