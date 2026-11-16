<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Settings\Services\MailSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PasswordResetLinkController extends Controller
{
    /**
     * Display the password reset link request view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/ForgotPassword', [
            'status' => session('status'),
        ]);
    }

    /**
     * Handle an incoming password reset link request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        if (! MailSettings::ready()) {
            throw ValidationException::withMessages([
                'email' => 'Email is not set up for this shop. Ask your administrator to reset your password under Users.',
            ]);
        }

        // Only active accounts get a link. The answer is the same whether or not the email exists,
        // so the form can't be used to find out who has an account.
        $user = User::where('email', $request->input('email'))->first();
        if ($user?->is_active) {
            try {
                Password::sendResetLink($request->only('email'));
            } catch (\Throwable $e) {
                report($e);

                throw ValidationException::withMessages([
                    'email' => 'The email could not be sent right now. Please try again later or ask your administrator.',
                ]);
            }
        }

        return back()->with('status', 'If an account exists for that email, a link to reset the password is on its way. Check your inbox (and spam folder).');
    }
}
