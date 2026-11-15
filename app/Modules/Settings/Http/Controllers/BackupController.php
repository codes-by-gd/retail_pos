<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Settings\Services\BackupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BackupController extends Controller
{
    public function __construct(private BackupService $backups) {}

    public function index(): Response
    {
        return Inertia::render('Settings/Backups', [
            'backups' => $this->backups->list(),
            'keepDays' => (int) settings('backup.keep_days', 14),
            'driver' => DB::connection()->getDriverName(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $name = $this->backups->create();
        activity()->causedBy($request->user())->withProperties(['file' => $name])->log('Created database backup');

        return back()->with('success', "Backup {$name} created.");
    }

    public function download(Request $request, string $name): BinaryFileResponse
    {
        $path = $this->backups->path($name);
        abort_unless($path, 404);
        activity()->causedBy($request->user())->withProperties(['file' => $name])->log('Downloaded database backup');

        return response()->download($path, $name, ['Content-Type' => 'application/gzip']);
    }

    public function destroy(Request $request, string $name): RedirectResponse
    {
        abort_unless($this->backups->delete($name), 404);
        activity()->causedBy($request->user())->withProperties(['file' => $name])->log('Deleted database backup');

        return back()->with('success', "Backup {$name} deleted.");
    }

    public function settings(Request $request): RedirectResponse
    {
        $data = $request->validate(['keep_days' => ['required', 'integer', 'min:1', 'max:365']]);
        settings()->set(['backup.keep_days' => $data['keep_days']]);

        return back()->with('success', 'Backup settings saved.');
    }
}
