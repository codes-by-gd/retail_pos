<?php

namespace App\Modules\Pos\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Pos\Models\RegisterSession;
use App\Modules\Pos\Services\RegisterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class RegisterSessionController extends Controller
{
    public function __construct(private RegisterService $registers) {}

    /** Shift history for the current branch. */
    public function index(): Response
    {
        $sessions = RegisterSession::where('branch_id', current_branch()->id())
            ->with(['register:id,name', 'opener:id,name', 'closer:id,name'])
            ->latest('id')->paginate(20)
            ->through(fn (RegisterSession $s) => [
                'id' => $s->id,
                'register' => $s->register->name,
                'status' => $s->status,
                'opened_at' => $s->opened_at->toIso8601String(),
                'opened_by' => $s->opener->name,
                'closed_at' => $s->closed_at?->toIso8601String(),
                'closed_by' => $s->closer?->name,
                'sales_total' => (float) $s->sales()->where('status', 'completed')->sum('grand_total'),
                'difference' => $s->difference !== null ? (float) $s->difference : null,
            ]);

        return Inertia::render('Pos/Sessions/Index', ['sessions' => $sessions]);
    }

    /** Z-report (also the close-register screen while the shift is open). */
    public function show(RegisterSession $registerSession): Response
    {
        abort_unless(current_branch()->allowedFor(auth()->user())->contains('id', $registerSession->branch_id), 403);
        $registerSession->load(['register:id,name', 'branch:id,name', 'opener:id,name', 'closer:id,name', 'cashMovements.user:id,name']);

        return Inertia::render('Pos/Sessions/Show', [
            'session' => [
                ...$registerSession->only(['id', 'status', 'closing_note']),
                'register' => $registerSession->register->name,
                'branch' => $registerSession->branch->name,
                'opened_at' => $registerSession->opened_at->toIso8601String(),
                'opened_by' => $registerSession->opener->name,
                'closed_at' => $registerSession->closed_at?->toIso8601String(),
                'closed_by' => $registerSession->closer?->name,
                'counted_cash' => $registerSession->counted_cash,
                'expected_cash' => $registerSession->expected_cash,
                'difference' => $registerSession->difference,
                'movements' => $registerSession->cashMovements->map(fn ($m) => [
                    'id' => $m->id, 'type' => $m->type, 'amount' => (float) $m->amount, 'reason' => $m->reason,
                    'user' => $m->user->name, 'created_at' => $m->created_at->toIso8601String(),
                ]),
            ],
            'summary' => $this->registers->summary($registerSession),
            'canClose' => $registerSession->isOpen() && auth()->user()->can('pos.register.manage'),
        ]);
    }

    public function close(Request $request, RegisterSession $registerSession): RedirectResponse
    {
        abort_unless($registerSession->branch_id === current_branch()->id(), 403);
        $data = $request->validate([
            'counted_cash' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'closing_note' => ['nullable', 'string', 'max:255'],
        ]);

        $this->registers->close($registerSession, $data['counted_cash'], $data['closing_note'] ?? null, $request->user());
        if ($request->session()->get(PosController::SESSION_KEY) === $registerSession->id) {
            $request->session()->forget(PosController::SESSION_KEY);
        }

        return to_route('register-sessions.show', $registerSession)->with('success', 'Register closed.');
    }

    public function cashMovement(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['in', 'out'])],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999'],
            'reason' => ['required', 'string', 'max:255'],
        ]);
        $session = PosController::currentSession($request) ?? abort(409, 'The register is not open.');
        $this->registers->cashMovement($session, $data['type'], $data['amount'], $data['reason'], $request->user());

        return response()->json(['expected_cash' => (float) $this->registers->summary($session)['expected_cash']], 201);
    }
}
