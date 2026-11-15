<?php

namespace App\Modules\Notifications\Services;

use App\Models\User;
use App\Modules\Notifications\Notifications\AlertNotification;
use App\Modules\Notifications\NotificationTypes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/** Works out who should receive an alert and sends it. Never lets an alert break the action that caused it. */
class Notifier
{
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

            return $users->count();
        } catch (\Throwable $e) {
            report($e);

            return 0;
        }
    }
}
