<?php

namespace App\Providers;

use App\Models\User;
use App\Modules\Settings\Services\MailSettings;
use App\Modules\Settings\Services\SettingsService;
use App\Support\CurrentBranch;
use App\Support\Permissions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SettingsService::class);
        $this->app->scoped(CurrentBranch::class);
    }

    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // Catch N+1 queries and silently discarded attributes during development.
        Model::shouldBeStrict(! $this->app->isProduction());

        // Passwords: letters + numbers, and not found in known data breaches (production only — the check calls an API).
        Password::defaults(fn () => $this->app->isProduction()
            ? Password::min(8)->letters()->numbers()->uncompromised()
            : Password::min(8));

        // Outgoing email from Settings → Email (falls back to .env MAIL_*)
        rescue(fn () => MailSettings::apply(), report: false);

        // Trusted reverse proxies (nginx / load balancer) for HTTPS and client-IP detection — config/app.php.
        TrustProxies::at(config('app.trusted_proxies'));

        // Admins can do everything.
        Gate::before(fn (User $user) => $user->hasRole(Permissions::ADMIN_ROLE) ? true : null);

        // Reports menu: any one of the report permissions opens it (each report then checks its own).
        Gate::define('reports.any', fn (User $user) => $user->hasAnyPermission(['reports.sales', 'reports.inventory', 'reports.financial']));
    }
}
