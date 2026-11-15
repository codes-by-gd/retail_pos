<?php

namespace App\Modules\Users\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

/** Audit log viewer: who did what, when, and what changed. Read-only. */
class ActivityController extends Controller
{
    /** Subject class basename => [label, route to open it]. */
    private const SUBJECTS = [
        'Sale' => ['Bill', 'sales.show'], 'SaleReturn' => ['Credit note', null], 'Purchase' => ['Purchase', 'purchases.show'],
        'PurchaseReturn' => ['Debit note', null], 'Product' => ['Product', 'products.show'], 'ProductVariant' => ['Item (SKU)', null],
        'Contact' => ['Contact', null], 'User' => ['User', 'users.edit'], 'Branch' => ['Branch', null], 'Expense' => ['Expense', null],
        'RegisterSession' => ['Register shift', 'register-sessions.show'], 'ContactPayment' => ['Payment', 'payments.print'],
        'Role' => ['Role', 'roles.edit'],
    ];

    public function index(Request $request): Response
    {
        $f = $request->validate([
            'user_id' => ['nullable', 'integer'], 'subject' => ['nullable', 'string', 'max:50'], 'search' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $activities = Activity::query()
            ->when($f['user_id'] ?? null, fn ($q, $id) => $q->where('causer_type', (new User)->getMorphClass())->where('causer_id', $id))
            ->when($f['subject'] ?? null, fn ($q, $s) => $q->where('subject_type', 'like', '%\\'.$s))
            ->when($f['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('description', 'like', "%{$s}%")->orWhere('properties', 'like', "%{$s}%")))
            ->when($f['from'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', $d))
            ->when($f['to'] ?? null, fn ($q, $d) => $q->where('created_at', '<=', $d.' 23:59:59'))
            ->latest('id')
            ->paginate(40)->withQueryString();

        $causers = User::whereIn('id', $activities->getCollection()->where('causer_type', (new User)->getMorphClass())->pluck('causer_id'))->pluck('name', 'id');

        return Inertia::render('Activity/Index', [
            'activities' => $activities->through(fn (Activity $a) => $this->present($a, $causers)),
            'filters' => $f,
            'users' => User::orderBy('name')->get(['id', 'name'])->map(fn ($u) => ['value' => $u->id, 'label' => $u->name]),
            'subjects' => collect(self::SUBJECTS)->map(fn ($s, $k) => ['value' => $k, 'label' => $s[0]])->values(),
        ]);
    }

    private function present(Activity $a, $causers): array
    {
        $base = $a->subject_type ? class_basename($a->subject_type) : null;
        [$label, $route] = self::SUBJECTS[$base] ?? [$base ? Str::headline($base) : null, null];

        // Model events: list the changed fields old → new; manual entries: their properties.
        $changes = [];
        $attr = $a->attribute_changes?->get('attributes') ?? [];
        $old = $a->attribute_changes?->get('old') ?? [];
        foreach ($attr as $field => $value) {
            $changes[] = ['field' => Str::headline($field), 'old' => $this->show($old[$field] ?? null), 'new' => $this->show($value)];
        }
        $details = collect($a->properties ?? [])->map(fn ($v, $k) => ['field' => Str::headline((string) $k), 'value' => $this->show($v)])->values();

        return [
            'id' => $a->id,
            'at' => $a->created_at,
            'user' => $a->causer_id ? ($causers[$a->causer_id] ?? 'Deleted user') : 'System',
            'description' => $a->event ? Str::headline($a->event).' '.Str::lower($label ?? '') : $a->description,
            'event' => $a->event,
            'subject' => $label ? trim($label.' #'.$a->subject_id) : null,
            'url' => $route && $a->subject_id && Route::has($route) ? route($route, $a->subject_id) : null,
            'changes' => $changes,
            'details' => $details,
        ];
    }

    private function show(mixed $v): string
    {
        return match (true) {
            $v === null => '—',
            is_bool($v) => $v ? 'Yes' : 'No',
            is_array($v) => Str::limit(json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 200),
            default => Str::limit((string) $v, 200),
        };
    }
}
