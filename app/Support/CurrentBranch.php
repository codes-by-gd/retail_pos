<?php

namespace App\Support;

use App\Models\User;
use App\Modules\Settings\Models\Branch;
use Illuminate\Support\Collection;

/**
 * Resolves the branch the logged-in user is working in (stored in the session).
 * Every branch-scoped query (stock, sales, registers…) should use current_branch()->id().
 */
class CurrentBranch
{
    public const SESSION_KEY = 'current_branch_id';

    private ?Branch $branch = null;

    /** Cache key "userId:sessionBranchId" — re-resolve when either changes (long-running workers, tests). */
    private ?string $resolvedFor = null;

    public function get(): ?Branch
    {
        $user = auth()->user();
        $sessionId = session(self::SESSION_KEY);
        $key = ($user?->id ?? 'guest').':'.($sessionId ?? '');

        if ($this->resolvedFor !== $key) {
            $this->branch = null;

            if ($user) {
                $allowed = $this->allowedFor($user);
                $this->branch = $allowed->firstWhere('id', $sessionId) ?? $allowed->first();

                if ($this->branch && $this->branch->id !== $sessionId) {
                    session([self::SESSION_KEY => $this->branch->id]);
                }
            }

            $this->resolvedFor = ($user?->id ?? 'guest').':'.($this->branch?->id ?? '');
        }

        return $this->branch;
    }

    public function id(): ?int
    {
        return $this->get()?->id;
    }

    public function switchTo(Branch $branch): void
    {
        session([self::SESSION_KEY => $branch->id]);
        $this->branch = $branch;
        $this->resolvedFor = auth()->id().':'.$branch->id;
    }

    /** Admins may work in every active branch; other users only in branches assigned to them. */
    public function allowedFor(User $user): Collection
    {
        $query = $user->isAdmin() ? Branch::query() : $user->branches();

        return $query->active()->orderBy('name')->get();
    }
}
