<?php

namespace App\Models;

use App\Modules\Settings\Models\Branch;
use App\Support\Permissions;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, LogsActivity, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'pin',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'pin',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'pin' => 'hashed',
            'is_active' => 'boolean',
            'notification_preferences' => 'array',
        ];
    }

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class);
    }

    public function isAdmin(): bool
    {
        return $this->hasRole(Permissions::ADMIN_ROLE);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Preferences: ['off' => [types switched off], 'email' => [types also sent by email]]. */
    public function wantsNotification(string $type, string $channel = 'app'): bool
    {
        $prefs = $this->notification_preferences ?? [];

        return $channel === 'mail'
            ? in_array($type, $prefs['email'] ?? [], true) && ! in_array($type, $prefs['off'] ?? [], true)
            : ! in_array($type, $prefs['off'] ?? [], true);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'email', 'phone', 'is_active'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
