<?php

namespace App\Modules\Reports\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Reports\ReportRegistry;
use App\Modules\Reports\Reports\Report;
use App\Modules\Reports\Services\FilterResolver;
use App\Modules\Reports\Services\ReportExporter;
use App\Modules\Reports\Support\Section;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class ReportController extends Controller
{
    /** Rows sent to the screen per section; exports always contain everything. */
    public const SCREEN_ROWS = 1000;

    public function __construct(private ReportRegistry $registry, private FilterResolver $filters) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Reports/Index', ['groups' => $this->registry->groupedFor($request->user())]);
    }

    public function show(Request $request, string $report): Response
    {
        $report = $this->authorizedReport($request, $report);
        $f = $this->filters->resolve($request, $report, $request->user());
        $sections = $report->build($f);
        $allowed = current_branch()->allowedFor($request->user());

        return Inertia::render('Reports/Show', [
            'report' => [...$report->meta(), 'usesDates' => $report->usesDates(), 'scope' => $report->scope(), 'chart' => $report->chart($f)],
            'filters' => [
                'from' => $f->fromDate(), 'to' => $f->toDate(),
                'branch' => count($f->branchIds) > 1 ? 'all' : (string) ($f->branchIds[0] ?? ''),
                'gstin' => $f->gstin, 'options' => $f->options,
            ],
            'branchLabel' => $f->branchLabel,
            'periodLabel' => $f->periodLabel(),
            'options' => collect($report->options())->map(fn ($o, $name) => [
                'name' => $name, 'label' => $o['label'],
                'options' => collect($o['options'])->map(fn ($label, $value) => ['value' => (string) $value, 'label' => $label])->values(),
            ])->values(),
            'branches' => $allowed->map(fn ($b) => ['value' => (string) $b->id, 'label' => $b->name])
                ->when($allowed->count() > 1, fn ($c) => $c->prepend(['value' => 'all', 'label' => 'All branches']))->values(),
            'gstins' => $this->filters->gstinGroups($allowed)->map(fn ($branches, $gstin) => [
                'value' => (string) $gstin, 'label' => ($gstin ?: 'No GSTIN').' — '.$branches->pluck('name')->implode(', '),
            ])->values(),
            'fyStart' => (string) settings('business.financial_year_start', '04-01'),
            'sections' => array_map(fn (Section $s) => $s->toArray(self::SCREEN_ROWS), $sections),
        ]);
    }

    public function export(Request $request, string $report, ReportExporter $exporter): HttpResponse
    {
        $request->validate(['format' => ['required', 'in:xlsx,csv,pdf']]);
        $report = $this->authorizedReport($request, $report);
        $f = $this->filters->resolve($request, $report, $request->user());

        return $exporter->export($request->query('format'), $report, $f, $report->build($f));
    }

    private function authorizedReport(Request $request, string $key): Report
    {
        $report = $this->registry->find($key);
        abort_unless($report, 404);
        abort_unless($request->user()->can($report->permission()), 403);

        return $report;
    }
}
