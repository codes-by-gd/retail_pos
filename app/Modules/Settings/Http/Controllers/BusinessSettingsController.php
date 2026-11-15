<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Settings\Http\Requests\BusinessSettingsRequest;
use App\Support\Gst\IndianStates;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class BusinessSettingsController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('Settings/Business', [
            'settings' => [
                'business' => settings()->group('business'),
                'invoice' => settings()->group('invoice'),
                'tax' => settings()->group('tax'),
                'pos' => settings()->group('pos'),
                'alerts' => settings()->group('alerts'),
            ],
            'states' => IndianStates::options(),
            'mailConfigured' => ! in_array(config('mail.default'), ['log', 'array'], true),
        ]);
    }

    public function update(BusinessSettingsRequest $request): RedirectResponse
    {
        settings()->set($request->flattened());

        activity()->causedBy($request->user())->log('Updated business settings');

        return back()->with('success', 'Settings saved.');
    }
}
