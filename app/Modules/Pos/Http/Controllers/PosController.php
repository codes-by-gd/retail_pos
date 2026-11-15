<?php

namespace App\Modules\Pos\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Loyalty\Services\LoyaltyService;
use App\Modules\Pos\Models\HeldCart;
use App\Modules\Pos\Models\Register;
use App\Modules\Pos\Models\RegisterSession;
use App\Modules\Pos\Services\RegisterService;
use App\Modules\Sales\Models\Sale;
use App\Support\CurrentBranch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PosController extends Controller
{
    public const SESSION_KEY = 'pos.register_session_id';

    public function __construct(private RegisterService $registers) {}

    /** The billing screen — or the "open register" screen when this browser has no open shift. */
    public function terminal(Request $request): Response
    {
        $branch = current_branch()->get();
        abort_unless($branch, 403, 'You are not assigned to any branch.');

        $session = self::currentSession($request);
        if (! $session) {
            return Inertia::render('Pos/OpenRegister', [
                'registers' => Register::where('branch_id', $branch->id)->active()->orderBy('name')
                    ->with('openSession.opener:id,name')->get()
                    ->map(fn (Register $r) => [
                        'id' => $r->id,
                        'name' => $r->name,
                        'open_session' => $r->openSession ? [
                            'opened_at' => $r->openSession->opened_at->toIso8601String(),
                            'opened_by' => $r->openSession->opener->name,
                        ] : null,
                    ]),
                'canOpen' => $request->user()->can('pos.register.manage'),
                'recallId' => $request->integer('recall') ?: null,   // keep "convert quotation" through opening the register
            ]);
        }

        $session->load('register:id,name', 'opener:id,name');

        return Inertia::render('Pos/Terminal', [
            'session' => [
                'id' => $session->id,
                'register' => $session->register->name,
                'opened_at' => $session->opened_at->toIso8601String(),
                'opened_by' => $session->opener->name,
            ],
            'walkIn' => Contact::where('is_walk_in', true)->first(['id', 'code', 'name']),
            'config' => [
                'round_off' => (bool) settings('tax.round_off'),
                'branch_state' => $branch->state_code,
                'accept_card' => (bool) settings('pos.accept_card'),
                'accept_upi' => (bool) settings('pos.accept_upi'),
                'loyalty' => LoyaltyService::rules(),
                'print_after_sale' => (bool) settings('pos.print_after_sale'),
                'receipt_size' => settings('pos.receipt_size'),
                'beep_on_scan' => (bool) settings('pos.beep_on_scan'),
            ],
            'heldCount' => HeldCart::where('branch_id', $branch->id)->count(),
            'lastSale' => Sale::where('register_session_id', $session->id)->latest('id')->first(['id', 'invoice_no', 'grand_total']),
            'lockUsers' => $this->lockUsers(),
            'recallId' => $request->integer('recall') ?: null,
            'popularIds' => $this->popularIds($branch->id),
        ]);
    }

    /** Best-selling SKUs at this branch over the last 30 days — quick picks on the billing screen. */
    private function popularIds(int $branchId): array
    {
        return DB::table('sale_items as si')->join('sales as s', 's.id', '=', 'si.sale_id')
            ->join('product_variants as pv', 'pv.id', '=', 'si.product_variant_id')->whereNull('pv.deleted_at')->where('pv.is_active', true)
            ->where('s.branch_id', $branchId)->where('s.status', Sale::COMPLETED)->where('s.sold_at', '>=', now()->subDays(30))
            ->groupBy('si.product_variant_id')->orderByRaw('SUM(si.quantity) DESC')->limit(12)->pluck('si.product_variant_id')->all();
    }

    public function openRegister(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'register_id' => ['required', Rule::exists('registers', 'id')->where('branch_id', current_branch()->id())->where('is_active', true)],
            'opening_cash' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'recall' => ['nullable', 'integer'],
        ]);
        $register = Register::findOrFail($data['register_id']);

        // Joining an already-open shift is allowed for any POS user; opening a new one needs permission.
        if (! $register->openSession()->exists() && ! $request->user()->can('pos.register.manage')) {
            abort(403, 'Ask a manager to open this register.');
        }

        $session = $this->registers->open($register, $request->user(), $data['opening_cash'] ?? 0);
        $request->session()->put(self::SESSION_KEY, $session->id);

        return to_route('pos.terminal', ! empty($data['recall']) ? ['recall' => $data['recall']] : []);
    }

    public function leaveRegister(Request $request): RedirectResponse
    {
        $request->session()->forget(self::SESSION_KEY);

        return to_route('pos.terminal');
    }

    /** PIN switch: another cashier takes over this screen without logging out. */
    public function unlock(Request $request): JsonResponse
    {
        $data = $request->validate(['user_id' => ['required', 'integer'], 'pin' => ['required', 'digits_between:4,6']]);
        $sessionId = $request->session()->get(self::SESSION_KEY);
        $branchId = current_branch()->id();

        $key = 'pos-unlock:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['pin' => 'Too many attempts. Try again in a minute.']);
        }

        $user = User::active()->whereKey($data['user_id'])->first();
        if (! $user || ! $user->pin || ! Hash::check($data['pin'], $user->pin)
            || ! $user->can('pos.access') || ! current_branch()->allowedFor($user)->contains('id', $branchId)) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['pin' => 'Wrong PIN.']);
        }
        RateLimiter::clear($key);

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put([self::SESSION_KEY => $sessionId, CurrentBranch::SESSION_KEY => $branchId]);

        return response()->json(['user' => ['id' => $user->id, 'name' => $user->name], 'csrf' => csrf_token()]);
    }

    /** Open shift chosen in this browser session, if still open and in the current branch. */
    public static function currentSession(Request $request): ?RegisterSession
    {
        $id = $request->session()->get(self::SESSION_KEY);
        $session = $id ? RegisterSession::whereKey($id)->where('status', RegisterSession::OPEN)->where('branch_id', current_branch()->id())->first() : null;

        if ($id && ! $session) {
            $request->session()->forget(self::SESSION_KEY);
        }

        return $session;
    }

    private function lockUsers(): array
    {
        $branchId = current_branch()->id();

        return User::active()->whereNotNull('pin')->orderBy('name')->get(['id', 'name'])
            ->filter(fn (User $u) => $u->can('pos.access') && current_branch()->allowedFor($u)->contains('id', $branchId))
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])
            ->values()->all();
    }
}
