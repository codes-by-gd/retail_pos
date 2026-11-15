<?php

namespace App\Http\Controllers;

use App\Modules\Reports\Services\DashboardService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardService $dashboard): Response
    {
        $period = array_key_exists((string) $request->query('period'), DashboardService::PERIODS) ? (string) $request->query('period') : 'today';
        $allowed = current_branch()->allowedFor($request->user());
        $all = $request->query('branch') === 'all' && $allowed->count() > 1;
        $current = current_branch()->get();

        [$branchIds, $label] = $all
            ? [$allowed->pluck('id')->all(), 'All branches']
            : [$current ? [$current->id] : [], $current?->name ?? 'no branch'];

        return Inertia::render('Dashboard', [
            'period' => $period,
            'periods' => collect(DashboardService::PERIODS)->map(fn ($label, $value) => compact('value', 'label'))->values(),
            'allBranches' => $all,
            'canAllBranches' => $allowed->count() > 1,
            'branchLabel' => $label,
            'data' => $branchIds ? $dashboard->build($request->user(), $period, $branchIds, $label) : null,
            'details' => Inertia::defer(fn () => $branchIds ? $dashboard->details($request->user(), $period, $branchIds, $label) : null),
        ]);
    }
}
