<?php

namespace App\Http\Middleware;

use App\Support\Permissions;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Props available on every page as $page.props.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'app' => fn () => [
                'name' => config('app.name'),
                'business_name' => settings('business.name'),
                'currency' => settings('business.currency_symbol'),
            ],
            'auth' => fn () => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'roles' => $user->getRoleNames(),
                    'is_admin' => $user->isAdmin(),
                    'permissions' => $user->isAdmin()
                        ? Permissions::all()
                        : $user->getAllPermissions()->pluck('name'),
                ] : null,
            ],
            'branch' => fn () => $user ? [
                'current' => current_branch()->get()?->only(['id', 'name', 'code', 'state_code']),
                'available' => current_branch()->allowedFor($user)->map->only(['id', 'name', 'code'])->values(),
            ] : null,
            'notifications' => fn () => $user ? ['unread' => $user->unreadNotifications()->count()] : null,
            'flash' => fn () => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
            ],
        ];
    }
}
