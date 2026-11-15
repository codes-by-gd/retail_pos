<?php

namespace App\Modules\Loyalty\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Loyalty\Models\LoyaltyTransaction;
use App\Modules\Loyalty\Services\LoyaltyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LoyaltyController extends Controller
{
    /** Summary + recent history for the customer page (null when not applicable). */
    public static function forContact(Contact $contact, LoyaltyService $loyalty): ?array
    {
        if ($contact->is_walk_in || $contact->type === Contact::SUPPLIER) {
            return null;
        }
        $balance = $loyalty->balance($contact);
        if (! LoyaltyService::enabled() && $balance === 0) {
            return null;
        }
        $next = LoyaltyTransaction::where('contact_id', $contact->id)->where('remaining', '>', 0)->whereNotNull('expires_at')
            ->orderBy('expires_at')->first(['expires_at']);

        return [
            'balance' => $balance,
            'value' => (float) LoyaltyService::value($balance),
            'enabled' => LoyaltyService::enabled(),
            'next_expiry' => $next ? [
                'date' => $next->expires_at->toDateString(),
                'points' => (int) LoyaltyTransaction::where('contact_id', $contact->id)->where('remaining', '>', 0)->whereDate('expires_at', $next->expires_at)->sum('remaining'),
            ] : null,
            'history' => LoyaltyTransaction::with('sale:id,invoice_no')->where('contact_id', $contact->id)->latest('id')->limit(25)->get()
                ->map(fn ($t) => ['id' => $t->id, 'date' => $t->created_at, 'type' => LoyaltyTransaction::LABELS[$t->type] ?? $t->type, 'points' => $t->points,
                    'note' => $t->note, 'sale_id' => $t->sale_id, 'expires_at' => $t->expires_at?->toDateString()]),
        ];
    }

    public function adjust(Request $request, Contact $contact, LoyaltyService $loyalty): RedirectResponse
    {
        abort_if($contact->is_walk_in || $contact->type === Contact::SUPPLIER, 404);
        $data = $request->validate(['points' => ['required', 'integer', 'not_in:0', 'between:-1000000,1000000'], 'note' => ['required', 'string', 'max:150']]);
        $loyalty->adjust($contact, (int) $data['points'], $data['note'], $request->user());
        activity()->causedBy($request->user())->performedOn($contact)->withProperties(['points' => (int) $data['points'], 'note' => $data['note']])->log('Adjusted loyalty points');

        return back()->with('success', 'Points updated.');
    }
}
