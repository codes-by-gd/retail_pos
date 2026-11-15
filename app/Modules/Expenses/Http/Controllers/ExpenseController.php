<?php

namespace App\Modules\Expenses\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Expenses\Http\Requests\ExpenseRequest;
use App\Modules\Expenses\Models\Expense;
use App\Modules\Expenses\Models\ExpenseCategory;
use App\Modules\Expenses\Services\ExpenseService;
use App\Modules\Pos\Models\RegisterSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExpenseController extends Controller
{
    public function __construct(private ExpenseService $expenses) {}

    public function index(Request $request): Response
    {
        $branchId = current_branch()->id();
        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'],
            'category' => ['nullable', 'integer'], 'method' => ['nullable', 'string'], 'search' => ['nullable', 'string', 'max:100'],
        ]) + ['from' => today()->startOfMonth()->toDateString(), 'to' => today()->toDateString()];

        $query = Expense::query()->where('branch_id', $branchId)
            ->whereBetween('date', [$filters['from'], $filters['to'].' 23:59:59'])
            ->when($filters['category'] ?? null, fn ($q, $c) => $q->where('expense_category_id', $c))
            ->when($filters['method'] ?? null, fn ($q, $m) => $q->where('method', $m))
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('reference_no', 'like', "%{$s}%")
                ->orWhere('paid_to', 'like', "%{$s}%")->orWhere('reference', 'like', "%{$s}%")->orWhere('note', 'like', "%{$s}%")));

        $byCategory = (clone $query)->join('expense_categories as ec', 'ec.id', '=', 'expenses.expense_category_id')
            ->groupBy('ec.name')->selectRaw('ec.name as name, SUM(expenses.amount) as total, COUNT(*) as count')
            ->orderByDesc('total')->get()->map(fn ($r) => ['name' => $r->name, 'total' => round((float) $r->total, 2), 'count' => (int) $r->count]);

        $expenses = $query->with(['category:id,name', 'user:id,name', 'session:id,status,register_id', 'session.register:id,name'])
            ->latest('date')->latest('id')->paginate(25)->withQueryString()
            ->through(fn (Expense $e) => [
                'id' => $e->id, 'reference_no' => $e->reference_no, 'date' => $e->date->toDateString(),
                'expense_category_id' => $e->expense_category_id, 'category' => $e->category->name,
                'amount' => (float) $e->amount, 'gst_amount' => (float) $e->gst_amount, 'method' => $e->method,
                'reference' => $e->reference, 'paid_to' => $e->paid_to, 'note' => $e->note,
                'register_session_id' => $e->register_session_id, 'register' => $e->session?->register?->name,
                'has_attachment' => (bool) $e->attachment_path, 'user' => $e->user->name,
                'locked' => $this->expenses->lockReason($e),
            ]);

        return Inertia::render('Expenses/Index', [
            'expenses' => $expenses,
            'filters' => $filters,
            'summary' => ['total' => round($byCategory->sum('total'), 2), 'count' => $byCategory->sum('count'), 'byCategory' => $byCategory],
            'categories' => ExpenseCategory::orderBy('name')->get(['id', 'name', 'is_active'])
                ->map(fn ($c) => ['value' => $c->id, 'label' => $c->name, 'active' => $c->is_active]),
            'methods' => collect(Expense::METHODS)->map(fn ($label, $value) => compact('value', 'label'))->values(),
            'registers' => RegisterSession::with('register:id,name', 'opener:id,name')->where('branch_id', $branchId)->where('status', RegisterSession::OPEN)->get()
                ->map(fn ($s) => ['value' => $s->id, 'label' => "{$s->register->name} · {$s->opener->name}"]),
            'today' => today()->toDateString(),
        ]);
    }

    public function store(ExpenseRequest $request): RedirectResponse
    {
        $branch = current_branch()->get();
        abort_unless($branch, 403, 'No branch selected.');
        $expense = $this->expenses->create($request->validated(), $branch, $request->user(), $request->file('attachment'));

        return back()->with('success', "Expense {$expense->reference_no} saved.");
    }

    public function update(ExpenseRequest $request, Expense $expense): RedirectResponse
    {
        $this->ensureBranch($expense);
        $this->expenses->update($expense, $request->validated(), $request->user(), $request->file('attachment'), $request->boolean('remove_attachment'));

        return back()->with('success', 'Expense updated.');
    }

    public function destroy(Request $request, Expense $expense): RedirectResponse
    {
        $this->ensureBranch($expense);
        $this->expenses->delete($expense, $request->user());

        return back()->with('success', 'Expense deleted.');
    }

    public function attachment(Expense $expense): StreamedResponse
    {
        $this->ensureBranch($expense);
        abort_unless($expense->attachment_path && Storage::disk(ExpenseService::DISK)->exists($expense->attachment_path), 404);

        return Storage::disk(ExpenseService::DISK)->response($expense->attachment_path, str_replace('/', '-', $expense->reference_no).'.'.pathinfo($expense->attachment_path, PATHINFO_EXTENSION));
    }

    private function ensureBranch(Expense $expense): void
    {
        abort_unless(current_branch()->allowedFor(request()->user())->contains('id', $expense->branch_id), 403);
    }
}
