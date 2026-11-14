<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Services\ProductCsvService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductImportController extends Controller
{
    public function __construct(private ProductCsvService $csv) {}

    public function create(): Response
    {
        return Inertia::render('Inventory/Products/Import', [
            'columns' => ProductCsvService::COLUMNS,
            'result' => session('import_result'),
            'currentBranch' => current_branch()->get()?->name,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:5120']]);

        @set_time_limit(300);
        $result = $this->csv->import($request->file('file')->getRealPath(), current_branch()->id());

        activity()->causedBy($request->user())->withProperties(['created' => $result['created'], 'updated' => $result['updated'], 'errors' => count($result['errors'])])->log('Imported products');

        return back()->with('import_result', $result)
            ->with($result['errors'] ? 'error' : 'success', "{$result['created']} created, {$result['updated']} updated".($result['errors'] ? ', '.count($result['errors']).' rows skipped.' : '.'));
    }

    public function template(): StreamedResponse
    {
        return $this->csv->template();
    }

    public function export(): StreamedResponse
    {
        return $this->csv->export(current_branch()->id());
    }
}
