<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Settings\Services\MailSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Settings → Email: SMTP account for password resets, welcome emails and email alerts. */
class MailSettingsController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('Settings/Email', [
            'mail' => [
                'enabled' => (bool) settings('mail.enabled'),
                'host' => settings('mail.host'),
                'port' => (int) settings('mail.port', 587),
                'encryption' => settings('mail.encryption', 'tls'),
                'username' => settings('mail.username'),
                'has_password' => (bool) settings('mail.password'),
                'from_address' => settings('mail.from_address'),
                'from_name' => settings('mail.from_name'),
            ],
            'encryptions' => collect(MailSettings::ENCRYPTIONS)->map(fn ($label, $value) => compact('value', 'label'))->values(),
            'status' => [
                'ready' => MailSettings::ready(),
                'source' => MailSettings::fromSettings() ? 'settings' : (MailSettings::ready() ? 'env' : 'none'),
                'env_mailer' => config('mail.default'),
            ],
            'businessName' => settings('business.name'),
            'adminEmail' => auth()->user()->email,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'host' => ['required_if:enabled,true', 'nullable', 'string', 'max:190'],
            'port' => ['required_if:enabled,true', 'nullable', 'integer', 'between:1,65535'],
            'encryption' => ['required', Rule::in(array_keys(MailSettings::ENCRYPTIONS))],
            'username' => ['nullable', 'string', 'max:190'],
            'password' => ['nullable', 'string', 'max:500'],   // empty = keep the saved one
            'remove_password' => ['nullable', 'boolean'],
            'from_address' => ['required_if:enabled,true', 'nullable', 'email', 'max:190'],
            'from_name' => ['nullable', 'string', 'max:100'],
        ], ['host.required_if' => 'Enter the SMTP server.', 'from_address.required_if' => 'Enter the address emails are sent from.']);

        $values = [
            'mail.enabled' => $data['enabled'],
            'mail.host' => ! empty($data['host']) ? trim($data['host']) : null,
            'mail.port' => $data['port'] ?? 587,
            'mail.encryption' => $data['encryption'],
            'mail.username' => ($data['username'] ?? null) ?: null,
            'mail.from_address' => ($data['from_address'] ?? null) ?: null,
            'mail.from_name' => ($data['from_name'] ?? null) ?: null,
        ];
        if (! empty($data['password'])) {
            $values['mail.password'] = MailSettings::encrypt($data['password']);
        } elseif (! empty($data['remove_password'])) {
            $values['mail.password'] = null;
        }
        settings()->set($values);
        MailSettings::apply();
        activity()->causedBy($request->user())->withProperties(['enabled' => $data['enabled'], 'host' => $values['mail.host']])->log('Updated email (SMTP) settings');

        return back()->with('success', 'Email settings saved. Send a test email to check them.');
    }

    /** Sends a short test email with the current settings and reports the SMTP error if it fails. */
    public function test(Request $request): RedirectResponse
    {
        $data = $request->validate(['to' => ['required', 'email', 'max:190']]);
        MailSettings::apply();
        if (! MailSettings::ready()) {
            return back()->with('error', 'Email is not set up yet — fill in and save the SMTP settings first.');
        }

        try {
            Mail::raw('This is a test email from the '.settings('business.name')." POS.\n\nIf you are reading this, password resets, welcome emails and email alerts will work.",
                fn ($m) => $m->to($data['to'])->subject(settings('business.name').': test email'));
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'Sending failed: '.str($e->getMessage())->limit(300));
        }
        activity()->causedBy($request->user())->withProperties(['to' => $data['to']])->log('Sent test email');

        return back()->with('success', "Test email sent to {$data['to']}. Check the inbox (and spam folder).");
    }
}
