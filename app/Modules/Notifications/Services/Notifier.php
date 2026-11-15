<?php

namespace App\Modules\Notifications\Services;

use App\Models\User;
use App\Modules\Notifications\Events\AlertPushed;
use App\Modules\Notifications\Http\Controllers\NotificationController;
use App\Modules\Notifications\Notifications\AlertNotification;
use App\Modules\Notifications\NotificationTypes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

use function Illuminate\Support\defer;

/** Works out who should receive an alert and sends it. Never lets an alert break the action that caused it. */
class Notifier
{
    private const PUSH_DOWN_KEY = 'notifications.push-down';

    /** @return Collection<int, User> */
    public function recipients(string $type, ?int $branchId = null, ?User $except = null): Collection
    {
        $permissions = NotificationTypes::ALL[$type]['permissions'];

        return User::query()->where('is_active', true)->with(['roles.permissions', 'permissions', 'branches:id'])->get()
            ->filter(fn (User $u) => $u->id !== $except?->id)
            ->filter(fn (User $u) => $u->isAdmin() || collect($permissions)->contains(fn ($p) => $u->hasPermissionTo($p)))
            ->filter(fn (User $u) => $branchId === null || $u->isAdmin() || $u->branches->contains('id', $branchId))
            ->filter(fn (User $u) => $u->wantsNotification($type))
            ->values();
    }

    public function send(AlertNotification $alert, ?int $branchId = null, ?User $except = null): int
    {
        try {
            $users = $this->recipients($alert->alertType, $branchId, $except);
            if ($alert->key) {
                // one unread alert per subject: don't stack "Navy / 40 is low" five times
                $users = $users->reject(fn (User $u) => $u->unreadNotifications()->where('data', 'like', '%"key":'.json_encode($alert->key).'%')->exists());
            }
            Notification::send($users, $alert);
            $this->push($users);

            return $users->count();
        } catch (\Throwable $e) {
            report($e);

            return 0;
        }
    }

    /** Send straight to one user (no permission / preference checks) — e.g. the "test alert" button. */
    public function sendTo(User $user, AlertNotification $alert): void
    {
        $user->notify($alert);
        $this->push(collect([$user]));
    }

    /**
     * Real-time push to open browser tabs, after the response is sent so a sale never waits for it.
     * If the WebSocket server is down, skip pushes for a minute (the bell falls back to polling).
     */
    private function push(Collection $users): void
    {
        $ids = $users->pluck('id')->all();
        if (! $ids) {
            return;
        }
        defer(function () use ($ids) {
            if (Cache::has(self::PUSH_DOWN_KEY)) {
                return;
            }
            foreach (User::whereIn('id', $ids)->get() as $user) {
                $latest = $user->notifications()->latest()->first();
                if (! $latest) {
                    continue;
                }
                try {
                    event(new AlertPushed($user->id, NotificationController::present($latest), $user->unreadNotifications()->count()));
                } catch (\Throwable $e) {
                    Cache::put(self::PUSH_DOWN_KEY, true, 60);
                    report($e);

                    return;
                }
            }
        });
    }
}
