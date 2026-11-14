<?php

namespace App\Modules\Contacts\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Contacts\Services\ContactCsvService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ContactImportController extends Controller
{
    public function __construct(private ContactCsvService $csv) {}

    public function create(Request $request): Response
    {
        return Inertia::render('Contacts/Import', [
            'kind' => $this->kind($request),
            'columns' => ContactCsvService::COLUMNS,
            'result' => session('import_result'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:5120']]);
        @set_time_limit(300);

        $result = $this->csv->import($request->file('file')->getRealPath(), $this->kind($request));
        activity()->causedBy($request->user())->withProperties(['kind' => $this->kind($request), 'created' => $result['created'], 'updated' => $result['updated']])->log('Imported contacts');

        return back()->with('import_result', $result)
            ->with($result['errors'] ? 'error' : 'success', "{$result['created']} created, {$result['updated']} updated".($result['errors'] ? ', '.count($result['errors']).' rows skipped.' : '.'));
    }

    public function template(Request $request): StreamedResponse
    {
        return $this->csv->template($this->kind($request));
    }

    public function export(Request $request): StreamedResponse
    {
        return $this->csv->export($this->kind($request));
    }

    private function kind(Request $request): string
    {
        return $request->route('kind') === Contact::SUPPLIER ? Contact::SUPPLIER : Contact::CUSTOMER;
    }
}
