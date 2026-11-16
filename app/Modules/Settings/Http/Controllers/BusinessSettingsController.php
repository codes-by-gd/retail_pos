<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Settings\Http\Requests\BusinessSettingsRequest;
use App\Modules\Settings\Services\MailSettings;
use App\Support\Branding;
use App\Support\Gst\IndianStates;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
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
                'loyalty' => settings()->group('loyalty'),
            ],
            'states' => IndianStates::options(),
            'logoUrl' => Branding::logoUrl(),
            'mailConfigured' => MailSettings::ready(),
        ]);
    }

    public function update(BusinessSettingsRequest $request): RedirectResponse
    {
        settings()->set($request->flattened());

        activity()->causedBy($request->user())->log('Updated business settings');

        return back()->with('success', 'Settings saved.');
    }

    /** Shop logo for receipts, invoices, reports and the app header. */
    public function uploadLogo(Request $request): RedirectResponse
    {
        $request->validate(['logo' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:1024', 'dimensions:max_width=2000,max_height=2000']]);
        $old = settings('business.logo');
        $path = $request->file('logo')->store('branding', 'public');
        settings()->set(['business.logo' => $path]);
        if ($old && $old !== $path) {
            Storage::disk('public')->delete($old);
        }
        activity()->causedBy($request->user())->log('Changed shop logo');

        return back()->with('success', 'Logo updated.');
    }

    public function removeLogo(Request $request): RedirectResponse
    {
        if ($old = settings('business.logo')) {
            Storage::disk('public')->delete($old);
        }
        settings()->set(['business.logo' => null]);
        activity()->causedBy($request->user())->log('Removed shop logo');

        return back()->with('success', 'Logo removed.');
    }
}
